<?php
// /api/switch_context.php - Switch tenant/branch context without re-login
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

// Include AuditLogger if available
$audit_path = dirname(__DIR__) . '/includes/AuditLogger.php';
if (file_exists($audit_path)) {
    require_once $audit_path;
}

use_backend_session();

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Handle POST request to switch context
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $data = json_decode(file_get_contents('php://input'), true);
    $tenant_id = (int)($data['tenant_id'] ?? 0);
    $branch_id = (int)($data['branch_id'] ?? 0);
    
    if ($tenant_id <= 0 || $branch_id <= 0) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid tenant or branch ID']);
        exit;
    }
    
    try {
        $pdo = db();
        
        // Verify user has access to this tenant and branch
        $stmt = $pdo->prepare("
            SELECT 
                t.name as tenant_name,
                b.name as branch_name
            FROM tenants t
            JOIN branches b ON b.tenant_id = t.id
            JOIN user_branches ub ON ub.branch_id = b.id
            LEFT JOIN user_tenants ut ON ut.tenant_id = t.id AND ut.user_id = :user_id
            WHERE t.id = :tenant_id
                AND b.id = :branch_id
                AND ub.user_id = :user_id2
                AND t.is_active = 1
                AND b.is_active = 1
                AND (ut.user_id = :user_id3 OR t.id = (SELECT tenant_id FROM users WHERE id = :user_id4))
            LIMIT 1
        ");
        
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':branch_id' => $branch_id,
            ':user_id' => $_SESSION['user_id'],
            ':user_id2' => $_SESSION['user_id'],
            ':user_id3' => $_SESSION['user_id'],
            ':user_id4' => $_SESSION['user_id']
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            http_response_code(403);
            echo json_encode(['error' => 'Access denied to this tenant/branch combination']);
            exit;
        }
        
        // Store old context for logging
        $old_tenant_id = $_SESSION['tenant_id'] ?? null;
        $old_branch_id = $_SESSION['branch_id'] ?? null;
        
        // Update session with new context
        $_SESSION['tenant_id'] = $tenant_id;
        $_SESSION['tenant_name'] = $result['tenant_name'];
        $_SESSION['branch_id'] = $branch_id;
        $_SESSION['branch_name'] = $result['branch_name'];
        
        // Update user array
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['tenant_id'] = $tenant_id;
            $_SESSION['user']['tenant_name'] = $result['tenant_name'];
            $_SESSION['user']['branch_id'] = $branch_id;
            $_SESSION['user']['branch_name'] = $result['branch_name'];
        }
        
        // Update device memory if cookie exists
        if (isset($_COOKIE['pos_device_token'])) {
            $stmt = $pdo->prepare("
                UPDATE user_devices 
                SET last_tenant_id = :tenant_id,
                    last_branch_id = :branch_id,
                    last_login = NOW()
                WHERE user_id = :user_id 
                    AND device_token = :token
            ");
            
            $stmt->execute([
                ':tenant_id' => $tenant_id,
                ':branch_id' => $branch_id,
                ':user_id' => $_SESSION['user_id'],
                ':token' => $_COOKIE['pos_device_token']
            ]);
        }
        
        // Log context switch
        if (class_exists('AuditLogger')) {
            AuditLogger::log(
                'context_switch',
                'User switched context',
                [
                    'from_tenant_id' => $old_tenant_id,
                    'to_tenant_id' => $tenant_id,
                    'from_branch_id' => $old_branch_id,
                    'to_branch_id' => $branch_id
                ]
            );
        }
        
        // Also log in context_switches table if it exists
        try {
            $stmt = $pdo->prepare("
                INSERT INTO context_switches (
                    user_id, from_tenant_id, to_tenant_id, 
                    from_branch_id, to_branch_id, ip_address
                ) VALUES (
                    :user_id, :from_tenant, :to_tenant,
                    :from_branch, :to_branch, :ip
                )
            ");
            
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':from_tenant' => $old_tenant_id,
                ':to_tenant' => $tenant_id,
                ':from_branch' => $old_branch_id,
                ':to_branch' => $branch_id,
                ':ip' => get_client_ip()
            ]);
        } catch (Exception $e) {
            // Table might not exist, that's okay
        }
        
        echo json_encode([
            'success' => true,
            'tenant_id' => $tenant_id,
            'tenant_name' => $result['tenant_name'],
            'branch_id' => $branch_id,
            'branch_name' => $result['branch_name']
        ]);
        
    } catch (Exception $e) {
        error_log('Context switch error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to switch context']);
    }
    exit;
}

// Handle GET request - return available contexts
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $pdo = db();
        
        // Get all tenant/branch combinations for user
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                t.id as tenant_id,
                t.name as tenant_name,
                b.id as branch_id,
                b.name as branch_name,
                CASE 
                    WHEN t.id = :current_tenant AND b.id = :current_branch THEN 1
                    ELSE 0
                END as is_current
            FROM tenants t
            JOIN branches b ON b.tenant_id = t.id
            JOIN user_branches ub ON ub.branch_id = b.id
            LEFT JOIN user_tenants ut ON ut.tenant_id = t.id AND ut.user_id = :user_id
            WHERE ub.user_id = :user_id2
                AND t.is_active = 1
                AND b.is_active = 1
                AND (ut.user_id = :user_id3 OR t.id = (SELECT tenant_id FROM users WHERE id = :user_id4))
            ORDER BY t.name, b.name
        ");
        
        $stmt->execute([
            ':current_tenant' => $_SESSION['tenant_id'] ?? 0,
            ':current_branch' => $_SESSION['branch_id'] ?? 0,
            ':user_id' => $_SESSION['user_id'],
            ':user_id2' => $_SESSION['user_id'],
            ':user_id3' => $_SESSION['user_id'],
            ':user_id4' => $_SESSION['user_id']
        ]);
        
        $contexts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'contexts' => $contexts,
            'current_tenant_id' => $_SESSION['tenant_id'] ?? null,
            'current_branch_id' => $_SESSION['branch_id'] ?? null
        ]);
        
    } catch (Exception $e) {
        error_log('Failed to get contexts: ' . $e->getMessage());
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode(['error' => 'Failed to load contexts']);
    }
    exit;
}

// Invalid method
http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
?>