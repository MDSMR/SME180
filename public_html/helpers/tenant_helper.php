<?php
/**
 * /helpers/tenant_helper.php
 * Tenant and Branch helper functions for multi-tenant SaaS POS
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';

/**
 * Get current tenant ID from session
 */
function get_current_tenant_id(): ?int {
    if (session_status() === PHP_SESSION_NONE) {
        use_backend_session();
    }
    return isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
}

/**
 * Get current branch ID from session
 */
function get_current_branch_id(): ?int {
    if (session_status() === PHP_SESSION_NONE) {
        use_backend_session();
    }
    return isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;
}

/**
 * Enforce tenant context - throws exception if not set
 */
function enforce_tenant_context(): array {
    $tenant_id = get_current_tenant_id();
    $branch_id = get_current_branch_id();
    
    if (!$tenant_id || !$branch_id) {
        throw new RuntimeException('No tenant/branch context set. Please login again.');
    }
    
    return [
        'tenant_id' => $tenant_id,
        'branch_id' => $branch_id
    ];
}

/**
 * Add tenant filter to SQL query
 */
function add_tenant_filter_to_query(string $query, string $table_alias = ''): string {
    $context = enforce_tenant_context();
    $prefix = $table_alias ? "$table_alias." : "";
    
    $tenant_condition = "{$prefix}tenant_id = " . $context['tenant_id'];
    
    // Check if WHERE clause exists
    if (stripos($query, 'WHERE') !== false) {
        // Add after WHERE
        $query = preg_replace('/WHERE/i', "WHERE $tenant_condition AND", $query, 1);
    } else {
        // Add WHERE clause
        $query .= " WHERE $tenant_condition";
    }
    
    return $query;
}

/**
 * Add branch filter to SQL query
 */
function add_branch_filter_to_query(string $query, string $table_alias = ''): string {
    $context = enforce_tenant_context();
    $prefix = $table_alias ? "$table_alias." : "";
    
    $branch_condition = "{$prefix}branch_id = " . $context['branch_id'];
    
    // Check if WHERE clause exists
    if (stripos($query, 'WHERE') !== false) {
        // Add after WHERE
        $query = preg_replace('/WHERE/i', "WHERE $branch_condition AND", $query, 1);
    } else {
        // Add WHERE clause
        $query .= " WHERE $branch_condition";
    }
    
    return $query;
}

/**
 * Add both tenant and branch filters
 */
function add_tenant_branch_filter(string $query, string $table_alias = ''): string {
    $context = enforce_tenant_context();
    $prefix = $table_alias ? "$table_alias." : "";
    
    $conditions = [
        "{$prefix}tenant_id = " . $context['tenant_id'],
        "{$prefix}branch_id = " . $context['branch_id']
    ];
    
    $filter = implode(' AND ', $conditions);
    
    if (stripos($query, 'WHERE') !== false) {
        $query = preg_replace('/WHERE/i', "WHERE $filter AND", $query, 1);
    } else {
        $query .= " WHERE $filter";
    }
    
    return $query;
}

/**
 * Validate user has access to specific tenant
 */
function validate_tenant_access(int $user_id, int $tenant_id): bool {
    try {
        $pdo = db();
        
        // Check if user has access to this tenant
        $stmt = $pdo->prepare("
            SELECT 1
            FROM users u
            LEFT JOIN user_tenants ut ON ut.user_id = u.id
            WHERE u.id = :user_id
            AND (u.tenant_id = :tenant_id OR ut.tenant_id = :tenant_id2)
            LIMIT 1
        ");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':tenant_id' => $tenant_id,
            ':tenant_id2' => $tenant_id
        ]);
        
        return $stmt->fetch() !== false;
        
    } catch (Exception $e) {
        error_log('Error validating tenant access: ' . $e->getMessage());
        return false;
    }
}

/**
 * Validate user has access to specific branch
 */
function validate_branch_access(int $user_id, int $branch_id, int $tenant_id): bool {
    try {
        $pdo = db();
        
        $stmt = $pdo->prepare("
            SELECT 1
            FROM user_branches ub
            JOIN branches b ON b.id = ub.branch_id
            WHERE ub.user_id = :user_id
            AND ub.branch_id = :branch_id
            AND b.tenant_id = :tenant_id
            AND b.is_active = 1
            LIMIT 1
        ");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':branch_id' => $branch_id,
            ':tenant_id' => $tenant_id
        ]);
        
        return $stmt->fetch() !== false;
        
    } catch (Exception $e) {
        error_log('Error validating branch access: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get all branches for current tenant that user has access to
 */
function get_user_accessible_branches(): array {
    try {
        $context = enforce_tenant_context();
        $user_id = $_SESSION['user_id'] ?? 0;
        
        if (!$user_id) {
            return [];
        }
        
        $pdo = db();
        
        $stmt = $pdo->prepare("
            SELECT 
                b.id,
                b.name,
                b.branch_type,
                b.address,
                b.phone,
                b.email
            FROM branches b
            JOIN user_branches ub ON b.id = ub.branch_id
            WHERE ub.user_id = :user_id
            AND b.tenant_id = :tenant_id
            AND b.is_active = 1
            ORDER BY b.name
        ");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':tenant_id' => $context['tenant_id']
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log('Error fetching user branches: ' . $e->getMessage());
        return [];
    }
}

/**
 * Switch to different branch within same tenant
 */
function switch_branch(int $new_branch_id): bool {
    try {
        $user_id = $_SESSION['user_id'] ?? 0;
        $tenant_id = get_current_tenant_id();
        
        if (!$user_id || !$tenant_id) {
            return false;
        }
        
        // Validate access
        if (!validate_branch_access($user_id, $new_branch_id, $tenant_id)) {
            return false;
        }
        
        // Get branch details
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT name 
            FROM branches 
            WHERE id = :id AND tenant_id = :tenant_id
        ");
        
        $stmt->execute([
            ':id' => $new_branch_id,
            ':tenant_id' => $tenant_id
        ]);
        
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$branch) {
            return false;
        }
        
        // Update session
        $_SESSION['branch_id'] = $new_branch_id;
        $_SESSION['branch_name'] = $branch['name'];
        
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['branch_id'] = $new_branch_id;
            $_SESSION['user']['branch_name'] = $branch['name'];
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log('Error switching branch: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get tenant settings
 */
function get_tenant_settings(string $key = null) {
    try {
        $tenant_id = get_current_tenant_id();
        
        if (!$tenant_id) {
            return null;
        }
        
        $pdo = db();
        
        if ($key) {
            $stmt = $pdo->prepare("
                SELECT value 
                FROM settings 
                WHERE tenant_id = :tenant_id 
                AND `key` = :key
            ");
            
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':key' => $key
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ? $result['value'] : null;
        } else {
            $stmt = $pdo->prepare("
                SELECT `key`, value 
                FROM settings 
                WHERE tenant_id = :tenant_id
            ");
            
            $stmt->execute([':tenant_id' => $tenant_id]);
            
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        }
        
    } catch (Exception $e) {
        error_log('Error fetching tenant settings: ' . $e->getMessage());
        return null;
    }
}
?>