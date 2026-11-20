<?php
// Staff API (used by staff.php). Staff and admin only.
//   GET  api/staff.php                       -> { orders: [...], resv: [...], menu: [...] }
//   POST api/staff.php  { "action": ... }    -> order_status | order_pay | resv_status | resv_start | menu_toggle | menu_delete | menu_save

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/stock.php';
date_default_timezone_set('Asia/Manila');

require_role_json('staff');
$pdo = db();
expire_unpaid_orders($pdo);                    // old unpaid orders are cancelled before staff see the list

const PAY_METHODS = ['cash', 'gcash', 'card'];

// ------------------------------------------------------------------ GET: everything staff needs
//   GET api/staff.php              -> full load: open orders/tables + the last STAFF_HISTORY_DAYS days, plus the menu
//   GET api/staff.php?since=<t>    -> only orders/reservations changed since <t> (the "dbNow" of the previous reply)
// The screen asks for the full load once (and after its own actions), then polls with ?since= every few seconds.
const STAFF_HISTORY_DAYS = 7;     // finished orders and past bookings stay on screen this long; open ones always show

/** Orders matching $where (SQL on alias o), with their items, in the shape staff.php uses. */
function staff_orders(PDO $pdo, string $where, array $args): array {
    $st = $pdo->prepare(
        'SELECT o.id, o.order_no, o.location, o.total, o.status, o.pay_method, o.pay_pref, o.tendered,
                UNIX_TIMESTAMP(o.created_at) * 1000 AS at_ms, UNIX_TIMESTAMP(o.paid_at) * 1000 AS paid_ms, u.email
           FROM orders o JOIN users u ON u.id = o.user_id
          WHERE ' . $where . ' ORDER BY o.id DESC'
    );
    $st->execute($args);
    $rows = $st->fetchAll();
    $itemsBy = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $in  = implode(',', array_fill(0, count($ids), '?'));
        $it  = $pdo->prepare("SELECT order_id, name, price, qty FROM order_items WHERE order_id IN ($in) ORDER BY id");
        $it->execute($ids);
        foreach ($it as $r) {
            $itemsBy[$r['order_id']][] = ['n' => $r['name'], 'p' => (float)$r['price'], 'q' => (int)$r['qty']];
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (string)$r['id'], 'no' => (int)$r['order_no'], 'who' => $r['email'], 'where' => $r['location'],
            'items' => $itemsBy[$r['id']] ?? [], 'total' => (float)$r['total'], 'status' => $r['status'],
            'pay' => $r['pay_method'], 'at' => (int)$r['at_ms'],
            'pref' => $r['pay_pref'], 'paidAt' => $r['paid_ms'] === null ? null : (int)$r['paid_ms'],
            'tendered' => $r['tendered'] === null ? null : (float)$r['tendered'],
        ];
    }
    return $out;
}

