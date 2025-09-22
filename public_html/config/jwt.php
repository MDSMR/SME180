<?php
// public_html/config/jwt.php
declare(strict_types=1);

/**
 * Minimal HS256 JWT utilities (no external libs).
 * Uses JWT_SECRET, JWT_EXPIRE, JWT_REFRESH_EXPIRE from env (via config/env.php shim).
 */

require_once __DIR__ . '/env.php';

/** Base64Url helpers */
function jwt_b64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function jwt_b64url_decode(string $data): string {
    $remainder = strlen($data) % 4;
    if ($remainder) $data .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($data, '-_', '+/')) ?: '';
}

/** Exported helpers */
function jwt_secret(): string {
    return JWT_SECRET;
}
function jwt_exp_seconds(): int {
    return (defined('JWT_EXPIRE') ? (int)JWT_EXPIRE : 7200);
}
function jwt_refresh_exp_seconds(): int {
    return (defined('JWT_REFRESH_EXPIRE') ? (int)JWT_REFRESH_EXPIRE : 604800);
}

/**
 * Create a JWT token (HS256).
 * @param array    $claims      Custom claims (e.g., ['sub'=>userId, 'role'=>'Admin'])
 * @param int|null $ttlSeconds  Override TTL; default uses JWT_EXPIRE.
 */
function jwt_issue(array $claims, ?int $ttlSeconds = null): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $now    = time();
    $ttl    = $ttlSeconds ?? jwt_exp_seconds();

    $payload = array_merge([
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + $ttl,
        'iss' => $_SERVER['HTTP_HOST'] ?? 'smorll-pos',
        // 'aud' => 'smorll-clients',
    ], $claims);

    $secret = jwt_secret();

    $h = jwt_b64url_encode(json_encode($header, JSON_UNESCAPED_SLASHES));
    $p = jwt_b64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
    $s = jwt_b64url_encode(hash_hmac('sha256', "$h.$p", $secret, true));

    return "$h.$p.$s";
}

/**
 * Verify a JWT token. Returns [true, payload] or [false, error].
 */
function jwt_verify(string $token): array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return [false, 'invalid token format'];

    [$h64, $p64, $s64] = $parts;
    $header  = json_decode(jwt_b64url_decode($h64), true);
    $payload = json_decode(jwt_b64url_decode($p64), true);
    $sig     = jwt_b64url_decode($s64);

    if (!is_array($header) || !is_array($payload))   return [false, 'invalid header or payload'];
    if (($header['alg'] ?? '') !== 'HS256')          return [false, 'unsupported alg'];

    $check = hash_hmac('sha256', "$h64.$p64", jwt_secret(), true);
    if (!hash_equals($check, $sig))                  return [false, 'signature mismatch'];

    $now = time();
    if (isset($payload['nbf']) && $now < (int)$payload['nbf']) return [false, 'token not yet valid'];
    if (isset($payload['exp']) && $now >= (int)$payload['exp']) return [false, 'token expired'];

    return [true, $payload];
}