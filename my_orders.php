<?php
// GET api/my_orders.php -> the logged-in customer's latest cafe orders and their live status.
// Polled by assets/orders-live.js (customer pages) to update "Preparing -> Ready" and to raise the "order ready" alert.
// Only the customer's own orders are returned.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock.php';

$user = require_role_json('member');
$pdo  = db();
expire_unpaid_orders($pdo);              // an order nobody paid for may have just been cancelled

$st = $pdo->prepare('SELECT id, order_no, location, total, status, (paid_at IS NOT NULL OR status = "settled") AS paid,
                            UNIX_TIMESTAMP(created_at) * 1000 AS at_ms
                       FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 30');
$st->execute([(int)$user['id']]);

$orders = [];
foreach ($st as $r) {
    $orders[] = ['id' => (int)$r['id'], 'no' => (int)$r['order_no'], 'where' => $r['location'], 'total' => (float)$r['total'],
                 'status' => $r['status'], 'paid' => (bool)$r['paid'], 'at' => (int)$r['at_ms']];
}

header('Cache-Control: no-store');
json_out(['orders' => $orders, 'now' => (int)round(microtime(true) * 1000)]);
