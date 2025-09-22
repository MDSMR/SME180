<?php
declare(strict_types=1);
require_once __DIR__ . '/config/env.php';
header('Content-Type: text/plain; charset=utf-8');

function mask($v){$len=strlen((string)$v);return $len<=4?str_repeat('*',$len):substr($v,0,2).str_repeat('*',max(0,$len-4)).substr($v,-2);}

echo "=== ENV DIAGNOSTICS ===\n";
echo "Loader (shim resolved): " . (defined('SMORLL_ENV_LOADER_SHIM') ? SMORLL_ENV_LOADER_SHIM : 'N/A') . "\n";
echo "Private loader file:    " . (defined('SMORLL_ENV_LOADER') ? SMORLL_ENV_LOADER : 'N/A') . "\n";
echo "Private .env file:      " . (defined('SMORLL_ENV_FILE') ? SMORLL_ENV_FILE : 'N/A') . "\n";

$envFile = defined('SMORLL_ENV_FILE') ? SMORLL_ENV_FILE : null;
echo "\n[File checks]\n";
echo "exists:      " . ($envFile && file_exists($envFile) ? 'yes' : 'no') . "\n";
echo "is_readable: " . ($envFile && is_readable($envFile) ? 'yes' : 'no') . "\n";
$size = ($envFile && @is_readable($envFile)) ? filesize($envFile) : -1;
echo "size_bytes:  " . $size . "\n";

echo "\n[Values]\n";
echo "DB_HOST: " . DB_HOST . "\n";
echo "DB_NAME: " . DB_NAME . "\n";
echo "DB_USER: " . DB_USER . "\n";
echo "DB_PASS: " . mask(DB_PASS) . "  (masked)\n";
echo "JWT_SECRET length: " . strlen(JWT_SECRET) . "\n";