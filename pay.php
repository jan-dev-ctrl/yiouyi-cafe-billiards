<?php
// GET  api/pay.php?id=12   -> { paid: true|false }
// POST api/pay.php  { "id": 12, "method": "cash" | "gcash" }
// The customer tells us how they will pay. Nothing is "paid" until the cashier receives it
// and records it on the staff screen.

require_once __DIR__ . '/../includes/auth.php';
$user = require_role_json('member');

if ($_SERVER['REQUEST_METHOD'] === 'GET') {          // pay.php polls this to know when the cashier has recorded payment
    $q = db()->prepare('SELECT paid_at IS NOT NULL AS paid, status FROM orders WHERE id = ? AND user_id = ?');
    $q->execute([(int)($_GET['id'] ?? 0), $user['id']]);
    $r = $q->fetch();
    json_out(['paid' => $r ? (bool)$r['paid'] : false, 'status' => $r['status'] ?? null]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only.'], 405);
}
$in = json_decode(file_get_contents('php://input'), true);
$id = (int)($in['id'] ?? 0);
$method = (string)($in['method'] ?? '');
if (!in_array($method, ['cash', 'gcash'], true)) {
    json_out(['error' => 'Choose Cash or GCash.'], 422);
}

$up = db()->prepare('UPDATE orders SET pay_pref = ?
                      WHERE id = ? AND user_id = ? AND paid_at IS NULL AND status IN ("new","ready")');
$up->execute([$method, $id, $user['id']]);
if (!$up->rowCount()) {
    // Same choice tapped twice also lands here; check whether the order is simply fine
    $ok = db()->prepare('SELECT 1 FROM orders WHERE id = ? AND user_id = ? AND pay_pref = ? AND paid_at IS NULL');
    $ok->execute([$id, $user['id'], $method]);
    if (!$ok->fetchColumn()) {
        json_out(['error' => 'This order can no longer be changed (already paid or cancelled).'], 409);
    }
}
json_out(['ok' => true]);
