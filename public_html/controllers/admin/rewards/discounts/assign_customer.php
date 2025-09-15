<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /controllers/admin/rewards/discounts/assign_customer.php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../../../config/db.php';
use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$tenantId = (int)$user['tenant_id'];
$userId = (int)$user['id'];

// Parse JSON input
$input = json_decode(file_get_contents('php://input'), true);
$schemeId = (int)($input['scheme_id'] ?? 0);
$customerId = (int)($input['customer_id'] ?? 0);
$action = $input['action'] ?? '';

if (!$schemeId || !$customerId || !in_array($action, ['assign', 'remove'])) {
    echo json_encode(['ok' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    $pdo = db();
    
    // Verify scheme belongs to tenant
    $schemeCheck = $pdo->prepare("SELECT id FROM discount_schemes WHERE id = :id AND tenant_id = :tenant_id");
    $schemeCheck->execute([':id' => $schemeId, ':tenant_id' => $tenantId]);
    if (!$schemeCheck->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Scheme not found']);
        exit;
    }
    
    // Verify customer belongs to tenant
    $customerCheck = $pdo->prepare("SELECT id FROM customers WHERE id = :id AND tenant_id = :tenant_id");
    $customerCheck->execute([':id' => $customerId, ':tenant_id' => $tenantId]);
    if (!$customerCheck->fetch()) {
        echo json_encode(['ok' => false, 'error' => 'Customer not found']);
        exit;
    }
    
    if ($action === 'assign') {
        // Insert assignment (ignore if already exists)
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO customer_scheme_assignments 
            (customer_id, scheme_id, assigned_by, tenant_id) 
            VALUES (:customer_id, :scheme_id, :assigned_by, :tenant_id)
        ");
        $stmt->execute([
            ':customer_id' => $customerId,
            ':scheme_id' => $schemeId,
            ':assigned_by' => $userId,
            ':tenant_id' => $tenantId
        ]);
        
        echo json_encode(['ok' => true, 'message' => 'Customer assigned successfully']);
        
    } else { // remove
        $stmt = $pdo->prepare("
            DELETE FROM customer_scheme_assignments 
            WHERE customer_id = :customer_id AND scheme_id = :scheme_id AND tenant_id = :tenant_id
        ");
        $stmt->execute([
            ':customer_id' => $customerId,
            ':scheme_id' => $schemeId,
            ':tenant_id' => $tenantId
        ]);
        
        echo json_encode(['ok' => true, 'message' => 'Customer removed successfully']);
    }
    
} catch (Exception $e) {
    error_log("Customer assignment error: " . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Database error']);
}
?>