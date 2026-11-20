<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';
$user = require_role('admin');   // admin only
date_default_timezone_set('Asia/Manila');
$pdo = db();

// ---- greeting
$st = $pdo->prepare('SELECT full_name FROM users WHERE id = ?');
$st->execute([$user['id']]);
$fullName = trim((string)$st->fetchColumn());
$first = $fullName !== '' ? explode(' ', $fullName)[0] : ucfirst(strstr($user['email'], '@', true));
$hr = (int)date('G');
$greet = $hr < 12 ? 'Good morning' : ($hr < 18 ? 'Good afternoon' : 'Good evening');

// ---- today's numbers (MySQL runs in Philippine time, see includes/db.php)
$cafeToday = $pdo->query(
    "SELECT COUNT(*) AS cnt, COALESCE(SUM(status = 'settled'), 0) AS paid,
            COALESCE(SUM(CASE WHEN status = 'settled' THEN total END), 0) AS sales
       FROM orders WHERE " . order_day_sql() . " = CURDATE() AND status <> 'cancelled'"
)->fetch();
$bilToday = $pdo->query(
    "SELECT COUNT(*) AS booked, COALESCE(SUM(status = 'pending'), 0) AS pending,
            COALESCE(SUM(status = 'confirmed'), 0) AS confirmed,
            COALESCE(SUM(CASE WHEN status = 'settled' THEN total END), 0) AS sales
       FROM reservations WHERE res_date = CURDATE() AND status <> 'cancelled'"
)->fetch();
$pendingAll = (int)$pdo->query("SELECT COUNT(*) FROM reservations WHERE status = 'pending'")->fetchColumn();
$salesToday = (float)$cafeToday['sales'] + (float)$bilToday['sales'];
$avgTicket  = (int)$cafeToday['paid'] ? round($cafeToday['sales'] / $cafeToday['paid']) : 0;

