#!/usr/bin/php
<?php
require_once '/home/customer/www/mohamedk10.sg-host.com/public_html/config/db.php';

$pdo = db();

// Clean expired sessions (30 min)
$pdo->exec("DELETE FROM app_sessions WHERE last_activity < " . (time() - 1800));

// Clean old rate limits (1 hour)
$pdo->exec("DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)");

// Clean old audit logs (90 days)
$pdo->exec("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");

echo date('Y-m-d H:i:s') . " - Cleanup complete\n";