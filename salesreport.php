<?php
// Sales report for any date range, shared by dashboard.php (the "Sales Report" card) and report.php (full printable page).
//
// Same rules as the rest of the admin side:
//   - only PAID things count as sales: orders with status 'settled' and billiards bookings with status 'settled'
//   - a paid cafe order belongs to the day it was paid (order_day_sql() in includes/billing.php), billiards to the reservation date
//   - open orders / unsettled bookings are listed separately as "still unpaid"

require_once __DIR__ . '/billing.php';

const SR_MAX_DAYS = 366;

/** Friendly names for the menu categories saved in menu_items.cat */
function sr_category_label(?string $cat): string {
    static $names = [
        'coffee' => 'Coffee', 'tea' => 'Tea & milk tea', 'fresh' => 'Fresh drinks', 'sweet' => 'Sweets & pastries',
        'rice' => 'Rice meals', 'chicken' => 'Chicken', 'apps' => 'Snacks & appetizers', 'pasta' => 'Pasta', 'burger' => 'Burgers',
    ];
    return $names[(string)$cat] ?? 'Other items';
}

/** Is this a real calendar date written as YYYY-MM-DD? */
function sr_valid_date($d): bool {
    if (!is_string($d)) { return false; }
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

/**
 * Work out the From/To dates from the query string (?from=..&to=.. , or the old ?date=..).
 * Bad input falls back to today; From/To given the wrong way round are swapped; the range is limited to SR_MAX_DAYS.
 * Returns [from, to, days, label, clamped].
 */
function sr_range(array $get, string $today): array {
    $from = $get['from'] ?? ($get['date'] ?? null);
    $to   = $get['to']   ?? ($get['date'] ?? null);
    if (!sr_valid_date($from) && !sr_valid_date($to)) { $from = $to = $today; }
    elseif (!sr_valid_date($from)) { $from = $to; }
    elseif (!sr_valid_date($to))   { $to = $from; }
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $clamped = false;
    if ($days > SR_MAX_DAYS) {
        $from = date('Y-m-d', strtotime($to . ' -' . (SR_MAX_DAYS - 1) . ' days'));
        $days = SR_MAX_DAYS; $clamped = true;
    }
    return ['from' => $from, 'to' => $to, 'days' => $days, 'label' => sr_label($from, $to), 'clamped' => $clamped];
}

/** "Oct 20, 2026"  or  "Oct 20 - Oct 26, 2026"  or across years */
function sr_label(string $from, string $to): string {
    if ($from === $to) { return date('M j, Y', strtotime($from)); }
    if (date('Y', strtotime($from)) === date('Y', strtotime($to))) {
        return date('M j', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
    }
    return date('M j, Y', strtotime($from)) . ' – ' . date('M j, Y', strtotime($to));
}

/** Quick links: Today, Yesterday, Last 7 days, This month, Last month  =>  [label => [from, to]] */
function sr_presets(string $today): array {
    $firstThis = date('Y-m-01', strtotime($today));
    return [
        'Today'        => [$today, $today],
        'Yesterday'    => [date('Y-m-d', strtotime($today . ' -1 day')), date('Y-m-d', strtotime($today . ' -1 day'))],
        'Last 7 days'  => [date('Y-m-d', strtotime($today . ' -6 days')), $today],
        'This month'   => [$firstThis, $today],
        'Last month'   => [date('Y-m-01', strtotime($firstThis . ' -1 month')), date('Y-m-d', strtotime($firstThis . ' -1 day'))],
    ];
}

/** Everything the report shows for $from..$to (inclusive, YYYY-MM-DD). */
function sales_report(PDO $pdo, string $from, string $to): array {
    $r = ['from' => $from, 'to' => $to];
    $orderDay    = order_day_sql();
    $orderDayOi  = order_day_sql('o');

    // ---- cafe orders by status
    $cafe = ['new' => [0, 0.0], 'ready' => [0, 0.0], 'settled' => [0, 0.0], 'cancelled' => [0, 0.0]];
    $st = $pdo->prepare("SELECT status, COUNT(*) AS c, COALESCE(SUM(total), 0) AS t FROM orders WHERE $orderDay BETWEEN ? AND ? GROUP BY status");
    $st->execute([$from, $to]);
    foreach ($st as $x) { $cafe[$x['status']] = [(int)$x['c'], (float)$x['t']]; }

    // ---- every item sold (paid orders), best sellers first
    $st = $pdo->prepare("SELECT oi.name, SUM(oi.qty) AS qty, SUM(oi.qty * oi.price) AS rev
                           FROM order_items oi JOIN orders o ON o.id = oi.order_id
                          WHERE o.status = 'settled' AND $orderDayOi BETWEEN ? AND ?
                          GROUP BY oi.name ORDER BY qty DESC, rev DESC, oi.name");
    $st->execute([$from, $to]);
    $items = $st->fetchAll();

    // item name -> category (kept separate from the sums above so repeated menu names can never double-count)
    $catOf = [];
    foreach ($pdo->query('SELECT v.order_name, MIN(i.cat) AS cat FROM menu_variants v JOIN menu_items i ON i.id = v.menu_item_id GROUP BY v.order_name') as $x) {
        $catOf[$x['order_name']] = $x['cat'];
    }
    $cats = [];
    foreach ($items as &$it) {
        $it['qty'] = (int)$it['qty']; $it['rev'] = (float)$it['rev'];
        $it['category'] = sr_category_label($catOf[$it['name']] ?? null);
        $c = &$cats[$it['category']];
        $c = $c ?? ['name' => $it['category'], 'qty' => 0, 'rev' => 0.0];
        $c['qty'] += $it['qty']; $c['rev'] += $it['rev'];
        unset($c);
    }
    unset($it);
    $cats = array_values($cats);
    usort($cats, fn($a, $b) => $b['rev'] <=> $a['rev']);

    $r['cafe'] = [
        'sales' => $cafe['settled'][1], 'paid' => $cafe['settled'][0], 'cancelled' => $cafe['cancelled'][0],
        'open' => $cafe['new'][0] + $cafe['ready'][0], 'openAmt' => $cafe['new'][1] + $cafe['ready'][1],
        'avg' => $cafe['settled'][0] ? $cafe['settled'][1] / $cafe['settled'][0] : 0.0,
        'units' => array_sum(array_column($items, 'qty')), 'items' => $items, 'cats' => $cats,
    ];

    // ---- billiards: settled sessions per table, fixed vs open time
    $tables = [];
    foreach (BOOKABLE_TABLES as $t) { $tables[$t] = ['table' => $t, 'sessions' => 0, 'mins' => 0, 'sales' => 0.0, 'open' => 0]; }
    $st = $pdo->prepare("SELECT table_name, COUNT(*) AS c, COALESCE(SUM(COALESCE(play_mins, mins, 0)), 0) AS m,
                                COALESCE(SUM(total), 0) AS t, COALESCE(SUM(mins IS NULL), 0) AS o,
                                COALESCE(SUM(CASE WHEN mins IS NULL THEN total ELSE 0 END), 0) AS ot
                           FROM reservations WHERE status = 'settled' AND res_date BETWEEN ? AND ? GROUP BY table_name ORDER BY table_name");
    $st->execute([$from, $to]);
    $openSales = 0.0;
    foreach ($st as $x) {
        $tables[$x['table_name']] = ['table' => $x['table_name'], 'sessions' => (int)$x['c'], 'mins' => (int)$x['m'],
                                     'sales' => (float)$x['t'], 'open' => (int)$x['o']];
        $openSales += (float)$x['ot'];
    }
    $tables = array_values($tables);
    $bilSales = array_sum(array_column($tables, 'sales'));
    $sessions = array_sum(array_column($tables, 'sessions'));
    $openSessions = array_sum(array_column($tables, 'open'));
    $other = $pdo->prepare("SELECT status, COUNT(*) FROM reservations WHERE res_date BETWEEN ? AND ? GROUP BY status");
    $other->execute([$from, $to]);
    $cnt = ['pending' => 0, 'confirmed' => 0, 'cancelled' => 0];
    foreach ($other->fetchAll(PDO::FETCH_NUM) as [$s, $c]) { $cnt[$s] = (int)$c; }
    $r['bil'] = [
        'sales' => $bilSales, 'sessions' => $sessions, 'mins' => array_sum(array_column($tables, 'mins')),
        'open_sessions' => $openSessions, 'fixed_sessions' => $sessions - $openSessions,
        'open_sales' => $openSales, 'fixed_sales' => $bilSales - $openSales,
        'cancelled' => $cnt['cancelled'], 'waiting' => $cnt['pending'] + $cnt['confirmed'], 'tables' => $tables,
    ];
    $r['total'] = $cafe['settled'][1] + $bilSales;

    // ---- payments by method (cafe + billiards)
    $pay = [];
    foreach (['orders' => "$orderDay BETWEEN ? AND ?", 'reservations' => 'res_date BETWEEN ? AND ?'] as $tbl => $where) {
        $st = $pdo->prepare("SELECT COALESCE(pay_method, 'other') AS m, COUNT(*) AS c, COALESCE(SUM(total), 0) AS t FROM $tbl WHERE status = 'settled' AND $where GROUP BY m");
        $st->execute([$from, $to]);
        foreach ($st as $x) {
            $pay[$x['m']]['c'] = ($pay[$x['m']]['c'] ?? 0) + (int)$x['c'];
            $pay[$x['m']]['t'] = ($pay[$x['m']]['t'] ?? 0) + (float)$x['t'];
        }
    }
    foreach (['cash', 'gcash', 'card'] as $m) { $pay += [$m => ['c' => 0, 't' => 0.0]]; }
    $rank = ['cash' => 1, 'gcash' => 2, 'card' => 3];
    uksort($pay, fn($a, $b) => ($rank[$a] ?? 9) <=> ($rank[$b] ?? 9));
    $r['pay'] = $pay;

    // ---- day by day (only for ranges up to 62 days)
    $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
    $r['daily'] = [];
    if ($days > 1 && $days <= 62) {
        $byDay = [];
        $st = $pdo->prepare("SELECT $orderDay AS d, COALESCE(SUM(total), 0) AS t FROM orders WHERE status = 'settled' AND $orderDay BETWEEN ? AND ? GROUP BY d");
        $st->execute([$from, $to]);
        foreach ($st as $x) { $byDay[$x['d']]['cafe'] = (float)$x['t']; }
        $st = $pdo->prepare("SELECT res_date AS d, COALESCE(SUM(total), 0) AS t FROM reservations WHERE status = 'settled' AND res_date BETWEEN ? AND ? GROUP BY d");
        $st->execute([$from, $to]);
        foreach ($st as $x) { $byDay[$x['d']]['bil'] = (float)$x['t']; }
        for ($d = $from; $d <= $to; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
            $c = $byDay[$d]['cafe'] ?? 0.0; $b = $byDay[$d]['bil'] ?? 0.0;
            $r['daily'][] = ['date' => $d, 'cafe' => $c, 'bil' => $b, 'total' => $c + $b];
        }
    }
    return $r;
}
