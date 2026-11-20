<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/seo.php';
$SEO_INDEX = true;        // the landing page is the one page that should appear in Google   // public page: no require_role()
$user = current_user();

// ---- customer feedback for the "Customer Feedback" card (real reviews saved by api/feedback.php)
date_default_timezone_set('Asia/Manila');
$fbReady = true; $fbList = []; $fbCount = 0; $fbAvg = 0.0; $fbPostedToday = false;
try {
    $fbDb  = db();
    $fbRow = $fbDb->query('SELECT COUNT(*) AS c, COALESCE(AVG(rating), 0) AS a FROM feedback WHERE visible = 1')->fetch();
    $fbCount = (int)$fbRow['c'];
    $fbAvg   = (float)$fbRow['a'];
    $fbList  = $fbDb->query('SELECT display_name, rating, message, created_at FROM feedback WHERE visible = 1 ORDER BY id DESC LIMIT 6')->fetchAll();
    if ($user && $user['role'] === 'user') {
        $q = $fbDb->prepare('SELECT COUNT(*) FROM feedback WHERE user_id = ? AND created_at > NOW() - INTERVAL 1 DAY');
        $q->execute([$user['id']]);
        $fbPostedToday = (int)$q->fetchColumn() > 0;
    }
} catch (PDOException $e) {
    $fbReady = false;                      // feedback table not created yet: run migrate_feedback.sql
}
function fb_when(string $ts): string {
    $days = (int)floor((strtotime('today') - strtotime(date('Y-m-d', strtotime($ts)))) / 86400);
    if ($days <= 0) { return 'Today'; }
    if ($days === 1) { return 'Yesterday'; }
    if ($days < 7) { return $days . ' days ago'; }
    return date('M j, Y', strtotime($ts));
}
function fb_stars(int $n, string $size = '16px'): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<span class="material-symbols-outlined' . ($i <= $n ? '' : ' text-outline') . '" style="font-size:' . $size . ";font-variation-settings:'FILL' " . ($i <= $n ? 1 : 0) . ';" aria-hidden="true">star</span>';
    }
    return '<span class="inline-flex items-center gap-0.5 text-primary" role="img" aria-label="' . $n . ' out of 5 stars">' . $out . '</span>';
}
?>
<!DOCTYPE html>

