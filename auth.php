<?php
// Replaces auth.js. Real PHP sessions + role checks.
// Usage at the top of a page:
//   require_once __DIR__ . '/includes/auth.php';
//   require_role('member');   // any logged-in user  (menu.php, billiards.php)
//   require_role('staff');    // staff and admin     (staff.php: orders, reservations, menu)
//   require_role('admin');    // admin only          (dashboard.php, records.php)
// Public pages (index.php, login.php) just include the file without require_role().

require_once __DIR__ . '/db.php';
if (is_file(__DIR__ . '/settings.php')) { require_once __DIR__ . '/settings.php'; }   // optional, see includes/settings.php
if (!defined('FORCE_HTTPS')) { define('FORCE_HTTPS', false); }

// A few hosts don't have PHP's optional "mbstring" extension. These stand-ins stop pages from breaking there
// (they are only defined when the real functions are missing).
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null): string {
        $chars = preg_split('//u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return implode('', array_slice($chars, $start, $length));
    }
}
if (!function_exists('mb_strtoupper')) {
    function mb_strtoupper(string $s): string { return strtoupper($s); }
}

/** Is this request on HTTPS? Also understands hosts that put a secure "front door" (proxy / Cloudflare) in front of the site. */
function is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') { return true; }
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) { return true; }
    $fwd = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    if ($fwd === 'https') { return true; }
    if (strtolower((string)($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on') { return true; }
    return stripos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), '"scheme":"https"') !== false;      // Cloudflare
}

/** Your own computer or home network (XAMPP, localhost, 192.168.x.x): never forced to https. */
function is_local_request(): bool {
    $h = trim(strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? ''))), '[]');
    return $h === '' || $h === 'localhost' || $h === '127.0.0.1' || $h === '::1'
        || (bool)preg_match('/\.(local|localhost|test)$/', $h)
        || (bool)preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.)/', $h);
}

// FORCE_HTTPS (includes/settings.php): visitors on http:// are sent to https://. Pages that SEND data are never
// processed over plain http, and browsers are told to remember "https only" for a week (HSTS).
if (FORCE_HTTPS && PHP_SAPI !== 'cli' && !is_local_request()) {
    if (!is_https()) {
        $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
        $uri  = preg_replace('/[\x00-\x20\x7f]/', '', (string)($_SERVER['REQUEST_URI'] ?? '/'));
        if (!preg_match('/^[a-z0-9]([a-z0-9.\-]*[a-z0-9])?$/', $host)) { http_response_code(400); exit('Bad request.'); }
        $target = 'https://' . $host . (($uri !== '' && $uri[0] === '/') ? $uri : '/');
        if (in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
            header('Location: ' . $target, true, 302);        // 302 (not 301) so switching FORCE_HTTPS off again is never stuck in browsers
        } else {
            http_response_code(426);                           // a form or app call arrived unencrypted: refuse it, do not process it
            header('Upgrade: TLS/1.2');
            echo 'Please use the secure address: ' . htmlspecialchars($target, ENT_QUOTES, 'UTF-8');
        }
        exit;
    }
    header('Strict-Transport-Security: max-age=604800');
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');       // never accept a session id the visitor made up (session fixation)
    session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => is_https()]);
    session_start();
}
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');                       // browsers must not guess file types
    header('X-Frame-Options: SAMEORIGIN');                           // other sites cannot put this site in a frame (clickjacking)
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

/** Logged-in user as ['id'=>, 'email'=>, 'role'=>] or null. */
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

/** Safe output for HTML:  <?= e($value) ?> */
function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Page guard. $need = 'member' | 'staff' | 'admin' */
function require_role(string $need): array {
    $user = current_user();
    if ($user) { $user = sync_session_user($user); }
    if (!$user) {
        $page = basename($_SERVER['SCRIPT_NAME']);
        header('Location: login.php?next=' . urlencode($page));
        exit;
    }
    if (!empty($user['must_change']) && basename($_SERVER['SCRIPT_NAME']) !== 'account.php') {
        header('Location: account.php');          // an admin reset this password: choose a new one first
        exit;
    }
    $ok = match ($need) {
        'admin'  => $user['role'] === 'admin',
        'staff'  => in_array($user['role'], ['staff', 'admin'], true),   // the counter screens: staff, and admin can check them too
        default  => true,
    };
    if (!$ok) {
        header('Location: index.php');
        exit;
    }
    return $user;
}

