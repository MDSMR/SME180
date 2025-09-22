<?php
declare(strict_types=1);

// public_html/api/auth/logout.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method not allowed']);
    exit;
}

// Accept JSON: { "refresh_token": "..." } to revoke
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
$refresh = trim($data['refresh_token'] ?? '');

if ($refresh !== '') {
    $uid = verify_refresh_token($refresh);
    if ($uid) revoke_refresh_token($uid, $refresh);
}

echo json_encode(['ok' => true, 'message' => 'logged out (refresh revoked if provided)']);