<?php
// "My Orders": a customer's own cafe orders and billiards bookings, with receipts.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/billing.php';
require_once __DIR__ . '/includes/stock.php';
$user = require_role('member');
expire_unpaid_orders(db());
date_default_timezone_set('Asia/Manila');
if ($user['role'] === 'staff') { header('Location: staff.php'); exit; }
if ($user['role'] === 'admin') { header('Location: dashboard.php'); exit; }

$pdo = db();
$uid = (int)$user['id'];

$st = $pdo->prepare("SELECT o.id, o.order_no, o.location, o.total, o.status, o.created_at, o.paid_at,
                            GROUP_CONCAT(CONCAT(oi.qty, ' x ', oi.name) ORDER BY oi.id SEPARATOR ', ') AS items
                       FROM orders o LEFT JOIN order_items oi ON oi.order_id = o.id
                      WHERE o.user_id = ?
                      GROUP BY o.id, o.order_no, o.location, o.total, o.status, o.created_at, o.paid_at
                      ORDER BY o.id DESC LIMIT 30");
$st->execute([$uid]);
$orders = $st->fetchAll();

// Profile card + lifetime numbers
$st = $pdo->prepare('SELECT full_name, email, phone, created_at FROM users WHERE id = ?');
$st->execute([$uid]);
$me = $st->fetch() ?: ['full_name' => '', 'email' => $user['email'], 'phone' => '', 'created_at' => null];
$st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN status = 'settled' THEN total END), 0) AS spent
                       FROM orders WHERE user_id = ? AND status <> 'cancelled'");
$st->execute([$uid]);
$life = $st->fetch();

// Orders still in progress get the live tracker; finished ones go to the history list
$active  = array_values(array_filter($orders, fn($o) => in_array($o['status'], ['new', 'ready'], true)));
$history = array_values(array_filter($orders, fn($o) => !in_array($o['status'], ['new', 'ready'], true)));