/** Same guard for JSON/API files: returns 401/403 instead of redirecting. */
function require_role_json(string $need): array {
    $user = current_user();
    if ($user) { $user = sync_session_user($user); }
    if (!$user) {
        json_out(['error' => 'Please log in first.'], 401);
    }
    if (!empty($user['must_change'])) {
        json_out(['error' => 'Please set a new password first.'], 403);
    }
    $ok = match ($need) {
        'admin'  => $user['role'] === 'admin',
        'staff'  => in_array($user['role'], ['staff', 'admin'], true),   // the counter screens: staff, and admin can check them too
        default  => true,
    };
    if (!$ok) {
        json_out(['error' => 'Not allowed.'], 403);
    }
    return $user;
}

function json_out($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

/** Check email + password. Returns the role on success, or null on failure. */
function login_user(string $email, string $password): ?string {
    $email = strtolower(trim($email));
    try {
        $stmt = db()->prepare('SELECT id, email, password_hash, role, must_change_password AS mc, pw_version AS pv FROM users WHERE email = ?');
        $stmt->execute([$email]);
    } catch (PDOException $e) {                      // password columns not migrated yet
        $stmt = db()->prepare('SELECT id, email, password_hash, role, 0 AS mc, 0 AS pv FROM users WHERE email = ?');
        $stmt->execute([$email]);
    }
    $row = $stmt->fetch();
    if (!$row || !password_verify($password, $row['password_hash'])) {
        return null;
    }
    session_regenerate_id(true);
    csrf_issue();                                    // a fresh CSRF token for the new login
    $_SESSION['user'] = ['id' => (int)$row['id'], 'email' => $row['email'], 'role' => $row['role'],
                         'pv' => (int)$row['pv'], 'must_change' => (bool)$row['mc']];
    return $row['role'];
}

/**
 * Re-check a logged-in user against the database on every protected page:
 *  - deleted account, or password changed/reset since this login  -> logged out (returns null)
 *  - role changed by the admin                                      -> takes effect immediately
 *  - admin reset the password                                       -> must_change is set until a new one is chosen
 */
function sync_session_user(array $user): ?array {
    try {
        $st = db()->prepare('SELECT role, must_change_password AS mc, pw_version AS pv FROM users WHERE id = ?');
        $st->execute([$user['id']]);
        $r = $st->fetch();
    } catch (PDOException $e) {
        return $user;                                // password columns not migrated yet: behave as before
    }
    if (!$r || (int)$r['pv'] !== (int)($user['pv'] ?? 0)) {
        unset($_SESSION['user']);
        return null;
    }
    $_SESSION['user']['role']        = $r['role'];
    $_SESSION['user']['must_change'] = (bool)$r['mc'];
    return $_SESSION['user'];
}

/** The user changes their own password. Returns an error message, or null on success. */
function change_password(int $id, string $current, string $new): ?string {
    $st = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$id]);
    $hash = $st->fetchColumn();
    if ($hash === false || !password_verify($current, $hash)) {
        return 'Your current password is not correct.';
    }
    if (strlen($new) < 6) {
        return 'New password must be at least 6 characters.';
    }
    if ($new === $current) {
        return 'Choose a password different from the current one.';
    }
    $newHash = password_hash($new, PASSWORD_DEFAULT);
    try {
        db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 0, pw_version = pw_version + 1 WHERE id = ?')
            ->execute([$newHash, $id]);
        $pv = db()->prepare('SELECT pw_version FROM users WHERE id = ?');
        $pv->execute([$id]);
        $_SESSION['user']['pv'] = (int)$pv->fetchColumn();     // keep THIS session; other sessions end
    } catch (PDOException $e) {                      // not migrated yet: plain password change
        db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$newHash, $id]);
    }
    $_SESSION['user']['must_change'] = false;
    return null;
}

/** Admin resets someone's password. Returns the temporary password, or null if the user doesn't exist. */
function reset_password(int $id): ?string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';   // no 0/O, 1/l/I
    $temp = '';
    for ($i = 0; $i < 8; $i++) {
        $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $up = db()->prepare('UPDATE users SET password_hash = ?, must_change_password = 1, pw_version = pw_version + 1 WHERE id = ?');
    $up->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);
    $chk = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
    $chk->execute([$id]);
    return (int)$chk->fetchColumn() ? $temp : null;
}

function register_user(string $email, string $password, string $fullName = '', string $phone = ''): ?string {
    $email = strtolower(trim($email));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return 'Please enter a valid email address.';
    }
    if (strlen($password) < 6) {
        return 'Password must be at least 6 characters.';
    }
    $phone = trim($phone);
    if (!preg_match('/^[0-9]{11}$/', $phone)) {
        return 'Mobile number must be exactly 11 digits (numbers only).';
    }
    $stmt = db()->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return 'That email is already registered.';
    }
    $stmt = db()->prepare('INSERT INTO users (full_name, email, phone, password_hash, role) VALUES (?, ?, ?, ?, "user")');
    $stmt->execute([trim($fullName), $email, trim($phone) ?: null, password_hash($password, PASSWORD_DEFAULT)]);
    return null;
}