<html lang="en"<?= $user ? ' class="in"' : '' ?>><head><meta charset="utf-8"/><?php seo_head(); ?><link href="theme.css" rel="stylesheet"/><?php require __DIR__ . '/includes/head-common.php'; ?><script src="theme.js"></script><script src="assets/csrf.js"></script><meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" name="viewport"/><meta content="mobile_tab" name="shell-type"/><link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/><link href="https://fonts.googleapis.com" rel="preconnect"/><link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&amp;family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/><style>@layer base{html,body{width:100vw;margin:0;padding:0;background-color:rgb(var(--c-background));}body{overscroll-behavior:none;}.pb-safe{padding-bottom:env(safe-area-inset-bottom,0px);}.pt-safe{padding-top:env(safe-area-inset-top,0px);}main>:first-child{margin-top:0!important;}main>:last-child{margin-bottom:0!important;}}::-webkit-scrollbar{display:none;}</style><script src="https://cdn.tailwindcss.com"></script><script id="tailwind-config">tailwind.config = { darkMode: "class", theme: { extend: { "colors": { "on-error-container": "rgb(var(--c-on-error-container) / <alpha-value>)", "on-primary": "rgb(var(--c-on-primary) / <alpha-value>)", "inverse-primary": "rgb(var(--c-inverse-primary) / <alpha-value>)", "surface-container": "rgb(var(--c-surface-container) / <alpha-value>)", "on-primary-fixed": "#390c00", "primary-fixed": "#ffdbcf", "surface-variant": "rgb(var(--c-surface-variant) / <alpha-value>)", "surface-container-lowest": "rgb(var(--c-surface-container-lowest) / <alpha-value>)", "secondary-fixed": "#ffdad4", "on-tertiary-container": "rgb(var(--c-on-tertiary-container) / <alpha-value>)", "tertiary": "rgb(var(--c-tertiary) / <alpha-value>)", "primary-fixed-dim": "#ffb59c", "on-tertiary": "rgb(var(--c-on-tertiary) / <alpha-value>)", "on-surface-variant": "rgb(var(--c-on-surface-variant) / <alpha-value>)", "outline-variant": "rgb(var(--c-outline-variant) / <alpha-value>)", "surface-container-highest": "rgb(var(--c-surface-container-highest) / <alpha-value>)", "on-secondary-container": "rgb(var(--c-on-secondary-container) / <alpha-value>)", "error-container": "rgb(var(--c-error-container) / <alpha-value>)", "on-surface": "rgb(var(--c-on-surface) / <alpha-value>)", "on-secondary-fixed": "#2b1613", "inverse-surface": "rgb(var(--c-inverse-surface) / <alpha-value>)", "error": "rgb(var(--c-error) / <alpha-value>)", "on-primary-fixed-variant": "#822801", "surface-container-low": "rgb(var(--c-surface-container-low) / <alpha-value>)", "primary": "rgb(var(--c-primary) / <alpha-value>)", "surface-tint": "rgb(var(--c-surface-tint) / <alpha-value>)", "secondary": "rgb(var(--c-secondary) / <alpha-value>)", "background": "rgb(var(--c-background) / <alpha-value>)", "surface-container-high": "rgb(var(--c-surface-container-high) / <alpha-value>)", "surface-dim": "rgb(var(--c-surface-dim) / <alpha-value>)", "on-background": "rgb(var(--c-on-background) / <alpha-value>)", "primary-container": "rgb(var(--c-primary-container) / <alpha-value>)", "on-tertiary-fixed": "#0f2000", "secondary-container": "rgb(var(--c-secondary-container) / <alpha-value>)", "on-tertiary-fixed-variant": "#394d23", "surface-bright": "rgb(var(--c-surface-bright) / <alpha-value>)", "outline": "rgb(var(--c-outline) / <alpha-value>)", "inverse-on-surface": "rgb(var(--c-inverse-on-surface) / <alpha-value>)", "tertiary-fixed-dim": "#b7ce99", "on-secondary-fixed-variant": "#5b403c", "on-primary-container": "rgb(var(--c-on-primary-container) / <alpha-value>)", "on-error": "rgb(var(--c-on-error) / <alpha-value>)", "tertiary-container": "rgb(var(--c-tertiary-container) / <alpha-value>)", "surface": "rgb(var(--c-surface) / <alpha-value>)", "secondary-fixed-dim": "#e3beb8", "on-secondary": "rgb(var(--c-on-secondary) / <alpha-value>)", "tertiary-fixed": "#d2ebb3" }, "borderRadius": { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px" }, "spacing": { "space-sm": "0.75rem", "gutter-mobile": "1rem", "space-xs": "0.375rem", "space-lg": "2rem", "gutter": "1.5rem", "space-xl": "3.5rem", "margin-mobile": "1.25rem", "margin": "3rem", "space-md": "1.25rem" }, "fontFamily": { "headline-lg": ["Playfair Display"], "headline-xl": ["Playfair Display"], "body-sm": ["Plus Jakarta Sans"], "headline-md": ["Playfair Display"], "label-md": ["Plus Jakarta Sans"], "headline-sm": ["Playfair Display"], "label-sm": ["Plus Jakarta Sans"], "headline-xl-mobile": ["Playfair Display"], "headline-lg-mobile": ["Playfair Display"], "body-lg": ["Plus Jakarta Sans"], "body-md": ["Plus Jakarta Sans"], "title-sm": ["Plus Jakarta Sans"], "title-md": ["Plus Jakarta Sans"] }, "fontSize": { "headline-lg": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.015em", "fontWeight": "600" }], "headline-xl": ["48px", { "lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "600" }], "body-sm": ["13px", { "lineHeight": "19px", "fontWeight": "400" }], "headline-md": ["26px", { "lineHeight": "34px", "letterSpacing": "-0.01em", "fontWeight": "500" }], "label-md": ["13px", { "lineHeight": "16px", "letterSpacing": "0.02em", "fontWeight": "600" }], "headline-sm": ["20px", { "lineHeight": "28px", "fontWeight": "500" }], "label-sm": ["11px", { "lineHeight": "14px", "letterSpacing": "0.05em", "fontWeight": "700" }], "headline-xl-mobile": ["34px", { "lineHeight": "42px", "letterSpacing": "-0.01em", "fontWeight": "600" }], "headline-lg-mobile": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.01em", "fontWeight": "600" }], "body-lg": ["17px", { "lineHeight": "26px", "fontWeight": "400" }], "body-md": ["15px", { "lineHeight": "23px", "fontWeight": "400" }], "title-sm": ["16px", { "lineHeight": "22px", "fontWeight": "600" }], "title-md": ["18px", { "lineHeight": "24px", "fontWeight": "600" }] } } } }</script><style>
    body {
      min-height: max(884px, 100dvh);
    }
  </style>
  <style>html:not(.in) [data-member]{display:none!important}html.in [data-guest]{display:none!important}</style></head><body class="bg-surface font-body-md text-on-surface flex flex-col min-h-screen"><header class="fixed top-0 w-full z-50 bg-surface/85 backdrop-blur-xl shadow-[0_1px_8px_rgba(0,0,0,0.03)] pt-safe"><div class="h-20 px-gutter-mobile flex items-center justify-between"><div class="flex items-center gap-space-xs"><img alt="Yiouyi Cafe Logo" class="h-8 w-auto object-contain rounded-lg" src="logo.png"/><div class="flex flex-col"><div class="flex items-center gap-1.5"><span class="font-headline-sm text-headline-sm text-on-surface leading-tight">Yiouyi Cafe</span></div><div class="flex items-center gap-1"><span class="material-symbols-outlined text-primary text-[13px]">location_on</span><span class="font-label-sm text-label-sm text-on-surface-variant truncate max-w-[170px]">Cafe &amp; Billiards • Sta. Clara</span></div></div></div><div class="flex items-center gap-space-xs"><button type="button" data-theme-toggle class="w-11 h-11 flex items-center justify-center rounded-full text-on-surface hover:bg-surface-container transition-colors"></button><a data-guest href="login.php" class="bg-primary text-on-primary px-3.5 py-2 rounded-lg font-label-md text-label-md shadow-sm active:scale-95 transition-all">Login</a></div></div></header><main class="flex flex-col relative w-full pt-20 pb-24 bg-surface min-h-screen"><div class="flex flex-col w-full">
