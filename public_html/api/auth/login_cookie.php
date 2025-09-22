<?php
declare(strict_types=1);

// public_html/api/auth/login_cookie.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$username = trim($data['username'] ?? '');
$password = (string)($data['password'] ?? '');

if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'username and password required']);
    exit;
}

$user = find_user_by_username($username);
if (!$user || !verify_password($password, $user['password_hash'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid credentials']);
    exit;
}
if (!empty($user['disabled_at'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'account disabled']);
    exit;
}

$role = $user['role'] ?? 'Staff';
[$access, $refresh] = issue_access_and_refresh((int)$user['id'], $role);

// Set HttpOnly cookies (server-side)
$secure   = true;            // your site is https
$httpOnly = true;
$domain   = $_SERVER['HTTP_HOST'] ?? '';
$path     = '/';

// Access: shorter TTL, Lax is fine for normal navigation
setcookie('access_token', $access, [
    'expires'  => time() + jwt_exp_seconds(),
    'path'     => $path,
    'domain'   => $domain,
    'secure'   => $secure,
    'httponly' => $httpOnly,
    'samesite' => 'Lax',
]);

// Refresh: longer TTL, Strict so it’s not sent on cross-site nav
setcookie('refresh_token', $refresh, [
    'expires'  => time() + jwt_refresh_exp_seconds(),
    'path'     => $path,
    'domain'   => $domain,
    'secure'   => $secure,
    'httponly' => $httpOnly,
    'samesite' => 'Strict',
]);

echo json_encode(['ok' => true, 'message' => 'logged in', 'user' => [
    'id' => (int)$user['id'],
    'username' => $user['username'],
    'email' => $user['email'],
    'role' => $role,
    'name' => $user['name'],
]]);