/** Reservations matching $where (SQL on alias r). */
function staff_resv(PDO $pdo, string $where, array $args): array {
    $st = $pdo->prepare(
        'SELECT r.id, r.table_name, r.res_date, TIME_FORMAT(r.res_time, "%H:%i") AS t, r.mins, r.total,
                r.status, r.pay_method, r.play_mins,
                UNIX_TIMESTAMP(r.started_at) * 1000 AS started_ms,
                UNIX_TIMESTAMP(DATE(r.started_at) + INTERVAL ' . CLOSE_MIN . ' MINUTE) * 1000 AS close_ms, u.email
           FROM reservations r JOIN users u ON u.id = r.user_id
          WHERE ' . $where . ' ORDER BY r.res_date, r.res_time'
    );
    $st->execute($args);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'id' => (string)$r['id'], 'who' => $r['email'], 'table' => $r['table_name'], 'date' => $r['res_date'],
            'time' => $r['t'], 'mins' => $r['mins'] === null ? null : (int)$r['mins'], 'total' => (float)$r['total'],
            'status' => $r['status'], 'pay' => $r['pay_method'],
            'open' => $r['mins'] === null,                       // open time: billed by the time actually played
            'started' => $r['started_ms'] === null ? null : (int)$r['started_ms'],
            'close' => $r['close_ms'] === null ? null : (int)$r['close_ms'],   // billing for an open session stops here
            'played' => $r['play_mins'] === null ? null : (int)$r['play_mins'],
        ];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $dbNow = (int)$pdo->query('SELECT UNIX_TIMESTAMP()')->fetchColumn();   // read BEFORE the data, so nothing can slip between polls
    $nowMs = (int)round(microtime(true) * 1000);
    $since = (int)($_GET['since'] ?? 0);

    if ($since > 0 && $dbNow - $since < 3600) {              // delta: only rows touched since the last reply
        try {
            json_out([
                'full' => false, 'dbNow' => $dbNow, 'now' => $nowMs,
                'orders' => staff_orders($pdo, 'o.updated_at >= FROM_UNIXTIME(?)', [$since]),
                'resv'   => staff_resv($pdo, 'r.updated_at >= FROM_UNIXTIME(?)', [$since]),
            ]);
        } catch (PDOException $e) {
            // updated_at columns not added yet (migrate_updated_at.sql): fall through to the full load
        }
    }

    $days = STAFF_HISTORY_DAYS;
    $menu = [];
    $byId = [];
    foreach ($pdo->query('SELECT id, cat, section, note, name, item_key, available FROM menu_items ORDER BY id') as $r) {
        $byId[$r['id']] = count($menu);
        $menu[] = ['id' => (string)$r['id'], 'cat' => $r['cat'], 'sec' => $r['section'], 'note' => $r['note'],
                   'name' => $r['name'], 'key' => $r['item_key'], 'on' => (bool)$r['available'], 'vs' => []];
    }
    foreach ($pdo->query('SELECT menu_item_id, label, order_name, price FROM menu_variants ORDER BY id') as $v) {
        if (isset($byId[$v['menu_item_id']])) {
            $menu[$byId[$v['menu_item_id']]]['vs'][] = ['l' => $v['label'], 'n' => $v['order_name'], 'p' => (float)$v['price']];
        }
    }

    json_out([
        'full' => true, 'dbNow' => $dbNow, 'now' => $nowMs,
        'orders' => staff_orders($pdo, 'o.status IN ("new","ready") OR o.created_at >= NOW() - INTERVAL ' . $days . ' DAY', []),
        'resv'   => staff_resv($pdo, 'r.status IN ("pending","confirmed") OR r.res_date >= CURDATE() - INTERVAL ' . $days . ' DAY', []),
        'menu'   => $menu,
        'tables' => BOOKABLE_TABLES,                          // the live billiards board shows one card per table
    ]);
}

// ------------------------------------------------------------------ POST: actions
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'Method not allowed.'], 405);
}
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_out(['error' => 'Invalid request.'], 400);
}
$action = (string)($in['action'] ?? '');
$id     = (int)($in['id'] ?? 0);

/** Move an order/reservation to a new status, only along allowed steps. */
function change_status(PDO $pdo, string $table, array $flow, int $id, string $to, ?string $pay): void {
    $cur = $pdo->prepare("SELECT status FROM $table WHERE id = ?");
    $cur->execute([$id]);
    $from = $cur->fetchColumn();
    if ($from === false) {
        json_out(['error' => 'Not found.'], 404);
    }
    if (!in_array($to, $flow[$from] ?? [], true)) {
        json_out(['error' => 'That change is not allowed (already ' . $from . ').'], 409);
    }
    if ($to === 'settled') {
        if (!in_array($pay, PAY_METHODS, true)) {
            json_out(['error' => 'Choose a payment method.'], 422);
        }
        $up = $pdo->prepare("UPDATE $table SET status = ?, pay_method = ? WHERE id = ? AND status = ?");
        $up->execute([$to, $pay, $id, $from]);
    } else {
        $up = $pdo->prepare("UPDATE $table SET status = ? WHERE id = ? AND status = ?");
        $up->execute([$to, $id, $from]);
    }
    if (!$up->rowCount()) {
        json_out(['error' => 'Someone else just changed this. Refreshing.'], 409);
    }
}

