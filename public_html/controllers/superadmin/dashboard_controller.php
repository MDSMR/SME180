<?php
/**
 * Super Admin Dashboard Controller
 * Handles all dashboard data fetching and metrics
 */

// FIXED: Changed from /includes/db.php to /config/db.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/AuditLogger.php';

class SuperAdminDashboardController {
    
    private $pdo;
    
    public function __construct() {
        $this->pdo = db();
    }
    
    /**
     * Get main dashboard KPIs
     */
    public function getKPIs() {
        try {
            $kpis = [];
            
            // Total tenants by status
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN subscription_status = 'active' THEN 1 ELSE 0 END) as active,
                    SUM(CASE WHEN subscription_status = 'trial' THEN 1 ELSE 0 END) as trial,
                    SUM(CASE WHEN subscription_status = 'suspended' THEN 1 ELSE 0 END) as suspended,
                    SUM(CASE WHEN payment_status = 'overdue' THEN 1 ELSE 0 END) as overdue
                FROM tenants
            ");
            $kpis['tenants'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Total users and branches
            $stmt = $this->pdo->query("
                SELECT 
                    (SELECT COUNT(*) FROM users WHERE disabled_at IS NULL) as total_users,
                    (SELECT COUNT(*) FROM branches WHERE is_active = 1) as total_branches,
                    (SELECT COUNT(*) FROM users WHERE DATE(last_login) = CURDATE()) as active_today
            ");
            $kpis['users'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Today's revenue
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(DISTINCT o.id) as orders_today,
                    COALESCE(SUM(o.total), 0) as revenue_today,
                    COUNT(DISTINCT o.tenant_id) as active_tenants_today
                FROM orders o
                WHERE DATE(o.created_at) = CURDATE()
                    AND o.status IN ('completed', 'paid')
            ");
            $kpis['today'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // System health
            $stmt = $this->pdo->query("
                SELECT 
                    (SELECT COUNT(*) FROM system_health_checks WHERE status != 'healthy' AND last_check > DATE_SUB(NOW(), INTERVAL 1 HOUR)) as unhealthy_services,
                    (SELECT COUNT(*) FROM security_anomalies WHERE resolved = 0 AND severity IN ('high', 'critical')) as security_alerts,
                    (SELECT COUNT(*) FROM cron_jobs WHERE last_status = 'failed' AND last_run > DATE_SUB(NOW(), INTERVAL 24 HOUR)) as failed_jobs
            ");
            $kpis['system'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Subscription metrics
            $stmt = $this->pdo->query("
                SELECT 
                    (SELECT COUNT(*) FROM tenants WHERE subscription_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)) as expiring_soon,
                    (SELECT COALESCE(SUM(total_amount), 0) FROM invoices WHERE status = 'overdue') as overdue_amount,
                    (SELECT COUNT(*) FROM invoices WHERE status IN ('draft', 'sent') AND due_date < CURDATE()) as overdue_invoices
            ");
            $kpis['billing'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $kpis;
        } catch (Exception $e) {
            error_log('Error fetching KPIs: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get system alerts
     */
    public function getAlerts($limit = 10) {
        $alerts = [];
        
        try {
            // Critical system alerts
            $stmt = $this->pdo->prepare("
                SELECT 
                    'system' as type,
                    'critical' as severity,
                    CONCAT('Service ', service_name, ' is ', status) as message,
                    last_check as timestamp,
                    JSON_OBJECT('service', service_name, 'status', status, 'error', error_message) as details
                FROM system_health_checks
                WHERE status IN ('down', 'degraded')
                    AND last_check > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                ORDER BY last_check DESC
                LIMIT 5
            ");
            $stmt->execute();
            $systemAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Security anomalies
            $stmt = $this->pdo->prepare("
                SELECT 
                    'security' as type,
                    severity,
                    CONCAT('Security anomaly: ', anomaly_type, ' for tenant #', tenant_id) as message,
                    created_at as timestamp,
                    details
                FROM security_anomalies
                WHERE resolved = 0
                    AND severity IN ('high', 'critical')
                ORDER BY created_at DESC
                LIMIT 5
            ");
            $stmt->execute();
            $securityAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Subscription alerts
            $stmt = $this->pdo->prepare("
                SELECT 
                    'billing' as type,
                    'warning' as severity,
                    CONCAT('Tenant \"', name, '\" subscription expires in ', 
                           DATEDIFF(subscription_expires_at, NOW()), ' days') as message,
                    NOW() as timestamp,
                    JSON_OBJECT('tenant_id', id, 'tenant_name', name, 'expires_at', subscription_expires_at) as details
                FROM tenants
                WHERE subscription_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)
                ORDER BY subscription_expires_at ASC
                LIMIT 5
            ");
            $stmt->execute();
            $billingAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Failed cron jobs
            $stmt = $this->pdo->prepare("
                SELECT 
                    'cron' as type,
                    'warning' as severity,
                    CONCAT('Cron job \"', job_name, '\" failed') as message,
                    last_run as timestamp,
                    JSON_OBJECT('job', job_name, 'output', last_output) as details
                FROM cron_jobs
                WHERE last_status = 'failed'
                    AND last_run > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                ORDER BY last_run DESC
                LIMIT 5
            ");
            $stmt->execute();
            $cronAlerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Merge and sort all alerts
            $alerts = array_merge($systemAlerts, $securityAlerts, $billingAlerts, $cronAlerts);
            usort($alerts, function($a, $b) {
                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
            });
            
            return array_slice($alerts, 0, $limit);
            
        } catch (Exception $e) {
            error_log('Error fetching alerts: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent activity
     */
    public function getRecentActivity($limit = 20) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    sal.action,
                    sal.details,
                    sal.created_at,
                    sa.name as admin_name,
                    t.name as tenant_name
                FROM super_admin_logs sal
                LEFT JOIN super_admins sa ON sal.admin_id = sa.id
                LEFT JOIN tenants t ON sal.tenant_id = t.id
                ORDER BY sal.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error fetching activity: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get revenue chart data
     */
    public function getRevenueChart($days = 30) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    DATE(created_at) as date,
                    COUNT(DISTINCT id) as orders,
                    COALESCE(SUM(total), 0) as revenue,
                    COUNT(DISTINCT tenant_id) as active_tenants
                FROM orders
                WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
                    AND status IN ('completed', 'paid')
                GROUP BY DATE(created_at)
                ORDER BY date ASC
            ");
            $stmt->bindValue(':days', $days, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error fetching revenue chart: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get tenant distribution by plan
     */
    public function getTenantDistribution() {
        try {
            $stmt = $this->pdo->query("
                SELECT 
                    subscription_plan as plan,
                    COUNT(*) as count,
                    SUM(CASE WHEN subscription_status = 'active' THEN 1 ELSE 0 END) as active
                FROM tenants
                GROUP BY subscription_plan
            ");
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error fetching tenant distribution: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get system notifications for super admin
     */
    public function getNotifications($adminId, $unreadOnly = false) {
        try {
            $sql = "
                SELECT 
                    id,
                    notification_type,
                    category,
                    title,
                    message,
                    action_url,
                    is_read,
                    priority,
                    created_at
                FROM system_notifications
                WHERE (recipient_type = 'super_admin' OR 
                       (recipient_type = 'specific_user' AND recipient_id = :admin_id))
                    AND (expires_at IS NULL OR expires_at > NOW())
            ";
            
            if ($unreadOnly) {
                $sql .= " AND is_read = 0";
            }
            
            $sql .= " ORDER BY priority DESC, created_at DESC LIMIT 20";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':admin_id', $adminId, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log('Error fetching notifications: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Mark notification as read
     */
    public function markNotificationRead($notificationId, $adminId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE system_notifications 
                SET is_read = 1, read_at = NOW()
                WHERE id = :id 
                    AND (recipient_type = 'super_admin' OR 
                         (recipient_type = 'specific_user' AND recipient_id = :admin_id))
            ");
            $stmt->execute([
                ':id' => $notificationId,
                ':admin_id' => $adminId
            ]);
            
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            error_log('Error marking notification read: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create system notification
     */
    public function createNotification($type, $category, $title, $message, $priority = 'normal', $actionUrl = null) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO system_notifications 
                (recipient_type, notification_type, category, title, message, action_url, priority)
                VALUES ('super_admin', :type, :category, :title, :message, :action_url, :priority)
            ");
            
            $stmt->execute([
                ':type' => $type,
                ':category' => $category,
                ':title' => $title,
                ':message' => $message,
                ':action_url' => $actionUrl,
                ':priority' => $priority
            ]);
            
            return $this->pdo->lastInsertId();
        } catch (Exception $e) {
            error_log('Error creating notification: ' . $e->getMessage());
            return false;
        }
    }
}