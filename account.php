<?php
// Change your own password (any role). Also where people land after an admin reset their password.
define('CSRF_MANUAL', true);          // checked below so the page can show a friendly message
require_once __DIR__ . '/includes/auth.php';

$user   = require_role('member');
$forced = !empty($user['must_change']);
$home   = $user['role'] === 'admin' ? 'dashboard.php' : ($user['role'] === 'staff' ? 'staff.php' : 'menu.php');

$error = '';
$done  = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $current = (string)($_POST['current'] ?? '');
    $new     = (string)($_POST['new'] ?? '');
    $confirm = (string)($_POST['confirm'] ?? '');
    if (!csrf_ok()) {
        $error = 'This page expired. Please try again.';
    } elseif ($new !== $confirm) {
        $error = 'The new passwords do not match.';
    } else {
        $error = change_password((int)$user['id'], $current, $new) ?? '';
        $done  = ($error === '');
    }
}
?>
<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"/><link href="theme.css" rel="stylesheet"/><?php require __DIR__ . '/includes/head-common.php'; ?><script src="theme.js"></script><script src="assets/csrf.js"></script>
<meta name="viewport" content="width=device-width, initial-scale=1"/>
<title>Change password • YiouYi</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;background:var(--page);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;color:var(--ink);padding:16px}
  .sheet{max-width:400px;margin:24px auto;background:var(--sheet);border-radius:14px;padding:24px 20px;box-shadow:0 2px 14px rgba(0,0,0,.08)}
  .head{text-align:center;margin-bottom:14px}
  .head img{height:52px;border-radius:12px}
  h1{font-size:20px;margin:8px 0 2px}
  .sub{font-size:13px;color:var(--muted);margin:0}
  label{display:block;font-size:12px;letter-spacing:.08em;text-transform:uppercase;color:var(--muted);margin:14px 0 4px}
  input{width:100%;padding:12px;background:var(--sheet);color:var(--ink);border:1px solid var(--line);border-radius:10px;font-size:16px}
  button,.btn{display:block;width:100%;text-align:center;margin-top:18px;padding:13px;border:0;border-radius:10px;background:var(--btn-bg);color:var(--btn-fg);font:600 15px system-ui,sans-serif;cursor:pointer;text-decoration:none}
  .msg{border-radius:10px;padding:10px 12px;font-size:14px;margin-top:12px}
  .err{background:var(--bad-bg);color:var(--bad-fg)}.ok{background:var(--ok-bg);color:var(--ok-fg)}.warn{background:var(--warn-bg);color:var(--warn-fg)}
  .links{text-align:center;margin-top:16px;font-size:14px}
  .links a{color:var(--muted)}
</style></head>
<body><button type="button" data-theme-toggle class="theme-fab"></button>
<div class="sheet">
  <div class="head">
    <img src="logo.png" alt=""/>
    <h1>Change password</h1>
    <p class="sub"><?= e($user['email']) ?></p>
  </div>

<?php if ($done): ?>
  <div class="msg ok">Your password has been changed.</div>
  <a class="btn" href="<?= e($home) ?>">Continue</a>
<?php else: ?>
  <?php if ($forced): ?>
  <div class="msg warn">An admin reset your password. Enter the temporary password you were given, then choose a new one to continue.</div>
  <?php endif; ?>
  <?php if ($error !== ''): ?><div class="msg err"><?= e($error) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <?= csrf_field() ?>
    <label for="current"><?= $forced ? 'Temporary password' : 'Current password' ?></label>
    <input id="current" name="current" type="password" autocomplete="current-password" required/>
    <label for="new">New password</label>
    <input id="new" name="new" type="password" minlength="6" autocomplete="new-password" required/>
    <label for="confirm">Confirm new password</label>
    <input id="confirm" name="confirm" type="password" minlength="6" autocomplete="new-password" required/>
    <button type="submit">Save new password</button>
  </form>
  <div class="links">
    <?php if (!$forced): ?><a href="<?= e($home) ?>">Cancel</a> • <?php endif; ?><a href="logout.php">Log out</a>
  </div>
<?php endif; ?>
</div>
</body></html>