function logout_user(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}


// =====================================================================================================
//  CSRF protection: a page on another website must not be able to make a logged-in visitor's browser
//  change data here (place orders, settle payments, change roles...).
//  How: every session gets a secret token. It is also stored in a cookie (yy_csrf) that only OUR pages can read;
//  assets/csrf.js copies it into an X-CSRF-Token header on every POST (and into a hidden _csrf field on forms).
//  Any POST/PUT/PATCH/DELETE without the matching token is refused, for every file that includes this one.
// =====================================================================================================

/** Make (or replace) this session's CSRF token and hand it to the browser in a cookie. */
function csrf_issue(): void {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    csrf_cookie();
}

function csrf_cookie(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) { return; }
    setcookie('yy_csrf', $_SESSION['csrf'], ['expires' => 0, 'path' => '/', 'secure' => is_https(), 'httponly' => false, 'samesite' => 'Lax']);
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) { csrf_issue(); }
    return $_SESSION['csrf'];
}

/** <input type="hidden"> to put inside any <form method="post"> */
function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"/>';
}

/** True for normal page views, and for POSTs that carry the right token. */
function csrf_ok(): bool {
    if (PHP_SAPI === 'cli' || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        return true;
    }
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    return is_string($sent) && $sent !== '' && hash_equals(csrf_token(), $sent);
}

csrf_token();                                                    // every session has a token...
if (PHP_SAPI !== 'cli' && ($_COOKIE['yy_csrf'] ?? '') !== $_SESSION['csrf']) { csrf_cookie(); }   // ...and the browser has the cookie
if (!defined('CSRF_MANUAL') && !csrf_ok()) {                    // login.php and account.php check it themselves to show a friendly message
    http_response_code(403);
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Your page expired. Please refresh it and try again.']);
    } else {
        echo 'This page expired. Please go back, refresh it and try again.';
    }
    exit;
}

// =====================================================================================================
//  Login lockout: after LOGIN_MAX_TRIES wrong passwords for the same email from the same network address,
//  that email + address must wait LOGIN_LOCK_MINUTES. A whole address is also limited (LOGIN_IP_MAX_TRIES wrong
//  passwords across any emails). Unknown emails are counted exactly like real ones, so the message never reveals
//  which accounts exist. Someone else's failed tries from another address never lock YOUR login.
//  Needs the login_attempts table (migrate_security.sql); without it the lockout is simply off.
// =====================================================================================================
const LOGIN_MAX_TRIES    = 5;
const LOGIN_IP_MAX_TRIES = 20;
const LOGIN_LOCK_MINUTES = 10;

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function login_key(string $email): string {
    return substr(preg_replace('/[^\x20-\x7E]/', '?', strtolower(trim($email))), 0, 190);   // plain ASCII, short
}

/** Seconds this visitor must still wait before trying to log in again (0 = go ahead). */
function login_wait_seconds(string $email): int {
    try {
        $pdo = db();
        $win = LOGIN_LOCK_MINUTES;
        $wait = 0;
        $checks = [['email = ? AND ip = ?', [login_key($email), client_ip()], LOGIN_MAX_TRIES],
                   ['ip = ?', [client_ip()], LOGIN_IP_MAX_TRIES]];
        foreach ($checks as [$where, $args, $max]) {
            $st = $pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, NOW(), created_at + INTERVAL $win MINUTE)
                                   FROM login_attempts WHERE $where AND created_at > NOW() - INTERVAL $win MINUTE
                                  ORDER BY created_at ASC");
            $st->execute($args);
            $waits = $st->fetchAll(PDO::FETCH_COLUMN);
            if (count($waits) >= $max) {
                $wait = max($wait, (int)$waits[count($waits) - $max]);     // locked until enough old tries expire
            }
        }
        return max(0, $wait);
    } catch (PDOException $e) {
        return 0;
    }
}

function login_record_failure(string $email): void {
    try {
        $pdo = db();
        $pdo->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([login_key($email), client_ip()]);
        if (random_int(1, 20) === 1) {                                     // now and then, forget tries older than a day
            $pdo->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
        }
    } catch (PDOException $e) { /* table not created yet */ }
}

/** A correct login wipes this email's wrong tries from this address (the address-wide count is kept). */
function login_clear_failures(string $email): void {
    try {
        db()->prepare('DELETE FROM login_attempts WHERE email = ? AND ip = ?')->execute([login_key($email), client_ip()]);
    } catch (PDOException $e) { /* table not created yet */ }
}

function login_wait_message(int $seconds): string {
    $m = max(1, (int)ceil($seconds / 60));
    return 'Too many wrong attempts. Please wait ' . $m . ' minute' . ($m === 1 ? '' : 's') . ' and try again.';
}
