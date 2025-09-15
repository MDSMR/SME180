<?php
declare(strict_types=1);

/**
 * Common helpers for POS APIs
 * - Parses JSON body, then POST, then GET
 * - Unified JSON response
 * - ?debug=1 only when APP_DEBUG is true
 */

$__APP_DEBUG = false;
$__cfg = __DIR__ . '/../../config/app.php';
if (file_exists($__cfg)) {
    require_once $__cfg;
    if (defined('APP_DEBUG')) $__APP_DEBUG = (bool)APP_DEBUG;
}

header('Content-Type: application/json; charset=utf-8');

function read_input(): array {
    $raw = file_get_contents('php://input');
    $data = null;

    if ($raw !== false && $raw !== '') {
        $tmp = json_decode($raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $data = $tmp;
    }
    if ($data === null && !empty($_POST)) $data = $_POST;
    if ($data === null && !empty($_GET))  $data = $_GET;

    return is_array($data) ? $data : [];
}

function respond(bool $success, $payload = null, int $status = 200): void {
    http_response_code($status);
    echo json_encode($success ? ['success'=>true,'data'=>$payload]
                              : ['success'=>false,'error'=>(string)$payload],
                     JSON_UNESCAPED_UNICODE);
    exit;
}

function require_fields(array $input, array $fields): void {
    foreach ($fields as $f) {
        if (!array_key_exists($f, $input) || $input[$f] === '' || $input[$f] === null) {
            respond(false, "Missing {$f}", 400);
        }
    }
}

// Debug view only if APP_DEBUG
if ($__APP_DEBUG && isset($_GET['debug']) && $_GET['debug'] === '1') {
    $raw = file_get_contents('php://input');
    echo json_encode([
        'success' => true,
        'data' => [
            'method'       => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'raw_body'     => $raw,
            'parsed'       => read_input(),
            'server_time'  => date('c'),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}