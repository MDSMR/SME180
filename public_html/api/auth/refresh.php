<?php
declare(strict_types=1);

// public_html/api/auth/refresh.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

// Accept JSON: { "refresh_token": "..." }
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$refresh = trim($data['refresh_token'] ?? '');

if ($refresh === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'refresh_token required']);
    exit;
}

$userId = verify_refresh_token($refresh);
if (!$userId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'invalid or expired refresh token']);
    exit;
}

// Optional: rotate refresh token (best practice)
revoke_refresh_token($userId, $refresh);
[$access, $newRefresh] = issue_access_and_refresh($userId);

// Fetch user for response
$pdo = db();
$stmt = $pdo->prepare('SELECT id, username, email, role, name FROM users WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $userId]);
$user = $stmt->fetch();

echo json_encode([
    'ok' => true,
    'access_token'  => $access,
    'expires_in'    => jwt_exp_seconds(),
    'refresh_token' => $newRefresh,
    'refresh_expires_in' => jwt_refresh_exp_seconds(),
    'user' => $user,
]);