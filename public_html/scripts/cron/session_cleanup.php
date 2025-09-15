#!/usr/bin/php
<?php
// /scripts/cron/session_cleanup.php
// Run every 10 minutes: */10 * * * * /usr/bin/php /path/to/session_cleanup.php

require_once __DIR__ . '/../../config/db.php';

try {
    $pdo = db();
    
    // Delete sessions older than 30 minutes of inactivity
    $expiry = time() - 1800;
    
    $stmt = $pdo->prepare("
        DELETE FROM app_sessions 
        WHERE last_activity < ?
    ");
    
    $stmt->execute([$expiry]);
    $deleted = $stmt->rowCount();
    
    // Also clean up rate limits older than 1 hour
    $pdo->exec("
        DELETE FROM rate_limits 
        WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)
    ");
    
    // Clean up old audit logs (keep 90 days)
    $pdo->exec("
        DELETE FROM audit_logs 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
    
    // Log cleanup
    echo date('Y-m-d H:i:s') . " - Cleaned {$deleted} expired sessions\n";
    
} catch (Exception $e) {
    error_log('Session cleanup failed: ' . $e->getMessage());
    exit(1);
}