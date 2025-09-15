<?php
/**
 * Subscription Enforcement Cron Job
 * Runs daily to check and enforce subscription limits
 * Schedule: 0 3 * * * (3 AM daily)
 */

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/BillingManager.php';
require_once __DIR__ . '/../../includes/SecurityManager.php';

// Log start
error_log('[CRON] Subscription enforcement started at ' . date('Y-m-d H:i:s'));

try {
    $pdo = db();
    $billing = new BillingManager();
    $security = new SecurityManager();
    
    // Update cron status
    $stmt = $pdo->prepare("
        UPDATE cron_jobs 
        SET last_status = 'running', last_run = NOW()
        WHERE job_name = 'subscription_enforce'
    ");
    $stmt->execute();
    
    // Run enforcement
    $billing->enforceSubscriptionLimits();
    
    // Check for anomalies
    $security->detectAnomalies();
    
    // Clean up old records
    cleanupOldRecords($pdo);
    
    // Update cron status
    $stmt = $pdo->prepare("
        UPDATE cron_jobs 
        SET last_status = 'success', 
            last_run = NOW(),
            next_run = DATE_ADD(NOW(), INTERVAL 1 DAY),
            failure_count = 0
        WHERE job_name = 'subscription_enforce'
    ");
    $stmt->execute();
    
    error_log('[CRON] Subscription enforcement completed successfully');
    
} catch (Exception $e) {
    error_log('[CRON] Subscription enforcement failed: ' . $e->getMessage());
    
    // Update failure count
    $stmt = $pdo->prepare("
        UPDATE cron_jobs 
        SET last_status = 'failed',
            failure_count = failure_count + 1,
            last_output = :error
        WHERE job_name = 'subscription_enforce'
    ");
    $stmt->execute([':error' => $e->getMessage()]);
}

/**
 * Clean up old records
 */
function cleanupOldRecords($pdo) {
    // Clean old health checks
    $pdo->exec("
        DELETE FROM system_health_checks 
        WHERE last_check < DATE_SUB(NOW(), INTERVAL 7 DAY)
    ");
    
    // Clean old notifications
    $pdo->exec("
        DELETE FROM system_notifications 
        WHERE is_read = 1 AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    
    // Archive old audit logs
    $pdo->exec("
        DELETE FROM audit_logs 
        WHERE created_at < DATE_SUB(NOW(), INTERVAL 180 DAY)
    ");
}