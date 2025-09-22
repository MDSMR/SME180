<?php
// public_html/api/_guard.php
declare(strict_types=1);

/**
 * Usage:
 *   require_once __DIR__ . '/_guard.php';
 *   [$ok, $payloadOrError, $user] = api_guard();
 *   if (!$ok) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>$payloadOrError]); exit; }
 *   // $user contains DB row (id, username, email, role, etc.)
 */

require_once __DIR__ . '/../config/jwt.php';
require_once __DIR__ . '/../config/auth.php';

function api_guard(): array {
    // Expect header: Authorization: Bearer <token>
    $hdr = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (stripos($hdr, 'Bearer ') !== 0) {
        return [false, 'missing bearer token', null];
    }
    $token = trim(substr($hdr, 7));
    if ($token === '') return [false, 'empty token', null];

    [$ok, $data] = jwt_verify($token);
    if (!$ok) return [false, $data, null]; // $data is error message

    $user = get_auth_user($data);
    if (!$user) return [false, 'user not found', null];

    return [true, $data, $user];
}