<?php
// /includes/AuditLogger.php - Audit Logging System
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

class AuditLogger {
    
    /**
     * Log an action to the audit_logs table
     * 
     * @param string $action The action being performed
     * @param array $details Additional details about the action
     * @param int|null $user_id Override the current user ID
     * @param int|null $tenant_id Override the current tenant ID
     * @param int|null $branch_id Override the current branch ID
     * @return bool Success status
     */
    public static function log(string $action, array $details = [], ?int $user_id = null, ?int $tenant_id = null, ?int $branch_id = null): bool {
        try {
            $pdo = db();
            
            // Use session values if not provided
            $user_id = $user_id ?? get_user_id();
            $tenant_id = $tenant_id ?? get_tenant_id();
            $branch_id = $branch_id ?? get_branch_id();
            
            // For certain actions, we might not have a user_id yet (like failed login)
            // In these cases, use 0 as a placeholder
            if ($user_id === null && in_array($action, ['failed_login', 'login_attempt'])) {
                $user_id = 0;
            }
            
            // Skip if essential data is missing (except for special cases)
            if ($user_id === null || $tenant_id === null) {
                error_log("AuditLogger: Missing required data - user_id: $user_id, tenant_id: $tenant_id");
                return false;
            }
            
            // Get client information
            $ip_address = get_client_ip();
            $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
            
            // Prepare the SQL statement
            $sql = "INSERT INTO audit_logs (user_id, tenant_id, branch_id, action, details, ip_address, user_agent) 
                    VALUES (:user_id, :tenant_id, :branch_id, :action, :details, :ip_address, :user_agent)";
            
            $stmt = $pdo->prepare($sql);
            
            // Convert details array to JSON
            $details_json = !empty($details) ? json_encode($details) : null;
            
            // Execute the statement
            $result = $stmt->execute([
                ':user_id' => $user_id,
                ':tenant_id' => $tenant_id,
                ':branch_id' => $branch_id,
                ':action' => $action,
                ':details' => $details_json,
                ':ip_address' => $ip_address,
                ':user_agent' => $user_agent
            ]);
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Audit logging failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log a login attempt
     * 
     * @param string $username The username attempting to login
     * @param bool $success Whether the login was successful
     * @param int|null $user_id The user ID if login was successful
     * @param int|null $tenant_id The tenant ID
     * @param int|null $branch_id The branch ID
     * @return bool Success status
     */
    public static function logLogin(string $username, bool $success, ?int $user_id = null, ?int $tenant_id = null, ?int $branch_id = null): bool {
        $details = [
            'username' => $username,
            'success' => $success,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        if (!$success) {
            $details['failure_reason'] = 'Invalid credentials';
            $action = 'failed_login';
            // For failed logins, use user_id = 0 if not provided
            $user_id = $user_id ?? 0;
        } else {
            $action = 'login';
            $_SESSION['login_time'] = time(); // Store login time for session duration tracking
        }
        
        return self::log($action, $details, $user_id, $tenant_id, $branch_id);
    }
    
    /**
     * Log a logout action
     * 
     * @param array $details Additional logout details
     * @return bool Success status
     */
    public static function logLogout(array $details = []): bool {
        // Calculate session duration if login_time is available
        if (isset($_SESSION['login_time'])) {
            $details['session_duration_seconds'] = time() - $_SESSION['login_time'];
            $details['session_duration_formatted'] = self::formatDuration($details['session_duration_seconds']);
        }
        
        $details['timestamp'] = date('Y-m-d H:i:s');
        
        return self::log('logout', $details);
    }
    
    /**
     * Log a branch switch
     * 
     * @param int|null $old_branch_id The previous branch ID
     * @param int $new_branch_id The new branch ID
     * @return bool Success status
     */
    public static function logBranchSwitch(?int $old_branch_id, int $new_branch_id): bool {
        $details = [
            'old_branch_id' => $old_branch_id,
            'new_branch_id' => $new_branch_id,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Get branch names for better readability in logs
        try {
            $pdo = db();
            
            if ($old_branch_id) {
                $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = :id");
                $stmt->execute([':id' => $old_branch_id]);
                $result = $stmt->fetch();
                if ($result) {
                    $details['old_branch_name'] = $result['branch_name'];
                }
            }
            
            $stmt = $pdo->prepare("SELECT branch_name FROM branches WHERE id = :id");
            $stmt->execute([':id' => $new_branch_id]);
            $result = $stmt->fetch();
            if ($result) {
                $details['new_branch_name'] = $result['branch_name'];
            }
        } catch (Exception $e) {
            // Continue even if we can't get branch names
        }
        
        return self::log('branch_switch', $details, null, null, $new_branch_id);
    }
    
    /**
     * Log a critical action (create, update, delete operations)
     * 
     * @param string $operation The operation type (create, update, delete)
     * @param string $entity The entity being operated on
     * @param mixed $entity_id The ID of the entity
     * @param array $additional_details Additional operation details
     * @return bool Success status
     */
    public static function logCriticalAction(string $operation, string $entity, $entity_id, array $additional_details = []): bool {
        $details = array_merge([
            'operation' => $operation,
            'entity' => $entity,
            'entity_id' => $entity_id,
            'timestamp' => date('Y-m-d H:i:s')
        ], $additional_details);
        
        $action = strtolower($operation) . '_' . strtolower($entity);
        
        return self::log($action, $details);
    }
    
    /**
     * Log data export action
     * 
     * @param string $export_type Type of export (pdf, excel, csv, etc.)
     * @param string $data_type Type of data exported (users, products, etc.)
     * @param int $record_count Number of records exported
     * @param array $filters Any filters applied
     * @return bool Success status
     */
    public static function logDataExport(string $export_type, string $data_type, int $record_count, array $filters = []): bool {
        $details = [
            'export_type' => $export_type,
            'data_type' => $data_type,
            'record_count' => $record_count,
            'filters' => $filters,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        return self::log('data_export', $details);
    }
    
    /**
     * Log permission change
     * 
     * @param int $target_user_id User whose permissions are changing
     * @param string $old_role Old role
     * @param string $new_role New role
     * @return bool Success status
     */
    public static function logPermissionChange(int $target_user_id, string $old_role, string $new_role): bool {
        $details = [
            'target_user_id' => $target_user_id,
            'old_role' => $old_role,
            'new_role' => $new_role,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
        // Get target user's name for better readability
        try {
            $pdo = db();
            $stmt = $pdo->prepare("SELECT username FROM users WHERE id = :id");
            $stmt->execute([':id' => $target_user_id]);
            $result = $stmt->fetch();
            if ($result) {
                $details['target_username'] = $result['username'];
            }
        } catch (Exception $e) {
            // Continue even if we can't get username
        }
        
        return self::log('permission_change', $details);
    }
    
    /**
     * Get audit logs for a specific tenant with pagination
     * 
     * @param int $tenant_id The tenant ID
     * @param int $limit Number of records to retrieve
     * @param int $offset Offset for pagination
     * @param array $filters Additional filters (user_id, action, date_from, date_to)
     * @return array The audit logs
     */
    public static function getAuditLogs(int $tenant_id, int $limit = 50, int $offset = 0, array $filters = []): array {
        try {
            $pdo = db();
            
            $where_conditions = ['al.tenant_id = :tenant_id'];
            $params = [':tenant_id' => $tenant_id];
            
            if (!empty($filters['user_id'])) {
                $where_conditions[] = 'al.user_id = :user_id';
                $params[':user_id'] = $filters['user_id'];
            }
            
            if (!empty($filters['branch_id'])) {
                $where_conditions[] = 'al.branch_id = :branch_id';
                $params[':branch_id'] = $filters['branch_id'];
            }
            
            if (!empty($filters['action'])) {
                $where_conditions[] = 'al.action = :action';
                $params[':action'] = $filters['action'];
            }
            
            if (!empty($filters['date_from'])) {
                $where_conditions[] = 'al.created_at >= :date_from';
                $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
            }
            
            if (!empty($filters['date_to'])) {
                $where_conditions[] = 'al.created_at <= :date_to';
                $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            
            // Get total count
            $count_sql = "SELECT COUNT(*) as total FROM audit_logs al WHERE $where_clause";
            $count_stmt = $pdo->prepare($count_sql);
            $count_stmt->execute($params);
            $total = $count_stmt->fetch()['total'];
            
            // Get logs with user and branch information
            $sql = "SELECT 
                        al.*,
                        u.username,
                        b.branch_name
                    FROM audit_logs al
                    LEFT JOIN users u ON al.user_id = u.id
                    LEFT JOIN branches b ON al.branch_id = b.id
                    WHERE $where_clause
                    ORDER BY al.created_at DESC
                    LIMIT :limit OFFSET :offset";
            
            $stmt = $pdo->prepare($sql);
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            
            $stmt->execute();
            $logs = $stmt->fetchAll();
            
            // Decode JSON details
            foreach ($logs as &$log) {
                if ($log['details']) {
                    $log['details'] = json_decode($log['details'], true);
                }
                // Format the timestamp
                $log['created_at_formatted'] = date('Y-m-d H:i:s', strtotime($log['created_at']));
                
                // Add human-readable action name
                $log['action_display'] = self::getActionDisplayName($log['action']);
            }
            
            return [
                'logs' => $logs,
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'pages' => ceil($total / $limit)
            ];
            
        } catch (Exception $e) {
            error_log("Failed to retrieve audit logs: " . $e->getMessage());
            return [
                'logs' => [],
                'total' => 0,
                'limit' => $limit,
                'offset' => $offset,
                'pages' => 0,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get distinct actions for filtering
     * 
     * @param int $tenant_id
     * @return array
     */
    public static function getDistinctActions(int $tenant_id): array {
        try {
            $pdo = db();
            $sql = "SELECT DISTINCT action FROM audit_logs WHERE tenant_id = :tenant_id ORDER BY action";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':tenant_id' => $tenant_id]);
            
            $actions = [];
            while ($row = $stmt->fetch()) {
                $actions[] = [
                    'value' => $row['action'],
                    'display' => self::getActionDisplayName($row['action'])
                ];
            }
            
            return $actions;
        } catch (Exception $e) {
            error_log("Failed to get distinct actions: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Format duration in seconds to human-readable format
     * 
     * @param int $seconds
     * @return string
     */
    private static function formatDuration(int $seconds): string {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $seconds = $seconds % 60;
        
        $parts = [];
        if ($hours > 0) {
            $parts[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
        if ($minutes > 0) {
            $parts[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }
        if ($seconds > 0 || empty($parts)) {
            $parts[] = $seconds . ' second' . ($seconds != 1 ? 's' : '');
        }
        
        return implode(', ', $parts);
    }
    
    /**
     * Get human-readable action display name
     * 
     * @param string $action
     * @return string
     */
    private static function getActionDisplayName(string $action): string {
        $action_map = [
            'login' => 'User Login',
            'logout' => 'User Logout',
            'failed_login' => 'Failed Login Attempt',
            'branch_switch' => 'Branch Switch',
            'create_user' => 'Create User',
            'update_user' => 'Update User',
            'delete_user' => 'Delete User',
            'create_product' => 'Create Product',
            'update_product' => 'Update Product',
            'delete_product' => 'Delete Product',
            'create_order' => 'Create Order',
            'update_order' => 'Update Order',
            'delete_order' => 'Delete Order',
            'data_export' => 'Data Export',
            'permission_change' => 'Permission Change',
            'password_reset' => 'Password Reset',
            'settings_update' => 'Settings Update'
        ];
        
        return $action_map[$action] ?? ucwords(str_replace('_', ' ', $action));
    }
}
?>