$st = $pdo->prepare("SELECT id, table_name, res_date, TIME_FORMAT(res_time, '%l:%i %p') AS t, mins, play_mins, total, status,
                            started_at IS NOT NULL AS started
                       FROM reservations WHERE user_id = ?
                      ORDER BY res_date DESC, res_time DESC, id DESC LIMIT 30");
$st->execute([$uid]);
$resvs = $st->fetchAll();

function money($n): string { return '₱' . number_format((float)$n, fmod((float)$n, 1) ? 2 : 0); }
function chip(string $label, string $kind): string {
    return '<span class="chip c-' . e($kind) . '">' . e($label) . '</span>';
}
?>
<!DOCTYPE html>

<html lang="en"><head><meta charset="utf-8"/><link href="theme.css" rel="stylesheet"/><title>My account • YiouYi</title><?php require __DIR__ . '/includes/head-common.php'; ?><script src="theme.js"></script><script src="assets/csrf.js"></script><meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" name="viewport"/><meta content="mobile_tab" name="shell-type"/><link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/><link href="https://fonts.googleapis.com" rel="preconnect"/><link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&amp;family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/><style>@layer base{html,body{width:100vw;margin:0;padding:0;background-color:rgb(var(--c-background));}body{overscroll-behavior:none;}.pb-safe{padding-bottom:env(safe-area-inset-bottom,0px);}.pt-safe{padding-top:env(safe-area-inset-top,0px);}main>:first-child{margin-top:0!important;}main>:last-child{margin-bottom:0!important;}}::-webkit-scrollbar{display:none;}</style><script src="https://cdn.tailwindcss.com"></script><script id="tailwind-config">tailwind.config = { darkMode: "class", theme: { extend: { "colors": { "on-error-container": "rgb(var(--c-on-error-container) / <alpha-value>)", "on-primary": "rgb(var(--c-on-primary) / <alpha-value>)", "inverse-primary": "rgb(var(--c-inverse-primary) / <alpha-value>)", "surface-container": "rgb(var(--c-surface-container) / <alpha-value>)", "on-primary-fixed": "#390c00", "primary-fixed": "#ffdbcf", "surface-variant": "rgb(var(--c-surface-variant) / <alpha-value>)", "surface-container-lowest": "rgb(var(--c-surface-container-lowest) / <alpha-value>)", "secondary-fixed": "#ffdad4", "on-tertiary-container": "rgb(var(--c-on-tertiary-container) / <alpha-value>)", "tertiary": "rgb(var(--c-tertiary) / <alpha-value>)", "primary-fixed-dim": "#ffb59c", "on-tertiary": "rgb(var(--c-on-tertiary) / <alpha-value>)", "on-surface-variant": "rgb(var(--c-on-surface-variant) / <alpha-value>)", "outline-variant": "rgb(var(--c-outline-variant) / <alpha-value>)", "surface-container-highest": "rgb(var(--c-surface-container-highest) / <alpha-value>)", "on-secondary-container": "rgb(var(--c-on-secondary-container) / <alpha-value>)", "error-container": "rgb(var(--c-error-container) / <alpha-value>)", "on-surface": "rgb(var(--c-on-surface) / <alpha-value>)", "on-secondary-fixed": "#2b1613", "inverse-surface": "rgb(var(--c-inverse-surface) / <alpha-value>)", "error": "rgb(var(--c-error) / <alpha-value>)", "on-primary-fixed-variant": "#822801", "surface-container-low": "rgb(var(--c-surface-container-low) / <alpha-value>)", "primary": "rgb(var(--c-primary) / <alpha-value>)", "surface-tint": "rgb(var(--c-surface-tint) / <alpha-value>)", "secondary": "rgb(var(--c-secondary) / <alpha-value>)", "background": "rgb(var(--c-background) / <alpha-value>)", "surface-container-high": "rgb(var(--c-surface-container-high) / <alpha-value>)", "surface-dim": "rgb(var(--c-surface-dim) / <alpha-value>)", "on-background": "rgb(var(--c-on-background) / <alpha-value>)", "primary-container": "rgb(var(--c-primary-container) / <alpha-value>)", "on-tertiary-fixed": "#0f2000", "secondary-container": "rgb(var(--c-secondary-container) / <alpha-value>)", "on-tertiary-fixed-variant": "#394d23", "surface-bright": "rgb(var(--c-surface-bright) / <alpha-value>)", "outline": "rgb(var(--c-outline) / <alpha-value>)", "inverse-on-surface": "rgb(var(--c-inverse-on-surface) / <alpha-value>)", "tertiary-fixed-dim": "#b7ce99", "on-secondary-fixed-variant": "#5b403c", "on-primary-container": "rgb(var(--c-on-primary-container) / <alpha-value>)", "on-error": "rgb(var(--c-on-error) / <alpha-value>)", "tertiary-container": "rgb(var(--c-tertiary-container) / <alpha-value>)", "surface": "rgb(var(--c-surface) / <alpha-value>)", "secondary-fixed-dim": "#e3beb8", "on-secondary": "rgb(var(--c-on-secondary) / <alpha-value>)", "tertiary-fixed": "#d2ebb3" }, "borderRadius": { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px" }, "spacing": { "space-sm": "0.75rem", "gutter-mobile": "1rem", "space-xs": "0.375rem", "space-lg": "2rem", "gutter": "1.5rem", "space-xl": "3.5rem", "margin-mobile": "1.25rem", "margin": "3rem", "space-md": "1.25rem" }, "fontFamily": { "headline-lg": ["Playfair Display"], "headline-xl": ["Playfair Display"], "body-sm": ["Plus Jakarta Sans"], "headline-md": ["Playfair Display"], "label-md": ["Plus Jakarta Sans"], "headline-sm": ["Playfair Display"], "label-sm": ["Plus Jakarta Sans"], "headline-xl-mobile": ["Playfair Display"], "headline-lg-mobile": ["Playfair Display"], "body-lg": ["Plus Jakarta Sans"], "body-md": ["Plus Jakarta Sans"], "title-sm": ["Plus Jakarta Sans"], "title-md": ["Plus Jakarta Sans"] }, "fontSize": { "headline-lg": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.015em", "fontWeight": "600" }], "headline-xl": ["48px", { "lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "600" }], "body-sm": ["13px", { "lineHeight": "19px", "fontWeight": "400" }], "headline-md": ["26px", { "lineHeight": "34px", "letterSpacing": "-0.01em", "fontWeight": "500" }], "label-md": ["13px", { "lineHeight": "16px", "letterSpacing": "0.02em", "fontWeight": "600" }], "headline-sm": ["20px", { "lineHeight": "28px", "fontWeight": "500" }], "label-sm": ["11px", { "lineHeight": "14px", "letterSpacing": "0.05em", "fontWeight": "700" }], "headline-xl-mobile": ["34px", { "lineHeight": "42px", "letterSpacing": "-0.01em", "fontWeight": "600" }], "headline-lg-mobile": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.01em", "fontWeight": "600" }], "body-lg": ["17px", { "lineHeight": "26px", "fontWeight": "400" }], "body-md": ["15px", { "lineHeight": "23px", "fontWeight": "400" }], "title-sm": ["16px", { "lineHeight": "22px", "fontWeight": "600" }], "title-md": ["18px", { "lineHeight": "24px", "fontWeight": "600" }] } } } }</script><style>
    body {
      min-height: max(884px, 100dvh);
    }
  </style>
  </head><body class="bg-surface font-body-md text-on-surface flex flex-col min-h-screen"><header class="fixed top-0 w-full z-50 bg-surface/85 backdrop-blur-xl shadow-[0_1px_8px_rgba(0,0,0,0.03)] pt-safe"><div class="h-20 px-gutter-mobile flex items-center justify-between"><div class="flex items-center gap-space-xs"><img alt="Yiouyi Cafe Logo" class="h-8 w-auto object-contain rounded-lg" src="logo.png"/><div class="flex flex-col"><span class="font-headline-sm text-headline-sm text-on-surface leading-tight">Yiouyi Cafe</span><div class="flex items-center gap-1"><span class="material-symbols-outlined text-primary text-[13px]">location_on</span><span class="font-label-sm text-label-sm text-on-surface-variant truncate max-w-[170px]">My Account</span></div></div></div><div class="flex items-center gap-space-xs"><button type="button" data-theme-toggle class="w-11 h-11 flex items-center justify-center rounded-full text-on-surface hover:bg-surface-container transition-colors"></button></div></div></header><style>
.chip{display:inline-block;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:700;white-space:nowrap}
.c-paid{background:var(--ok-bg);color:var(--ok-fg)}.c-wait{background:var(--warn-bg);color:var(--warn-fg)}
.c-info{background:var(--info-bg);color:var(--info-fg)}.c-cancel{background:var(--bad-bg);color:var(--bad-fg)}
.mbtn{flex:1;text-align:center;padding:9px 12px;border-radius:10px;font-weight:600;font-size:14px;border:0;cursor:pointer;text-decoration:none}
.mb-main{background:var(--btn-bg);color:var(--btn-fg)}.mb-soft{background:var(--soft-bg);color:var(--ink)}.mb-danger{background:var(--bad-bg);color:var(--bad-fg)}
</style>
<main data-live-reload class="flex flex-col relative w-full pt-20 pb-24 bg-surface min-h-screen"><div class="px-gutter-mobile pt-space-xs pb-space-sm flex flex-col gap-space-md">
<div>
<h1 class="font-headline-lg-mobile text-headline-lg-mobile text-on-surface leading-tight">My Account</h1>
<p class="font-body-sm text-body-sm text-on-surface-variant">Your orders, bookings and receipts.</p>
</div>

<section class="bg-surface-container-lowest rounded-xl p-4 shadow-sm flex flex-col gap-2">
<div class="flex items-center gap-3">
<div class="w-12 h-12 rounded-full bg-primary text-on-primary flex items-center justify-center font-headline-sm text-headline-sm"><?= e(mb_strtoupper(mb_substr(trim((string)$me['full_name']) !== '' ? $me['full_name'] : $me['email'], 0, 1))) ?></div>
<div class="min-w-0">
<div class="font-title-md text-title-md truncate"><?= e(trim((string)$me['full_name']) !== '' ? $me['full_name'] : 'Customer') ?></div>
<div class="font-body-sm text-body-sm text-secondary truncate"><?= e($me['email']) ?><?= $me['phone'] ? ' • ' . e($me['phone']) : '' ?></div>
</div>
</div>
<div class="flex justify-between font-body-sm text-body-sm text-secondary pt-1">
<span><?= (int)$life['n'] ?> order<?= (int)$life['n'] === 1 ? '' : 's' ?> • <?= e(money($life['spent'])) ?> spent</span>
<?php if ($me['created_at']): ?><span>Member since <?= e(date('M Y', strtotime($me['created_at']))) ?></span><?php endif; ?>
</div>
<div data-alerts-box style="display:none" class="flex items-center justify-between gap-3 p-2.5 rounded-lg bg-surface-container">
<span class="font-body-sm text-body-sm">Get a pop-up when your order is ready.</span>
<button type="button" data-alerts-btn class="mbtn mb-main" style="flex:none">Turn on alerts</button>
</div>
</section>

<?php if ($active): ?>
<section class="flex flex-col gap-2.5">
<h2 class="font-title-md text-title-md text-on-surface">Active orders</h2>
<?php foreach ($active as $o): $unpaid = $o['paid_at'] === null; ?>
<div class="bg-surface-container-lowest rounded-xl p-3.5 shadow-sm flex flex-col gap-2.5" data-live-order="<?= (int)$o['id'] ?>" data-status="<?= e($o['status']) ?>" data-paid="<?= $unpaid ? '0' : '1' ?>" data-loc="<?= e($o['location']) ?>">
<div class="flex items-center justify-between gap-2"><span class="font-title-sm text-title-sm">#YIO-<?= e($o['order_no']) ?> <span class="font-body-sm text-body-sm text-secondary">• <?= e($o['location']) ?></span></span><span data-live-chip class="chip"></span></div>
<div data-live-track></div>
<div data-live-msg class="font-body-sm text-body-sm text-on-surface font-semibold"></div>
<div class="font-body-sm text-body-sm text-on-surface-variant"><?= e($o['items'] ?? '') ?></div>
<div class="flex justify-between gap-3 font-body-sm text-body-sm"><span class="text-secondary"><?= e(date('M j, g:i A', strtotime($o['created_at']))) ?></span><b><?= e(money($o['total'])) ?></b></div>
<div class="flex gap-2"><?php if ($unpaid): ?><a class="mbtn mb-main" href="pay.php?id=<?= (int)$o['id'] ?>">Pay now</a><?php endif; ?><a class="mbtn mb-soft" href="receipt.php?type=order&amp;id=<?= (int)$o['id'] ?>"><?= $unpaid ? 'Order slip' : 'Receipt' ?></a></div>
<?php if ($o['status'] === 'new' && $unpaid): ?>
<div class="flex" data-live-cancel><button type="button" class="mbtn mb-danger" onclick="cancelOrder(<?= (int)$o['id'] ?>,'#YIO-<?= e($o['order_no']) ?>')">Cancel order</button></div>
<?php elseif (!$unpaid): ?>
<p class="font-label-sm text-label-sm text-on-surface-variant">Already paid. If you need to change this order, please ask the cashier.</p>
<?php endif; ?>
</div>
<?php endforeach; ?>
</section>
<?php endif; ?>

<section class="flex flex-col gap-2.5">
<h2 class="font-title-md text-title-md text-on-surface">Billiards bookings</h2>
<?php foreach ($resvs as $r):
    $open = $r['mins'] === null;
    $len  = $open ? ($r['play_mins'] !== null ? 'Open time • played ' . fmt_mins((int)$r['play_mins']) : 'Open time') : fmt_mins((int)$r['mins']);
    $amt  = ($open && $r['status'] !== 'settled') ? 'Billed by time played' : money($r['total']);
    if ($r['status'] === 'pending')        { $c = chip('Awaiting confirmation', 'wait'); }
    elseif ($r['status'] === 'confirmed')  { $c = $r['started'] ? chip('Playing', 'info') : chip('Confirmed', 'info'); }
    elseif ($r['status'] === 'settled')    { $c = chip('Paid', 'paid'); }
    else                                   { $c = chip('Cancelled', 'cancel'); }
?>
<div class="bg-surface-container-lowest rounded-xl p-3.5 shadow-sm flex flex-col gap-2">
<div class="flex items-center justify-between gap-2"><span class="font-title-sm text-title-sm"><?= e($r['table_name']) ?></span><?= $c ?></div>
<div class="flex justify-between gap-3 font-body-sm text-body-sm"><span class="text-secondary"><?= e(date('M j, Y', strtotime($r['res_date']))) ?> • <?= e($r['t']) ?> • <?= e($len) ?></span><b><?= $r['status'] === 'cancelled' ? '—' : e($amt) ?></b></div>
<?php if ($r['status'] !== 'cancelled'): ?>
<div class="flex gap-2">
<?php if ($r['status'] !== 'cancelled'): ?><a class="mbtn mb-soft" href="receipt.php?type=resv&amp;id=<?= (int)$r['id'] ?>">Receipt</a><?php endif; ?>
<?php if ($r['status'] === 'pending'): ?><button class="mbtn mb-danger" onclick="cancelBooking(<?= (int)$r['id'] ?>)">Cancel booking</button><?php endif; ?>
</div>
<?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (!$resvs): ?><p class="text-center text-outline py-5 font-body-sm text-body-sm">No bookings yet. <a class="text-primary font-bold" href="billiards.php">Reserve a table</a></p><?php endif; ?>
</section>

<section class="flex flex-col gap-2.5">
<h2 class="font-title-md text-title-md text-on-surface">Order history</h2>
<?php foreach ($history as $o):
    $unpaid = $o['paid_at'] === null && in_array($o['status'], ['new', 'ready'], true);
    if ($o['status'] === 'new')            { $c = chip($unpaid ? 'Unpaid' : 'Paid • preparing', $unpaid ? 'wait' : 'paid'); }
    elseif ($o['status'] === 'ready')      { $c = chip($unpaid ? 'Ready • unpaid' : 'Paid • ready', $unpaid ? 'wait' : 'info'); }
    elseif ($o['status'] === 'settled')    { $c = chip('Paid', 'paid'); }
    else                                   { $c = chip('Cancelled', 'cancel'); }
?>
<div class="bg-surface-container-lowest rounded-xl p-3.5 shadow-sm flex flex-col gap-2">
<div class="flex items-center justify-between gap-2"><span class="font-title-sm text-title-sm">#YIO-<?= e($o['order_no']) ?> <span class="font-body-sm text-body-sm text-secondary">• <?= e($o['location']) ?></span></span><?= $c ?></div>
<div class="font-body-sm text-body-sm text-on-surface"><?= e($o['items'] ?? '') ?></div>
<div class="flex justify-between gap-3 font-body-sm text-body-sm"><span class="text-secondary"><?= e(date('M j, Y g:i A', strtotime($o['created_at']))) ?></span><b><?= $o['status'] === 'cancelled' ? '—' : e(money($o['total'])) ?></b></div>
<?php if ($o['status'] !== 'cancelled'): ?><div class="flex gap-2"><?php if ($unpaid): ?><a class="mbtn mb-main" href="pay.php?id=<?= (int)$o['id'] ?>">Pay now</a><?php endif; ?><a class="mbtn mb-soft" href="receipt.php?type=order&amp;id=<?= (int)$o['id'] ?>"><?= $unpaid ? 'Order slip' : 'Receipt' ?></a></div><?php endif; ?>
</div>
<?php endforeach; ?>
<?php if (!$orders): ?><p class="text-center text-outline py-5 font-body-sm text-body-sm">No orders yet. <a class="text-primary font-bold" href="menu.php">Browse the menu</a></p><?php elseif (!$history): ?><p class="text-center text-outline py-5 font-body-sm text-body-sm">No finished orders yet.</p><?php endif; ?>
</section>
<p class="text-center font-body-sm text-body-sm"><a class="text-primary font-bold" href="index.php#feedback">Leave feedback</a> &bull; <a class="text-primary font-bold" href="account.php">Change password</a></p>
<?php if (count($orders) >= 30 || count($resvs) >= 30): ?><p class="text-center font-body-sm text-body-sm text-outline">Showing your latest 30 of each.</p><?php endif; ?>
</div>
<script>
async function cancelOrder(id,label){
  if(!confirm('Cancel order '+label+'? This cannot be undone.'))return;
  try{
    var r=await fetch('api/orders.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'cancel',id:id})});
    var j=await r.json();
    if(r.ok&&j.ok){location.reload()}else{alert(j.error||'Could not cancel. Please try again.');if(r.status===409)location.reload()}
  }catch(e){alert('Network error. Please try again.')}
}
async function cancelBooking(id){
  if(!confirm('Cancel this booking?'))return;
  try{
    var r=await fetch('api/reservations.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'cancel',id:id})});
    var j=await r.json();
    if(r.ok&&j.ok){location.reload()}else{alert(j.error||'Could not cancel. Please try again.')}
  }catch(e){alert('Network error. Please try again.')}
}
</script>
</main><?php include __DIR__ . '/includes/nav.php'; ?></body></html>
