<?php
// POST api/reservations.php -> request a billiards table (called by billiards.php)
//   or  { "action": "cancel", "id": 7 }  -> a customer cancels their own booking while it is still pending (mine.php)
// Body (JSON): { "table": "Table 1", "date": "2026-10-20", "time": "18:00", "mins": 90 }
//          or: { "table": "Table 1", "date": "2026-10-20", "time": "18:00", "open": true }   (open time)
// Fixed bookings: the total is computed here, never trusted from the browser.
// Open time: no duration or total is stored; staff start/stop the session and the bill is worked out
// from the time actually played (see api/staff.php).

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
date_default_timezone_set('Asia/Manila');

$user = require_role_json('member');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only.'], 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_out(['error' => 'Invalid request.'], 400);
}

if (($in['action'] ?? '') === 'cancel') {
    $up = db()->prepare('UPDATE reservations SET status = "cancelled"
                          WHERE id = ? AND user_id = ? AND status = "pending"');
    $up->execute([(int)($in['id'] ?? 0), (int)$user['id']]);
    if (!$up->rowCount()) {
        json_out(['error' => 'This booking can no longer be cancelled here. Please ask the staff.'], 409);
    }
    json_out(['ok' => true]);
}

const DURATIONS       = [60, 90, 120, 150, 180];
const MAX_DAYS_AHEAD  = 60;

function fmt12(int $min): string {
    return date('g:i A', mktime(intdiv($min, 60) % 24, $min % 60, 0, 1, 1, 2000));
}

$table = (string)($in['table'] ?? '');
$date  = (string)($in['date'] ?? '');
$time  = (string)($in['time'] ?? '');
$open  = !empty($in['open']);                 // open time: no fixed duration
$mins  = $open ? null : (int)($in['mins'] ?? 0);

if (!in_array($table, BOOKABLE_TABLES, true)) {
    json_out(['error' => 'Please choose an available table.'], 422);
}
if (!$open && !in_array($mins, DURATIONS, true)) {
    json_out(['error' => 'Please choose a valid duration.'], 422);
}

$day = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
if (!$day || $day->format('Y-m-d') !== $date) {
    json_out(['error' => 'Pick a date first.'], 422);
}
$today = new DateTimeImmutable('today');
if ($day < $today) {
    json_out(['error' => 'That date has already passed.'], 422);
}
if ($day > $today->modify('+' . MAX_DAYS_AHEAD . ' days')) {
    json_out(['error' => 'You can book up to ' . MAX_DAYS_AHEAD . ' days ahead.'], 422);
}

if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $time, $m)) {
    json_out(['error' => 'Please choose a valid start time.'], 422);
}
$start = (int)$m[1] * 60 + (int)$m[2];
// Open time has no end yet, so it holds the table until closing for the clash check
$end   = $open ? CLOSE_MIN : $start + $mins;

if ($start < OPEN_MIN || $start >= CLOSE_MIN || $end > CLOSE_MIN) {
    json_out(['error' => $open
        ? 'We are open 11:00 AM to 12:00 AM. Please start before closing time.'
        : 'We are open 11:00 AM to 12:00 AM. Your session must finish by midnight.'], 422);
}
if ($date === $today->format('Y-m-d') && $start <= (int)date('G') * 60 + (int)date('i')) {
    json_out(['error' => 'That start time has already passed today.'], 422);
}

$pdo  = db();
$lock = 'yiouyi_resv_' . md5($table . $date);

// Lock this table+date so two people can't grab the same slot at the same moment
$got = $pdo->prepare('SELECT GET_LOCK(?, 5)');
$got->execute([$lock]);
if (!(int)$got->fetchColumn()) {
    json_out(['error' => 'The system is busy. Please try again.'], 503);
}

$error  = null;
$status = 409;
$id = 0;
$total = $open ? 0 : billiards_price($mins);   // open time is billed when the session ends

try {
    $q = $pdo->prepare(
        'SELECT res_time, mins FROM reservations
          WHERE table_name = ? AND res_date = ? AND status IN ("pending","confirmed")
            AND TIME_TO_SEC(res_time) < ?
            AND (CASE WHEN mins IS NULL THEN 86400 ELSE TIME_TO_SEC(res_time) + mins * 60 END) > ?
          LIMIT 1'
    );
    $q->execute([$table, $date, $end * 60, $start * 60]);
    $clash = $q->fetch();

    if ($clash) {
        [$h, $mi] = array_map('intval', explode(':', $clash['res_time']));
        $cs = $h * 60 + $mi;
        $error = $table . ($clash['mins'] === null
                    ? ' has an open-time booking starting at ' . fmt12($cs) . ' on that date.'
                    : ' is already booked from ' . fmt12($cs) . ' to ' . fmt12($cs + (int)$clash['mins']) . ' on that date.')
               . ' Please pick another time or table.';
    } else {
        $pdo->prepare('INSERT INTO reservations (user_id, table_name, res_date, res_time, mins, total, status)
                       VALUES (?, ?, ?, ?, ?, ?, "pending")')
            ->execute([$user['id'], $table, $date, $time . ':00', $mins, $total]);
        $id = (int)$pdo->lastInsertId();
    }
} catch (Throwable $e) {
    $error  = 'Could not save your reservation. Please try again.';
    $status = 500;
}

$pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);

if ($error) {
    json_out(['error' => $error], $status);
}
json_out(['ok' => true, 'id' => $id, 'total' => $total]);
