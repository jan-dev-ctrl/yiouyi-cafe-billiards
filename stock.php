<?php
// Automatic stock counting for menu items that track stock (menu_items.stock IS NOT NULL).
//   stock_take()    - called inside the order transaction: reduces stock, or refuses if there isn't enough.
//   stock_restore() - called when staff cancel an order: puts back exactly what that order took.
// Items with stock = NULL are "not tracked" and are never touched.

class OutOfStock extends RuntimeException {}

/**
 * $need = [menu_item_id => units]. Locks each item row, checks, then deducts.
 * Returns [menu_item_id => true|false] telling which items are tracked (so the caller can record stock_used).
 * Throws OutOfStock (with a customer-friendly message) when a tracked item can't cover the order.
 */
function stock_take(PDO $pdo, array $need): array {
    ksort($need);                                       // same lock order every time -> no deadlocks
    $sel = $pdo->prepare('SELECT name, stock FROM menu_items WHERE id = ? FOR UPDATE');
    $upd = $pdo->prepare('UPDATE menu_items SET stock = stock - ? WHERE id = ?');
    $tracked = [];
    foreach ($need as $itemId => $units) {
        $sel->execute([$itemId]);
        $row = $sel->fetch();
        if (!$row || $row['stock'] === null) { $tracked[$itemId] = false; continue; }
        $left = (int)$row['stock'];
        if ($left < $units) {
            throw new OutOfStock($left <= 0
                ? 'Sorry, ' . $row['name'] . ' is out of stock.'
                : 'Sorry, only ' . $left . ' left of ' . $row['name'] . '.');
        }
        $upd->execute([$units, $itemId]);
        $tracked[$itemId] = true;
    }
    return $tracked;
}

/** Give back the stock an order took. Safe to call twice: stock_used is zeroed afterwards. */
function stock_restore(PDO $pdo, int $orderId): void {
    $rows = $pdo->prepare('SELECT menu_item_id, SUM(stock_used) AS n FROM order_items
                            WHERE order_id = ? AND stock_used > 0 AND menu_item_id IS NOT NULL
                            GROUP BY menu_item_id ORDER BY menu_item_id');
    $rows->execute([$orderId]);
    $upd = $pdo->prepare('UPDATE menu_items SET stock = stock + ? WHERE id = ? AND stock IS NOT NULL');
    foreach ($rows->fetchAll() as $r) {
        $upd->execute([(int)$r['n'], (int)$r['menu_item_id']]);
    }
    $pdo->prepare('UPDATE order_items SET stock_used = 0 WHERE order_id = ?')->execute([$orderId]);
}

/** Unpaid orders still "new" after this many minutes are cancelled automatically and their stock is given back. */
const UNPAID_ORDER_MINUTES = 30;

/**
 * Cancel orders that were placed but never paid (status "new", no payment recorded) for longer than
 * UNPAID_ORDER_MINUTES, and return the stock they took. There is no cron on shared hosting, so the pages that
 * show stock or orders call this first. Each order is cancelled with a guarded UPDATE, so it is safe if two
 * requests run it at the same time (only one of them wins each order).
 * Returns how many orders were cancelled.
 */
function expire_unpaid_orders(PDO $pdo): int {
    try {
        $ids = $pdo->query('SELECT id FROM orders
                             WHERE status = "new" AND paid_at IS NULL
                               AND created_at < NOW() - INTERVAL ' . UNPAID_ORDER_MINUTES . ' MINUTE')->fetchAll(PDO::FETCH_COLUMN);
        $n = 0;
        foreach ($ids as $id) {
            $pdo->beginTransaction();
            $up = $pdo->prepare('UPDATE orders SET status = "cancelled"
                                  WHERE id = ? AND status = "new" AND paid_at IS NULL
                                    AND created_at < NOW() - INTERVAL ' . UNPAID_ORDER_MINUTES . ' MINUTE');
            $up->execute([(int)$id]);
            if ($up->rowCount()) {
                stock_restore($pdo, (int)$id);
                $n++;
            }
            $pdo->commit();
        }
        return $n;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        return 0;                                   // never break a page because the cleanup failed
    }
}
