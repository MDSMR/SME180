<?php
// File: /public_html/pos/api/auth/validate_pin.php
// FIXED VERSION - Handles NULL user_id in audit log
declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

$in = read_input();
$pin = $in['pin'] ?? '';
$action = $in['action'] ?? 'override';
$tenantId = isset($in['tenant_id']) ? (int)$in['tenant_id'] : null;
$branchId = isset($in['branch_id']) ? (int)$in['branch_id'] : null;

if ($pin === '') {
    respond(false, 'PIN is required', 400);
}

try {
    $pdo = db();
    
    // Check manager_pin
    $sql = "SELECT 
                id, 
                tenant_id,
                name, 
                username,
                role_key AS role
            FROM users
            WHERE manager_pin = :pin
              AND (disabled_at IS NULL OR disabled_at > NOW())";
    
    if ($tenantId !== null) {
        $sql .= " AND tenant_id = :tenant_id";
    }
    
    $sql .= " LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $params = ['pin' => $pin];
    if ($tenantId !== null) {
        $params['tenant_id'] = $tenantId;
    }
    
    $stmt->execute($params);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        respond(false, 'Invalid manager PIN', 401);
    }
    
    // Check if user has manager/admin role
    $managerRoles = ['admin', 'manager', 'owner', 'pos_manager'];
    if (!in_array($user['role'], $managerRoles)) {
        respond(false, 'Insufficient permissions. Manager role required.', 403);
    }
    
    // Get requesting user from session if available
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    $requestingUserId = $_SESSION['pos_user_id'] ?? null;
    
    // Log validation - handle NULL user_id gracefully
    try {
        // Only log if audit_logs accepts NULL user_id, otherwise use the manager's ID
        $logUserId = $requestingUserId ?? $user['id'];
        
        $logStmt = $pdo->prepare(
            "INSERT INTO audit_logs (
                tenant_id, 
                branch_id, 
                user_id, 
                action, 
                entity_type,
                entity_id,
                details, 
                created_at
            ) VALUES (
                :tenant_id,
                :branch_id,
                :user_id,
                'manager_override',
                'validation',
                :entity_id,
                :details,
                NOW()
            )"
        );
        
        $logStmt->execute([
            'tenant_id' => $user['tenant_id'],
            'branch_id' => $branchId,
            'user_id' => $logUserId, // Use manager ID if no session user
            'entity_id' => $user['id'],
            'details' => json_encode([
                'action' => $action,
                'manager_name' => $user['name'] ?? $user['username'],
                'requesting_user' => $requestingUserId,
                'timestamp' => date('Y-m-d H:i:s')
            ])
        ]);
    } catch (Exception $e) {
        // Ignore audit log errors
        error_log("Audit log failed: " . $e->getMessage());
    }
    
    // Return validation success
    respond(true, [
        'valid' => true,
        'manager_id' => (int)$user['id'],
        'manager_name' => $user['name'] ?? $user['username'],
        'manager_role' => $user['role'],
        'action_authorized' => true,
        'authorization_token' => bin2hex(random_bytes(16)),
        'expires_in' => 300 // 5 minutes
    ]);
    
} catch (Throwable $e) {
    respond(false, 'Validation failed: ' . $e->getMessage(), 500);
}
