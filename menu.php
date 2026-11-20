<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/stock.php';
$user = require_role('member');
$activeOrders = 0;                                  // orders already placed and not finished yet (shown as a gentle reminder in the review screen)
try {
    $q = db()->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ? AND status IN ('new','ready')");
    $q->execute([$user['id']]);
    $activeOrders = (int)$q->fetchColumn();
} catch (PDOException $e) { /* ignore */ }   // any logged-in user
expire_unpaid_orders(db());   // give back stock from orders nobody paid for

function peso($p): string {
    $p = (float)$p;
    return number_format($p, $p == floor($p) ? 0 : 2);
}

// Load the menu from the database, grouped by section (in menu order)
$rows = db()->query(
    'SELECT i.id, i.cat, i.section, i.note, i.name, i.item_key,
            (i.available = 1 AND (i.stock IS NULL OR i.stock > 0)) AS available,   -- sold out when tracked stock hits 0
            v.id AS vid, v.label, v.order_name, v.price
       FROM menu_items i
       LEFT JOIN menu_variants v ON v.menu_item_id = i.id
      ORDER BY i.id, v.id'
)->fetchAll();

$items = [];
foreach ($rows as $r) {
    $id = (int)$r['id'];
    if (!isset($items[$id])) {
        $items[$id] = ['cat' => $r['cat'], 'section' => $r['section'], 'note' => $r['note'],
                       'name' => $r['name'], 'key' => $r['item_key'], 'on' => (bool)$r['available'], 'vs' => []];
    }
    if ($r['vid'] !== null) {
        $items[$id]['vs'][] = ['id' => $r['vid'], 'label' => $r['label'], 'order_name' => $r['order_name'], 'price' => $r['price']];
    }
}
$sections = [];
foreach ($items as $it) {
    $k = $it['cat'] . '|' . $it['section'];
    if (!isset($sections[$k])) {
        $sections[$k] = ['cat' => $it['cat'], 'section' => $it['section'], 'note' => $it['note'], 'items' => []];
    }
    $sections[$k]['items'][] = $it;
}
?>
<!DOCTYPE html>

