<?php
// POST api/orders.php  -> place a cafe order (called by menu.php)
//   or  { "action": "cancel", "id": 12 }  -> a customer cancels their OWN order, only while it is still unpaid and
//                                              not yet marked ready (My Orders / pay.php). The stock it held goes back.
// Body (JSON): { "where": "Table 2", "items": [ { "vid": 12, "q": 2 }, ... ] }
// Prices come from the database, never from the browser.
// Items that track stock are counted down here, and refused when there isn't enough left.

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/stock.php';

$user = require_role_json('member');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only.'], 405);
}

$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_out(['error' => 'Invalid request.'], 400);
}

// ---------- cancel one of my own orders ----------
if (($in['action'] ?? '') === 'cancel') {
    $oid = (int)($in['id'] ?? 0);
    $pdo = db();
    try {
        $pdo->beginTransaction();
        // One guarded UPDATE decides it: mine, still "new" (not ready yet) and nobody has recorded a payment.
        // If the cashier records a payment at the same moment, only one of the two wins.
        $up = $pdo->prepare('UPDATE orders SET status = "cancelled" WHERE id = ? AND user_id = ? AND status = "new" AND paid_at IS NULL');
        $up->execute([$oid, $user['id']]);
        if ($up->rowCount()) {
            stock_restore($pdo, $oid);                    // give back exactly what this order took
            $pdo->commit();
            json_out(['ok' => true]);
        }
        $pdo->rollBack();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        json_out(['error' => 'Could not cancel the order. Please try again.'], 500);
    }
    // Not cancellable: say why
    $why = $pdo->prepare('SELECT status, paid_at IS NOT NULL AS paid FROM orders WHERE id = ? AND user_id = ?');
    $why->execute([$oid, $user['id']]);
    $o = $why->fetch();
    if (!$o) { json_out(['error' => 'Order not found.'], 404); }
    if ($o['status'] === 'cancelled') { json_out(['ok' => true, 'already' => true]); }     // tapped twice: nothing more to do
    if ($o['paid'])                  { json_out(['error' => 'This order is already paid. Please ask the cashier if you need to cancel it.'], 409); }
    if ($o['status'] === 'ready')    { json_out(['error' => 'Your order is already ready, so it can no longer be cancelled here. Please ask the cashier.'], 409); }
    json_out(['error' => 'This order can no longer be cancelled.'], 409);
}

// Where to serve it
$allowedWhere = ['Takeout'];
for ($i = 1; $i <= 10; $i++) {
    $allowedWhere[] = 'Table ' . $i;
}
$where = (string)($in['where'] ?? '');
if (!in_array($where, $allowedWhere, true)) {
    json_out(['error' => 'Please choose Takeout or a table.'], 422);
}

// Clean up the cart: variant id => quantity
$qty = [];
foreach (($in['items'] ?? []) as $it) {
    $vid = (int)($it['vid'] ?? 0);
    $q   = (int)($it['q'] ?? 0);
    if ($vid > 0 && $q >= 1 && $q <= 99) {
        $qty[$vid] = ($qty[$vid] ?? 0) + $q;
    }
}
if (!$qty || count($qty) > 50) {
    json_out(['error' => 'Your order is empty.'], 422);
}

$pdo = db();
expire_unpaid_orders($pdo);                    // stock held by old unpaid orders comes back first
$ids = array_keys($qty);
$marks = implode(',', array_fill(0, count($ids), '?'));
$stmt = $pdo->prepare(
    "SELECT v.id, v.order_name, v.price, v.menu_item_id
       FROM menu_variants v
       JOIN menu_items i ON i.id = v.menu_item_id
      WHERE i.available = 1 AND v.id IN ($marks)"
);
$stmt->execute($ids);
$found = $stmt->fetchAll();

if (count($found) !== count($ids)) {
    json_out(['error' => 'Some items are no longer available. Please refresh the menu.'], 409);
}

$total = 0;
$need  = [];                                   // menu_item_id => units (all sizes of one item share its stock)
foreach ($found as $row) {
    $total += $row['price'] * $qty[(int)$row['id']];
    $need[(int)$row['menu_item_id']] = ($need[(int)$row['menu_item_id']] ?? 0) + $qty[(int)$row['id']];
}

// Two customers ordering at the same moment can be handed the same order number (MAX+1). The UNIQUE key
// refuses the second one (duplicate entry, or a deadlock between the two), so the whole order is simply
// tried again, which picks the next free number. Only a few tries are needed.
$orderId = 0;
$no = 0;
for ($attempt = 1; $attempt <= 3; $attempt++) {
    try {
        $pdo->beginTransaction();

        $tracked = stock_take($pdo, $need);            // throws OutOfStock if an item can't cover the order

        $no = (int)$pdo->query('SELECT COALESCE(MAX(order_no), 200) + 1 FROM orders FOR UPDATE')->fetchColumn();

        $pdo->prepare('INSERT INTO orders (order_no, user_id, location, total, status) VALUES (?, ?, ?, ?, "new")')
            ->execute([$no, $user['id'], $where, $total]);
        $orderId = (int)$pdo->lastInsertId();

        $ins = $pdo->prepare('INSERT INTO order_items (order_id, name, price, qty, menu_item_id, stock_used) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($found as $row) {
            $q = $qty[(int)$row['id']];
            $ins->execute([$orderId, $row['order_name'], $row['price'], $q, (int)$row['menu_item_id'],
                           $tracked[(int)$row['menu_item_id']] ? $q : 0]);
        }

        $pdo->commit();
        break;                                          // saved
    } catch (OutOfStock $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        json_out(['error' => $e->getMessage()], 409);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();                           // stock taken in this attempt is given back too
        }
        $code = $e instanceof PDOException ? (int)($e->errorInfo[1] ?? 0) : 0;
        $clash = in_array($code, [1062, 1213, 1205], true);   // duplicate order number / deadlock / lock wait
        if ($clash && $attempt < 3) {
            usleep(random_int(20000, 80000));           // short random pause so the two requests don't collide again
            continue;
        }
        json_out(['error' => $clash ? 'The cafe is busy right now. Please tap Place order again.'
                                    : 'Could not save your order. Please try again.'], $clash ? 503 : 500);
    }
}

json_out(['ok' => true, 'id' => $orderId, 'no' => $no, 'total' => $total]);