switch ($action) {
    case 'order_status':
        if ((string)($in['status'] ?? '') === 'cancelled') {
            // Cancel and give the stock back in one step, so a cancelled order never keeps units.
            try {
                $pdo->beginTransaction();
                $up = $pdo->prepare('UPDATE orders SET status = "cancelled" WHERE id = ? AND status = "new" AND paid_at IS NULL');
                $up->execute([$id]);
                if ($up->rowCount()) {
                    stock_restore($pdo, $id);
                    $pdo->commit();
                    json_out(['ok' => true]);
                }
                $pdo->rollBack();                  // not cancellable: change_status below explains why
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                json_out(['error' => 'Could not cancel the order. Please try again.'], 500);
            }
        }
        if ((string)($in['status'] ?? '') === 'settled') {
            // Served & settled. If the cashier already received payment, just close the order;
            // otherwise record the payment now (old behaviour).
            $pay = $in['pay'] ?? null;
            $cur = $pdo->prepare('SELECT paid_at IS NOT NULL FROM orders WHERE id = ? AND status = "ready"');
            $cur->execute([$id]);
            $already = $cur->fetchColumn();
            if ($already === false) {
                json_out(['error' => 'That change is not allowed (the order must be ready first).'], 409);
            }
            if ((int)$already === 1) {
                $up = $pdo->prepare('UPDATE orders SET status = "settled" WHERE id = ? AND status = "ready"');
                $up->execute([$id]);
            } else {
                if (!in_array($pay, PAY_METHODS, true)) {
                    json_out(['error' => 'Choose a payment method.'], 422);
                }
                $up = $pdo->prepare('UPDATE orders SET status = "settled", pay_method = ?, paid_at = NOW(), cashier_id = ?
                                      WHERE id = ? AND status = "ready"');
                $up->execute([$pay, $_SESSION['user']['id'], $id]);
            }
            if (!$up->rowCount()) {
                json_out(['error' => 'Someone else just changed this. Refreshing.'], 409);
            }
            json_out(['ok' => true]);
        }
        change_status($pdo, 'orders', ['new' => ['ready', 'cancelled'], 'ready' => ['settled']],
                      $id, (string)($in['status'] ?? ''), $in['pay'] ?? null);
        json_out(['ok' => true]);

    case 'order_pay':
        // Cashier received the payment (at the counter, or a GCash transfer they checked).
        // The order can still be preparing: paying and serving are separate steps.
        $pay = $in['pay'] ?? null;
        if (!in_array($pay, PAY_METHODS, true)) {
            json_out(['error' => 'Choose a payment method.'], 422);
        }
        $row = $pdo->prepare('SELECT total FROM orders WHERE id = ? AND status IN ("new","ready") AND paid_at IS NULL');
        $row->execute([$id]);
        $total = $row->fetchColumn();
        if ($total === false) {
            json_out(['error' => 'This order is already paid, cancelled or finished. Refreshing.'], 409);
        }
        $tendered = null;
        if ($pay === 'cash' && isset($in['tendered']) && $in['tendered'] !== '' && $in['tendered'] !== null) {
            $tendered = round((float)$in['tendered'], 2);
            if ($tendered < (float)$total || $tendered > 1000000) {
                json_out(['error' => 'Cash received is less than the total (₱' . number_format((float)$total, 2) . ').'], 422);
            }
        }
        $up = $pdo->prepare('UPDATE orders SET pay_method = ?, paid_at = NOW(), tendered = ?, cashier_id = ?
                              WHERE id = ? AND status IN ("new","ready") AND paid_at IS NULL');
        $up->execute([$pay, $tendered, $_SESSION['user']['id'], $id]);
        if (!$up->rowCount()) {
            json_out(['error' => 'Someone else just changed this. Refreshing.'], 409);
        }
        json_out(['ok' => true, 'change' => $tendered === null ? null : round($tendered - (float)$total, 2)]);

    case 'resv_start':
        // Staff press Start when the players sit down (open time AND fixed bookings; the live board shows the clock).
        $rv = $pdo->prepare('SELECT table_name, res_date FROM reservations WHERE id = ? AND status = "confirmed" AND started_at IS NULL');
        $rv->execute([$id]);
        $row = $rv->fetch();
        if (!$row) {
            json_out(['error' => 'This session cannot be started (not confirmed, or already started).'], 409);
        }
        if ($row['res_date'] !== date('Y-m-d')) {
            json_out(['error' => 'Only a booking for today can be started.'], 409);
        }
        $busy = $pdo->prepare('SELECT 1 FROM reservations WHERE table_name = ? AND status = "confirmed" AND started_at IS NOT NULL AND id <> ? LIMIT 1');
        $busy->execute([$row['table_name'], $id]);
        if ($busy->fetchColumn()) {
            json_out(['error' => $row['table_name'] . ' is still being played. End and settle that session first.'], 409);
        }
        $up = $pdo->prepare('UPDATE reservations SET started_at = NOW()
                              WHERE id = ? AND status = "confirmed" AND started_at IS NULL');
        $up->execute([$id]);
        if (!$up->rowCount()) {
            json_out(['error' => 'Someone else just changed this. Refreshing.'], 409);
        }
        json_out(['ok' => true]);

    case 'resv_status':
        $to = (string)($in['status'] ?? '');
        // An open session is only billed until closing time, so a session someone forgot to end can't run up the bill.
        $close = 'DATE(started_at) + INTERVAL ' . CLOSE_MIN . ' MINUTE';
        $cur = $pdo->prepare("SELECT mins, started_at,
                                     TIMESTAMPDIFF(SECOND, started_at, LEAST(NOW(), $close)) AS secs,
                                     (NOW() > $close) AS capped
                                FROM reservations WHERE id = ?");
        $cur->execute([$id]);
        $rv = $cur->fetch();
        if ($rv && $rv['mins'] === null) {            // open-time booking
            if ($to === 'cancelled' && $rv['started_at'] !== null) {
                json_out(['error' => 'This session has already started. End and settle it instead.'], 409);
            }
            if ($to === 'settled') {
                $pay = $in['pay'] ?? null;
                if ($rv['started_at'] === null) {
                    json_out(['error' => 'Start the session first, then end and settle it.'], 409);
                }
                if (!in_array($pay, PAY_METHODS, true)) {
                    json_out(['error' => 'Choose a payment method.'], 422);
                }
                $played = billable_minutes((int)$rv['secs']);
                $up = $pdo->prepare('UPDATE reservations
                                        SET status = "settled", pay_method = ?, ended_at = LEAST(NOW(), ' . $close . '),
                                            play_mins = ?, total = ?
                                      WHERE id = ? AND status = "confirmed" AND ended_at IS NULL');
                $up->execute([$pay, $played, billiards_price($played), $id]);
                if (!$up->rowCount()) {
                    json_out(['error' => 'Someone else just changed this. Refreshing.'], 409);
                }
                json_out(['ok' => true, 'mins' => $played, 'total' => billiards_price($played), 'capped' => (bool)$rv['capped']]);
            }
        }
        if ($rv && $rv['mins'] !== null && $to === 'cancelled' && $rv['started_at'] !== null) {   // fixed booking already playing
            json_out(['error' => 'This session has already started. End and settle it instead.'], 409);
        }
        change_status($pdo, 'reservations', ['pending' => ['confirmed', 'cancelled'], 'confirmed' => ['settled', 'cancelled']],
                      $id, $to, $in['pay'] ?? null);
        json_out(['ok' => true]);

    case 'menu_toggle':
        $pdo->prepare('UPDATE menu_items SET available = 1 - available WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);

    case 'menu_delete':
        $pdo->prepare('DELETE FROM menu_items WHERE id = ?')->execute([$id]);   // variants go with it (ON DELETE CASCADE)
        json_out(['ok' => true]);

    case 'menu_save':
        $name = trim((string)($in['name'] ?? ''));
        $cat  = trim((string)($in['cat'] ?? ''));
        $sec  = trim((string)($in['sec'] ?? '')) ?: 'Other';
        $vs   = [];
        foreach (($in['vs'] ?? []) as $v) {
            $price = (int)($v['p'] ?? 0);
            $label = trim((string)($v['l'] ?? ''));
            if ($price > 0 && $price <= 99999 && strlen($label) <= 40) {
                $vs[] = ['l' => $label, 'p' => $price];
            }
        }
        if ($name === '' || strlen($name) > 90 || strlen($sec) > 80 || !preg_match('/^[a-z0-9_-]{1,40}$/', $cat)) {
            json_out(['error' => 'Check the name, category and section.'], 422);
        }
        if (!$vs || count($vs) > 8) {
            json_out(['error' => 'Add at least one price (up to 8 sizes).'], 422);
        }
        $labels = array_map(fn($v) => strtolower($v['l']), $vs);
        if (count($labels) !== count(array_unique($labels))) {
            json_out(['error' => 'Each size needs a different label, for example "Hot=100, Iced=110".'], 422);
        }

        // Keep the section's note consistent with the other items already in it
        $n = $pdo->prepare('SELECT note FROM menu_items WHERE cat = ? AND section = ? AND id <> ? ORDER BY id LIMIT 1');
        $n->execute([$cat, $sec, $id]);
        $note = (string)($n->fetchColumn() ?: '');
        $key  = $cat . ' ' . strtolower($name);

        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE menu_items SET cat = ?, section = ?, note = ?, name = ?, item_key = ? WHERE id = ?')
                    ->execute([$cat, $sec, $note, $name, $key, $id]);
                $pdo->prepare('DELETE FROM menu_variants WHERE menu_item_id = ?')->execute([$id]);
            } else {
                $pdo->prepare('INSERT INTO menu_items (cat, section, note, name, item_key, available) VALUES (?, ?, ?, ?, ?, 1)')
                    ->execute([$cat, $sec, $note, $name, $key]);
                $id = (int)$pdo->lastInsertId();
            }
            // Each variant needs its own order name (it is what orders, receipts and reports show).
            $taken = $pdo->prepare('SELECT 1 FROM menu_variants WHERE order_name = ? AND menu_item_id <> ? LIMIT 1');
            $ins   = $pdo->prepare('INSERT INTO menu_variants (menu_item_id, label, order_name, price) VALUES (?, ?, ?, ?)');
            $used  = [];
            foreach ($vs as $v) {
                $tag = ($v['l'] !== '' && strtolower($v['l']) !== 'add') ? ' (' . $v['l'] . ')' : '';
                $cands = [$name . $tag, $name . ' (' . $sec . ($tag !== '' ? ', ' . $v['l'] : '') . ')', $name . ' (' . $sec . ')' . $tag . ' #' . $id];
                $on = null;
                foreach ($cands as $c) {
                    $taken->execute([$c, $id]);
                    if (!$taken->fetchColumn() && !isset($used[strtolower($c)])) { $on = $c; break; }
                }
                if ($on === null) { throw new RuntimeException('duplicate sizes'); }
                $used[strtolower($on)] = true;
                $ins->execute([$id, $v['l'], $on, $v['p']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['error' => $e instanceof RuntimeException ? 'Each size needs a different label, for example "Hot=100, Iced=110".'
                                                                  : 'Could not save the item.'], $e instanceof RuntimeException ? 422 : 500);
        }
        json_out(['ok' => true, 'id' => $id]);

    default:
        json_out(['error' => 'Unknown action.'], 400);
}
