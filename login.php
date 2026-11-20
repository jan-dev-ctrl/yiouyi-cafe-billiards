<?php
define('CSRF_MANUAL', true);          // this page checks the CSRF token itself so it can show a friendly message
require_once __DIR__ . '/includes/auth.php';

function home_for(string $role): string {
    return $role === 'admin' ? 'dashboard.php' : ($role === 'staff' ? 'staff.php' : 'menu.php');
}

// Already logged in? Go to the right page.
if ($u = current_user()) {
    header('Location: ' . home_for($u['role']));
    exit;
}

$next = $_GET['next'] ?? '';
if (!in_array($next, ['menu.php', 'billiards.php', 'index.php'], true)) {
    $next = '';
}

$tab = 'login';
$error = '';
$notice = '';
$email = '';
$fullname = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $mode     = (($_POST['mode'] ?? 'login') === 'signup') ? 'signup' : 'login';
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!csrf_ok()) {
        $error = 'This page expired. Please try again.';
        $tab = $mode;
    } elseif ($mode === 'signup') {
        $tab      = 'signup';
        $fullname = trim($_POST['fullname'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $confirm  = $_POST['confirm'] ?? '';
        if ($fullname === '') {
            $error = 'Please enter your full name.';
        } elseif ($password !== $confirm) {
            $error = 'Passwords do not match.';
        } else {
            $error = register_user($email, $password, $fullname, $phone) ?? '';
            if ($error === '') {
                $tab = 'login';
                $notice = 'Account created! Please log in.';
                $fullname = $phone = '';
            }
        }
    } else {
        $wait = login_wait_seconds($email);
        $role = $wait > 0 ? null : login_user($email, $password);      // while locked, even the right password is refused
        if ($wait > 0) {
            $error = login_wait_message($wait);
        } elseif ($role === null) {
            login_record_failure($email);
            $wait = login_wait_seconds($email);
            $error = $wait > 0 ? login_wait_message($wait) : 'Wrong email or password.';
        } else {
            login_clear_failures($email);
            if (!empty($_SESSION['user']['must_change'])) {
                header('Location: account.php');           // temporary password: choose a new one first
                exit;
            }
            header('Location: ' . (($role === 'user' && $next) ? $next : home_for($role)));
            exit;
        }
    }
}
?>
<!DOCTYPE html>

<html lang="en"><head><meta charset="utf-8"/><link href="theme.css" rel="stylesheet"/><title>Log in or sign up • Yiouyi Cafe &amp; Billiards</title><?php require __DIR__ . '/includes/head-common.php'; ?><script src="theme.js"></script><script src="assets/csrf.js"></script><meta content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" name="viewport"/><meta content="mobile_tab" name="shell-type"/><link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" rel="stylesheet"/><link href="https://fonts.googleapis.com" rel="preconnect"/><link crossorigin="" href="https://fonts.gstatic.com" rel="preconnect"/><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400..900;1,400..900&amp;family=Plus+Jakarta+Sans:ital,wght@0,300..800;1,300..800&amp;display=swap" rel="stylesheet"/>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&amp;display=swap" rel="stylesheet"/><style>@layer base{html,body{width:100vw;margin:0;padding:0;background-color:rgb(var(--c-background));}body{overscroll-behavior:none;}.pb-safe{padding-bottom:env(safe-area-inset-bottom,0px);}.pt-safe{padding-top:env(safe-area-inset-top,0px);}main>:first-child{margin-top:0!important;}main>:last-child{margin-bottom:0!important;}}::-webkit-scrollbar{display:none;}</style><script src="https://cdn.tailwindcss.com"></script><script id="tailwind-config">tailwind.config = { darkMode: "class", theme: { extend: { "colors": { "on-error-container": "rgb(var(--c-on-error-container) / <alpha-value>)", "on-primary": "rgb(var(--c-on-primary) / <alpha-value>)", "inverse-primary": "rgb(var(--c-inverse-primary) / <alpha-value>)", "surface-container": "rgb(var(--c-surface-container) / <alpha-value>)", "on-primary-fixed": "#390c00", "primary-fixed": "#ffdbcf", "surface-variant": "rgb(var(--c-surface-variant) / <alpha-value>)", "surface-container-lowest": "rgb(var(--c-surface-container-lowest) / <alpha-value>)", "secondary-fixed": "#ffdad4", "on-tertiary-container": "rgb(var(--c-on-tertiary-container) / <alpha-value>)", "tertiary": "rgb(var(--c-tertiary) / <alpha-value>)", "primary-fixed-dim": "#ffb59c", "on-tertiary": "rgb(var(--c-on-tertiary) / <alpha-value>)", "on-surface-variant": "rgb(var(--c-on-surface-variant) / <alpha-value>)", "outline-variant": "rgb(var(--c-outline-variant) / <alpha-value>)", "surface-container-highest": "rgb(var(--c-surface-container-highest) / <alpha-value>)", "on-secondary-container": "rgb(var(--c-on-secondary-container) / <alpha-value>)", "error-container": "rgb(var(--c-error-container) / <alpha-value>)", "on-surface": "rgb(var(--c-on-surface) / <alpha-value>)", "on-secondary-fixed": "#2b1613", "inverse-surface": "rgb(var(--c-inverse-surface) / <alpha-value>)", "error": "rgb(var(--c-error) / <alpha-value>)", "on-primary-fixed-variant": "#822801", "surface-container-low": "rgb(var(--c-surface-container-low) / <alpha-value>)", "primary": "rgb(var(--c-primary) / <alpha-value>)", "surface-tint": "rgb(var(--c-surface-tint) / <alpha-value>)", "secondary": "rgb(var(--c-secondary) / <alpha-value>)", "background": "rgb(var(--c-background) / <alpha-value>)", "surface-container-high": "rgb(var(--c-surface-container-high) / <alpha-value>)", "surface-dim": "rgb(var(--c-surface-dim) / <alpha-value>)", "on-background": "rgb(var(--c-on-background) / <alpha-value>)", "primary-container": "rgb(var(--c-primary-container) / <alpha-value>)", "on-tertiary-fixed": "#0f2000", "secondary-container": "rgb(var(--c-secondary-container) / <alpha-value>)", "on-tertiary-fixed-variant": "#394d23", "surface-bright": "rgb(var(--c-surface-bright) / <alpha-value>)", "outline": "rgb(var(--c-outline) / <alpha-value>)", "inverse-on-surface": "rgb(var(--c-inverse-on-surface) / <alpha-value>)", "tertiary-fixed-dim": "#b7ce99", "on-secondary-fixed-variant": "#5b403c", "on-primary-container": "rgb(var(--c-on-primary-container) / <alpha-value>)", "on-error": "rgb(var(--c-on-error) / <alpha-value>)", "tertiary-container": "rgb(var(--c-tertiary-container) / <alpha-value>)", "surface": "rgb(var(--c-surface) / <alpha-value>)", "secondary-fixed-dim": "#e3beb8", "on-secondary": "rgb(var(--c-on-secondary) / <alpha-value>)", "tertiary-fixed": "#d2ebb3" }, "borderRadius": { "DEFAULT": "0.25rem", "lg": "0.5rem", "xl": "0.75rem", "full": "9999px" }, "spacing": { "space-sm": "0.75rem", "gutter-mobile": "1rem", "space-xs": "0.375rem", "space-lg": "2rem", "gutter": "1.5rem", "space-xl": "3.5rem", "margin-mobile": "1.25rem", "margin": "3rem", "space-md": "1.25rem" }, "fontFamily": { "headline-lg": ["Playfair Display"], "headline-xl": ["Playfair Display"], "body-sm": ["Plus Jakarta Sans"], "headline-md": ["Playfair Display"], "label-md": ["Plus Jakarta Sans"], "headline-sm": ["Playfair Display"], "label-sm": ["Plus Jakarta Sans"], "headline-xl-mobile": ["Playfair Display"], "headline-lg-mobile": ["Playfair Display"], "body-lg": ["Plus Jakarta Sans"], "body-md": ["Plus Jakarta Sans"], "title-sm": ["Plus Jakarta Sans"], "title-md": ["Plus Jakarta Sans"] }, "fontSize": { "headline-lg": ["36px", { "lineHeight": "44px", "letterSpacing": "-0.015em", "fontWeight": "600" }], "headline-xl": ["48px", { "lineHeight": "56px", "letterSpacing": "-0.02em", "fontWeight": "600" }], "body-sm": ["13px", { "lineHeight": "19px", "fontWeight": "400" }], "headline-md": ["26px", { "lineHeight": "34px", "letterSpacing": "-0.01em", "fontWeight": "500" }], "label-md": ["13px", { "lineHeight": "16px", "letterSpacing": "0.02em", "fontWeight": "600" }], "headline-sm": ["20px", { "lineHeight": "28px", "fontWeight": "500" }], "label-sm": ["11px", { "lineHeight": "14px", "letterSpacing": "0.05em", "fontWeight": "700" }], "headline-xl-mobile": ["34px", { "lineHeight": "42px", "letterSpacing": "-0.01em", "fontWeight": "600" }], "headline-lg-mobile": ["28px", { "lineHeight": "36px", "letterSpacing": "-0.01em", "fontWeight": "600" }], "body-lg": ["17px", { "lineHeight": "26px", "fontWeight": "400" }], "body-md": ["15px", { "lineHeight": "23px", "fontWeight": "400" }], "title-sm": ["16px", { "lineHeight": "22px", "fontWeight": "600" }], "title-md": ["18px", { "lineHeight": "24px", "fontWeight": "600" }] } } } }</script><style>
    body {
      min-height: max(884px, 100dvh);
    }
  </style>
  </head><body class="bg-surface font-body-md text-on-surface pt-safe pb-safe flex flex-col min-h-screen"><button type="button" data-theme-toggle class="theme-fab"></button><main class="flex flex-col items-center w-full min-h-screen px-4 pt-4 pb-8">
<a href="index.php" class="self-start flex items-center gap-1 text-on-surface-variant font-label-md text-label-md mb-2"><span class="material-symbols-outlined text-[20px]">arrow_back</span>Back to site</a>
<div class="flex flex-col items-center text-center mb-4"><img alt="Yiouyi Cafe Logo" class="h-14 w-auto object-contain mb-2 rounded-lg" src="logo.png"/><span class="font-headline-lg-mobile text-headline-lg-mobile text-on-surface">Yiouyi Cafe &amp; Billiards</span><p class="font-body-sm text-body-sm italic text-on-surface-variant">&ldquo;Creating memories one sip at a time&rdquo;</p><p id="subtitle" class="font-body-sm text-body-sm text-secondary mt-0.5">Welcome back</p></div>
<div class="w-full max-w-md bg-surface-container-lowest rounded-xl shadow-md p-5 flex flex-col gap-4">
<div class="grid grid-cols-2 gap-1 p-1 bg-surface-container rounded-lg"><button id="tabLogin" type="button" onclick="setTab('login')" class="py-2 rounded-lg font-label-md text-label-md bg-primary text-on-primary shadow-sm">Login</button><button id="tabSignup" type="button" onclick="setTab('signup')" class="py-2 rounded-lg font-label-md text-label-md text-on-surface-variant">Sign Up</button></div>
<?php if ($error): ?><div class="px-3 py-2 rounded-lg bg-error-container text-on-error-container font-body-sm text-body-sm"><?= e($error) ?></div><?php endif; ?>
<?php if ($notice): ?><div class="px-3 py-2 rounded-lg bg-tertiary-fixed text-on-tertiary-fixed font-body-sm text-body-sm"><?= e($notice) ?></div><?php endif; ?>
<form id="authForm" method="post" action="login.php<?= $next ? '?next=' . urlencode($next) : '' ?>" class="flex flex-col gap-3.5" onsubmit="return checkForm()"><?= csrf_field() ?>
<input type="hidden" name="mode" id="mode" value="<?= e($tab) ?>"/>
<div id="nameRow" class="hidden flex-col gap-1"><label class="font-label-sm text-label-sm uppercase tracking-wider text-secondary" for="fullname">Full Name</label><input id="fullname" name="fullname" value="<?= e($fullname) ?>" type="text" placeholder="Juan Dela Cruz" class="w-full px-3 py-2.5 bg-surface-container-low rounded-lg font-body-md placeholder:text-outline focus:bg-surface-container-lowest focus:outline-none focus:shadow-sm"/></div>
<div class="flex flex-col gap-1"><label id="emailLabel" class="font-label-sm text-label-sm uppercase tracking-wider text-secondary" for="email">Email</label><input id="email" name="email" value="<?= e($email) ?>" type="email" required placeholder="you@email.com" class="w-full px-3 py-2.5 bg-surface-container-low rounded-lg font-body-md placeholder:text-outline focus:bg-surface-container-lowest focus:outline-none focus:shadow-sm"/></div>
<div id="phoneRow" class="hidden flex-col gap-1"><label class="font-label-sm text-label-sm uppercase tracking-wider text-secondary" for="phone">Mobile Number</label><input id="phone" name="phone" value="<?= e($phone) ?>" type="tel" inputmode="numeric" autocomplete="tel" maxlength="11" minlength="11" pattern="[0-9]{11}" title="Exactly 11 digits, numbers only" oninput="this.value=this.value.replace(/\D/g,'').slice(0,11)" placeholder="09XXXXXXXXX" class="w-full px-3 py-2.5 bg-surface-container-low rounded-lg font-body-md placeholder:text-outline focus:bg-surface-container-lowest focus:outline-none focus:shadow-sm"/></div>
<div class="flex flex-col gap-1"><div class="flex items-center justify-between"><label class="font-label-sm text-label-sm uppercase tracking-wider text-secondary" for="pw">Password</label><a id="forgot" href="javascript:void(0)" onclick="alert('Forgot your password? Ask the cafe admin to reset it. They will give you a temporary password, and you will choose a new one when you log in.')" class="font-label-sm text-label-sm text-primary">Forgot?</a></div><div class="relative flex items-center"><input id="pw" name="password" type="password" required placeholder="Enter password" class="w-full px-3 pr-10 py-2.5 bg-surface-container-low rounded-lg font-body-md placeholder:text-outline focus:bg-surface-container-lowest focus:outline-none focus:shadow-sm"/><button type="button" aria-label="Toggle password" onclick="const p=document.getElementById('pw');const s=p.type==='password';p.type=s?'text':'password';this.firstChild.textContent=s?'visibility_off':'visibility'" class="absolute right-2.5 p-1 text-secondary"><span class="material-symbols-outlined text-[19px]">visibility</span></button></div></div>
<div id="confirmRow" class="hidden flex-col gap-1"><label class="font-label-sm text-label-sm uppercase tracking-wider text-secondary" for="pw2">Confirm Password</label><input id="pw2" name="confirm" type="password" placeholder="Re-enter password" class="w-full px-3 py-2.5 bg-surface-container-low rounded-lg font-body-md placeholder:text-outline focus:bg-surface-container-lowest focus:outline-none focus:shadow-sm"/></div>
<button id="submitBtn" type="submit" class="w-full mt-1 py-3 rounded-lg bg-primary hover:bg-primary-container text-on-primary font-title-sm text-title-sm flex items-center justify-center gap-2 shadow-sm active:scale-[0.99] transition-all">Login</button>
</form>
</div>
<script>
let tab='<?= e($tab) ?>';
const on='bg-primary text-on-primary shadow-sm',off='text-on-surface-variant';
function style(id,active){const e=document.getElementById(id);e.classList.remove(...on.split(' '),off);e.classList.add(...(active?on:off).split(' '))}
function show(id,v){const e=document.getElementById(id);e.classList.toggle('hidden',!v);e.classList.toggle('flex',v)}
function render(){
 const login=tab==='login';
 style('tabLogin',login);style('tabSignup',!login);
 show('nameRow',!login);show('phoneRow',!login);show('confirmRow',!login);show('forgot',login);
 document.getElementById('phone').required=!login;
 document.getElementById('submitBtn').textContent=login?'Login':'Create Account';
 document.getElementById('subtitle').textContent=login?'Welcome back':'Create your customer account';
}
function setTab(t){tab=t;document.getElementById('mode').value=t;render()}
function checkForm(){
 if(tab==='signup'&&!/^[0-9]{11}$/.test(document.getElementById('phone').value)){alert('Mobile number must be exactly 11 digits (numbers only).');return false}
 if(tab==='signup'&&document.getElementById('pw').value!==document.getElementById('pw2').value){alert('Passwords do not match.');return false}
 return true}
render();
</script></main></body></html>