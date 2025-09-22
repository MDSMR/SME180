<?php
declare(strict_types=1);

// Shim that locates the REAL loader inside _config/env.php

$tries = [];
$tries[] = dirname(__DIR__, 2) . '/_config/env.php'; // /public_html/config → project root
$tries[] = dirname(__DIR__) . '/_config/env.php';
if ($p = getenv('APP_ENV_DIR')) $tries[] = rtrim($p, '/').'/env.php';

$loader = null;
foreach ($tries as $path) {
    if (is_readable($path)) { $loader = $path; break; }
}

if (!$loader) {
    http_response_code(500);
    echo "<h1>Config error</h1><p>Could not locate private _config/env.php</p>";
    if (ini_get('display_errors')) {
        echo "<pre>Tried:\n" . htmlspecialchars(implode("\n", $tries)) . "</pre>";
    }
    exit;
}

require_once $loader;

// (Optional) expose for diagnostics
if (!defined('SMORLL_ENV_LOADER_SHIM')) define('SMORLL_ENV_LOADER_SHIM', $loader);