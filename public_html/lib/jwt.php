<?php
// lib/jwt.php - minimal JWT implementation using HMAC-SHA256
require_once __DIR__ . '/../config/env.php';

function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode($data) {
    $remainder = strlen($data) % 4;
    if ($remainder) $data .= str_repeat('=', 4 - $remainder);
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_encode($payload, $exp_seconds = null) {
    $secret = env('JWT_SECRET', 'change_me');
    $header = ['alg'=>'HS256','typ'=>'JWT'];
    if ($exp_seconds) $payload['exp'] = time() + intval($exp_seconds);
    $segments = [];
    $segments[] = base64url_encode(json_encode($header));
    $segments[] = base64url_encode(json_encode($payload));
    $signing_input = implode('.', $segments);
    $signature = hash_hmac('sha256', $signing_input, $secret, true);
    $segments[] = base64url_encode($signature);
    return implode('.', $segments);
}

function jwt_decode($jwt) {
    $secret = env('JWT_SECRET', 'change_me');
    $parts = explode('.', $jwt);
    if (count($parts) != 3) return null;
    list($h64,$p64,$s64) = $parts;
    $header = json_decode(base64url_decode($h64), true);
    $payload = json_decode(base64url_decode($p64), true);
    $signature = base64url_decode($s64);
    $valid = hash_equals(hash_hmac('sha256', $h64.'.'.$p64, $secret, true), $signature);
    if (!$valid) return null;
    if (isset($payload['exp']) && time() > $payload['exp']) return null;
    return $payload;
}
