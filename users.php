<?php
// POST api/users.php { "id": 5, "role": "staff" }            -> admin changes someone's role (Team tab in dashboard.php)
// POST api/users.php { "id": 5, "action": "reset_password" }  -> admin gives someone a temporary password (shown once)

require_once __DIR__ . '/../includes/auth.php';

$admin = require_role_json('admin');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['error' => 'POST only.'], 405);
}
$in   = json_decode(file_get_contents('php://input'), true);
$id   = (int)($in['id'] ?? 0);
$role = (string)($in['role'] ?? '');

if (($in['action'] ?? '') === 'reset_password') {
    if ($id === (int)$admin['id']) {
        json_out(['error' => 'Use "Change password" for your own account.'], 409);
    }
    $temp = reset_password($id);
    if ($temp === null) {
        json_out(['error' => 'User not found.'], 404);
    }
    json_out(['ok' => true, 'temp' => $temp]);
}

if (!in_array($role, ['user', 'staff', 'admin'], true)) {
    json_out(['error' => 'Invalid role.'], 422);
}
if ($id === (int)$admin['id']) {
    json_out(['error' => "You can't change your own role."], 409);
}

$st = db()->prepare('UPDATE users SET role = ? WHERE id = ?');
$st->execute([$role, $id]);

$chk = db()->prepare('SELECT COUNT(*) FROM users WHERE id = ?');
$chk->execute([$id]);
if (!(int)$chk->fetchColumn()) {
    json_out(['error' => 'User not found.'], 404);
}
json_out(['ok' => true]);
