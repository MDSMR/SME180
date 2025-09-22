<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Try to get $pdo from common includes
$pdo = $pdo ?? null;
$include_candidates = [
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../config.php',
    __DIR__ . '/../bootstrap.php',
];
foreach ($include_candidates as $inc) {
    if (file_exists($inc)) require_once $inc;
}
if ($pdo instanceof PDO) return;

// Read .env from _config/.env then .env
function _read_env($path) {
    if (!file_exists($path) || !is_readable($path)) return [];
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#') continue;
        [$k,$v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k); $v = trim($v);
        if ($v !== '' && (($v[0] === '"' && substr($v,-1)==='"') || ($v[0]==="'" && substr($v,-1)==="'"))) $v = substr($v,1,-1);
        if ($k !== '') $out[$k] = $v;
    }
    return $out;
}
$project_root = realpath(__DIR__.'/..') ?: dirname(__DIR__);
$env = array_merge(
    _read_env($project_root.'/_config/.env'),
    _read_env($project_root.'/.env')
);

$db_host = $env['DB_HOST'] ?? (defined('DB_HOST')?DB_HOST:'localhost');
$db_name = $env['DB_NAME'] ?? (defined('DB_NAME')?DB_NAME:'');
$db_user = $env['DB_USER'] ?? (defined('DB_USER')?DB_USER:'');
$db_pass = $env['DB_PASS'] ?? (defined('DB_PASS')?DB_PASS:'');
$db_charset = $env['DB_CHARSET'] ?? 'utf8mb4';

if ($db_name === '' || $db_user === '') {
    http_response_code(500);
    echo "<h1>Database not initialized</h1><p>Missing DB_NAME or DB_USER. Checked includes/db.php, _config/.env, .env.</p>";
    exit;
}
try {
    $dsn = "mysql:host={$db_host};dbname={$db_name};charset={$db_charset}";
    $pdo = new PDO($dsn, $db_user, $db_pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Database connection failed</h1><pre>".htmlspecialchars($e->getMessage())."</pre>";
    exit;
}
