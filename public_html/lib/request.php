<?php
require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/logger.php';
// lib/request.php - helper to get sanitized inputs and CSRF token
session_start();

function req_json() {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function req_post($key, $filter = FILTER_SANITIZE_STRING, $options = null) {
    $val = $_POST[$key] ?? null;
    if ($val === null) return null;
    return filter_var($val, $filter, $options);
}

function req_get($key, $filter = FILTER_SANITIZE_STRING, $options = null) {
    $val = $_GET[$key] ?? null;
    if ($val === null) return null;
    return filter_var($val, $filter, $options);
}

// CSRF token functions
function csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf_token'];
}
function csrf_check($token) {
    return hash_equals($_SESSION['csrf_token'] ?? '', $token ?? '');
}
