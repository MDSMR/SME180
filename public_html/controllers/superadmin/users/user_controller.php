<?php
// /controllers/superadmin/users/user_controller.php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/config/db.php';

class UserController {
    private $pdo;
    private $admin_id;
    
    public function __construct() {
        $this->pdo = db();
        $this->admin_id = $_SESSION['super_admin_id'] ?? null;
    }
    
    /**
     * Create a new user
     */
    public function createUser(array $data): array {
        try {
            // Validate required fields
            $errors = $this->validateUserData($data);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }
            
            // Check username uniqueness across selected tenants
            if (!$this->isUsernameAvailable($data['username'], $data['tenants'], null)) {
                return ['success' => false, 'message' => 'Username already exists in one or more selected tenants'];
            }
            
            // Check tenant user limits
            foreach ($data['tenants'] as $tenant_id) {
                if (!$this->checkTenantUserLimit($tenant_id)) {
                    $tenant_name = $this->getTenantName($tenant_id);
                    return ['success' => false, 'message' => "Tenant '{$tenant_name}' has reached its user limit"];
                }
            }
            
            $this->pdo->beginTransaction();
            
            // Insert user
            $stmt = $this->pdo->prepare("
                INSERT INTO users (
                    tenant_id, name, username, email, password_hash, pass_code, 
                    role_key, user_type, created_at
                ) VALUES (
                    :tenant_id, :name, :username, :email, :password_hash, :pass_code,
                    :role_key, :user_type, NOW()
                )
            ");
            
            $stmt->execute([
                'tenant_id' => $data['primary_tenant'],
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?: null,
                'password_hash' => !empty($data['password']) ? password_hash($data['password'], PASSWORD_DEFAULT) : null,
                'pass_code' => !empty($data['pin']) ? password_hash($data['pin'], PASSWORD_DEFAULT) : null,
                'role_key' => $data['role_key'],
                'user_type' => $data['user_type']
            ]);
            
            $user_id = $this->pdo->lastInsertId();
            
            // Assign tenants
            $this->assignUserTenants($user_id, $data['tenants'], $data['primary_tenant']);
            
            // Assign branches
            if (!empty($data['branches'])) {
                $this->assignUserBranches($user_id, $data['branches']);
            }
            
            // Log creation
            $this->logAction('user_create', [
                'user_id' => $user_id,
                'username' => $data['username'],
                'tenants' => $data['tenants']
            ]);
            
            $this->pdo->commit();
            return ['success' => true, 'user_id' => $user_id];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => 'Error creating user: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update existing user
     */
    public function updateUser(int $user_id, array $data): array {
        try {
            // Validate required fields
            $errors = $this->validateUserData($data, $user_id);
            if (!empty($errors)) {
                return ['success' => false, 'errors' => $errors];
            }
            
            // Check username uniqueness
            if (!$this->isUsernameAvailable($data['username'], $data['tenants'], $user_id)) {
                return ['success' => false, 'message' => 'Username already exists in one or more selected tenants'];
            }
            
            $this->pdo->beginTransaction();
            
            // Build update query
            $update_fields = [
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'] ?: null,
                'user_type' => $data['user_type'],
                'role_key' => $data['role_key'],
                'tenant_id' => $data['primary_tenant']
            ];
            
            $sql = "UPDATE users SET 
                    name = :name,
                    username = :username,
                    email = :email,
                    user_type = :user_type,
                    role_key = :role_key,
                    tenant_id = :tenant_id";
            
            // Update password if provided
            if (!empty($data['password'])) {
                $sql .= ", password_hash = :password_hash";
                $update_fields['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
            }
            
            // Update PIN if provided
            if (!empty($data['pin']) && in_array($data['user_type'], ['pos', 'both'])) {
                $sql .= ", pass_code = :pass_code";
                $update_fields['pass_code'] = password_hash($data['pin'], PASSWORD_DEFAULT);
            }
            
            $sql .= " WHERE id = :id";
            $update_fields['id'] = $user_id;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($update_fields);
            
            // Update tenant assignments
            $this->pdo->prepare("DELETE FROM user_tenants WHERE user_id = ?")->execute([$user_id]);
            $this->assignUserTenants($user_id, $data['tenants'], $data['primary_tenant']);
            
            // Update branch assignments
            $this->pdo->prepare("DELETE FROM user_branches WHERE user_id = ?")->execute([$user_id]);
            if (!empty($data['branches'])) {
                $this->assignUserBranches($user_id, $data['branches']);
            }
            
            // Clear user sessions to force re-authentication
            $this->clearUserSessions($user_id);
            
            // Log update
            $this->logAction('user_update', [
                'user_id' => $user_id,
                'username' => $data['username'],
                'fields_updated' => array_keys($update_fields)
            ]);
            
            $this->pdo->commit();
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => 'Error updating user: ' . $e->getMessage()];
        }
    }
    
    /**
     * Enable or disable user
     */
    public function toggleUserStatus(int $user_id, string $action): array {
        try {
            if ($action === 'disable') {
                $stmt = $this->pdo->prepare("
                    UPDATE users 
                    SET disabled_at = NOW(), disabled_by = :admin_id 
                    WHERE id = :user_id
                ");
                $stmt->execute(['admin_id' => $this->admin_id, 'user_id' => $user_id]);
                
                // Clear all user sessions
                $this->clearUserSessions($user_id);
                
                $this->logAction('user_disable', ['user_id' => $user_id]);
                
            } else if ($action === 'enable') {
                $stmt = $this->pdo->prepare("
                    UPDATE users 
                    SET disabled_at = NULL, disabled_by = NULL 
                    WHERE id = :user_id
                ");
                $stmt->execute(['user_id' => $user_id]);
                
                $this->logAction('user_enable', ['user_id' => $user_id]);
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Reset user credentials
     */
    public function resetCredentials(int $user_id, array $data): array {
        try {
            $this->pdo->beginTransaction();
            
            $updates = [];
            $fields_reset = [];
            
            // Reset password
            if (!empty($data['password'])) {
                $updates['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
                $fields_reset[] = 'password';
            }
            
            // Reset PIN
            if (!empty($data['pin'])) {
                $updates['pass_code'] = password_hash($data['pin'], PASSWORD_DEFAULT);
                $fields_reset[] = 'pin';
            }
            
            if (empty($updates)) {
                return ['success' => false, 'message' => 'No credentials provided to reset'];
            }
            
            // Build update query
            $sql = "UPDATE users SET ";
            $parts = [];
            foreach ($updates as $field => $value) {
                $parts[] = "$field = :$field";
            }
            $sql .= implode(', ', $parts) . " WHERE id = :user_id";
            $updates['user_id'] = $user_id;
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($updates);
            
            // Clear user sessions
            $this->clearUserSessions($user_id);
            
            // Log reset
            $this->logAction('credentials_reset', [
                'user_id' => $user_id,
                'fields_reset' => $fields_reset
            ]);
            
            $this->pdo->commit();
            return ['success' => true, 'message' => 'Credentials reset successfully'];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Delete user (soft delete)
     */
    public function deleteUser(int $user_id): array {
        try {
            $this->pdo->beginTransaction();
            
            // Soft delete - set disabled and deleted flags
            $stmt = $this->pdo->prepare("
                UPDATE users 
                SET disabled_at = NOW(), 
                    disabled_by = :admin_id,
                    is_deleted = 1,
                    deleted_at = NOW(),
                    deleted_by = :admin_id2
                WHERE id = :user_id
            ");
            $stmt->execute([
                'admin_id' => $this->admin_id,
                'admin_id2' => $this->admin_id,
                'user_id' => $user_id
            ]);
            
            // Clear sessions
            $this->clearUserSessions($user_id);
            
            // Remove device tokens
            $this->pdo->prepare("DELETE FROM user_devices WHERE user_id = ?")->execute([$user_id]);
            
            $this->logAction('user_delete', ['user_id' => $user_id]);
            
            $this->pdo->commit();
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->pdo->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    /**
     * Get branches by tenant
     */
    public function getBranchesByTenant(int $tenant_id): array {
        $stmt = $this->pdo->prepare("
            SELECT id, name 
            FROM branches 
            WHERE tenant_id = :tenant_id AND is_active = 1 
            ORDER BY name
        ");
        $stmt->execute(['tenant_id' => $tenant_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Validate user data
     */
    private function validateUserData(array $data, ?int $user_id = null): array {
        $errors = [];
        
        if (empty($data['name'])) {
            $errors[] = 'Name is required';
        }
        
        if (empty($data['username'])) {
            $errors[] = 'Username is required';
        } else if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $data['username'])) {
            $errors[] = 'Username must be 3-50 characters and contain only letters, numbers, and underscores';
        }
        
        if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email format';
        }
        
        if (empty($data['role_key'])) {
            $errors[] = 'Role is required';
        }
        
        if (empty($data['user_type']) || !in_array($data['user_type'], ['backend', 'pos', 'both'])) {
            $errors[] = 'Invalid user type';
        }
        
        if (empty($data['tenants']) || !is_array($data['tenants'])) {
            $errors[] = 'At least one tenant must be selected';
        }
        
        if (empty($data['primary_tenant']) || !in_array($data['primary_tenant'], $data['tenants'] ?? [])) {
            $errors[] = 'Primary tenant must be selected from assigned tenants';
        }
        
        // For new users, password is required
        if (!$user_id && empty($data['password'])) {
            $errors[] = 'Password is required for new users';
        }
        
        // For POS users, PIN is required
        if (!$user_id && in_array($data['user_type'], ['pos', 'both']) && empty($data['pin'])) {
            $errors[] = 'PIN is required for POS users';
        }
        
        if (!empty($data['pin']) && !preg_match('/^[0-9]{4,6}$/', $data['pin'])) {
            $errors[] = 'PIN must be 4-6 digits';
        }
        
        return $errors;
    }
    
    /**
     * Check if username is available
     */
    private function isUsernameAvailable(string $username, array $tenant_ids, ?int $exclude_user_id = null): bool {
        $sql = "
            SELECT COUNT(*) 
            FROM users u
            JOIN user_tenants ut ON u.id = ut.user_id
            WHERE u.username = :username 
            AND ut.tenant_id IN (" . implode(',', array_map('intval', $tenant_ids)) . ")
        ";
        
        $params = ['username' => $username];
        
        if ($exclude_user_id) {
            $sql .= " AND u.id != :exclude_id";
            $params['exclude_id'] = $exclude_user_id;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchColumn() == 0;
    }
    
    /**
     * Check tenant user limit
     */
    private function checkTenantUserLimit(int $tenant_id): bool {
        $stmt = $this->pdo->prepare("
            SELECT t.max_users,
                   (SELECT COUNT(*) FROM user_tenants ut 
                    JOIN users u ON ut.user_id = u.id 
                    WHERE ut.tenant_id = t.id 
                    AND u.disabled_at IS NULL) as current_users
            FROM tenants t
            WHERE t.id = :tenant_id
        ");
        $stmt->execute(['tenant_id' => $tenant_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return false;
        }
        
        return $result['current_users'] < $result['max_users'];
    }
    
    /**
     * Get tenant name
     */
    private function getTenantName(int $tenant_id): string {
        $stmt = $this->pdo->prepare("SELECT name FROM tenants WHERE id = ?");
        $stmt->execute([$tenant_id]);
        return $stmt->fetchColumn() ?: 'Unknown';
    }
    
    /**
     * Assign user to tenants
     */
    private function assignUserTenants(int $user_id, array $tenant_ids, int $primary_tenant_id): void {
        foreach ($tenant_ids as $tenant_id) {
            $is_primary = ($tenant_id == $primary_tenant_id) ? 1 : 0;
            $stmt = $this->pdo->prepare("
                INSERT INTO user_tenants (user_id, tenant_id, is_primary) 
                VALUES (:user_id, :tenant_id, :is_primary)
            ");
            $stmt->execute([
                'user_id' => $user_id,
                'tenant_id' => $tenant_id,
                'is_primary' => $is_primary
            ]);
        }
    }
    
    /**
     * Assign user to branches
     */
    private function assignUserBranches(int $user_id, array $branch_ids): void {
        foreach ($branch_ids as $branch_id) {
            // Get tenant_id for this branch
            $stmt = $this->pdo->prepare("SELECT tenant_id FROM branches WHERE id = ?");
            $stmt->execute([$branch_id]);
            $tenant_id = $stmt->fetchColumn();
            
            if ($tenant_id) {
                $stmt = $this->pdo->prepare("
                    INSERT INTO user_branches (user_id, branch_id, tenant_id) 
                    VALUES (:user_id, :branch_id, :tenant_id)
                ");
                $stmt->execute([
                    'user_id' => $user_id,
                    'branch_id' => $branch_id,
                    'tenant_id' => $tenant_id
                ]);
            }
        }
    }
    
    /**
     * Clear user sessions
     */
    private function clearUserSessions(int $user_id): void {
        // Clear app sessions
        $this->pdo->prepare("DELETE FROM app_sessions WHERE user_id = ?")->execute([$user_id]);
        
        // Invalidate device tokens
        $this->pdo->prepare("
            UPDATE user_devices 
            SET is_trusted = 0, trust_revoked_at = NOW() 
            WHERE user_id = ?
        ")->execute([$user_id]);
    }
    
    /**
     * Log admin action
     */
    private function logAction(string $action, array $details): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO super_admin_logs (admin_id, action, details, ip_address, user_agent, created_at) 
            VALUES (:admin_id, :action, :details, :ip, :ua, NOW())
        ");
        $stmt->execute([
            'admin_id' => $this->admin_id,
            'action' => $action,
            'details' => json_encode($details),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
}