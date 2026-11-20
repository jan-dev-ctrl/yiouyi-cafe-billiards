<?php
// Customer feedback.
//   POST api/feedback.php { "rating": 1-5, "message": "..." }           -> a customer shares feedback (it appears on the landing page)
//   POST api/feedback.php { "action": "hide"|"show"|"delete", "id": 7 } -> admin moderation (Customer Feedback box on dashboard.php)
// Customers are limited to one feedback every 24 hours. Only the first name and last initial are ever shown publicly.

require_once __DIR__ . '/../includes/auth.php';
$user = require_role_json('member');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only.'], 405);
}
$in  = json_decode(file_get_contents('php://input'), true) ?: [];
$pdo = db();

// Text helpers that work on any PHP (they don't need the optional mbstring extension)
function fb_len(string $s): int { return (int)preg_match_all('/./su', $s); }
function fb_left(string $s, int $n): string { return preg_match('/^.{0,' . $n . '}/su', $s, $m) ? $m[0] : ''; }
function fb_upper(string $c): string { return function_exists('mb_strtoupper') ? mb_strtoupper($c) : strtoupper($c); }

// ---------- admin: hide / show / delete ----------
$action = (string)($in['action'] ?? '');
if ($action !== '') {
    if ($user['role'] !== 'admin') {
        json_out(['error' => 'Only the admin can do that.'], 403);
    }
    $id = (int)($in['id'] ?? 0);
    if ($action === 'delete') {
        $st = $pdo->prepare('DELETE FROM feedback WHERE id = ?');
        $st->execute([$id]);
    } elseif ($action === 'hide' || $action === 'show') {
        $st = $pdo->prepare('UPDATE feedback SET visible = ? WHERE id = ?');
        $st->execute([$action === 'show' ? 1 : 0, $id]);
        // rowCount() is 0 when nothing changed (already hidden/shown); only an unknown id is an error
        $exists = $pdo->prepare('SELECT 1 FROM feedback WHERE id = ?');
        $exists->execute([$id]);
        if (!$exists->fetchColumn()) {
            json_out(['error' => 'Feedback not found.'], 404);
        }
    } else {
        json_out(['error' => 'Unknown action.'], 422);
    }
    json_out(['ok' => true]);
}

// ---------- customer: share feedback ----------
if ($user['role'] !== 'user') {
    json_out(['error' => 'Feedback is for customers. Please log in with a customer account.'], 403);
}
$rating = (int)($in['rating'] ?? 0);
if ($rating < 1 || $rating > 5) {
    json_out(['error' => 'Please choose 1 to 5 stars.'], 422);
}
$raw = (string)($in['message'] ?? '');
if (!preg_match('//u', $raw)) {                        // not valid UTF-8
    json_out(['error' => 'Your message has characters we cannot read. Please retype it.'], 422);
}
$msg = trim(preg_replace('/\s+/u', ' ', preg_replace('/[\x00-\x1F\x7F]+/', ' ', $raw)));
$len = fb_len($msg);
if ($len < 5) {
    json_out(['error' => 'Please write at least a few words about your visit.'], 422);
}
if ($len > 500) {
    json_out(['error' => 'Please keep it under 500 characters.'], 422);
}
if (preg_match('~(https?://|www\.)~i', $msg)) {
    json_out(['error' => 'Please leave links out of your feedback.'], 422);
}

/** "Ana Cruz" -> "Ana C."   (never the email, never the full surname) */
function fb_display_name(string $full): string {
    $parts = preg_split('/\s+/u', trim($full), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$parts) {
        return 'Guest';
    }
    $first = fb_left($parts[0], 20);
    return count($parts) > 1 ? $first . ' ' . fb_upper(fb_left(end($parts), 1)) . '.' : $first;
}

try {
    $pdo->beginTransaction();
    // lock this customer's row so two quick taps can't both pass the daily limit
    $u = $pdo->prepare('SELECT full_name FROM users WHERE id = ? FOR UPDATE');
    $u->execute([$user['id']]);
    $full = $u->fetchColumn();
    if ($full === false) {
        $pdo->rollBack();
        json_out(['error' => 'Please log in again.'], 401);
    }
    $recent = $pdo->prepare('SELECT COUNT(*) FROM feedback WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 DAY');
    $recent->execute([$user['id']]);
    if ((int)$recent->fetchColumn() > 0) {
        $pdo->rollBack();
        json_out(['error' => 'Thank you! You already shared feedback today. You can write another tomorrow.'], 429);
    }
    $ins = $pdo->prepare('INSERT INTO feedback (user_id, display_name, rating, message) VALUES (?, ?, ?, ?)');
    $ins->execute([$user['id'], fb_display_name((string)$full), $rating, $msg]);
    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_out(['error' => 'Feedback is not set up yet. Please try again later.'], 500);
}
json_out(['ok' => true]);