// ---- sales trend: last 7 or 30 days, café vs billiards (paid sales only, same rules as "Sales Today")
$range = (($_GET['range'] ?? '7') === '30') ? 30 : 7;
$from  = date('Y-m-d', strtotime('-' . (2 * $range - 1) . ' days'));   // current period + the one before it
$byDay = [];                                                           // 'Y-m-d' => ['cafe' => n, 'bil' => n]
foreach ($pdo->query("SELECT " . order_day_sql() . " AS d, SUM(total) AS t FROM orders
                       WHERE status = 'settled' AND " . order_day_sql() . " >= '$from' GROUP BY d") as $r) {
    $byDay[$r['d']]['cafe'] = (float)$r['t'];
}
foreach ($pdo->query("SELECT res_date AS d, SUM(total) AS t FROM reservations
                       WHERE status = 'settled' AND res_date >= '$from' GROUP BY d") as $r) {
    $byDay[$r['d']]['bil'] = (float)$r['t'];
}
$trend = [];  $prevTotal = 0.0;
for ($i = 2 * $range - 1; $i >= 0; $i--) {
    $d    = date('Y-m-d', strtotime("-$i days"));
    $cafe = $byDay[$d]['cafe'] ?? 0.0;
    $bil  = $byDay[$d]['bil']  ?? 0.0;
    if ($i >= $range) {                 // older half = previous period
        $prevTotal += $cafe + $bil;
        continue;
    }
    $trend[] = ['d' => $d, 'cafe' => $cafe, 'bil' => $bil, 'sum' => $cafe + $bil];
}
$trendTotal = array_sum(array_column($trend, 'sum'));
$trendCafe  = array_sum(array_column($trend, 'cafe'));
$trendBil   = array_sum(array_column($trend, 'bil'));
$trendMax   = max(array_column($trend, 'sum'));
$trendAvg   = $trendTotal / $range;
$best       = $trend[0];
foreach ($trend as $t) { if ($t['sum'] > $best['sum']) { $best = $t; } }
$trendDelta = $prevTotal > 0 ? round(($trendTotal - $prevTotal) / $prevTotal * 100) : null;

// ---- best sellers / slow movers over the same period (paid orders only; $range is 7 or 30, safe to inline)
// Order names are unique once migrate_unique_names.sql has been run. Until then, names shared by several menu items
// (e.g. "Blueberry") can't be told apart, so they are left out of the slow-movers list.
$since = order_day_sql('o') . " >= CURDATE() - INTERVAL " . ($range - 1) . " DAY";
$bestSellers = $pdo->query(
    "SELECT oi.name, SUM(oi.qty) AS qty, SUM(oi.qty * oi.price) AS rev
       FROM order_items oi JOIN orders o ON o.id = oi.order_id
      WHERE o.status = 'settled' AND $since
      GROUP BY oi.name ORDER BY qty DESC, rev DESC, oi.name LIMIT 5"
)->fetchAll();
$slowMovers = [];
if ($bestSellers) {      // only meaningful once there are sales to compare against
    $slowMovers = $pdo->query(
        "SELECT v.order_name AS name,
                COALESCE(SUM(CASE WHEN o.id IS NOT NULL THEN oi.qty END), 0) AS qty
           FROM menu_variants v
           JOIN menu_items i ON i.id = v.menu_item_id AND i.available = 1
           LEFT JOIN order_items oi ON oi.name = v.order_name
           LEFT JOIN orders o ON o.id = oi.order_id AND o.status = 'settled' AND $since
          WHERE v.order_name IN (SELECT order_name FROM menu_variants GROUP BY order_name HAVING COUNT(*) = 1)
          GROUP BY v.id, v.order_name
          ORDER BY qty ASC, v.order_name LIMIT 5"
    )->fetchAll();
}
$bestMax = $bestSellers ? max(array_map(fn($r) => (int)$r['qty'], $bestSellers)) : 0;

// ---- low stock (same rule as Records: available, stock tracked, stock <= reorder level)
$stockTracked = (int)$pdo->query('SELECT COUNT(*) FROM menu_items WHERE stock IS NOT NULL')->fetchColumn();
$lowCount     = (int)$pdo->query('SELECT COUNT(*) FROM menu_items WHERE available = 1 AND stock IS NOT NULL AND stock <= reorder_level')->fetchColumn();
// ---- customer feedback (the admin can hide or delete what shows on the landing page)
$fbAdminReady = true; $fbAdmin = []; $fbShown = 0; $fbHiddenN = 0;
try {
    $fbAdmin = $pdo->query('SELECT id, display_name, rating, message, visible, created_at FROM feedback ORDER BY id DESC LIMIT 15')->fetchAll();
    $fbCnt   = $pdo->query('SELECT COALESCE(SUM(visible = 1), 0) AS s, COALESCE(SUM(visible = 0), 0) AS h FROM feedback')->fetch();
    $fbShown = (int)$fbCnt['s']; $fbHiddenN = (int)$fbCnt['h'];
} catch (PDOException $e) {
    $fbAdminReady = false;                 // table not created yet: run migrate_feedback.sql
}
$lowItems     = $pdo->query('SELECT name, sku, stock, reorder_level FROM menu_items
                              WHERE available = 1 AND stock IS NOT NULL AND stock <= reorder_level
                              ORDER BY stock ASC, name LIMIT 6')->fetchAll();

// ---- billiards usage over the same period: settled sessions only (fixed = booked length, open = time actually played)
$bilRows = $pdo->query(
    "SELECT table_name, res_date, HOUR(res_time) AS h, COALESCE(mins, play_mins, 0) AS m, total, (mins IS NULL) AS is_open
       FROM reservations
      WHERE status = 'settled' AND res_date BETWEEN CURDATE() - INTERVAL " . ($range - 1) . " DAY AND CURDATE()"
)->fetchAll();
$blank   = ['n' => 0, 'm' => 0, 'rev' => 0.0];
$perTbl  = array_fill_keys(BOOKABLE_TABLES, $blank);
$kind    = ['fixed' => $blank, 'open' => $blank];
$perDow  = array_fill(1, 7, 0);                      // 1 = Mon ... 7 = Sun, minutes played
$perHour = [];                                       // start hour => sessions
foreach ($bilRows as $r) {
    $m = (int)$r['m'];  $rev = (float)$r['total'];
    $perTbl[$r['table_name']] ??= $blank;
    $perTbl[$r['table_name']]['n']++;  $perTbl[$r['table_name']]['m'] += $m;  $perTbl[$r['table_name']]['rev'] += $rev;
    $k = $r['is_open'] ? 'open' : 'fixed';
    $kind[$k]['n']++;  $kind[$k]['m'] += $m;  $kind[$k]['rev'] += $rev;
    $perDow[(int)date('N', strtotime($r['res_date']))] += $m;
    $perHour[(int)$r['h']] = ($perHour[(int)$r['h']] ?? 0) + 1;
}
$bilN     = count($bilRows);
$bilMins  = array_sum(array_column($bilRows, 'm'));
$tblMax   = max(array_column($perTbl, 'm'));
$dowMax   = max($perDow);
$peakDow  = $dowMax > 0 ? array_search($dowMax, $perDow) : null;
arsort($perHour);
$peakHours = array_slice($perHour, 0, 3, true);
$fh = function (int $m): string { return rtrim(rtrim(number_format($m / 60, 1), '0'), '.') . 'h'; };
$hr12 = function (int $h): string { return ($h % 12 ?: 12) . ' ' . ($h < 12 ? 'AM' : 'PM'); };
$dowNames = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

// ---- open orders (live ticket stream)
$openOrders = $pdo->query(
    "SELECT o.id, o.order_no, o.location, o.total, o.status, u.email,
            TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS mins
       FROM orders o JOIN users u ON u.id = o.user_id
      WHERE o.status IN ('new','ready') ORDER BY o.id DESC LIMIT 12"
)->fetchAll();
$seated = $takeout = 0;
foreach ($pdo->query("SELECT location FROM orders WHERE status IN ('new','ready')") as $r) {
    $r['location'] === 'Takeout' ? $takeout++ : $seated++;
}
if ($openOrders) {
    $in = implode(',', array_fill(0, count($openOrders), '?'));
    $q  = $pdo->prepare("SELECT order_id, name, price, qty FROM order_items WHERE order_id IN ($in) ORDER BY id");
    $q->execute(array_column($openOrders, 'id'));
    $by = [];
    foreach ($q as $it) { $by[$it['order_id']][] = $it; }
    foreach ($openOrders as &$o) { $o['items'] = $by[$o['id']] ?? []; }
    unset($o);
}

// ---- data for the Café / Billiards tabs: only the selected period ($range days, see the 7 / 30 toggle) plus anything still open
$n    = $range - 1;                                   // $range is 7 or 30, safe to inline
$winO = "(o.status IN ('new','ready') OR " . order_day_sql('o') . " >= CURDATE() - INTERVAL $n DAY)";
$orders = [];
$rows = $pdo->query("SELECT o.id, o.order_no, o.location, o.total, o.status, o.pay_method FROM orders o WHERE $winO ORDER BY o.id")->fetchAll();
$itemsBy = [];
foreach ($pdo->query("SELECT oi.order_id, oi.name, oi.price, oi.qty FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE $winO ORDER BY oi.id") as $it) {
    $itemsBy[$it['order_id']][] = ['n' => $it['name'], 'p' => (float)$it['price'], 'q' => (int)$it['qty']];
}
foreach ($rows as $r) {
    $orders[] = ['where' => $r['location'], 'items' => $itemsBy[$r['id']] ?? [], 'total' => (float)$r['total'],
                 'status' => $r['status'], 'pay' => $r['pay_method']];
}
$resv = [];
foreach ($pdo->query("SELECT table_name, COALESCE(mins, play_mins, 0) AS mins, total, status FROM reservations
                       WHERE status IN ('pending','confirmed') OR res_date >= CURDATE() - INTERVAL $n DAY ORDER BY id") as $r) {
    $resv[] = ['table' => $r['table_name'], 'mins' => (int)$r['mins'], 'total' => (float)$r['total'], 'status' => $r['status']];
}
$dataJson = json_encode(['orders' => $orders, 'resv' => $resv, 'days' => $range],
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

// ---- accounts for the Team tab
$users = $pdo->query("SELECT id, full_name, email, role FROM users ORDER BY role = 'user', role, id")->fetchAll();
?>
<!DOCTYPE html>

<html lang="en"><head><meta charset="utf-8"/><link href="theme.css" rel="stylesheet"/><title>Admin dashboard • YiouYi</title><?php require __DIR__ . '/includes/head-common.php'; ?><script src="theme.js"></script><script src="assets/csrf.js"></script><meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" name="viewport"/><meta content="mobile_stack" name="shell-type"/><link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/><link href="https://fonts.googleapis.com" rel="preconnect"/><link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&amp;family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/><style>@layer base{html,body{width:100vw;margin:0;padding:0;background-color:rgb(var(--c-background));}body{overscroll-behavior:none;}.pb-safe{padding-bottom:env(safe-area-inset-bottom,0px);}.pt-safe{padding-top:env(safe-area-inset-top,0px);}main>:first-child{margin-top:0!important;}main>:last-child{margin-bottom:0!important;}}::-webkit-scrollbar{display:none;}</style><script src="https://cdn.tailwindcss.com"></script><script id="tailwind-config">tailwind.config = { darkMode: "class", theme: { extend: { "colors": { "on-error-container": "rgb(var(--c-on-error-container) / <alpha-value>)", "on-primary": "rgb(var(--c-on-primary) / <alpha-value>)", "inverse-primary": "rgb(var(--c-inverse-primary) / <alpha-value>)", "surface-container": "rgb(var(--c-surface-container) / <alpha-value>)", "on-primary-fixed": "#390c00", "primary-fixed": "#ffdbcf", "surface-variant": "rgb(var(--c-surface-variant) / <alpha-value>)", "surface-container-lowest": "rgb(var(--c-surface-container-lowest) / <alpha-value>)", "secondary-fixed": "#ffdad4", "on-tertiary-container": "rgb(var(--c-on-tertiary-container) / <alpha-value>)", "tertiary": "rgb(var(--c-tertiary) / <alpha-value>)", "primary-fixed-dim": "#ffb59c", "on-tertiary": "rgb(var(--c-on-tertiary) / <alpha-value>)", "on-surface-variant": "rgb(var(--c-on-surface-variant) / <alpha-value>)", "outline-variant": "rgb(var(--c-outline-variant) / <alpha-value>)", "surface-container-highest": "rgb(var(--c-surface-container-highest) / <alpha-value>)", "on-secondary-container": "rgb(var(--c-on-secondary-container) / <alpha-value>)", "error-container": "rgb(var(--c-error-container) / <alpha-value>)", "on-surface": "rgb(var(--c-on-surface) / <alpha-value>)", "on-secondary-fixed": "#2b1613", "inverse-surface": "rgb(var(--c-inverse-surface) / <alpha-value>)", "error": "rgb(var(--c-error) / <alpha-value>)", "on-primary-fixed-variant": "#822801", "surface-container-low": "rgb(var(--c-surface-container-low) / <alpha-value>)", "primary": "rgb(var(--c-primary) / <alpha-value>)", "surface-tint": "rgb(var(--c-surface-tint) / <alpha-value>)", "secondary": "rgb(var(--c-secondary) / <alpha-value>)", "background": "rgb(var(--c-background) / <alpha-value>)", "surface-container-high": "rgb(var(--c-surface-container-high) / <alpha-value>)", "surface-dim": "rgb(var(--c-surface-dim) / <alpha-value>)", "on-background": "rgb(var(--c-on-background) / <alpha-value>)", "primary-container": "rgb(var(--c-primary-container) / <alpha-value>)", "on-tertiary-fixed": "#0f2000", "secondary-container": "rgb(var(--c-secondary-container) / <alpha-value>)", "on-tertiary-fixed-variant": "#394d23", "surface-bright": "rgb(var(--c-surface-bright) / <alpha-value>)", "outline": "rgb(var(--c-outline) / <alpha-value>)", "inverse-on-surface": "rgb(var(--c-inverse-on-surface) / <alpha-value>)", "tertiary-fixed-dim": "#b7ce99", "on-secondary-fixed-variant": "#5b403c", "on-primary-container": "rgb(var(--c-on-primary-container) / <alpha-value>)", "on-error": "rgb(var(--c-on-error) / <alpha-value>)", "tertiary-container": "rgb(var(--c-tertiary-container) / <alpha-value>)", "surface": "rgb(var(--c-surface) / <alpha-value>)", "secondary-fixed-dim": "#e3beb8", "on-secondary": "rgb(var(--c-on-secondary) / <alpha-value>)", "tertiary-fixed": "#d2ebb3" }, "borderRadius": { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px" }, "spacing": { "space-sm": "0.75rem", "gutter-mobile": "1rem", "space-xs": "0.375rem", "space-lg": "2rem", "gutter": "1.5rem", "space-xl": "3.5rem", "margin-mobile": "1.25rem", "margin": "3rem", "space-md": "1.25rem" }, "fontFamily": { "headline-lg": ["Playfair Display"], "headline-xl": ["Playfair Display"], "body-sm": ["Plus Jakarta Sans"], "headline-md": ["Playfair Display"], "label-md": ["Plus Jakarta Sans"], "headline-sm": ["Playfair Display"], "label-sm": ["Plus Jakarta Sans"], "headline-xl-mobile": ["Playfair Display"], "headline-lg-mobile": ["Playfair Display"], "body-lg": ["Plus Jakarta Sans"], "body-md": ["Plus Jakarta Sans"], "title-sm": ["Plus Jakarta Sans"], "title-md": ["Plus Jakarta Sans"] }, "fontSize": { "headline-lg": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.015em", "fontWeight": "600" }], "headline-xl": ["48px", { "lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "600" }], "body-sm": ["13px", { "lineHeight": "19px", "fontWeight": "400" }], "headline-md": ["26px", { "lineHeight": "34px", "letterSpacing": "-0.01em", "fontWeight": "500" }], "label-md": ["13px", { "lineHeight": "16px", "letterSpacing": "0.02em", "fontWeight": "600" }], "headline-sm": ["20px", { "lineHeight": "28px", "fontWeight": "500" }], "label-sm": ["11px", { "lineHeight": "14px", "letterSpacing": "0.05em", "fontWeight": "700" }], "headline-xl-mobile": ["34px", { "lineHeight": "42px", "letterSpacing": "-0.01em", "fontWeight": "600" }], "headline-lg-mobile": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.01em", "fontWeight": "600" }], "body-lg": ["17px", { "lineHeight": "26px", "fontWeight": "400" }], "body-md": ["15px", { "lineHeight": "23px", "fontWeight": "400" }], "title-sm": ["16px", { "lineHeight": "22px", "fontWeight": "600" }], "title-md": ["18px", { "lineHeight": "24px", "fontWeight": "600" }] } } } }</script><style>
    body {
      min-height: max(884px, 100dvh);
    }
  </style>
  </head><body class="bg-surface font-body-md text-on-surface flex flex-col min-h-screen"><header class="fixed top-0 w-full z-50 bg-surface/85 backdrop-blur-xl shadow-[0_1px_8px_rgba(0,0,0,0.03)] pt-safe"><div class="h-16 px-gutter-mobile flex items-center justify-between"><div class="flex items-center gap-space-xs"><a href="index.php" aria-label="Go back" class="w-11 h-11 -ml-2 flex items-center justify-center rounded-full text-on-surface hover:bg-surface-container transition-colors"><span class="material-symbols-outlined text-[22px]">arrow_back</span></a><img alt="Yiouyi Cafe Logo" class="h-7 w-auto object-contain rounded-lg" src="logo.png"/><h1 class="font-title-sm text-title-sm text-on-surface truncate max-w-[190px]">Admin Dashboard</h1></div><div class="flex items-center gap-space-xs"><button type="button" data-theme-toggle class="w-11 h-11 flex items-center justify-center rounded-full text-on-surface hover:bg-surface-container transition-colors"></button><button type="button" aria-label="Logout" onclick="location.href='logout.php'" class="w-11 h-11 flex items-center justify-center rounded-full text-on-surface hover:bg-surface-container transition-colors"><span class="material-symbols-outlined text-[22px]">logout</span></button></div></div></header><main class="flex flex-col relative w-full pt-16 bg-surface min-h-screen"><div class="sticky top-16 z-40 bg-surface/95 backdrop-blur-md px-gutter-mobile py-2"><div id="atabs" class="grid grid-cols-4 gap-1 p-1 bg-surface-container rounded-lg"></div></div>
<div id="p-over" class="flex flex-col w-full px-gutter-mobile pb-10 space-y-5">
<!-- Banner -->
<section class="bg-surface-container-lowest rounded-xl p-space-md shadow-sm relative overflow-hidden mt-1">
<div class="flex items-start justify-between">
<div class="space-y-1">
<div class="flex items-center gap-1.5">
<span class="inline-flex items-center px-2 py-0.5 rounded-full text-label-sm font-label-sm bg-tertiary-fixed text-on-tertiary-fixed">
<span class="w-1.5 h-1.5 rounded-full bg-tertiary mr-1 animate-pulse"></span>
            Live • Real-time data
          </span>
<span class="text-label-sm font-label-sm text-secondary">Sta. Clara Branch</span>
</div>
<h2 class="font-headline-md text-headline-md text-on-surface tracking-tight"><?= e($greet) ?>, <?= e($first) ?></h2>
<p class="font-body-sm text-body-sm text-secondary">Sta. Maria Bypass Road</p>
</div>
<div class="w-11 h-11 rounded-full bg-secondary-container flex items-center justify-center text-on-secondary-container">
<span class="material-symbols-outlined text-xl" style="font-variation-settings: 'FILL' 1;">local_cafe</span>
</div>
</div>
<div class="mt-4 pt-3 flex items-center justify-between text-label-sm font-label-sm text-on-surface-variant bg-surface-container-low rounded-lg px-3 py-2">
<div class="flex items-center gap-1.5">
<span class="material-symbols-outlined text-sm text-primary">schedule</span>
<span><?= date('l, F j') ?></span>
</div>
<span class="font-semibold text-tertiary">Updated <?= date('g:i A') ?></span>
</div>
</section>
<?php
// ---- connection check (admin only): is this page opened over HTTPS?
$httpsNote = null;
if (!is_local_request()) {
    if (!is_https()) {
        $httpsNote = ['warn', 'This site is being opened WITHOUT https, so logins and passwords can be read on public Wi-Fi. Install an SSL certificate in your hosting panel, check that https://your-site opens, then set FORCE_HTTPS to true in includes/settings.php.'];
    } elseif (!FORCE_HTTPS) {
        $httpsNote = ['info', 'https works on this site. Set FORCE_HTTPS to true in includes/settings.php so everyone who types http:// is sent to the secure address automatically.'];
    }
}
?>
<?php if ($httpsNote): ?>
<div role="note" class="rounded-xl px-3.5 py-3 font-body-sm text-body-sm" style="<?= $httpsNote[0] === 'warn' ? 'background:var(--bad-bg);color:var(--bad-fg)' : 'background:var(--warn-bg);color:var(--warn-fg)' ?>"><b><?= $httpsNote[0] === 'warn' ? 'Not secure:' : 'Almost there:' ?></b> <?= e($httpsNote[1]) ?></div>
<?php endif; ?>
<!-- KPI cards -->
<section class="grid grid-cols-2 gap-2.5">
<div class="bg-surface-container-lowest p-3.5 rounded-xl shadow-sm flex flex-col justify-between">
<div class="flex items-center justify-between">
<span class="font-label-sm text-label-sm uppercase tracking-wider text-secondary">Sales Today</span>
<span class="material-symbols-outlined text-base text-primary">payments</span>
</div>
<div class="my-2">
<div class="font-headline-sm text-headline-sm text-on-surface">₱<?= number_format($salesToday) ?></div>
<div class="font-body-sm text-body-sm text-secondary"><?= $cafeToday['cnt'] ?> café orders today</div>
</div>
<div class="font-label-sm text-label-sm text-secondary">Café ₱<?= number_format($cafeToday['sales']) ?> • Tables ₱<?= number_format($bilToday['sales']) ?></div>
</div>
<div class="bg-surface-container-lowest p-3.5 rounded-xl shadow-sm flex flex-col justify-between">
<div class="flex items-center justify-between">
<span class="font-label-sm text-label-sm uppercase tracking-wider text-secondary">Avg Ticket</span>
<span class="material-symbols-outlined text-base text-primary">receipt_long</span>
</div>
<div class="my-2">
<div class="font-headline-sm text-headline-sm text-on-surface">₱<?= number_format($avgTicket) ?></div>
<div class="font-body-sm text-body-sm text-secondary"><?= $cafeToday['paid'] ?> paid today</div>
</div>
</div>
<div class="bg-surface-container-lowest p-3.5 rounded-xl shadow-sm flex flex-col justify-between">
<div class="flex items-center justify-between">
<span class="font-label-sm text-label-sm uppercase tracking-wider text-secondary">Floor Load</span>
<span class="material-symbols-outlined text-base text-primary">deck</span>
</div>
<div class="my-2">
<div class="font-headline-sm text-headline-sm text-on-surface"><?= $seated ?> Seated</div>
<div class="font-body-sm text-body-sm text-secondary"><?= $takeout ?> Takeout pending</div>
</div>
</div>
<div class="bg-secondary-container p-3.5 rounded-xl shadow-sm flex flex-col justify-between text-on-secondary-container">
<div class="flex items-center justify-between">
<span class="font-label-sm text-label-sm uppercase tracking-wider text-on-secondary-container">Reservations</span>
<span class="material-symbols-outlined text-base text-primary">event_available</span>
</div>
<div class="my-2">
<div class="font-headline-sm text-headline-sm text-on-secondary-container"><?= $pendingAll ?> Pending</div>
<div class="font-body-sm text-body-sm text-on-secondary-container truncate"><?= (int)$bilToday['confirmed'] ?> confirmed today</div>
</div>

</div>
</section>
<!-- Billiards summary -->
<section><div class="flex items-center gap-3 p-4 rounded-xl text-white shadow-sm" style="background:linear-gradient(135deg,#143d28,#2a6e48)"><span class="material-symbols-outlined text-[26px]">radio_button_checked</span><div class="flex-1"><div class="font-title-sm text-title-sm">Billiards Floor</div><div class="font-body-sm text-body-sm opacity-90"><?= (int)$bilToday['booked'] ?> booked today • <?= (int)$bilToday['pending'] ?> awaiting confirmation</div></div></div></section>
<?php
// ---- Sales Report for any From/To dates (same numbers as the full report page, includes/salesreport.php)
require_once __DIR__ . '/includes/salesreport.php';
$srToday   = date('Y-m-d');
$srRange   = sr_range($_GET, $srToday);
$sr        = sales_report($pdo, $srRange['from'], $srRange['to']);
$srPresets = sr_presets($srToday);
$srTop     = array_slice($sr['cafe']['items'], 0, 10);
$srMore    = count($sr['cafe']['items']) - count($srTop);
$srUrl     = 'report.php?from=' . $srRange['from'] . '&to=' . $srRange['to'];
$srP       = fn($n) => '₱' . number_format((float)$n);
$srN       = fn($n, string $w) => (int)$n . ' ' . $w . ((int)$n === 1 ? '' : 's');
$srT       = fn(int $m) => $m >= 60 ? intdiv($m, 60) . 'h' . ($m % 60 ? ' ' . ($m % 60) . 'm' : '') : $m . 'm';
?>
<!-- Sales report (pick any dates) -->
<section id="salesreport" class="bg-surface-container-lowest rounded-xl p-3.5 shadow-sm space-y-3">
<div>
<h3 class="font-title-sm text-title-sm text-on-surface">Sales Report</h3>
<p class="font-body-sm text-body-sm text-secondary">Pick any dates to see what the café and billiards sold.</p>
</div>
<form method="get" action="dashboard.php#salesreport" class="flex flex-wrap items-end gap-2">
<label class="flex flex-col gap-0.5 font-label-sm text-label-sm text-secondary">From
<input type="date" name="from" value="<?= e($srRange['from']) ?>" required class="px-2 py-1.5 rounded-lg bg-surface-container-low border border-outline-variant/40 text-on-surface font-body-sm text-body-sm"/></label>
<label class="flex flex-col gap-0.5 font-label-sm text-label-sm text-secondary">To
<input type="date" name="to" value="<?= e($srRange['to']) ?>" required class="px-2 py-1.5 rounded-lg bg-surface-container-low border border-outline-variant/40 text-on-surface font-body-sm text-body-sm"/></label>
<button type="submit" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md active:scale-95 transition-transform">Show</button>
</form>
<div class="flex flex-wrap gap-1.5">
<?php foreach ($srPresets as $label => [$pf, $pt]): $on = ($srRange['from'] === $pf && $srRange['to'] === $pt); ?>
<a href="dashboard.php?from=<?= e($pf) ?>&amp;to=<?= e($pt) ?>#salesreport" class="px-2.5 py-1 rounded-lg font-label-md text-label-md <?= $on ? 'bg-inverse-surface text-inverse-on-surface' : 'bg-surface-container text-on-surface-variant' ?>"><?= e($label) ?></a>
<?php endforeach; ?>
</div>

<div>
<div class="font-label-sm text-label-sm text-secondary"><?= e($srRange['label']) ?><?= $srRange['days'] > 1 ? ' • ' . $srRange['days'] . ' days' : '' ?><?= $srRange['clamped'] ? ' (latest ' . SR_MAX_DAYS . ' days)' : '' ?></div>
<div class="font-headline-sm text-headline-sm text-on-surface"><?= $srP($sr['total']) ?> <span class="font-label-md text-label-md text-secondary">total sales</span></div>
<?php if ($srRange['days'] > 1): ?><div class="font-label-sm text-label-sm text-secondary">Average <?= $srP($sr['total'] / $srRange['days']) ?> per day</div><?php endif; ?>
</div>

<div class="grid grid-cols-2 gap-2">
<div class="rounded-lg bg-surface-container p-2.5">
<div class="font-label-sm text-label-sm text-secondary">Café</div>
<div class="font-title-sm text-title-sm text-on-surface"><?= $srP($sr['cafe']['sales']) ?></div>
<div class="font-label-sm text-label-sm text-secondary"><?= $srN($sr['cafe']['paid'], 'order') ?> • <?= $srN($sr['cafe']['units'], 'item') ?></div>
</div>
<div class="rounded-lg bg-surface-container p-2.5">
<div class="font-label-sm text-label-sm text-secondary">Billiards</div>
<div class="font-title-sm text-title-sm text-on-surface"><?= $srP($sr['bil']['sales']) ?></div>
<div class="font-label-sm text-label-sm text-secondary"><?= $srN($sr['bil']['sessions'], 'session') ?> • <?= e($srT((int)$sr['bil']['mins'])) ?></div>
</div>
</div>

<div class="grid gap-3 md:grid-cols-2 md:gap-8 md:items-start">
<div>
<h4 class="font-label-md text-label-md uppercase tracking-wider text-secondary mb-1.5">Café: what we sold</h4>
<?php if ($sr['cafe']['cats']): ?>
<ul class="space-y-1 mb-2.5">
<?php foreach ($sr['cafe']['cats'] as $c): ?>
<li class="flex items-baseline justify-between gap-3 font-body-sm text-body-sm"><span class="text-on-surface truncate"><?= e($c['name']) ?></span><span class="shrink-0 text-secondary"><?= (int)$c['qty'] ?> sold • <b class="text-on-surface"><?= $srP($c['rev']) ?></b></span></li>
<?php endforeach; ?>
</ul>
<ol class="divide-y divide-outline-variant/30 border-t border-outline-variant/30">
<?php foreach ($srTop as $i => $it): ?>
<li class="flex items-baseline justify-between gap-3 py-1.5 font-body-sm text-body-sm"><span class="text-on-surface truncate"><span class="font-bold text-primary mr-1.5"><?= $i + 1 ?></span><?= e($it['name']) ?></span><span class="shrink-0 text-secondary"><?= (int)$it['qty'] ?> • <b class="text-on-surface"><?= $srP($it['rev']) ?></b></span></li>
<?php endforeach; ?>
</ol>
<?php if ($srMore > 0): ?><p class="font-label-sm text-label-sm text-secondary mt-1">+ <?= $srMore ?> more item<?= $srMore === 1 ? '' : 's' ?> in the full report</p><?php endif; ?>
<?php else: ?>
<p class="text-center text-outline py-3 font-body-sm text-body-sm">No café sales in this period.</p>
<?php endif; ?>
</div>

<div>
<h4 class="font-label-md text-label-md uppercase tracking-wider text-secondary mb-1.5">Billiards</h4>
<ul class="space-y-1">
<?php foreach ($sr['bil']['tables'] as $t): ?>
<li class="flex items-baseline justify-between gap-3 font-body-sm text-body-sm"><span class="text-on-surface"><?= e($t['table']) ?></span><span class="shrink-0 text-secondary"><?= $srN($t['sessions'], 'session') ?> • <?= e($srT((int)$t['mins'])) ?> • <b class="text-on-surface"><?= $srP($t['sales']) ?></b></span></li>
<?php endforeach; ?>
</ul>
<p class="font-label-sm text-label-sm text-secondary mt-1.5">Fixed bookings: <?= $sr['bil']['fixed_sessions'] ?> (<?= $srP($sr['bil']['fixed_sales']) ?>) • Open time: <?= $sr['bil']['open_sessions'] ?> (<?= $srP($sr['bil']['open_sales']) ?>)</p>
</div>
</div>

<?php if ($sr['cafe']['open'] || $sr['bil']['waiting']): ?>
<p class="rounded-lg px-3 py-2 font-body-sm text-body-sm" style="background:#fff1d6;color:#8a5a00">Still unpaid in this period: <?= $sr['cafe']['open'] ? $sr['cafe']['open'] . ' café order' . ($sr['cafe']['open'] > 1 ? 's' : '') . ' (' . $srP($sr['cafe']['openAmt']) . ')' : '' ?><?= ($sr['cafe']['open'] && $sr['bil']['waiting']) ? ' and ' : '' ?><?= $sr['bil']['waiting'] ? $sr['bil']['waiting'] . ' billiards booking' . ($sr['bil']['waiting'] > 1 ? 's' : '') : '' ?>.</p>
<?php endif; ?>

<a href="<?= e($srUrl) ?>" class="block text-center py-2.5 rounded-lg bg-surface-container font-label-md text-label-md text-on-surface active:scale-95 transition-transform">Open full report &amp; print</a>
</section>
<!-- Sales trend -->
<section id="trend" class="bg-surface-container-lowest rounded-xl p-3.5 shadow-sm space-y-3">
<div class="flex items-center justify-between">
<div>
<h3 class="font-title-sm text-title-sm text-on-surface">Sales Trend</h3>
<p class="font-body-sm text-body-sm text-secondary">Paid sales, last <?= $range ?> days</p>
</div>
<div class="flex gap-1.5">
<?php foreach ([7, 30] as $r): ?>
<a href="dashboard.php?range=<?= $r ?>#trend" class="px-3 py-1.5 rounded-lg font-label-md text-label-md <?= $range === $r ? 'bg-inverse-surface text-inverse-on-surface' : 'bg-surface-container text-on-surface-variant' ?>"><?= $r ?> days</a>
<?php endforeach; ?>
</div>
</div>
<?php if ($trendTotal > 0): ?>
<div class="flex items-end justify-between gap-2">
<div>
<div class="font-headline-sm text-headline-sm text-on-surface">₱<?= number_format($trendTotal) ?></div>
<div class="font-body-sm text-body-sm text-secondary">Avg ₱<?= number_format($trendAvg) ?> / day</div>
</div>
<?php if ($trendDelta !== null): ?>
<div class="px-2 py-1 rounded-full font-label-sm text-label-sm font-bold <?= $trendDelta >= 0 ? 'bg-[color:var(--ok-bg)] text-[color:var(--ok-fg)]' : 'bg-[color:var(--bad-bg)] text-[color:var(--bad-fg)]' ?>">
<?= $trendDelta >= 0 ? '▲' : '▼' ?> <?= abs($trendDelta) ?>% vs previous <?= $range ?> days
</div>
<?php endif; ?>
</div>
<div class="flex items-stretch gap-[3px] h-36 pt-1">
<?php foreach ($trend as $t):
    $pct   = $trendMax > 0 ? max(3, round($t['sum'] / $trendMax * 100)) : 0;
    $isNow = $t['d'] === date('Y-m-d');
    $tip   = date('D, M j', strtotime($t['d'])) . ': ₱' . number_format($t['sum'])
           . ' (Café ₱' . number_format($t['cafe']) . ' • Tables ₱' . number_format($t['bil']) . ')'; ?>
<div class="flex-1 min-w-0 flex flex-col justify-end h-full" title="<?= e($tip) ?>">
<?php if ($t['sum'] > 0): ?>
<div class="flex flex-col rounded-t overflow-hidden <?= $isNow ? 'opacity-100' : 'opacity-90' ?>" style="height:<?= $pct ?>%">
<div style="flex:<?= $t['bil'] ?>;background:#2a6e48"></div>
<div class="bg-primary" style="flex:<?= $t['cafe'] ?>"></div>
</div>
<?php else: ?>
<div class="h-[2px] rounded bg-surface-container-high"></div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<div class="flex gap-[3px] font-label-sm text-label-sm text-secondary">
<?php foreach ($trend as $i => $t):
    $isNow = $t['d'] === date('Y-m-d');
    $show  = $range === 7 || $isNow || ($i % 5 === 0 && $i < $range - 2); ?>
<div class="flex-1 min-w-0 text-center <?= $isNow ? 'font-bold text-primary' : '' ?>"><?= $show ? ($range === 7 ? date('D', strtotime($t['d'])) : date('j', strtotime($t['d']))) : '&nbsp;' ?></div>
<?php endforeach; ?>
</div>
<div class="flex items-center justify-between font-label-sm text-label-sm text-secondary">
<span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm bg-primary"></span>Café ₱<?= number_format($trendCafe) ?></span>
<span class="flex items-center gap-1"><span class="w-2.5 h-2.5 rounded-sm" style="background:#2a6e48"></span>Billiards ₱<?= number_format($trendBil) ?></span>
<span>Best: <?= date('D j', strtotime($best['d'])) ?> ₱<?= number_format($best['sum']) ?></span>
</div>
<?php else: ?>
<p class="text-center text-outline py-8 font-body-sm text-body-sm">No paid sales in the last <?= $range ?> days yet. Paid orders and settled billiards sessions will show up here.</p>
<?php endif; ?>
</section>
<!-- Best sellers -->
<section id="best" class="bg-surface-container-lowest rounded-xl p-3.5 shadow-sm space-y-3">
<div>
<h3 class="font-title-sm text-title-sm text-on-surface">Best Sellers</h3>
<p class="font-body-sm text-body-sm text-secondary">Café items, paid orders, last <?= $range ?> days (follows the toggle above)</p>
</div>
<?php if ($bestSellers): ?>
<ol class="space-y-2.5">
<?php foreach ($bestSellers as $i => $b): $w = $bestMax > 0 ? max(6, round((int)$b['qty'] / $bestMax * 100)) : 0; ?>
<li>
<div class="flex items-baseline justify-between gap-3">
<span class="font-body-md text-body-md text-on-surface truncate"><span class="font-bold text-primary mr-1.5"><?= $i + 1 ?></span><?= e($b['name']) ?></span>
<span class="font-label-md text-label-md text-secondary shrink-0"><?= (int)$b['qty'] ?> sold • ₱<?= number_format($b['rev']) ?></span>
</div>
<div class="mt-1 h-2 rounded-full bg-surface-container overflow-hidden"><div class="h-full rounded-full bg-primary" style="width:<?= $w ?>%"></div></div>
</li>
<?php endforeach; ?>
</ol>
<?php if ($slowMovers): ?>
<div class="pt-2 border-t border-outline-variant/40">
<h4 class="font-label-md text-label-md uppercase tracking-wider text-secondary mb-1.5">Slow movers</h4>
<ul class="space-y-1">
<?php foreach ($slowMovers as $m): ?>
<li class="flex items-baseline justify-between gap-3 font-body-sm text-body-sm">
<span class="text-on-surface truncate"><?= e($m['name']) ?></span>
<span class="shrink-0 <?= (int)$m['qty'] === 0 ? 'text-error font-bold' : 'text-secondary' ?>"><?= (int)$m['qty'] === 0 ? 'none sold' : (int)$m['qty'] . ' sold' ?></span>
</li>
<?php endforeach; ?>
</ul>
<p class="font-body-sm text-body-sm text-outline mt-1.5">Items currently on the menu with the fewest sales. Consider promoting or removing them.</p>
</div>
<?php endif; ?>
<?php else: ?>
<p class="text-center text-outline py-6 font-body-sm text-body-sm">No paid café orders in the last <?= $range ?> days yet. Your top sellers will appear here.</p>
<?php endif; ?>
</section>
<!-- Low stock -->
<section id="stock" class="bg-surface-container-lowest rounded-xl p-3.5 shadow-sm space-y-2.5">
<div class="flex items-center justify-between">
<div>
<h3 class="font-title-sm text-title-sm text-on-surface">Low Stock</h3>
<p class="font-body-sm text-body-sm text-secondary">Items at or below their reorder level</p>
</div>
<span class="px-2 py-0.5 rounded-full font-label-sm text-label-sm font-bold <?= $lowCount ? 'bg-[color:var(--bad-bg)] text-[color:var(--bad-fg)]' : 'bg-[color:var(--ok-bg)] text-[color:var(--ok-fg)]' ?>"><?= $lowCount ?> item<?= $lowCount === 1 ? '' : 's' ?></span>
</div>
<?php if ($lowItems): ?>
<ul class="divide-y divide-outline-variant/30">
<?php foreach ($lowItems as $li): $out = (int)$li['stock'] <= 0; ?>
<li class="flex items-center justify-between gap-3 py-2">
<div class="min-w-0">
<div class="font-body-md text-body-md text-on-surface truncate"><?= e($li['name']) ?></div>
<div class="font-label-sm text-label-sm text-secondary"><?= $li['sku'] ? e($li['sku']) . ' • ' : '' ?>reorder at <?= (int)$li['reorder_level'] ?></div>
</div>
<span class="shrink-0 font-label-md text-label-md font-bold <?= $out ? 'text-error' : 'text-primary' ?>"><?= $out ? 'Out of stock' : (int)$li['stock'] . ' left' ?></span>
</li>
<?php endforeach; ?>
</ul>
<?php if ($lowCount > count($lowItems)): ?><p class="font-body-sm text-body-sm text-secondary">+ <?= $lowCount - count($lowItems) ?> more</p><?php endif; ?>
<a href="records.php?status=low&amp;sort=stock" class="block text-center py-2 rounded-lg bg-surface-container font-label-md text-label-md text-on-surface active:scale-95 transition-transform">Manage stock</a>
<?php elseif ($stockTracked === 0): ?>
<p class="text-center text-outline py-4 font-body-sm text-body-sm">Stock isn't tracked yet. Enter "Units Left" for an item on the <a class="text-primary font-bold" href="records.php">Records page</a> and it will show up here when it runs low.</p>
<?php else: ?>
<p class="text-center py-4 font-body-sm text-body-sm" style="color:var(--ok-fg)">All <?= $stockTracked ?> tracked items are above their reorder level.</p>
<?php endif; ?>
<p class="font-body-sm text-body-sm text-outline">Stock goes down automatically when an order is placed and comes back if staff cancel it. Use the Records page to restock or correct a count.</p>
</section>
<!-- Customer feedback -->
<section id="feedback" class="bg-surface-container-lowest rounded-xl p-3.5 shadow-sm space-y-2.5">
<div class="flex items-center justify-between gap-2">
<div>
<h3 class="font-title-sm text-title-sm text-on-surface">Customer Feedback</h3>
<p class="font-body-sm text-body-sm text-secondary">Shown on the landing page. Hide anything that shouldn't be public.</p>
</div>
<?php if ($fbAdminReady): ?><span class="px-2 py-0.5 rounded-full bg-surface-container font-label-sm text-label-sm font-bold shrink-0"><?= $fbShown ?> shown<?= $fbHiddenN ? ' &bull; ' . $fbHiddenN . ' hidden' : '' ?></span><?php endif; ?>
</div>
<?php if (!$fbAdminReady): ?>
<p class="text-center text-outline py-4 font-body-sm text-body-sm">Feedback isn't set up yet. Import <b>migrate_feedback.sql</b> in phpMyAdmin.</p>
<?php elseif (!$fbAdmin): ?>
<p class="text-center text-outline py-4 font-body-sm text-body-sm">No feedback yet. Customers can leave it from the landing page.</p>
<?php else: ?>
<ul class="divide-y divide-outline-variant/30">
<?php foreach ($fbAdmin as $f): $hid = !(int)$f['visible']; ?>
<li class="py-2.5 flex flex-col gap-1.5<?= $hid ? ' opacity-60' : '' ?>">
<div class="flex items-center justify-between gap-2">
<span class="font-label-md text-label-md text-on-surface font-semibold truncate"><?= e($f['display_name']) ?> <span class="text-primary tracking-tight"><?= str_repeat('★', (int)$f['rating']) . '<span class="text-outline">' . str_repeat('★', 5 - (int)$f['rating']) . '</span>' ?></span></span>
<span class="font-label-sm text-label-sm text-secondary shrink-0"><?= e(date('M j, g:i A', strtotime($f['created_at']))) ?><?= $hid ? ' &bull; <b class="text-error">Hidden</b>' : '' ?></span>
</div>
<p class="font-body-sm text-body-sm text-on-surface break-words"><?= e($f['message']) ?></p>
<div class="flex gap-2">
<button type="button" onclick="fbAct(<?= (int)$f['id'] ?>,'<?= $hid ? 'show' : 'hide' ?>')" class="px-2.5 py-1 rounded-lg bg-surface-container font-label-md text-label-md"><?= $hid ? 'Show on page' : 'Hide' ?></button>
<button type="button" onclick="fbAct(<?= (int)$f['id'] ?>,'delete')" class="px-2.5 py-1 rounded-lg bg-surface-container font-label-md text-label-md text-error">Delete</button>
</div>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>
</section>
<!-- Shortcuts -->
<section class="space-y-2">
<div class="flex items-center justify-between px-1">
<span class="font-title-sm text-title-sm text-on-surface">Manager Actions</span>
<span class="font-label-sm text-label-sm text-primary font-bold">Shortcuts</span>
</div>
<div class="grid grid-cols-4 gap-2">
<?php foreach ([['report.php','assignment','Daily Report'],['records.php','summarize','Records']] as $qa): ?>
<a href="<?= $qa[0] ?>" class="flex flex-col items-center justify-center p-2.5 rounded-xl bg-surface-container-lowest shadow-sm hover:bg-surface-container transition-transform active:scale-95 text-center">
<div class="w-9 h-9 rounded-full bg-primary-fixed flex items-center justify-center text-on-primary-fixed mb-1">
<span class="material-symbols-outlined text-lg"><?= $qa[1] ?></span>
</div>
<span class="font-label-sm text-label-sm text-on-surface leading-tight"><?= $qa[2] ?></span>
</a>
<?php endforeach; ?>
</div>
</section>
<!-- Live tickets -->
<section class="space-y-3">
<div class="flex items-center justify-between px-1">
<div class="flex items-center gap-2">
<h3 class="font-title-md text-title-md text-on-surface">Live Ticket Stream</h3>
<span class="px-2 py-0.5 rounded-full bg-primary text-on-primary text-label-sm font-label-sm"><?= count($openOrders) ?> Open</span>
</div>
<span class="font-label-sm text-label-sm text-secondary">Reload to refresh</span>
</div>
<div class="space-y-2.5">
<?php foreach ($openOrders as $o): ?>
<div class="bg-surface-container-lowest p-3.5 rounded-xl shadow-sm flex flex-col gap-2">
<div class="flex items-center justify-between">
<div class="flex items-center gap-2">
<span class="font-label-md text-label-md text-on-surface">#YIO-<?= (int)$o['order_no'] ?></span>
<span class="px-2 py-0.5 rounded text-label-sm font-label-sm bg-surface-container-high text-on-surface"><?= e($o['location']) ?></span>
</div>
<?php if ($o['status'] === 'ready'): ?>
<span class="inline-flex items-center px-2 py-0.5 rounded-full text-label-sm font-label-sm bg-tertiary-fixed text-on-tertiary-fixed">Ready to serve</span>
<?php else: ?>
<span class="inline-flex items-center px-2 py-0.5 rounded-full text-label-sm font-label-sm bg-secondary-fixed text-on-secondary-fixed"><span class="material-symbols-outlined text-xs mr-1 animate-spin">refresh</span>Preparing</span>
<?php endif; ?>
</div>
<div class="font-body-sm text-body-sm text-on-surface space-y-0.5">
<?php foreach ($o['items'] as $it): ?>
<div class="flex justify-between"><span><?= (int)$it['qty'] ?>× <?= e($it['name']) ?></span><span class="font-medium">₱<?= number_format($it['price'] * $it['qty']) ?></span></div>
<?php endforeach; ?>
</div>
<div class="pt-2 flex items-center justify-between text-body-sm font-body-sm text-secondary">
<span class="text-label-sm font-label-sm flex items-center gap-1 text-primary"><span class="material-symbols-outlined text-sm">timer</span> <?= (int)$o['mins'] ?>m elapsed • <?= e($o['email']) ?></span>
<span class="font-title-sm text-title-sm text-on-surface font-bold">₱<?= number_format($o['total'], 2) ?></span>
</div>
</div>
<?php endforeach; ?>
<?php if (!$openOrders): ?><p class="font-body-sm text-body-sm text-outline text-center py-4">No open orders right now.</p><?php endif; ?>
</div>
</section>
</div>
<!-- Billiards usage (shown inside the Billiards tab by bil() below) -->
<template id="bil-usage">
<div class="bg-surface-container-lowest rounded-xl p-4 shadow-sm flex flex-col gap-3.5">
<div class="flex items-center justify-between">
<div>
<h3 class="font-title-md text-title-md">Billiards Usage</h3>
<p class="font-body-sm text-body-sm text-secondary">Settled sessions, last <?= $range ?> days</p>
</div>
<div class="flex gap-1.5">
<?php foreach ([7, 30] as $rg): ?>
<a href="dashboard.php?range=<?= $rg ?>&amp;tab=bil" class="px-3 py-1.5 rounded-lg font-label-md text-label-md <?= $range === $rg ? 'bg-inverse-surface text-inverse-on-surface' : 'bg-surface-container text-on-surface-variant' ?>"><?= $rg ?> days</a>
<?php endforeach; ?>
</div>
</div>
<?php if ($bilN > 0): ?>
<div class="grid grid-cols-3 gap-2 text-center">
<div class="bg-surface-container-low rounded-lg py-2"><div class="font-title-sm text-title-sm"><?= $fh($bilMins) ?></div><div class="font-label-sm text-label-sm text-secondary">played</div></div>
<div class="bg-surface-container-low rounded-lg py-2"><div class="font-title-sm text-title-sm"><?= $bilN ?></div><div class="font-label-sm text-label-sm text-secondary">sessions</div></div>
<div class="bg-surface-container-low rounded-lg py-2"><div class="font-title-sm text-title-sm"><?= $fh((int)round($bilMins / $bilN)) ?></div><div class="font-label-sm text-label-sm text-secondary">avg session</div></div>
</div>

<div class="space-y-2">
<h4 class="font-label-md text-label-md uppercase tracking-wider text-secondary">Hours played per table</h4>
<?php foreach ($perTbl as $tn => $t): $w = $tblMax > 0 ? ($t['m'] > 0 ? max(4, round($t['m'] / $tblMax * 100)) : 0) : 0; ?>
<div>
<div class="flex items-baseline justify-between gap-3 font-body-sm text-body-sm"><span class="text-on-surface"><?= e($tn) ?></span><span class="text-secondary"><?= $fh($t['m']) ?> • <?= $t['n'] ?> session<?= $t['n'] === 1 ? '' : 's' ?> • ₱<?= number_format($t['rev']) ?></span></div>
<div class="mt-1 h-2 rounded-full bg-surface-container overflow-hidden"><div class="h-full rounded-full" style="width:<?= $w ?>%;background:#2a6e48"></div></div>
</div>
<?php endforeach; ?>
</div>

<div class="space-y-2">
<h4 class="font-label-md text-label-md uppercase tracking-wider text-secondary">Open time vs fixed</h4>
<div class="grid grid-cols-2 gap-2">
<?php foreach (['fixed' => 'Fixed length', 'open' => 'Open time'] as $kk => $lbl): $kv = $kind[$kk]; ?>
<div class="bg-surface-container-low rounded-lg p-2.5">
<div class="font-label-md text-label-md text-secondary"><?= $lbl ?></div>
<div class="font-title-sm text-title-sm"><?= $kv['n'] ?> <span class="font-body-sm text-body-sm text-secondary">session<?= $kv['n'] === 1 ? '' : 's' ?></span></div>
<div class="font-body-sm text-body-sm text-secondary"><?= $fh($kv['m']) ?> • ₱<?= number_format($kv['rev']) ?></div>
<?php if ($kk === 'open' && $kv['n'] > 0): ?><div class="font-label-sm text-label-sm text-secondary">avg <?= $fh((int)round($kv['m'] / $kv['n'])) ?> played</div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
</div>

<div class="space-y-2">
<h4 class="font-label-md text-label-md uppercase tracking-wider text-secondary">Busiest days <span class="normal-case tracking-normal">(hours played)</span></h4>
<div class="flex items-stretch gap-1.5 h-24">
<?php foreach ($perDow as $d => $m): $h = $dowMax > 0 && $m > 0 ? max(4, round($m / $dowMax * 100)) : 0; ?>
<div class="flex-1 flex flex-col justify-end items-center h-full" title="<?= e($dowNames[$d] . ': ' . $fh($m)) ?>">
<?php if ($m > 0): ?><div class="w-full rounded-t" style="height:<?= $h ?>%;background:#2a6e48;opacity:<?= $d === $peakDow ? '1' : '.6' ?>"></div><?php else: ?><div class="w-full h-[2px] rounded bg-surface-container-high"></div><?php endif; ?>
</div>
<?php endforeach; ?>
</div>
<div class="flex gap-1.5 font-label-sm text-label-sm text-secondary">
<?php foreach ($perDow as $d => $m): ?><div class="flex-1 text-center <?= $d === $peakDow ? 'font-bold text-primary' : '' ?>"><?= $dowNames[$d] ?></div><?php endforeach; ?>
</div>
<?php if ($peakDow): ?><p class="font-body-sm text-body-sm text-secondary">Busiest: <b class="text-on-surface"><?= $dowNames[$peakDow] ?></b> with <?= $fh($dowMax) ?> played.</p><?php endif; ?>
</div>

<div class="space-y-1.5">
<h4 class="font-label-md text-label-md uppercase tracking-wider text-secondary">Peak start times</h4>
<?php foreach ($peakHours as $hh => $cnt): ?>
<div class="flex items-center justify-between font-body-sm text-body-sm"><span class="text-on-surface"><?= $hr12($hh) ?> – <?= $hr12(($hh + 1) % 24) ?></span><span class="text-secondary"><?= $cnt ?> session<?= $cnt === 1 ? '' : 's' ?> started</span></div>
<?php endforeach; ?>
</div>
<?php else: ?>
<p class="text-center text-outline py-6 font-body-sm text-body-sm">No settled billiards sessions in the last <?= $range ?> days yet. Usage will show up here once staff settle sessions.</p>
<?php endif; ?>
</div>
</template>
<div id="p-cafe" class="hidden flex-col w-full px-gutter-mobile pb-10 gap-4"></div>
<div id="p-bil" class="hidden flex-col w-full px-gutter-mobile pb-10 gap-4"></div>
<div id="p-team" class="hidden flex-col w-full px-gutter-mobile pb-10 gap-3">
<div class="bg-surface-container-lowest rounded-xl p-4 shadow-sm flex flex-col gap-3"><h3 class="font-title-md text-title-md">Accounts &amp; roles</h3>
<p class="font-body-sm text-body-sm text-secondary"><b>Admin</b>: reports, records and accounts. <b>Staff</b>: orders, reservations and menu. <b>Customer</b>: menu ordering and table reservations. New sign-ups are customers; promote someone here.</p>
</div>
<div class="bg-surface-container-lowest rounded-xl p-4 shadow-sm flex flex-col gap-3"><h3 class="font-title-md text-title-md">Accounts (<?= count($users) ?>)</h3><div id="custs" class="flex flex-col gap-2.5 font-body-sm text-body-sm">
<?php foreach ($users as $u): ?>
<div class="flex items-center justify-between gap-2">
<div class="min-w-0"><div class="truncate font-medium"><?= e($u['full_name'] ?: '—') ?></div><div class="truncate text-secondary"><?= e($u['email']) ?></div></div>
<?php if ((int)$u['id'] === (int)$user['id']): ?>
<a href="account.php" class="px-2 py-1 rounded-lg bg-surface-container font-label-md text-label-md">You (admin) • Change password</a>
<?php else: ?>
<div class="flex items-center gap-1.5 shrink-0">
<button type="button" title="Reset password" onclick="resetPw(<?= (int)$u['id'] ?>,this.dataset.who)" data-who="<?= e($u['full_name'] ?: $u['email']) ?>" class="px-2 py-1.5 rounded-lg bg-surface-container font-label-md text-label-md">Reset password</button>
<select onchange="setRole(<?= (int)$u['id'] ?>,this)" class="px-2 py-1.5 rounded-lg bg-surface-container font-label-md text-label-md">
<?php foreach (['user' => 'Customer', 'staff' => 'Staff', 'admin' => 'Admin'] as $rv => $rl): ?><option value="<?= $rv ?>"<?= $u['role'] === $rv ? ' selected' : '' ?>><?= $rl ?></option><?php endforeach; ?>
</select>
</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
</div></div></div>
<script>
var DATA=<?= $dataJson ?>;
var S={orders:function(){return DATA.orders},resv:function(){return DATA.resv},peso:function(n){return '₱'+Number(n||0).toLocaleString()}};
var esc=function(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return '&#'+c.charCodeAt(0)+';'})};
async function resetPw(id,who){
  if(!confirm('Reset the password for '+who+'? Their current password will stop working and they will be logged out.'))return;
  try{
    var r=await fetch('api/users.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,action:'reset_password'})});
    var j=await r.json();
    if(!r.ok||j.error){alert(j.error||'Could not reset the password.');return}
    prompt('Temporary password for '+who+' (copy it and give it to them). They will choose a new password when they log in. This is shown only once.',j.temp);
  }catch(e){alert('Network error.')}
}
async function fbAct(id,action){
  if(action==='delete'&&!confirm('Delete this feedback permanently?'))return;
  try{var r=await fetch('api/feedback.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:action,id:id})});
    var j=await r.json();if(!r.ok||j.error){alert(j.error||'Could not update the feedback.');return}location.reload()}
  catch(e){alert('Network error. Please try again.')}
}
async function setRole(id,sel){try{var r=await fetch('api/users.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,role:sel.value})});
 var j=await r.json();if(!r.ok||j.error){alert(j.error||'Could not change the role.');location.reload()}}catch(e){alert('Network error.');location.reload()}}
var T=['over','cafe','bil','team'],IC={over:['dashboard','Overview'],cafe:['local_cafe','Café'],bil:['radio_button_checked','Billiards'],team:['group','Team']};
function atab(t){T.forEach(function(k){var e=document.getElementById('p-'+k);e.classList.toggle('hidden',k!==t);e.classList.toggle('flex',k===t)});
 document.getElementById('atabs').innerHTML=T.map(function(k){return '<button onclick="atab(\''+k+'\')" class="py-1.5 rounded-lg font-label-sm text-label-sm flex flex-col items-center '+(k===t?'bg-primary text-on-primary shadow-sm':'text-on-surface-variant')+'"><span class="material-symbols-outlined text-[20px]">'+IC[k][0]+'</span>'+IC[k][1]+'</button>'}).join('');
 if(t==='cafe')cafe();if(t==='bil')bil();window.scrollTo(0,0)}
var card=function(l,v,s){return '<div class="bg-surface-container-lowest p-3.5 rounded-xl shadow-sm"><div class="font-label-sm text-label-sm text-secondary">'+l+'</div><div class="font-headline-sm text-headline-sm">'+v+'</div><div class="font-body-sm text-body-sm text-secondary">'+s+'</div></div>'};
var bars=function(rows){var mx=Math.max.apply(null,rows.map(function(r){return r[1]}).concat(1));return rows.length?rows.map(function(r){return '<div><div class="flex justify-between font-body-sm"><span class="truncate pr-2">'+esc(r[0])+'</span><b>'+r[2]+'</b></div><div class="h-2 rounded-full bg-surface-container"><div class="h-2 rounded-full bg-primary" style="width:'+Math.max(4,r[1]/mx*100)+'%"></div></div></div>'}).join(''):'<p class="font-body-sm text-outline">No data yet.</p>'};
var box=function(t,h){return '<div class="bg-surface-container-lowest rounded-xl p-4 shadow-sm flex flex-col gap-2.5"><h3 class="font-title-md text-title-md">'+t+'</h3>'+h+'</div>'};
function cafe(){var o=S.orders().filter(function(x){return x.status!=='cancelled'}),paid=o.filter(function(x){return x.status==='settled'}),rev=paid.reduce(function(s,x){return s+x.total},0),open=o.length-paid.length,it={},pm={};
 o.forEach(function(x){x.items.forEach(function(i){var e=it[i.n]=it[i.n]||{q:0,r:0};e.q+=i.q;e.r+=i.q*i.p})});paid.forEach(function(x){pm[x.pay||'cash']=(pm[x.pay||'cash']||0)+x.total});
 var top=Object.keys(it).map(function(k){return [k,it[k].r,it[k].q+' sold • '+S.peso(it[k].r)]}).sort(function(a,b){return b[1]-a[1]}).slice(0,6);
 document.getElementById('p-cafe').innerHTML='<div class="grid grid-cols-2 gap-2.5">'+card('Café revenue',S.peso(rev),'settled • last '+DATA.days+' days')+card('Orders',o.length,open+' still open')+card('Avg ticket',S.peso(paid.length?Math.round(rev/paid.length):0),paid.length+' paid')+card('Takeout',o.filter(function(x){return x.where==='Takeout'}).length,'vs '+o.filter(function(x){return x.where!=='Takeout'}).length+' dine-in')+'</div>'+
 box('Best sellers',bars(top))+box('Payments',bars(Object.keys(pm).map(function(k){return [k,pm[k],S.peso(pm[k])]})))}
function bil(){var r=S.resv().filter(function(x){return x.status!=='cancelled'}),paid=r.filter(function(x){return x.status==='settled'}),rev=paid.reduce(function(s,x){return s+x.total},0),hrs=r.reduce(function(s,x){return s+x.mins/60},0),tb={};
  document.getElementById('p-bil').innerHTML='<div class="grid grid-cols-2 gap-2.5">'+card('Table revenue',S.peso(rev),'settled • last '+DATA.days+' days')+card('Reservations',r.length,r.filter(function(x){return x.status==='pending'}).length+' pending')+card('Hours booked',hrs,'all tables')+card('Avg session',(r.length?(hrs/r.length).toFixed(1):0)+'h','per booking')+'</div>'+document.getElementById('bil-usage').innerHTML}
var TAB=new URLSearchParams(location.search).get('tab');atab(['over','cafe','bil','team'].indexOf(TAB)>-1?TAB:'over');
</script></main></body></html>