<html lang="en"><head><meta charset="utf-8"/><link href="theme.css" rel="stylesheet"/><title>Menu • Yiouyi Cafe &amp; Billiards</title><?php require __DIR__ . '/includes/head-common.php'; ?><script src="theme.js"></script><script src="assets/csrf.js"></script><meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" name="viewport"/><meta content="mobile_tab" name="shell-type"/><link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/><link href="https://fonts.googleapis.com" rel="preconnect"/><link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&amp;family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/><style>@layer base{html,body{width:100vw;margin:0;padding:0;background-color:rgb(var(--c-background));}body{overscroll-behavior:none;}.pb-safe{padding-bottom:env(safe-area-inset-bottom,0px);}.pt-safe{padding-top:env(safe-area-inset-top,0px);}main>:first-child{margin-top:0!important;}main>:last-child{margin-bottom:0!important;}}::-webkit-scrollbar{display:none;}</style><script src="https://cdn.tailwindcss.com"></script><script id="tailwind-config">tailwind.config = { darkMode: "class", theme: { extend: { "colors": { "on-error-container": "rgb(var(--c-on-error-container) / <alpha-value>)", "on-primary": "rgb(var(--c-on-primary) / <alpha-value>)", "inverse-primary": "rgb(var(--c-inverse-primary) / <alpha-value>)", "surface-container": "rgb(var(--c-surface-container) / <alpha-value>)", "on-primary-fixed": "#390c00", "primary-fixed": "#ffdbcf", "surface-variant": "rgb(var(--c-surface-variant) / <alpha-value>)", "surface-container-lowest": "rgb(var(--c-surface-container-lowest) / <alpha-value>)", "secondary-fixed": "#ffdad4", "on-tertiary-container": "rgb(var(--c-on-tertiary-container) / <alpha-value>)", "tertiary": "rgb(var(--c-tertiary) / <alpha-value>)", "primary-fixed-dim": "#ffb59c", "on-tertiary": "rgb(var(--c-on-tertiary) / <alpha-value>)", "on-surface-variant": "rgb(var(--c-on-surface-variant) / <alpha-value>)", "outline-variant": "rgb(var(--c-outline-variant) / <alpha-value>)", "surface-container-highest": "rgb(var(--c-surface-container-highest) / <alpha-value>)", "on-secondary-container": "rgb(var(--c-on-secondary-container) / <alpha-value>)", "error-container": "rgb(var(--c-error-container) / <alpha-value>)", "on-surface": "rgb(var(--c-on-surface) / <alpha-value>)", "on-secondary-fixed": "#2b1613", "inverse-surface": "rgb(var(--c-inverse-surface) / <alpha-value>)", "error": "rgb(var(--c-error) / <alpha-value>)", "on-primary-fixed-variant": "#822801", "surface-container-low": "rgb(var(--c-surface-container-low) / <alpha-value>)", "primary": "rgb(var(--c-primary) / <alpha-value>)", "surface-tint": "rgb(var(--c-surface-tint) / <alpha-value>)", "secondary": "rgb(var(--c-secondary) / <alpha-value>)", "background": "rgb(var(--c-background) / <alpha-value>)", "surface-container-high": "rgb(var(--c-surface-container-high) / <alpha-value>)", "surface-dim": "rgb(var(--c-surface-dim) / <alpha-value>)", "on-background": "rgb(var(--c-on-background) / <alpha-value>)", "primary-container": "rgb(var(--c-primary-container) / <alpha-value>)", "on-tertiary-fixed": "#0f2000", "secondary-container": "rgb(var(--c-secondary-container) / <alpha-value>)", "on-tertiary-fixed-variant": "#394d23", "surface-bright": "rgb(var(--c-surface-bright) / <alpha-value>)", "outline": "rgb(var(--c-outline) / <alpha-value>)", "inverse-on-surface": "rgb(var(--c-inverse-on-surface) / <alpha-value>)", "tertiary-fixed-dim": "#b7ce99", "on-secondary-fixed-variant": "#5b403c", "on-primary-container": "rgb(var(--c-on-primary-container) / <alpha-value>)", "on-error": "rgb(var(--c-on-error) / <alpha-value>)", "tertiary-container": "rgb(var(--c-tertiary-container) / <alpha-value>)", "surface": "rgb(var(--c-surface) / <alpha-value>)", "secondary-fixed-dim": "#e3beb8", "on-secondary": "rgb(var(--c-on-secondary) / <alpha-value>)", "tertiary-fixed": "#d2ebb3" }, "borderRadius": { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px" }, "spacing": { "space-sm": "0.75rem", "gutter-mobile": "1rem", "space-xs": "0.375rem", "space-lg": "2rem", "gutter": "1.5rem", "space-xl": "3.5rem", "margin-mobile": "1.25rem", "margin": "3rem", "space-md": "1.25rem" }, "fontFamily": { "headline-lg": ["Playfair Display"], "headline-xl": ["Playfair Display"], "body-sm": ["Plus Jakarta Sans"], "headline-md": ["Playfair Display"], "label-md": ["Plus Jakarta Sans"], "headline-sm": ["Playfair Display"], "label-sm": ["Plus Jakarta Sans"], "headline-xl-mobile": ["Playfair Display"], "headline-lg-mobile": ["Playfair Display"], "body-lg": ["Plus Jakarta Sans"], "body-md": ["Plus Jakarta Sans"], "title-sm": ["Plus Jakarta Sans"], "title-md": ["Plus Jakarta Sans"] }, "fontSize": { "headline-lg": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.015em", "fontWeight": "600" }], "headline-xl": ["48px", { "lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "600" }], "body-sm": ["13px", { "lineHeight": "19px", "fontWeight": "400" }], "headline-md": ["26px", { "lineHeight": "34px", "letterSpacing": "-0.01em", "fontWeight": "500" }], "label-md": ["13px", { "lineHeight": "16px", "letterSpacing": "0.02em", "fontWeight": "600" }], "headline-sm": ["20px", { "lineHeight": "28px", "fontWeight": "500" }], "label-sm": ["11px", { "lineHeight": "14px", "letterSpacing": "0.05em", "fontWeight": "700" }], "headline-xl-mobile": ["34px", { "lineHeight": "42px", "letterSpacing": "-0.01em", "fontWeight": "600" }], "headline-lg-mobile": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.01em", "fontWeight": "600" }], "body-lg": ["17px", { "lineHeight": "26px", "fontWeight": "400" }], "body-md": ["15px", { "lineHeight": "23px", "fontWeight": "400" }], "title-sm": ["16px", { "lineHeight": "22px", "fontWeight": "600" }], "title-md": ["18px", { "lineHeight": "24px", "fontWeight": "600" }] } } } }</script><style>
    body {
      min-height: max(884px, 100dvh);
    }
  </style>
  <style>.no-scrollbar::-webkit-scrollbar{display:none}.no-scrollbar{scrollbar-width:none}</style></head><body class="bg-surface font-body-md text-on-surface flex flex-col min-h-screen"><header class="fixed top-0 w-full z-50 bg-surface/85 backdrop-blur-xl shadow-[0_1px_8px_rgba(0,0,0,0.03)] pt-safe"><div class="h-20 px-gutter-mobile flex items-center justify-between"><div class="flex items-center gap-space-xs"><img alt="Yiouyi Cafe Logo" class="h-8 w-auto object-contain rounded-lg" src="logo.png"/><div class="flex flex-col"><div class="flex items-center gap-1.5"><span class="font-headline-sm text-headline-sm text-on-surface leading-tight">Yiouyi Cafe</span><span class="hidden">Menu</span></div><div class="flex items-center gap-1"><span class="material-symbols-outlined text-primary text-[13px]">location_on</span><span class="font-label-sm text-label-sm text-on-surface-variant truncate max-w-[170px]">Sta. Clara, Sta. Maria Bypass</span></div></div></div><div class="flex items-center gap-space-xs"><button type="button" data-theme-toggle class="w-11 h-11 flex items-center justify-center rounded-full text-on-surface hover:bg-surface-container transition-colors"></button></div></div></header><main class="flex flex-col relative w-full pt-20 pb-40 bg-surface min-h-screen">