<!-- Welcoming Editorial Hero Banner -->
<div class="px-gutter-mobile pt-space-xs pb-space-md">
<div class="relative w-full rounded-2xl overflow-hidden bg-surface-container shadow-md">
<div class="bg-cover bg-center min-h-[28rem] w-full relative flex flex-col justify-between gap-4 p-space-md" data-alt="Window bar seats and tables at Yiouyi Cafe and Billiards." style="background-image: url('photos/seating.jpg'); background-position: 50% 84%;">
<div class="absolute inset-0 bg-gradient-to-t from-on-surface/90 via-on-surface/40 to-transparent pointer-events-none"></div>
<!-- Top Status Pills -->
<div class="relative z-10 flex flex-wrap items-center gap-space-xs">
<span class="inline-flex items-center gap-1 bg-surface-container-lowest/90 backdrop-blur-md px-2.5 py-1 rounded-full text-primary font-label-sm text-label-sm shadow-sm">
<span class="material-symbols-outlined text-[14px]">pin_drop</span>
            Sta. Clara, Bulacan
          </span>
<span class="inline-flex items-center gap-1 bg-primary/95 backdrop-blur-md px-2.5 py-1 rounded-full text-on-primary font-label-sm text-label-sm shadow-sm">
<span class="material-symbols-outlined text-[14px]">radio_button_checked</span>
            Cafe + Billiards
          </span>
<span class="inline-flex items-center gap-1.5 bg-tertiary-container/90 backdrop-blur-md px-2.5 py-1 rounded-full text-on-tertiary-container font-label-sm text-label-sm">
<span class="w-2 h-2 rounded-full bg-tertiary-fixed animate-pulse"></span>
            Open: 11:00 AM – 12:00 MN
          </span>
</div>
<!-- Hero Content Body -->
<div class="relative z-10 flex flex-col gap-space-xs text-surface-bright">
<p class="font-label-md text-label-md text-secondary-container tracking-widest uppercase">Cafe &amp; Billiards Lounge</p>
<h1 class="font-headline-lg-mobile text-headline-lg-mobile text-surface-bright leading-tight">
            Coffee, good food &amp; billiards — all in one place.
          </h1>
<p class="font-body-sm text-body-sm text-surface-variant">
            Yiouyi is a cafe-billiards hangout along the Sta. Maria Bypass in Sta. Clara, Bulacan. Enjoy handcrafted drinks and fresh pastries, then rack up a game of pool with friends and family.
          </p>
