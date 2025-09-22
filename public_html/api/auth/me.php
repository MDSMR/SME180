<?php
declare(strict_types=1);

// public_html/api/auth/me.php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../_guard.php';

[$ok, $payloadOrError, $user] = api_guard();
if (!$ok) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $payloadOrError]);
    exit;
}

// Return current user info (from DB) and token payload
echo json_encode([
    'ok' => true,
    'user' => $user,
    'token' => $payloadOrError,
]);