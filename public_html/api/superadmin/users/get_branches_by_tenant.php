<?php
// /api/superadmin/users/get_branches_by_tenant.php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/config/db.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if user is super admin
use_backend_session();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // Get tenant ID from request
    $tenant_id = isset($_GET['tenant_id']) ? (int)$_GET['tenant_id'] : 0;
    
    if (!$tenant_id) {
        throw new Exception('Tenant ID is required');
    }
    
    $pdo = db();
    
    // Get branches for the tenant
    $stmt = $pdo->prepare("
        SELECT 
            b.id,
            b.name,
            b.branch_type,
            b.is_active,
            CASE 
                WHEN b.branch_type = 'central_kitchen' THEN 'Central Kitchen'
                WHEN b.branch_type = 'sales_branch' THEN 'Sales Branch'
                WHEN b.branch_type = 'mixed' THEN 'Mixed'
                ELSE 'Branch'
            END as type_label,
            (SELECT COUNT(*) FROM users u 
             JOIN user_branches ub ON u.id = ub.user_id 
             WHERE ub.branch_id = b.id AND u.disabled_at IS NULL) as user_count
        FROM branches b
        WHERE b.tenant_id = :tenant_id 
        AND b.is_active = 1
        ORDER BY b.name
    ");
    
    $stmt->execute(['tenant_id' => $tenant_id]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get tenant info
    $stmt = $pdo->prepare("
        SELECT name, max_users, max_branches 
        FROM tenants 
        WHERE id = :tenant_id
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tenant) {
        throw new Exception('Tenant not found');
    }
    
    // Get current user count for this tenant
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT ut.user_id) as user_count
        FROM user_tenants ut
        JOIN users u ON ut.user_id = u.id
        WHERE ut.tenant_id = :tenant_id
        AND u.disabled_at IS NULL
    ");
    $stmt->execute(['tenant_id' => $tenant_id]);
    $user_count = $stmt->fetchColumn();
    
    // Return response
    echo json_encode([
        'success' => true,
        'branches' => $branches,
        'tenant' => [
            'id' => $tenant_id,
            'name' => $tenant['name'],
            'max_users' => $tenant['max_users'],
            'max_branches' => $tenant['max_branches'],
            'current_users' => $user_count,
            'users_remaining' => $tenant['max_users'] - $user_count
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}