<p class="font-title-sm text-title-sm italic text-secondary-container">&ldquo;Creating memories<br class="sm:hidden"/> one sip at a time&rdquo;</p>
<!-- Quick CTAs -->
<div class="flex items-center gap-space-xs pt-1" data-guest>
<a class="flex-1 text-center bg-primary text-on-primary py-2.5 px-space-sm rounded-lg font-label-md text-label-md shadow-md active:scale-95 transition-all" href="login.php">Login / Sign Up</a>
<a class="flex-1 text-center bg-surface-bright/95 backdrop-blur-md text-on-surface py-2.5 px-space-sm rounded-lg font-label-md text-label-md shadow-sm active:scale-95 transition-all" href="#location-card">Visit Us Today</a>
</div>
<div class="flex items-center gap-space-xs pt-1" data-member>
<a class="flex-1 text-center bg-primary text-on-primary py-2.5 px-space-sm rounded-lg font-label-md text-label-md shadow-md active:scale-95 transition-all" href="menu.php">Explore Menu</a>
<a class="flex-1 text-center bg-surface-bright/95 backdrop-blur-md text-on-surface py-2.5 px-space-sm rounded-lg font-label-md text-label-md shadow-sm active:scale-95 transition-all" href="billiards.php">Book a Table</a>
</div>
</div>
</div>
</div>
</div>
<!-- Warm Highlight Value Props -->
<div class="px-gutter-mobile py-space-xs">
<div class="grid grid-cols-2 gap-space-xs">
<div class="bg-surface-container-low p-space-sm rounded-xl flex flex-col gap-1.5 shadow-sm">
<div class="w-9 h-9 rounded-full bg-primary-fixed flex items-center justify-center text-primary">
<span class="material-symbols-outlined text-[20px]">coffee_maker</span>
</div>
<h2 class="font-title-sm text-title-sm text-on-surface leading-snug">Handcrafted Brews</h2>
<p class="font-body-sm text-body-sm text-on-surface-variant line-clamp-2">Locally sourced beans pulled with artisan precision.</p>
</div>
<div class="bg-surface-container-low p-space-sm rounded-xl flex flex-col gap-1.5 shadow-sm">
<div class="w-9 h-9 rounded-full bg-secondary-container flex items-center justify-center text-secondary">
<span class="material-symbols-outlined text-[20px]">potted_plant</span>
</div>
<h2 class="font-title-sm text-title-sm text-on-surface leading-snug">Garden Nook</h2>
<p class="font-body-sm text-body-sm text-on-surface-variant line-clamp-2">Lush, breezy outdoor seating away from bypass noise.</p>
</div>
<div class="bg-surface-container-low p-space-sm rounded-xl flex flex-col gap-1.5 shadow-sm">
<div class="w-9 h-9 rounded-full bg-secondary-fixed flex items-center justify-center text-on-secondary-container">
<span class="material-symbols-outlined text-[20px]">radio_button_checked</span>
</div>
<h2 class="font-title-sm text-title-sm text-on-surface leading-snug">Billiards Lounge</h2>
<p class="font-body-sm text-body-sm text-on-surface-variant line-clamp-2">4 tables for a friendly game.</p>
</div>
<div class="bg-surface-container-low p-space-sm rounded-xl flex flex-col gap-1.5 shadow-sm">
<div class="w-9 h-9 rounded-full bg-surface-container-highest flex items-center justify-center text-on-surface-variant">
<span class="material-symbols-outlined text-[20px]">wifi</span>
</div>
<h2 class="font-title-sm text-title-sm text-on-surface leading-snug">Work Friendly</h2>
<p class="font-body-sm text-body-sm text-on-surface-variant line-clamp-2">High-speed fiber &amp; dedicated sockets at every table.</p>
</div>
</div>
</div>
<!-- Photo gallery -->
<section class="px-gutter-mobile py-space-sm" id="gallery" aria-labelledby="gallery-title">
<div class="mb-space-xs">
<span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider">Take a look</span>
<h2 id="gallery-title" class="font-headline-md text-headline-md text-on-surface">Inside Yiouyi</h2>
</div>
<div class="grid grid-cols-2 md:grid-cols-4 gap-space-xs w-full max-w-5xl mx-auto">
<a href="photos/coffee-bar.jpg" data-cap="The coffee bar" class="gallery-item group relative block overflow-hidden rounded-xl shadow-sm bg-surface-container col-span-2 md:row-span-2 aspect-[16/10] md:aspect-auto">
<img src="photos/coffee-bar.jpg" alt="Coffee bar with grinders and green tile columns at Yiouyi" width="1269" height="952" loading="lazy" decoding="async" class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"/>
<span class="absolute inset-x-0 bottom-0 px-3 pb-2 pt-8 bg-gradient-to-t from-black/70 to-transparent text-white font-label-md text-label-md">The coffee bar</span>
</a>
<a href="photos/pastries.jpg" data-cap="Fresh pastries & cakes" class="gallery-item group relative block overflow-hidden rounded-xl shadow-sm bg-surface-container aspect-[4/3]">
<img src="photos/pastries.jpg" alt="Display case with muffins, cookies, egg tarts and cheesecake slices" width="1269" height="952" loading="lazy" decoding="async" class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"/>
<span class="absolute inset-x-0 bottom-0 px-3 pb-2 pt-8 bg-gradient-to-t from-black/70 to-transparent text-white font-label-md text-label-md">Fresh pastries & cakes</span>
</a>
<a href="photos/billiards-table.jpg" data-cap="Billiards lounge" class="gallery-item group relative block overflow-hidden rounded-xl shadow-sm bg-surface-container aspect-[4/3]">
<img src="photos/billiards-table.jpg" alt="A Star Billiards Diamond Maxima 8 pool table with teal cloth" width="1269" height="952" loading="lazy" decoding="async" class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"/>
<span class="absolute inset-x-0 bottom-0 px-3 pb-2 pt-8 bg-gradient-to-t from-black/70 to-transparent text-white font-label-md text-label-md">Billiards lounge</span>
</a>
<a href="photos/lounge.jpg" data-cap="Lounge seating" class="gallery-item group relative block overflow-hidden rounded-xl shadow-sm bg-surface-container aspect-[4/3]">
<img src="photos/lounge.jpg" alt="Sofa booths and round tables in the lounge area" width="1269" height="952" loading="lazy" decoding="async" class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"/>
<span class="absolute inset-x-0 bottom-0 px-3 pb-2 pt-8 bg-gradient-to-t from-black/70 to-transparent text-white font-label-md text-label-md">Lounge seating</span>
</a>
<a href="photos/art-wall.jpg" data-cap="The art wall" class="gallery-item group relative block overflow-hidden rounded-xl shadow-sm bg-surface-container aspect-[4/3]">
<img src="photos/art-wall.jpg" alt="Painting on a green tile and wood wall with a long bench" width="1269" height="952" loading="lazy" decoding="async" class="absolute inset-0 w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"/>
<span class="absolute inset-x-0 bottom-0 px-3 pb-2 pt-8 bg-gradient-to-t from-black/70 to-transparent text-white font-label-md text-label-md">The art wall</span>
</a>
</div>
</section>
<div id="lightbox" class="hidden fixed inset-0 z-[80] bg-black/90 items-center justify-center p-4" role="dialog" aria-modal="true" aria-label="Photo viewer">
<button type="button" id="lb-close" aria-label="Close photo" class="absolute top-4 right-4 w-11 h-11 rounded-full bg-white/15 text-white flex items-center justify-center"><span class="material-symbols-outlined">close</span></button>
<figure class="max-w-full m-0"><img id="lb-img" alt="" class="max-h-[80vh] max-w-full rounded-lg object-contain mx-auto"/><figcaption id="lb-cap" class="text-center text-white/90 font-label-md text-label-md mt-3"></figcaption></figure>
</div>
<!-- Featured Best-Sellers Section -->
<div class="py-space-sm flex flex-col">
<div class="px-gutter-mobile flex items-end justify-between mb-space-xs">
<div>
<span class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider">Our Best Sellers</span>
<h2 class="font-headline-md text-headline-md text-on-surface">Best Sellers</h2>
</div>
<a data-guest class="text-primary font-label-md text-label-md flex items-center gap-0.5" href="login.php">
        Login to order <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
</a><a data-member class="text-primary font-label-md text-label-md flex items-center gap-0.5" href="menu.php">
        Full Menu <span class="material-symbols-outlined text-[16px]">arrow_forward</span>
