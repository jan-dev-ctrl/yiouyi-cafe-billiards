<?php
// Bottom navigation bar (replaces the nav that auth.js built).
// Include it where the old nav was, from any page in the project root:
//   include __DIR__ . '/includes/nav.php';

$navUser = current_user();
$navPage = strtolower(basename($_SERVER['SCRIPT_NAME']));

$navItem = function (string $href, string $icon, string $label) use ($navPage): string {
    $active = ($navPage === $href);
    $cls = 'flex flex-col items-center justify-center gap-0.5 min-w-[56px] h-12 transition-colors '
         . ($active ? 'text-primary font-bold' : 'text-on-surface-variant hover:text-on-surface');
    return '<a class="' . $cls . '" href="' . $href . '"' . ($active ? ' aria-current="page"' : '') . '>'
         . '<span class="material-symbols-outlined text-[22px]">' . $icon . '</span>'
         . '<span class="font-label-sm text-label-sm">' . $label . '</span></a>';
};

$items = $navItem('index.php', 'coffee', 'Home');
if (!$navUser) {
    $items .= $navItem('login.php', 'account_circle', 'Login');
} else {
    if ($navUser['role'] === 'staff') {
        $items .= $navItem('staff.php', 'receipt_long', 'Orders')
                . $navItem('account.php', 'manage_accounts', 'Account');
    } elseif ($navUser['role'] === 'admin') {
        $items .= $navItem('dashboard.php', 'dashboard', 'Admin')
                . $navItem('staff.php', 'receipt_long', 'Orders')
                . $navItem('records.php', 'summarize', 'Records')
                . $navItem('account.php', 'manage_accounts', 'Account');
    } else {
        $items .= $navItem('menu.php', 'restaurant_menu', 'Menu')
                . $navItem('billiards.php', 'radio_button_checked', 'Billiards')
                . $navItem('mine.php', 'account_circle', 'Account');
    }
    $items .= $navItem('logout.php', 'logout', 'Logout');
}
?>
<nav class="fixed bottom-0 w-full z-50 pb-safe bg-surface/90 backdrop-blur-xl shadow-[0_-4px_16px_rgba(62,39,35,0.05)]" data-active-classes="text-primary font-bold"><div class="flex justify-around items-center h-16 px-gutter-mobile"><?= $items ?></div></nav>
<?php if ($navUser && $navUser['role'] === 'user'): ?><script src="assets/orders-live.js" defer></script><?php endif; ?>
