<?php
// Which billiards tables are free right now, and which times are already booked.
// Used by billiards.php (first paint) and api/availability.php (refreshes every 30 seconds).
// Customers only ever get: table, status and time slots. Never names, emails or money.

require_once __DIR__ . '/billing.php';

const AVAIL_SOON_MIN = 60;   // same rule as the staff Board: a booking starting within an hour (or already late) = "Reserved"

/** 0..1440 minutes since midnight -> "3:30 PM"  (1440 = "12:00 AM") */
function avail_clock(int $min): string {
    $min = max(0, min(1440, $min));
    $h = intdiv($min, 60) % 24; $m = $min % 60;
    return (($h % 12) ?: 12) . ':' . str_pad((string)$m, 2, '0', STR_PAD_LEFT) . ' ' . ($h < 12 ? 'AM' : 'PM');
}

function avail_now(): array {
    $n = new DateTime('now', new DateTimeZone('Asia/Manila'));
    return ['date' => $n->format('Y-m-d'), 'min' => (int)$n->format('G') * 60 + (int)$n->format('i'), 'label' => $n->format('g:i A')];
}

/** Real status of every bookable table at this moment. */
function table_status(PDO $pdo): array {
    $now = avail_now();
    $st = $pdo->prepare("SELECT table_name, TIME_TO_SEC(res_time) DIV 60 AS start_min, mins, status,
                                started_at IS NOT NULL AS started, UNIX_TIMESTAMP(started_at) AS st
                           FROM reservations
                          WHERE status IN ('pending','confirmed')
                            AND (res_date = ? OR (status = 'confirmed' AND started_at IS NOT NULL))
                          ORDER BY res_time");
    $st->execute([$now['date']]);
    $rows = $st->fetchAll();
    $tz = new DateTimeZone('Asia/Manila');
    $out = [];
    foreach (BOOKABLE_TABLES as $t) {
        $play = null; $next = null;
        foreach ($rows as $r) {
            if ($r['table_name'] !== $t) { continue; }
            if ($r['status'] === 'confirmed' && (int)$r['started']) { $play = $play ?? $r; }
            elseif (!(int)$r['started'] && $next === null) { $next = $r; }          // earliest booking not started yet
        }
        if ($next && ($next['status'] === 'pending' || $next['status'] === 'confirmed') && $next['start_min'] !== null) { /* ok */ } else { $next = null; }
        $nextLabel = $next ? avail_clock((int)$next['start_min']) : null;
        if ($play) {
            $status = 'playing'; $label = 'Playing';
            if ($play['mins'] !== null) {
                $until = (new DateTime('@' . ((int)$play['st'] + (int)$play['mins'] * 60)))->setTimezone($tz);
                $note = $until->getTimestamp() > time() ? 'In use • until about ' . $until->format('g:i A') : 'In use • finishing up';
            } else {
                $note = 'In use • open time';
            }
            if ($nextLabel) { $note .= ' • next booking ' . $nextLabel; }
        } elseif ($next && (int)$next['start_min'] - $now['min'] <= AVAIL_SOON_MIN) {
            $status = 'reserved'; $label = 'Reserved';
            $note = ((int)$next['start_min'] - $now['min'] > 0 ? 'Reserved for ' : 'Reserved since ') . $nextLabel;
        } else {
            $status = 'available'; $label = 'Available';
            $note = $nextLabel ? 'Free now • next booking ' . $nextLabel : 'Free now';
        }
        $out[] = ['table' => $t, 'status' => $status, 'label' => $label, 'note' => $note];
    }
    return $out;
}

/** Times already taken on $date (Y-m-d), per table: [table => [['s'=>minutes,'e'=>minutes,'open'=>bool,'label'=>'3:00 PM – 4:30 PM'], ...]] */
function booked_slots(PDO $pdo, string $date): array {
    $slots = [];
    foreach (BOOKABLE_TABLES as $t) { $slots[$t] = []; }
    $st = $pdo->prepare("SELECT table_name, TIME_TO_SEC(res_time) DIV 60 AS s, mins FROM reservations
                          WHERE res_date = ? AND status IN ('pending','confirmed') ORDER BY res_time");
    $st->execute([$date]);
    foreach ($st as $r) {
        if (!isset($slots[$r['table_name']])) { continue; }
        $s = (int)$r['s']; $open = $r['mins'] === null;
        $e = $open ? CLOSE_MIN : $s + (int)$r['mins'];                      // open time holds the table until closing (same rule as booking)
        $slots[$r['table_name']][] = ['s' => $s, 'e' => $e, 'open' => $open,
            'label' => avail_clock($s) . ($open ? ' onward (open time)' : ' – ' . avail_clock($e))];
    }
    return $slots;
}