</a>
</div>
<!-- Product Grid / Slider Cards -->
<div class="px-gutter-mobile grid grid-cols-2 md:grid-cols-4 gap-space-sm w-full max-w-5xl mx-auto">
<!-- Item 1: Spanish Latte -->
<div class="bg-surface-container-lowest rounded-xl p-space-sm flex flex-col justify-between shadow-sm">
<div class="relative w-full aspect-square md:aspect-[4/3] rounded-lg overflow-hidden bg-surface-container mb-space-xs">
<img class="w-full h-full object-cover" src="photos/best-spanish-latte.jpg" alt="Iced Spanish latte in a glass with layers of espresso and milk" width="600" height="600" loading="lazy" decoding="async"/>
<span class="absolute top-2 left-2 bg-surface-container-lowest/90 backdrop-blur-sm text-primary font-label-sm text-label-sm px-2 py-0.5 rounded-full shadow-sm">Best seller</span>
</div>
<div class="flex flex-col flex-1">
<span class="font-label-sm text-label-sm text-on-surface-variant">Signature Espresso</span>
<h4 class="font-headline-sm text-headline-sm text-on-surface line-clamp-1">Spanish Latte</h4>
<p class="font-body-sm text-body-sm text-on-surface-variant line-clamp-1 mb-2">Hot or iced, espresso with sweet creamy milk.</p>
</div>
<div class="flex items-center justify-between pt-1">
<span class="font-title-md text-title-md text-primary font-bold">From ₱160</span>
</div>
</div>
<!-- Item 2: Java Chip Frappé -->
<div class="bg-surface-container-lowest rounded-xl p-space-sm flex flex-col justify-between shadow-sm">
<div class="relative w-full aspect-square md:aspect-[4/3] rounded-lg overflow-hidden bg-surface-container mb-space-xs">
<img class="w-full h-full object-cover" src="photos/best-java-chip.jpg" alt="Java chip frappé topped with whipped cream and chocolate drizzle" width="600" height="600" loading="lazy" decoding="async"/>
<span class="absolute top-2 left-2 bg-surface-container-lowest/90 backdrop-blur-sm text-primary font-label-sm text-label-sm px-2 py-0.5 rounded-full shadow-sm">Best seller</span>
</div>
<div class="flex flex-col flex-1">
<span class="font-label-sm text-label-sm text-on-surface-variant">Frappé</span>
<h4 class="font-headline-sm text-headline-sm text-on-surface line-clamp-1">Java Chip Frappé</h4>
<p class="font-body-sm text-body-sm text-on-surface-variant line-clamp-1 mb-2">Icy blended coffee with chocolate chips.</p>
</div>
<div class="flex items-center justify-between pt-1">
<span class="font-title-md text-title-md text-primary font-bold">₱200</span>
</div>
</div>
<!-- Item 3: Creamy Truffle Pasta -->
<div class="bg-surface-container-lowest rounded-xl p-space-sm flex flex-col justify-between shadow-sm">
<div class="relative w-full aspect-square md:aspect-[4/3] rounded-lg overflow-hidden bg-surface-container mb-space-xs">
<img class="w-full h-full object-cover" src="photos/best-truffle-pasta.jpg" alt="Bowl of creamy truffle pasta with fresh parsley" width="600" height="600" loading="lazy" decoding="async"/>
<span class="absolute top-2 left-2 bg-surface-container-lowest/90 backdrop-blur-sm text-primary font-label-sm text-label-sm px-2 py-0.5 rounded-full shadow-sm">Best seller</span>
</div>
<div class="flex flex-col flex-1">
<span class="font-label-sm text-label-sm text-on-surface-variant">Pasta</span>
<h4 class="font-headline-sm text-headline-sm text-on-surface line-clamp-1">Creamy Truffle Pasta</h4>
<p class="font-body-sm text-body-sm text-on-surface-variant line-clamp-1 mb-2">Creamy truffle pasta, good for sharing.</p>
</div>
<div class="flex items-center justify-between pt-1">
<span class="font-title-md text-title-md text-primary font-bold">₱280</span>
</div>
</div>
<!-- Item 4: Chicken Strips -->
<div class="bg-surface-container-lowest rounded-xl p-space-sm flex flex-col justify-between shadow-sm">
<div class="relative w-full aspect-square md:aspect-[4/3] rounded-lg overflow-hidden bg-surface-container mb-space-xs">
<img class="w-full h-full object-cover" src="photos/best-chicken-strips.jpg" alt="Plate of golden crispy chicken strips with a sweet chili dip" width="600" height="600" loading="lazy" decoding="async"/>
<span class="absolute top-2 left-2 bg-surface-container-lowest/90 backdrop-blur-sm text-primary font-label-sm text-label-sm px-2 py-0.5 rounded-full shadow-sm">Best seller</span>
</div>
<div class="flex flex-col flex-1">
<span class="font-label-sm text-label-sm text-on-surface-variant">Chicken</span>
<h4 class="font-headline-sm text-headline-sm text-on-surface line-clamp-1">Chicken Strips</h4>
<p class="font-body-sm text-body-sm text-on-surface-variant line-clamp-1 mb-2">Crispy strips, spicy or original.</p>
</div>
<div class="flex items-center justify-between pt-1">
<span class="font-title-md text-title-md text-primary font-bold">From ₱180</span>
</div>
</div>
</div>
</div>
<!-- Customer feedback: real reviews from the database + a form for logged-in customers -->
<div class="px-gutter-mobile py-space-sm" id="feedback">
<div class="bg-secondary-container/40 p-space-md rounded-2xl flex flex-col gap-space-sm shadow-sm w-full max-w-5xl mx-auto">
<div class="flex items-center justify-between gap-3">
<div class="flex items-center gap-1.5">
<span class="material-symbols-outlined text-primary text-[20px]">favorite</span>
<span class="font-headline-sm text-headline-sm text-on-surface">Customer Feedback</span>
</div>
<?php if ($fbCount > 0): ?>
<span class="font-label-sm text-label-sm text-secondary font-bold text-right"><?= number_format($fbAvg, 1) ?> / 5.0 &bull; <?= $fbCount ?> review<?= $fbCount === 1 ? '' : 's' ?></span>
<?php endif; ?>
</div>