<section class="px-gutter-mobile pt-space-sm pb-space-xs"><div class="rounded-xl bg-surface-container p-space-md shadow-sm flex flex-col gap-1"><span class="font-label-sm text-label-sm text-primary uppercase tracking-wider">Our Menu</span><h2 class="font-headline-lg-mobile text-headline-lg-mobile text-on-surface">Eat, sip, and stay a while.</h2><p class="font-body-sm text-body-sm text-on-surface-variant">Coffee, milk tea, Taiwan fried chicken, pasta, pastries and more. <span class="font-semibold text-on-surface"><?= count($items) ?> items.</span></p></div></section>
<section class="sticky top-20 z-40 bg-surface/95 backdrop-blur-md px-gutter-mobile py-2.5 flex flex-col gap-2"><div class="relative w-full"><span class="material-symbols-outlined absolute left-3.5 top-1/2 -translate-y-1/2 text-outline text-[20px]">search</span><input id="q" type="search" placeholder="Search latte, pasta, fried chicken..." class="w-full bg-surface-container-lowest text-on-surface pl-10 pr-3 py-2.5 rounded-lg font-body-sm text-body-sm placeholder:text-outline focus:outline-none shadow-sm"/></div><div id="tabs" class="flex gap-2 overflow-x-auto no-scrollbar"><button class="cat-tab shrink-0 px-3.5 py-1.5 rounded-lg font-title-sm text-title-sm bg-inverse-surface text-inverse-on-surface transition-all" data-cat="all">All</button><button class="cat-tab shrink-0 px-3.5 py-1.5 rounded-lg font-title-sm text-title-sm bg-surface-container text-on-surface-variant transition-all" data-cat="coffee">Coffee &amp; Frappé</button><button class="cat-tab shrink-0 px-3.5 py-1.5 rounded-lg font-title-sm text-title-sm bg-surface-container text-on-surface-variant transition-all" data-cat="tea">Milk &amp; Tea</button><button class="cat-tab shrink-0 px-3.5 py-1.5 rounded-lg font-title-sm text-title-sm bg-surface-container text-on-surface-variant transition-all" data-cat="fresh">Juice, Soda &amp; Smoothies</button><button class="cat-tab shrink-0 px-3.5 py-1.5 rounded-lg font-title-sm text-title-sm bg-surface-container text-on-surface-variant transition-all" data-cat="chicken">Taiwan Fried Chicken</button><button class="cat-tab shrink-0 px-3.5 py-1.5 rounded-lg font-title-sm text-title-sm bg-surface-container text-on-surface-variant transition-all" data-cat="rice">Rice Meals &amp; Breakfast</button><button class="cat-tab shrink-0 px-3.5 py-1.5 rounded-lg font-title-sm text-title-sm bg-surface-container text-on-surface-variant transition-all" data-cat="burger">Burgers &amp; Sandwiches</button><button class="cat-tab shrink-0 px-3.5 py-1.5 rounded-lg font-title-sm text-title-sm bg-surface-container text-on-surface-variant transition-all" data-cat="pasta">Pasta</button><button class="cat-tab shrink-0 px-3.5 py-1.5 rounded-lg font-title-sm text-title-sm bg-surface-container text-on-surface-variant transition-all" data-cat="apps">Appetizers</button><button class="cat-tab shrink-0 px-3.5 py-1.5 rounded-lg font-title-sm text-title-sm bg-surface-container text-on-surface-variant transition-all" data-cat="sweet">Croffle &amp; Pastries</button></div></section>
<div id="feed" class="px-gutter-mobile py-2 flex flex-col gap-4">
<?php foreach ($sections as $s): ?>
<section class="menu-sec flex flex-col gap-2" data-cat="<?= e($s['cat']) ?>"><div class="flex items-end justify-between px-1 pt-2"><h3 class="font-headline-sm text-headline-sm text-on-surface"><?= e($s['section']) ?></h3><span class="font-label-sm text-label-sm text-secondary normal-case"><?= e($s['note']) ?></span></div>
<?php foreach ($s['items'] as $it): ?>
<article class="menu-row bg-surface-container-lowest rounded-xl px-3 py-2.5 shadow-sm flex items-center justify-between gap-3" data-cat="<?= e($it['cat']) ?>" data-name="<?= e(strtolower($it['key'] ?: $it['name'])) ?>"><div class="flex flex-col min-w-0"><span class="font-title-sm text-title-sm text-on-surface"><?= e($it['name']) ?></span></div>
<?php if ($it['on'] && $it['vs']): ?>
<div class="flex items-center gap-1.5"><?php foreach ($it['vs'] as $v): ?><button class="px-2.5 py-1 rounded-lg bg-surface-container text-primary font-label-md text-label-md active:scale-95 transition-transform" data-vid="<?= (int)$v['id'] ?>" data-n="<?= e($v['order_name']) ?>" data-p="<?= e($v['price']) ?>"><?= e($v['label']) ?> ₱<?= peso($v['price']) ?></button><?php endforeach; ?></div>
<?php else: ?>
<span class="font-label-sm text-label-sm text-outline px-2">Unavailable</span>
<?php endif; ?>
</article>
<?php endforeach; ?>
</section>
<?php endforeach; ?>
<div id="empty" class="hidden flex-col items-center text-center py-10 text-on-surface-variant"><span class="material-symbols-outlined text-[36px]">search_off</span><p class="font-title-sm text-title-sm mt-1">No items found</p><p class="font-body-sm text-body-sm">Try another word or pick All.</p></div></div>
<div id="tray" class="hidden fixed bottom-[68px] left-0 right-0 z-40 px-gutter-mobile"><div class="bg-inverse-surface text-inverse-on-surface rounded-xl shadow-lg px-4 py-3 flex items-center justify-between gap-3">
<div class="min-w-0"><div class="font-label-md text-label-md"><span id="cnt">0</span> items in your order</div><div id="sum" class="font-title-md text-title-md font-bold">₱0</div></div>
<div class="flex items-center gap-2 shrink-0"><button type="button" onclick="clr()" class="px-3 py-2 rounded-lg bg-inverse-on-surface/10 font-label-md text-label-md">Clear</button><button type="button" id="rv-open" onclick="rvOpen()" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md font-bold active:scale-95 transition-transform">Review order</button></div>
</div></div>
<!-- Review your order: the customer checks every item (and can change or remove them) before it is sent -->
<div id="review" class="hidden fixed inset-0 z-[70]" role="dialog" aria-modal="true" aria-labelledby="rv-title">
<div class="absolute inset-0 bg-black/50" onclick="rvClose()"></div>
<div class="absolute bottom-0 inset-x-0 mx-auto w-full max-w-lg bg-surface text-on-surface rounded-t-2xl shadow-2xl max-h-[88vh] flex flex-col">
<div class="px-4 pt-4 pb-2 flex items-start justify-between gap-3">
<div><h2 id="rv-title" class="font-title-md text-title-md font-bold">Review your order</h2><p class="font-body-sm text-body-sm text-on-surface-variant">Check your items before they go to the counter. <span id="rv-count"></span></p></div>
<button type="button" id="rv-close" onclick="rvClose()" aria-label="Close" class="w-9 h-9 shrink-0 rounded-full bg-surface-container text-on-surface font-title-md text-title-md flex items-center justify-center">&times;</button>
</div>
<?php if ($activeOrders): ?><a href="mine.php" class="mx-4 mb-2 px-3 py-2 rounded-lg font-body-sm text-body-sm" style="background:#fff1d6;color:#8a5a00">You already have <?= $activeOrders ?> order<?= $activeOrders > 1 ? 's' : '' ?> in progress. <b>View in My Orders</b></a><?php endif; ?>
<ul id="rv-list" class="px-4 overflow-y-auto divide-y divide-outline-variant/30 border-t border-outline-variant/30"></ul>
<div class="px-4 pt-3 pb-4 border-t border-outline-variant/30 flex flex-col gap-3">
<label class="flex items-center justify-between gap-3 font-label-md text-label-md"><span>Serve at</span>
<select id="where" class="px-3 py-2 rounded-lg bg-surface-container text-on-surface font-label-md text-label-md border border-outline-variant/40"><?php foreach (array_merge(['Takeout'], array_map(fn($i) => 'Table ' . $i, range(1, 10))) as $w): ?><option><?= e($w) ?></option><?php endforeach; ?></select></label>
<div class="flex items-baseline justify-between"><span class="font-label-md text-label-md text-on-surface-variant">Total</span><b id="rv-total" class="font-title-md text-title-md">₱0</b></div>
<p id="rv-err" class="hidden font-body-sm text-body-sm text-error" role="alert"></p>
<div class="flex gap-2"><button type="button" onclick="rvClose()" class="flex-1 py-3 rounded-xl bg-surface-container text-on-surface font-label-md text-label-md font-bold">Add more</button><button type="button" id="rv-place" onclick="place()" class="flex-[2] py-3 rounded-xl bg-primary text-on-primary font-label-md text-label-md font-bold active:scale-95 transition-transform disabled:opacity-60">Place order</button></div>
<p class="text-center font-label-sm text-label-sm text-on-surface-variant">You pay at the counter or by GCash next. An unpaid order can still be cancelled from My Orders.</p>
</div></div></div>
<div class="fixed bottom-36 left-1/2 -translate-x-1/2 z-50 bg-on-surface text-surface-bright px-4 py-2.5 rounded-full shadow-lg flex items-center gap-2 opacity-0 pointer-events-none transition-all duration-300" id="toast"><span class="material-symbols-outlined text-secondary-container text-[18px]">check_circle</span><span class="font-label-md text-label-md" id="toast-message"></span></div>
</main><script>
let cat='all',query='',count=0,total=0,tt;
const rows=[...document.querySelectorAll('.menu-row')],secs=[...document.querySelectorAll('.menu-sec')];
function filter(){let any=false;
 rows.forEach(r=>{const ok=(cat==='all'||r.dataset.cat===cat)&&(!query||r.dataset.name.includes(query));r.classList.toggle('hidden',!ok);if(ok)any=true});
 secs.forEach(s=>s.classList.toggle('hidden',!s.querySelector('.menu-row:not(.hidden)')));
 const em=document.getElementById('empty');em.classList.toggle('hidden',any);em.classList.toggle('flex',!any)}
