<?php
/**
 * Super Admin Impersonation Controller
 * Handles impersonation of tenant admins with full audit trail
 */

session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/AuditLogger.php';

// Check super admin authentication
if (!isset($_SESSION['super_admin']) || !$_SESSION['super_admin']['id']) {
    header('HTTP/1.1 403 Forbidden');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$superAdminId = $_SESSION['super_admin']['id'];

switch ($action) {
    case 'start':
        startImpersonation();
        break;
    case 'stop':
        stopImpersonation();
        break;
    case 'status':
        getImpersonationStatus();
        break;
    default:
        header('HTTP/1.1 400 Bad Request');
        echo json_encode(['error' => 'Invalid action']);
        break;
}

/**
 * Start impersonating a tenant
 */
function startImpersonation() {
    global $superAdminId;
    
    $tenantId = filter_input(INPUT_POST, 'tenant_id', FILTER_VALIDATE_INT);
    $userId = filter_input(INPUT_POST, 'user_id', FILTER_VALIDATE_INT);
    $reason = filter_input(INPUT_POST, 'reason', FILTER_SANITIZE_STRING);
    
    if (!$tenantId) {
        echo json_encode(['success' => false, 'error' => 'Invalid tenant ID']);
        return;
    }
    
    if (!$reason) {
        echo json_encode(['success' => false, 'error' => 'Reason is required for impersonation']);
        return;
    }
    
    try {
        $pdo = db();
        
        // Check if already impersonating
        if (isset($_SESSION['impersonation'])) {
            echo json_encode(['success' => false, 'error' => 'Already impersonating. Stop current session first.']);
            return;
        }
        
        // Get tenant details
        $stmt = $pdo->prepare("
            SELECT id, name, subscription_status, is_active 
            FROM tenants 
            WHERE id = :tenant_id
        ");
        $stmt->execute([':tenant_id' => $tenantId]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tenant) {
            echo json_encode(['success' => false, 'error' => 'Tenant not found']);
            return;
        }
        
        // If specific user requested, get user details
        $targetUser = null;
        if ($userId) {
            $stmt = $pdo->prepare("
                SELECT id, name, username, role_key 
                FROM users 
                WHERE id = :user_id AND tenant_id = :tenant_id AND disabled_at IS NULL
            ");
            $stmt->execute([':user_id' => $userId, ':tenant_id' => $tenantId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        } else {
            // Get first admin user of tenant
            $stmt = $pdo->prepare("
                SELECT id, name, username, role_key 
                FROM users 
                WHERE tenant_id = :tenant_id 
                    AND role_key = 'admin' 
                    AND disabled_at IS NULL
                ORDER BY id ASC
                LIMIT 1
            ");
            $stmt->execute([':tenant_id' => $tenantId]);
            $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$targetUser) {
            echo json_encode(['success' => false, 'error' => 'No valid user found for impersonation']);
            return;
        }
        
        // Get first branch for the tenant
        $stmt = $pdo->prepare("
            SELECT id, name 
            FROM branches 
            WHERE tenant_id = :tenant_id AND is_active = 1
            ORDER BY id ASC
            LIMIT 1
        ");
        $stmt->execute([':tenant_id' => $tenantId]);
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$branch) {
            echo json_encode(['success' => false, 'error' => 'No active branch found for tenant']);
            return;
        }
        
        // Store original session
        $_SESSION['original_session'] = $_SESSION;
        
        // Create impersonation log
        $stmt = $pdo->prepare("
            INSERT INTO impersonation_logs 
            (admin_id, tenant_id, user_id, reason, started_at, ip_address, user_agent)
            VALUES (:admin_id, :tenant_id, :user_id, :reason, NOW(), :ip, :ua)
        ");
        $stmt->execute([
            ':admin_id' => $superAdminId,
            ':tenant_id' => $tenantId,
            ':user_id' => $targetUser['id'],
            ':reason' => $reason,
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        $impersonationId = $pdo->lastInsertId();
        
        // Set up impersonation session
        $_SESSION['impersonation'] = [
            'id' => $impersonationId,
            'super_admin_id' => $superAdminId,
            'super_admin_name' => $_SESSION['super_admin']['name'],
            'started_at' => date('Y-m-d H:i:s'),
            'reason' => $reason
        ];
        
        // Set up user session as if they logged in
        $_SESSION['user_id'] = $targetUser['id'];
        $_SESSION['user'] = [
            'id' => $targetUser['id'],
            'name' => $targetUser['name'],
            'username' => $targetUser['username'],
            'role_key' => $targetUser['role_key'],
            'tenant_id' => $tenantId,
            'tenant_name' => $tenant['name'],
            'branch_id' => $branch['id'],
            'branch_name' => $branch['name']
        ];
        $_SESSION['tenant_id'] = $tenantId;
        $_SESSION['branch_id'] = $branch['id'];
        
        // Log the impersonation start
        AuditLogger::log(
            'impersonation_start',
            [
                'super_admin_id' => $superAdminId,
                'target_tenant' => $tenant['name'],
                'target_user' => $targetUser['username'],
                'reason' => $reason
            ],
            $targetUser['id'],
            $tenantId,
            $branch['id']
        );
        
        // Log in super admin logs
        $stmt = $pdo->prepare("
            INSERT INTO super_admin_logs 
            (admin_id, action, tenant_id, details, ip_address, user_agent)
            VALUES (:admin_id, 'impersonation_start', :tenant_id, :details, :ip, :ua)
        ");
        $stmt->execute([
            ':admin_id' => $superAdminId,
            ':tenant_id' => $tenantId,
            ':details' => json_encode([
                'target_user' => $targetUser['username'],
                'reason' => $reason
            ]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        echo json_encode([
            'success' => true,
            'message' => 'Impersonation started successfully',
            'redirect' => '/views/admin/dashboard.php'
        ]);
        
    } catch (Exception $e) {
        error_log('Impersonation error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to start impersonation']);
    }
}

/**
 * Stop impersonating
 */
function stopImpersonation() {
    global $superAdminId;
    
    if (!isset($_SESSION['impersonation'])) {
        echo json_encode(['success' => false, 'error' => 'Not currently impersonating']);
        return;
    }
    
    try {
        $pdo = db();
        
        $impersonationId = $_SESSION['impersonation']['id'];
        
        // Update impersonation log
        $stmt = $pdo->prepare("
            UPDATE impersonation_logs 
            SET ended_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $impersonationId]);
        
        // Log the impersonation end
        AuditLogger::log(
            'impersonation_end',
            [
                'super_admin_id' => $_SESSION['impersonation']['super_admin_id'],
                'duration_minutes' => round((time() - strtotime($_SESSION['impersonation']['started_at'])) / 60)
            ],
            $_SESSION['user_id'] ?? null,
            $_SESSION['tenant_id'] ?? null,
            $_SESSION['branch_id'] ?? null
        );
        
        // Log in super admin logs
        $stmt = $pdo->prepare("
            INSERT INTO super_admin_logs 
            (admin_id, action, tenant_id, details, ip_address, user_agent)
            VALUES (:admin_id, 'impersonation_end', :tenant_id, :details, :ip, :ua)
        ");
        $stmt->execute([
            ':admin_id' => $_SESSION['impersonation']['super_admin_id'],
            ':tenant_id' => $_SESSION['tenant_id'] ?? null,
            ':details' => json_encode([
                'duration_minutes' => round((time() - strtotime($_SESSION['impersonation']['started_at'])) / 60)
            ]),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
        
        // Restore original session
        if (isset($_SESSION['original_session'])) {
            $originalSession = $_SESSION['original_session'];
            session_destroy();
            session_start();
            $_SESSION = $originalSession;
            unset($_SESSION['original_session']);
        } else {
            // Fallback: clear session and redirect to super admin login
            session_destroy();
            session_start();
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Impersonation ended successfully',
            'redirect' => '/views/superadmin/dashboard.php'
        ]);
        
    } catch (Exception $e) {
        error_log('Stop impersonation error: ' . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Failed to stop impersonation']);
    }
}

/**
 * Get current impersonation status
 */
function getImpersonationStatus() {
    if (isset($_SESSION['impersonation'])) {
        echo json_encode([
            'impersonating' => true,
            'super_admin' => $_SESSION['impersonation']['super_admin_name'],
            'started_at' => $_SESSION['impersonation']['started_at'],
            'tenant' => $_SESSION['user']['tenant_name'] ?? '',
            'user' => $_SESSION['user']['username'] ?? ''
        ]);
    } else {
        echo json_encode(['impersonating' => false]);
    }
}