<?php if ($fbList): ?>
<div class="grid gap-space-sm md:grid-cols-2">
<?php foreach ($fbList as $f): ?>
<div class="bg-surface-container-lowest p-space-sm rounded-xl shadow-sm flex flex-col gap-1.5">
<?= fb_stars((int)$f['rating']) ?>
<p class="font-body-sm text-body-sm text-on-surface italic break-words">&ldquo;<?= e($f['message']) ?>&rdquo;</p>
<div class="flex items-center justify-between gap-2 pt-1">
<span class="font-label-md text-label-md text-on-surface font-semibold"><?= e($f['display_name']) ?></span>
<span class="font-label-sm text-label-sm text-on-surface-variant"><?= e(fb_when($f['created_at'])) ?></span>
</div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<p class="text-center font-body-sm text-body-sm text-on-surface-variant py-space-sm">No reviews yet. Be the first to share your experience!</p>
<?php endif; ?>

<?php if ($fbReady): ?>
<?php if (!$user): ?>
<div class="bg-surface-container-lowest p-space-sm rounded-xl shadow-sm flex flex-col gap-2 items-center text-center">
<span class="font-body-sm text-body-sm text-on-surface">Been to Yiouyi? Tell us how it went.</span>
<a href="login.php?next=index.php" class="inline-flex items-center justify-center px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md active:scale-95 transition-transform">Log in to share feedback</a>
</div>
<?php elseif ($user['role'] !== 'user'): ?>
<p class="text-center font-body-sm text-body-sm text-on-surface-variant">Feedback is written by our customers.</p>
<?php elseif ($fbPostedToday): ?>
<p class="text-center font-body-sm text-body-sm text-primary font-semibold">Thank you for your feedback today! You can share another one tomorrow.</p>
<?php else: ?>
<form id="fbForm" class="bg-surface-container-lowest p-space-sm rounded-xl shadow-sm flex flex-col gap-2" onsubmit="return false">
<div class="font-label-md text-label-md text-on-surface font-semibold">Share your experience</div>
<div id="fbStars" role="radiogroup" aria-label="Your rating" class="flex items-center gap-1">
<?php for ($i = 1; $i <= 5; $i++): ?>
<button type="button" role="radio" aria-checked="false" aria-label="<?= $i ?> star<?= $i > 1 ? 's' : '' ?>" class="fb-star text-outline p-0.5 rounded active:scale-90 transition-transform"><span class="material-symbols-outlined" style="font-size:30px;font-variation-settings:'FILL' 0;" aria-hidden="true">star</span></button>
<?php endfor; ?>
</div>
<textarea id="fbMsg" maxlength="500" rows="3" placeholder="What did you enjoy? Coffee, food, billiards, service..." class="w-full bg-surface-container-low rounded-lg px-3 py-2 font-body-sm text-body-sm text-on-surface border border-outline-variant/40 focus:outline-none focus:ring-2 focus:ring-primary/40"></textarea>
<div class="flex items-center justify-between gap-3">
<span id="fbCount" class="font-label-sm text-label-sm text-on-surface-variant">0/500</span>
<button type="button" id="fbSend" class="px-4 py-2 rounded-lg bg-primary text-on-primary font-label-md text-label-md active:scale-95 transition-transform disabled:opacity-60">Post feedback</button>
</div>
<p id="fbNote" role="status" class="hidden"></p>
</form>
<script>
(function(){
  var f=document.getElementById('fbForm'); if(!f)return;
  var rating=0, stars=[].slice.call(f.querySelectorAll('.fb-star')), msg=document.getElementById('fbMsg'),
      cnt=document.getElementById('fbCount'), note=document.getElementById('fbNote'), btn=document.getElementById('fbSend');
  function paint(v){stars.forEach(function(s,i){var on=i<v;
    s.firstElementChild.style.fontVariationSettings="'FILL' "+(on?1:0);
    s.classList.toggle('text-primary',on); s.classList.toggle('text-outline',!on);
    s.setAttribute('aria-checked',(i+1)===rating?'true':'false')})}
  stars.forEach(function(s,i){
    s.addEventListener('click',function(){rating=i+1;paint(rating)});
    s.addEventListener('mouseenter',function(){paint(i+1)});
    s.addEventListener('mouseleave',function(){paint(rating)});
  });
  msg.addEventListener('input',function(){cnt.textContent=msg.value.length+'/500'});
  function say(t,ok){note.textContent=t;note.className='font-body-sm text-body-sm '+(ok?'text-primary font-semibold':'text-error')}
  btn.addEventListener('click',async function(){
    if(!rating){say('Please tap a star rating first.',false);return}
    if(msg.value.trim().length<5){say('Please write a few words about your visit.',false);return}
    var old=btn.textContent; btn.disabled=true; btn.textContent='Posting...';
    try{
      var r=await fetch('api/feedback.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rating:rating,message:msg.value})});
      var j=await r.json();
      if(!r.ok||j.error){say(j.error||'Could not post your feedback.',false);btn.disabled=false;btn.textContent=old;return}
      say('Thank you! Your feedback is now on our page.',true);
      setTimeout(function(){location.reload()},1300);
    }catch(e){say('Network error. Please try again.',false);btn.disabled=false;btn.textContent=old}
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
<!-- Billiards teaser -->
<div class="px-gutter-mobile py-space-sm"><div class="block rounded-2xl p-space-md shadow-md text-white" style="background:linear-gradient(135deg,rgba(20,61,40,.90),rgba(42,110,72,.74)),url('photos/billiards.jpg') center/cover"><span class="font-label-sm text-label-sm uppercase tracking-wider text-tertiary-fixed">More than a cafe • Billiards Lounge</span><h2 class="font-headline-md text-headline-md mt-1">Rack up between sips.</h2><p class="font-body-sm text-body-sm opacity-90 mt-1">Grab a drink, then play. Yiouyi has 4 billiard tables open daily.</p><a data-guest href="login.php" class="inline-flex items-center gap-1 mt-space-xs bg-white text-[#9f3c16] px-3 py-1.5 rounded-lg font-label-md text-label-md">Login to reserve a table <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a><a data-member href="billiards.php" class="inline-flex items-center gap-1 mt-space-xs bg-white text-[#9f3c16] px-3 py-1.5 rounded-lg font-label-md text-label-md">View Tables <span class="material-symbols-outlined text-[16px]">arrow_forward</span></a></div></div>
<!-- About (merged from About page) -->
<div class="px-gutter-mobile py-space-sm" id="about"><div class="bg-surface-container-low rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm"><span class="font-label-sm text-label-sm text-primary uppercase tracking-widest">About Yiouyi</span><h2 class="font-headline-md text-headline-md text-on-surface">A tranquil haven along the bypass.</h2><p class="font-body-sm text-body-sm text-on-surface-variant">Yiouyi Cafe sits along the Sta. Maria Bypass in Sta. Clara, Bulacan. It is a cafe-billiards spot for coffee, good food, and a few games of pool with friends and family.</p><div class="flex flex-wrap gap-1.5"><span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-surface-container font-label-sm text-label-sm"><span class="material-symbols-outlined text-[14px] text-primary">wifi</span>Free Wi-Fi</span><span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-surface-container font-label-sm text-label-sm"><span class="material-symbols-outlined text-[14px] text-primary">pets</span>Pet Friendly</span><span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-surface-container font-label-sm text-label-sm"><span class="material-symbols-outlined text-[14px] text-primary">local_parking</span>Free Parking</span><span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-surface-container font-label-sm text-label-sm"><span class="material-symbols-outlined text-[14px] text-primary">celebration</span>Private Events</span></div></div></div>
<!-- Interactive Find & Location Info -->
<div class="px-gutter-mobile py-space-sm" id="location-card">
<div class="bg-surface-container-low rounded-2xl p-space-md shadow-sm flex flex-col gap-space-sm">
<div class="flex items-center justify-between">
<div class="flex items-center gap-2">
<span class="w-8 h-8 rounded-full bg-primary text-on-primary flex items-center justify-center">
<span class="material-symbols-outlined text-[18px]">near_me</span>
</span>
<div>
<h3 class="font-title-md text-title-md text-on-surface leading-tight">Find Us &amp; Contact</h3>
<p class="font-body-sm text-body-sm text-on-surface-variant">Sta. Maria branch • easy access with dedicated parking</p>
</div>
</div>
</div>
<!-- Google Map -->
<div class="w-full rounded-xl overflow-hidden shadow-inner bg-surface-container">
<iframe title="Map showing YiouYi Fresh Tea &amp; Coffee, Sta. Maria, Bulacan" src="https://www.google.com/maps?q=YiouYi+Fresh+Tea+%26+Coffee+-+Sta.+Maria%2C+Santa+Maria+Bypass+Rd%2C+Santa+Maria%2C+3022+Bulacan&amp;z=17&amp;output=embed" class="w-full h-64 border-0 block" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe>
</div>
<div class="flex gap-2">
<a href="https://www.google.com/maps/dir/?api=1&amp;destination=YiouYi+Fresh+Tea+%26+Coffee+-+Sta.+Maria%2C+Santa+Maria+Bypass+Rd%2C+Santa+Maria%2C+3022+Bulacan" target="_blank" rel="noopener" class="flex-1 text-center bg-primary text-on-primary py-2.5 rounded-lg font-label-md text-label-md active:scale-95 transition-transform">Get directions</a>
<a href="https://www.google.com/maps/search/?api=1&amp;query=YiouYi+Fresh+Tea+%26+Coffee+-+Sta.+Maria%2C+Santa+Maria+Bypass+Rd%2C+Santa+Maria%2C+3022+Bulacan" target="_blank" rel="noopener" class="flex-1 text-center bg-surface-container text-on-surface py-2.5 rounded-lg font-label-md text-label-md active:scale-95 transition-transform">Open in Google Maps</a>
</div>
<div class="flex flex-col gap-2.5 pt-1 font-body-sm text-body-sm text-on-surface">
<a href="https://www.google.com/maps/search/?api=1&amp;query=YiouYi+Fresh+Tea+%26+Coffee+-+Sta.+Maria%2C+Santa+Maria+Bypass+Rd%2C+Santa+Maria%2C+3022+Bulacan" target="_blank" rel="noopener" class="flex items-start gap-2 hover:text-primary transition-colors">
<span class="material-symbols-outlined text-secondary text-[18px]">location_on</span>
<span><strong>Address:</strong> YiouYi Fresh Tea &amp; Coffee – Sta. Maria, Santa Maria Bypass Rd, Santa Maria, 3022 Bulacan<br/><span class="text-on-surface-variant">Unit 6, 3rd Floor, Commercial Building, Brgy. Santa Clara</span></span>
</a>
<a href="tel:+63442302313" class="flex items-start gap-2 hover:text-primary transition-colors">
<span class="material-symbols-outlined text-secondary text-[18px]">call</span>
<span><strong>Phone:</strong> (044) 230 2313</span>
</a>
<a href="mailto:support@cooldown.biz" class="flex items-start gap-2 hover:text-primary transition-colors">
<span class="material-symbols-outlined text-secondary text-[18px]">mail</span>
<span><strong>Email:</strong> support@cooldown.biz</span>
</a>
<div class="flex items-start gap-2">
<span class="material-symbols-outlined text-secondary text-[18px]">schedule</span>
<span><strong>Open daily:</strong> 11:00 AM – 12:00 MN</span>
</div>
<a href="https://yiouyiphilippines.com" target="_blank" rel="noopener" class="flex items-start gap-2 hover:text-primary transition-colors">
<span class="material-symbols-outlined text-secondary text-[18px]">public</span>
<span><strong>Website:</strong> yiouyiphilippines.com</span>
</a>
<a href="https://www.facebook.com/share/1FPajkepMF/" target="_blank" rel="noopener" class="flex items-start gap-2 hover:text-primary transition-colors">
<span class="material-symbols-outlined text-secondary text-[18px]">thumb_up</span>
<span><strong>Facebook:</strong> YiouYi Fresh Tea &amp; Coffee – Philippines</span>
</a>
<a href="https://www.tiktok.com/@yiouyi.ph" target="_blank" rel="noopener" class="flex items-start gap-2 hover:text-primary transition-colors">
<span class="material-symbols-outlined text-secondary text-[18px]">music_note</span>
<span><strong>TikTok:</strong> @yiouyi.ph</span>
</a>
<div class="flex items-start gap-2">
<span class="material-symbols-outlined text-secondary text-[18px]">local_parking</span>
<span>Spacious on-site parking for bikes &amp; cars</span>
</div>
</div>
</div>
</div>
<!-- Tagline -->
<div class="px-gutter-mobile py-space-md w-full max-w-5xl mx-auto">
<p class="text-center font-headline-sm text-headline-sm text-primary italic leading-snug">&ldquo;Creating memories<br class="sm:hidden"/> one sip at a time&rdquo;</p>
</div>
<!-- Guest CTA -->
<div class="px-gutter-mobile pt-space-xs pb-space-lg" data-guest>
<div class="bg-surface-container-low rounded-2xl p-space-md shadow-sm flex flex-col items-center text-center gap-space-xs">
<h2 class="font-headline-sm text-headline-sm text-on-surface">Ready to order or play?</h2>
<p class="font-body-sm text-body-sm text-on-surface-variant">Log in or create a free account to see our full menu and reserve a billiards table.</p>
<a href="login.php" class="w-full text-center bg-primary text-on-primary py-3 rounded-lg font-title-sm text-title-sm shadow-sm active:scale-[0.99] transition-all">Login / Sign Up</a>
</div>
</div>
<!-- Direct Shortcuts Hub (members only) -->
<div class="px-gutter-mobile pt-space-xs pb-space-lg" data-member>
<div class="flex flex-col gap-space-xs">
<p class="font-label-sm text-label-sm text-on-surface-variant uppercase tracking-wider px-1">Explore Yiouyi</p>
<div class="grid grid-cols-3 gap-space-xs">
<a class="bg-surface-container-lowest p-space-sm rounded-xl flex flex-col items-center text-center gap-1 shadow-sm hover:bg-surface-container transition-colors" href="menu.php">
<div class="w-10 h-10 rounded-full bg-surface-container flex items-center justify-center text-primary">
<span class="material-symbols-outlined text-[20px]">restaurant_menu</span>
</div>
<span class="font-label-md text-label-md text-on-surface">Full Menu</span>
<span class="font-label-sm text-label-sm text-on-surface-variant">149 items</span>
</a>
<a class="bg-surface-container-lowest p-space-sm rounded-xl flex flex-col items-center text-center gap-1 shadow-sm hover:bg-surface-container transition-colors" href="billiards.php">
<div class="w-10 h-10 rounded-full bg-surface-container flex items-center justify-center text-tertiary">
<span class="material-symbols-outlined text-[20px]">radio_button_checked</span>
</div>
<span class="font-label-md text-label-md text-on-surface">Billiards</span>
<span class="font-label-sm text-label-sm text-on-surface-variant">4 tables</span>
</a>
<a class="bg-surface-container-lowest p-space-sm rounded-xl flex flex-col items-center text-center gap-1 shadow-sm hover:bg-surface-container transition-colors" href="login.php">
<div class="w-10 h-10 rounded-full bg-surface-container flex items-center justify-center text-on-surface-variant">
<span class="material-symbols-outlined text-[20px]">login</span>
</div>
<span class="font-label-md text-label-md text-on-surface">Login</span>
<span class="font-label-sm text-label-sm text-on-surface-variant">Sign in / up</span>
</a>
</div>
</div>
</div>
</div>
</main>
<script>
(function(){var lb=document.getElementById('lightbox'),img=document.getElementById('lb-img'),cap=document.getElementById('lb-cap');
function close(){lb.classList.add('hidden');lb.classList.remove('flex');img.removeAttribute('src')}
document.querySelectorAll('.gallery-item').forEach(function(a){a.addEventListener('click',function(e){e.preventDefault();
 img.src=a.getAttribute('href');img.alt=a.querySelector('img').alt;cap.textContent=a.dataset.cap;lb.classList.remove('hidden');lb.classList.add('flex')})});
lb.addEventListener('click',function(e){if(e.target!==img)close()});
document.addEventListener('keydown',function(e){if(e.key==='Escape')close()})})();
</script>
<?php include __DIR__ . '/includes/nav.php'; ?></body></html>