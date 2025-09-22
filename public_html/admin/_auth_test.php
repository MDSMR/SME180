<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__ . '/../config/admin_auth.php';
header('Content-Type: text/plain; charset=utf-8');
echo "admin_require_auth exists? " . (function_exists('admin_require_auth') ? "YES\n" : "NO\n");
echo "Logged in admin_user_id: " . ($_SESSION['admin_user_id'] ?? 'none') . "\n";