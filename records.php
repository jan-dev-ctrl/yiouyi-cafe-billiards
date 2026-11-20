<?php
// Admin-only API for records.php (menu & inventory records)
// POST api/records.php  { "action": "save" | "duplicate" | "delete" | "bulk", ... }

require_once __DIR__ . '/../includes/auth.php';

require_role_json('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only.'], 405);
}
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)) {
    json_out(['error' => 'Invalid request.'], 400);
}

$pdo    = db();
$action = (string)($in['action'] ?? '');
$id     = (int)($in['id'] ?? 0);

switch ($action) {

    case 'save':
        $name = trim((string)($in['name'] ?? ''));
        $cat  = trim((string)($in['cat'] ?? ''));
        $sec  = trim((string)($in['sec'] ?? '')) ?: 'Other';
        $sku  = strtoupper(trim((string)($in['sku'] ?? '')));
        $avail = !empty($in['available']) ? 1 : 0;

        if ($name === '' || strlen($name) > 90 || strlen($sec) > 80 || !preg_match('/^[a-z0-9_-]{1,40}$/', $cat)) {
            json_out(['error' => 'Check the name, category and section.'], 422);
        }
        if ($sku !== '' && !preg_match('/^[A-Z0-9._-]{1,30}$/', $sku)) {
            json_out(['error' => 'SKU can only use letters, numbers, dot, dash and underscore.'], 422);
        }
        $sku = $sku === '' ? null : $sku;

        $cost = $in['cost'] ?? null;
        if ($cost === '' || $cost === null) {
            $cost = null;
        } elseif (!is_numeric($cost) || $cost < 0 || $cost > 99999) {
            json_out(['error' => 'Cost must be a number.'], 422);
        } else {
            $cost = round((float)$cost, 2);
        }

        $stock = $in['stock'] ?? null;
        if ($stock === '' || $stock === null) {
            $stock = null;                       // not tracked
        } elseif (!is_numeric($stock) || $stock < 0 || $stock > 999999) {
            json_out(['error' => 'Units left must be a whole number (or leave it empty).'], 422);
        } else {
            $stock = (int)$stock;
        }

        $reorder = $in['reorder'] ?? 5;
        $reorder = (is_numeric($reorder) && $reorder >= 0 && $reorder <= 9999) ? (int)$reorder : 5;

        $vs = [];
        foreach (($in['vs'] ?? []) as $v) {
            $price = (int)($v['p'] ?? 0);
            $label = trim((string)($v['l'] ?? ''));
            if ($price > 0 && $price <= 99999 && strlen($label) <= 40) {
                $vs[] = ['l' => $label, 'p' => $price];
            }
        }
        if (!$vs || count($vs) > 8) {
            json_out(['error' => 'Add at least one price (up to 8 sizes).'], 422);
        }

        $n = $pdo->prepare('SELECT note FROM menu_items WHERE cat = ? AND section = ? AND id <> ? ORDER BY id LIMIT 1');
        $n->execute([$cat, $sec, $id]);
        $note = (string)($n->fetchColumn() ?: '');
        $key  = $cat . ' ' . strtolower($name);

        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE menu_items SET cat=?, section=?, note=?, name=?, item_key=?, available=?, sku=?, cost=?, stock=?, reorder_level=? WHERE id=?')
                    ->execute([$cat, $sec, $note, $name, $key, $avail, $sku, $cost, $stock, $reorder, $id]);
                $pdo->prepare('DELETE FROM menu_variants WHERE menu_item_id = ?')->execute([$id]);
            } else {
                $pdo->prepare('INSERT INTO menu_items (cat, section, note, name, item_key, available, sku, cost, stock, reorder_level) VALUES (?,?,?,?,?,?,?,?,?,?)')
                    ->execute([$cat, $sec, $note, $name, $key, $avail, $sku, $cost, $stock, $reorder]);
                $id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare('INSERT INTO menu_variants (menu_item_id, label, order_name, price) VALUES (?, ?, ?, ?)');
            foreach ($vs as $v) {
                $ins->execute([$id, $v['l'], $name . ($v['l'] !== '' ? ' (' . $v['l'] . ')' : ''), $v['p']]);
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ($e->getCode() === '23000') {
                json_out(['error' => 'That SKU is already used by another item.'], 409);
            }
            json_out(['error' => 'Could not save the item.'], 500);
        }
        json_out(['ok' => true, 'id' => $id]);

    case 'duplicate':
        $src = $pdo->prepare('SELECT * FROM menu_items WHERE id = ?');
        $src->execute([$id]);
        $it = $src->fetch();
        if (!$it) {
            json_out(['error' => 'Item not found.'], 404);
        }
        $newName = substr($it['name'], 0, 82) . ' (copy)';
        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO menu_items (cat, section, note, name, item_key, available, sku, cost, stock, reorder_level) VALUES (?,?,?,?,?,?,NULL,?,?,?)')
                ->execute([$it['cat'], $it['section'], $it['note'], $newName, $it['cat'] . ' ' . strtolower($newName),
                           $it['available'], $it['cost'], $it['stock'], $it['reorder_level']]);
            $newId = (int)$pdo->lastInsertId();
            $vq = $pdo->prepare('SELECT label, price FROM menu_variants WHERE menu_item_id = ? ORDER BY id');
            $vq->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO menu_variants (menu_item_id, label, order_name, price) VALUES (?, ?, ?, ?)');
            foreach ($vq as $v) {
                $ins->execute([$newId, $v['label'], $newName . ($v['label'] !== '' ? ' (' . $v['label'] . ')' : ''), $v['price']]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            json_out(['error' => 'Could not duplicate the item.'], 500);
        }
        json_out(['ok' => true, 'id' => $newId]);

    case 'delete':
        $pdo->prepare('DELETE FROM menu_items WHERE id = ?')->execute([$id]);
        json_out(['ok' => true]);

    case 'bulk':
        $ids = array_values(array_unique(array_filter(array_map('intval', (array)($in['ids'] ?? [])))));
        $op  = (string)($in['op'] ?? '');
        if (!$ids || count($ids) > 200) {
            json_out(['error' => 'Select at least one item.'], 422);
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        if ($op === 'soldout') {
            $pdo->prepare("UPDATE menu_items SET available = 0 WHERE id IN ($marks)")->execute($ids);
        } elseif ($op === 'available') {
            $pdo->prepare("UPDATE menu_items SET available = 1 WHERE id IN ($marks)")->execute($ids);
        } elseif ($op === 'delete') {
            $pdo->prepare("DELETE FROM menu_items WHERE id IN ($marks)")->execute($ids);
        } else {
            json_out(['error' => 'Unknown action.'], 400);
        }
        json_out(['ok' => true, 'count' => count($ids)]);

    default:
        json_out(['error' => 'Unknown action.'], 400);
}
