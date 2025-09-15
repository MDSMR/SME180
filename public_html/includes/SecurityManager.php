<?php
/**
 * Security Manager
 * Handles security monitoring, anomaly detection, and device management
 */

require_once __DIR__ . '/../config/db.php';

class SecurityManager {
    
    private $pdo;
    
    public function __construct() {
        $this->pdo = db();
    }
    
    /**
     * Get security metrics
     */
    public function getSecurityMetrics() {
        try {
            $metrics = [];
            
            // Failed logins in last 24 hours
            $stmt = $this->pdo->query("
                SELECT COUNT(*) as count 
                FROM audit_logs 
                WHERE action = 'failed_login' 
                AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ");
            $metrics['failed_logins_24h'] = $stmt->fetchColumn();
            
            // Active anomalies
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN severity = 'critical' THEN 1 ELSE 0 END) as critical
                FROM security_anomalies 
                WHERE resolved = 0
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['active_anomalies'] = $result['total'];
            $metrics['critical_anomalies'] = $result['critical'];
            
            // Trusted devices
            $stmt = $this->pdo->query("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN created_at > DATE_SUB(NOW(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as recent
                FROM user_devices 
                WHERE is_trusted = 1
            ");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $metrics['trusted_devices'] = $result['total'];
            $metrics['devices_last_30d'] = $result['recent'];
            
            // Blocked IPs (would need a blocked_ips table)
            $metrics['blocked_ips'] = 0;
            $metrics['blocks_last_7d'] = 0;
            
            return $metrics;
            
        } catch (Exception $e) {
            error_log('Error fetching security metrics: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get recent security anomalies
     */
    public function getRecentAnomalies($limit = 20) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    sa.*,
                    t.name as tenant_name,
                    u.username
                FROM security_anomalies sa
                LEFT JOIN tenants t ON sa.tenant_id = t.id
                LEFT JOIN users u ON sa.user_id = u.id
                WHERE sa.resolved = 0
                ORDER BY 
                    FIELD(sa.severity, 'critical', 'high', 'medium', 'low'),
                    sa.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('Error fetching anomalies: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Detect and log security anomalies
     */
    public function detectAnomalies() {
        try {
            // Check for multiple failed logins from same IP
            $stmt = $this->pdo->query("
                SELECT 
                    ip_address,
                    COUNT(*) as attempts,
                    MAX(tenant_id) as tenant_id,
                    MAX(user_id) as user_id
                FROM audit_logs
                WHERE action = 'failed_login'
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                GROUP BY ip_address
                HAVING attempts > 5
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->createAnomaly(
                    'multiple_failures',
                    $row['tenant_id'],
                    $row['user_id'],
                    'high',
                    [
                        'ip_address' => $row['ip_address'],
                        'attempts' => $row['attempts']
                    ],
                    $row['ip_address']
                );
            }
            
            // Check for unusual login times
            $stmt = $this->pdo->query("
                SELECT 
                    user_id,
                    tenant_id,
                    ip_address,
                    HOUR(created_at) as login_hour
                FROM audit_logs
                WHERE action = 'login'
                    AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
                    AND (HOUR(created_at) < 6 OR HOUR(created_at) > 22)
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->createAnomaly(
                    'unusual_time',
                    $row['tenant_id'],
                    $row['user_id'],
                    'low',
                    ['login_hour' => $row['login_hour']],
                    $row['ip_address']
                );
            }
            
            // Check for rapid context switching
            $stmt = $this->pdo->query("
                SELECT 
                    user_id,
                    COUNT(DISTINCT tenant_id) as tenant_count,
                    COUNT(DISTINCT branch_id) as branch_count,
                    GROUP_CONCAT(DISTINCT tenant_id) as tenants
                FROM audit_logs
                WHERE action IN ('login', 'branch_switch')
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                GROUP BY user_id
                HAVING tenant_count > 3 OR branch_count > 5
            ");
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->createAnomaly(
                    'suspicious_pattern',
                    null,
                    $row['user_id'],
                    'medium',
                    [
                        'tenant_count' => $row['tenant_count'],
                        'branch_count' => $row['branch_count'],
                        'tenants' => $row['tenants']
                    ]
                );
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Error detecting anomalies: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Create security anomaly record
     */
    private function createAnomaly($type, $tenantId, $userId, $severity, $details, $ipAddress = null) {
        try {
            // Check if similar anomaly already exists in last hour
            $stmt = $this->pdo->prepare("
                SELECT id FROM security_anomalies
                WHERE anomaly_type = :type
                    AND (tenant_id = :tenant_id OR (:tenant_id2 IS NULL AND tenant_id IS NULL))
                    AND (user_id = :user_id OR (:user_id2 IS NULL AND user_id IS NULL))
                    AND resolved = 0
                    AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)
                LIMIT 1
            ");
            
            $stmt->execute([
                ':type' => $type,
                ':tenant_id' => $tenantId,
                ':tenant_id2' => $tenantId,
                ':user_id' => $userId,
                ':user_id2' => $userId
            ]);
            
            if ($stmt->fetch()) {
                return; // Similar anomaly already logged
            }
            
            // Insert new anomaly
            $stmt = $this->pdo->prepare("
                INSERT INTO security_anomalies 
                (tenant_id, user_id, anomaly_type, severity, details, ip_address, user_agent)
                VALUES (:tenant_id, :user_id, :type, :severity, :details, :ip, :ua)
            ");
            
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':user_id' => $userId,
                ':type' => $type,
                ':severity' => $severity,
                ':details' => json_encode($details),
                ':ip' => $ipAddress,
                ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            // Create notification for critical anomalies
            if ($severity === 'critical' || $severity === 'high') {
                $this->createNotification($type, $severity, $tenantId, $userId);
            }
            
        } catch (Exception $e) {
            error_log('Error creating anomaly: ' . $e->getMessage());
        }
    }
    
    /**
     * Resolve security anomaly
     */
    public function resolveAnomaly($anomalyId, $resolvedBy) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE security_anomalies 
                SET resolved = 1,
                    resolved_by = :resolved_by,
                    resolved_at = NOW()
                WHERE id = :id
            ");
            
            return $stmt->execute([
                ':id' => $anomalyId,
                ':resolved_by' => $resolvedBy
            ]);
            
        } catch (Exception $e) {
            error_log('Error resolving anomaly: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get trusted devices
     */
    public function getTrustedDevices($limit = 50) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    ud.*,
                    u.username,
                    t.name as tenant_name
                FROM user_devices ud
                JOIN users u ON ud.user_id = u.id
                JOIN tenants t ON ud.tenant_id = t.id
                WHERE ud.is_trusted = 1
                ORDER BY ud.last_used DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('Error fetching devices: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Revoke device trust
     */
    public function revokeDevice($deviceId) {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE user_devices 
                SET is_trusted = 0,
                    trust_revoked_at = NOW()
                WHERE id = :id
            ");
            
            return $stmt->execute([':id' => $deviceId]);
            
        } catch (Exception $e) {
            error_log('Error revoking device: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get failed login attempts
     */
    public function getFailedLoginAttempts($limit = 20) {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    al.ip_address,
                    al.created_at,
                    JSON_EXTRACT(al.details, '$.username') as username,
                    t.name as tenant_name
                FROM audit_logs al
                LEFT JOIN tenants t ON al.tenant_id = t.id
                WHERE al.action = 'failed_login'
                ORDER BY al.created_at DESC
                LIMIT :limit
            ");
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Clean up JSON extracted values
            foreach ($results as &$row) {
                $row['username'] = trim($row['username'], '"');
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log('Error fetching failed logins: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Block IP address
     */
    public function blockIpAddress($ipAddress, $reason = 'Manual block') {
        try {
            // This would interact with your firewall or .htaccess
            // For now, just log it
            $stmt = $this->pdo->prepare("
                INSERT INTO super_admin_logs 
                (admin_id, action, details, ip_address)
                VALUES (:admin_id, 'block_ip', :details, :ip)
            ");
            
            $stmt->execute([
                ':admin_id' => $_SESSION['super_admin']['id'] ?? 0,
                ':details' => json_encode(['blocked_ip' => $ipAddress, 'reason' => $reason]),
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? ''
            ]);
            
            // You would implement actual IP blocking here
            // e.g., write to .htaccess or firewall rules
            
            return true;
            
        } catch (Exception $e) {
            error_log('Error blocking IP: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check rate limits
     */
    public function checkRateLimit($identifier, $action, $limit = 10, $window = 60) {
        try {
            $key = "rate_limit:{$action}:{$identifier}";
            
            // This would use Redis or similar for production
            // For now, check database
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) 
                FROM audit_logs 
                WHERE ip_address = :identifier
                    AND action = :action
                    AND created_at > DATE_SUB(NOW(), INTERVAL :window SECOND)
            ");
            
            $stmt->execute([
                ':identifier' => $identifier,
                ':action' => $action,
                ':window' => $window
            ]);
            
            $count = $stmt->fetchColumn();
            
            if ($count >= $limit) {
                // Log rate limit violation
                $this->createAnomaly(
                    'rate_limit',
                    null,
                    null,
                    'high',
                    [
                        'identifier' => $identifier,
                        'action' => $action,
                        'count' => $count,
                        'limit' => $limit
                    ],
                    $identifier
                );
                
                return false;
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Error checking rate limit: ' . $e->getMessage());
            return true; // Fail open
        }
    }
    
    /**
     * Create security notification
     */
    private function createNotification($type, $severity, $tenantId = null, $userId = null) {
        try {
            $title = 'Security Alert: ' . ucwords(str_replace('_', ' ', $type));
            $message = "A {$severity} severity security event has been detected.";
            
            if ($tenantId) {
                $message .= " Tenant ID: {$tenantId}.";
            }
            if ($userId) {
                $message .= " User ID: {$userId}.";
            }
            
            $stmt = $this->pdo->prepare("
                INSERT INTO system_notifications 
                (recipient_type, notification_type, category, title, message, priority)
                VALUES ('super_admin', 'alert', 'security', :title, :message, :priority)
            ");
            
            $priority = ($severity === 'critical') ? 'urgent' : (($severity === 'high') ? 'high' : 'normal');
            
            $stmt->execute([
                ':title' => $title,
                ':message' => $message,
                ':priority' => $priority
            ]);
            
        } catch (Exception $e) {
            error_log('Error creating security notification: ' . $e->getMessage());
        }
    }
}