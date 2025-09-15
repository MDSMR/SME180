<?php
// /api/get_user_branches.php - Get user's branches for selected tenant
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../config/db.php';

use_backend_session();

// Check if user is logged in
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$tenant_id = (int)($_GET['tenant_id'] ?? 0);

if ($tenant_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid tenant ID']);
    exit;
}

try {
    $pdo = db();
    
    // First verify user has access to this tenant
    $stmt = $pdo->prepare("
        SELECT 1
        FROM user_tenants ut
        WHERE ut.user_id = :user_id
            AND ut.tenant_id = :tenant_id
        UNION
        SELECT 1
        FROM users u
        WHERE u.id = :user_id2
            AND u.tenant_id = :tenant_id2
        LIMIT 1
    ");
    
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':tenant_id' => $tenant_id,
        ':user_id2' => $_SESSION['user_id'],
        ':tenant_id2' => $tenant_id
    ]);
    
    if (!$stmt->fetch()) {
        http_response_code(403);
        echo json_encode(['error' => 'Access denied to this tenant']);
        exit;
    }
    
    // Get branches for this tenant that user has access to
    $stmt = $pdo->prepare("
        SELECT 
            b.id,
            b.name,
            b.address,
            b.branch_type as type,
            b.phone,
            b.email
        FROM branches b
        JOIN user_branches ub ON b.id = ub.branch_id
        WHERE ub.user_id = :user_id
            AND b.tenant_id = :tenant_id
            AND b.is_active = 1
        ORDER BY b.name ASC
    ");
    
    $stmt->execute([
        ':user_id' => $_SESSION['user_id'],
        ':tenant_id' => $tenant_id
    ]);
    
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if user has a remembered branch for this tenant
    $remembered_branch = null;
    if (isset($_COOKIE['pos_device_token'])) {
        $stmt = $pdo->prepare("
            SELECT last_branch_id
            FROM user_devices
            WHERE user_id = :user_id
                AND device_token = :token
                AND last_tenant_id = :tenant_id
                AND remember_context = 1
                AND expires_at > NOW()
            LIMIT 1
        ");
        
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':token' => $_COOKIE['pos_device_token'],
            ':tenant_id' => $tenant_id
        ]);
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $remembered_branch = $result['last_branch_id'];
        }
    }
    
    // Return JSON response
    echo json_encode([
        'success' => true,
        'branches' => $branches,
        'remembered_branch' => $remembered_branch,
        'count' => count($branches)
    ]);
    
} catch (Exception $e) {
    error_log('Error fetching branches: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load branches']);
}
?>