document.getElementById('q').addEventListener('input',e=>{query=e.target.value.trim().toLowerCase();filter()});
document.querySelectorAll('.cat-tab').forEach(b=>b.addEventListener('click',()=>{
 document.querySelectorAll('.cat-tab').forEach(t=>{t.classList.remove('bg-inverse-surface','text-inverse-on-surface');t.classList.add('bg-surface-container','text-on-surface-variant')});
 b.classList.remove('bg-surface-container','text-on-surface-variant');b.classList.add('bg-inverse-surface','text-inverse-on-surface');
 cat=b.dataset.cat;filter();window.scrollTo({top:0,behavior:'smooth'})}));
function toast(m){const t=document.getElementById('toast');document.getElementById('toast-message').textContent=m;t.classList.remove('opacity-0');t.classList.add('opacity-100');clearTimeout(tt);tt=setTimeout(()=>{t.classList.remove('opacity-100');t.classList.add('opacity-0')},1800)}
var cart={},busy=false,lastFocus=null,MAXQ=99;
document.getElementById('feed').addEventListener('click',function(e){var b=e.target.closest('button[data-vid]');if(b)add(+b.dataset.vid,b.dataset.n,+b.dataset.p)});
function rvFmt(n){n=Math.round(n*100)/100;return '₱'+n.toLocaleString('en-PH',{minimumFractionDigits:n%1?2:0,maximumFractionDigits:2})}
function render(){
  count=0;total=0;Object.keys(cart).forEach(function(k){count+=cart[k].q;total+=cart[k].q*cart[k].p});
  document.getElementById('cnt').textContent=count;
  document.getElementById('sum').textContent=rvFmt(total);
  document.getElementById('tray').classList.toggle('hidden',count===0);
  if(!document.getElementById('review').classList.contains('hidden'))rvDraw();
}
function add(vid,n,p){
  var i=cart[vid]=cart[vid]||{vid:vid,q:0,n:n,p:p};
  if(i.q>=MAXQ){toast('That is the most you can order at once.');return}
  i.q++;render();toast(n+' added');
}
function clr(){if(count&&!confirm('Remove everything from your order?'))return;cart={};render();rvClose()}
function rvChange(vid,d){var i=cart[vid];if(!i)return;i.q+=d;if(i.q<=0)delete cart[vid];render();rvClearErr();if(!count)rvClose()}
function rvRemove(vid){delete cart[vid];render();rvClearErr();if(!count)rvClose()}
function rvBtn(label,aria,fn,off){
  var b=document.createElement('button');b.type='button';b.textContent=label;b.setAttribute('aria-label',aria);b.disabled=!!off;
  b.className='w-8 h-8 rounded-full bg-surface-container text-on-surface font-label-md text-label-md flex items-center justify-center active:scale-90 transition-transform disabled:opacity-40';
  b.addEventListener('click',fn);return b;
}
function rvDraw(){
  var ul=document.getElementById('rv-list');ul.textContent='';
  Object.keys(cart).forEach(function(k){
    var i=cart[k],li=document.createElement('li');li.className='py-3 flex items-center gap-2.5';
    var left=document.createElement('div');left.className='min-w-0 flex-1';
    var nm=document.createElement('div');nm.className='font-body-md text-body-md text-on-surface break-words';nm.textContent=i.n;
    var pr=document.createElement('div');pr.className='font-label-sm text-label-sm text-on-surface-variant';pr.textContent=rvFmt(i.p)+' each';
    left.appendChild(nm);left.appendChild(pr);
    var step=document.createElement('div');step.className='flex items-center gap-1.5 shrink-0';
    step.appendChild(rvBtn('\u2212','Remove one '+i.n,function(){rvChange(i.vid,-1)}));
    var q=document.createElement('span');q.className='w-6 text-center font-label-md text-label-md font-bold';q.textContent=i.q;step.appendChild(q);
    step.appendChild(rvBtn('+','Add one more '+i.n,function(){rvChange(i.vid,1)},i.q>=MAXQ));
    var lt=document.createElement('div');lt.className='w-16 text-right font-label-md text-label-md font-bold shrink-0';lt.textContent=rvFmt(i.p*i.q);
    var rm=rvBtn('\u00d7','Remove '+i.n+' from the order',function(){rvRemove(i.vid)});rm.classList.add('text-error');
    li.appendChild(left);li.appendChild(step);li.appendChild(lt);li.appendChild(rm);ul.appendChild(li);
  });
  document.getElementById('rv-total').textContent=rvFmt(total);
  document.getElementById('rv-count').textContent='('+count+(count===1?' item':' items')+')';
}
function rvOpen(){
  if(!count)return;
  lastFocus=document.activeElement;rvDraw();rvClearErr();
  document.getElementById('review').classList.remove('hidden');document.body.style.overflow='hidden';
  document.getElementById('rv-close').focus();
}
function rvClose(){
  var r=document.getElementById('review');if(r.classList.contains('hidden'))return;
  r.classList.add('hidden');document.body.style.overflow='';
  if(lastFocus&&lastFocus.focus)lastFocus.focus();
}
function rvErr(m){var e=document.getElementById('rv-err');e.textContent=m;e.classList.remove('hidden')}
function rvClearErr(){var e=document.getElementById('rv-err');e.textContent='';e.classList.add('hidden')}
document.addEventListener('keydown',function(e){if(e.key==='Escape')rvClose()});
async function place(){
  if(busy||!count)return;busy=true;
  var pb=document.getElementById('rv-place'),old=pb.textContent;pb.disabled=true;pb.textContent='Placing order...';rvClearErr();
  try{
    const r=await fetch('api/orders.php',{method:'POST',headers:{'Content-Type':'application/json'},
      body:JSON.stringify({where:document.getElementById('where').value,items:Object.keys(cart).map(function(k){return{vid:cart[k].vid,q:cart[k].q}})})});
    const d=await r.json();
    if(!r.ok||d.error){rvErr(d.error||'Could not place your order. Please try again.');pb.disabled=false;pb.textContent=old}
    else{cart={};render();rvClose();toast('Order #'+d.no+' placed! Choose how to pay...');setTimeout(function(){location.href='pay.php?id='+d.id},900);return}   // stay "busy" while the page changes
  }catch(err){rvErr('Network error. Please try again.');pb.disabled=false;pb.textContent=old}
  busy=false;
}
</script><?php include __DIR__ . '/includes/nav.php'; ?></body></html>