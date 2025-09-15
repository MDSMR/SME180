<?php
// controllers/admin/finance/payment_save.php
declare(strict_types=1);

$base_path = dirname(__DIR__, 3);
require_once $base_path . '/config/db.php';
require_once $base_path . '/middleware/auth_login.php';
auth_require_login();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function json_out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $user = $_SESSION['user'] ?? null;
    if (!$user) json_out(401, ['ok' => false, 'error' => 'Not authenticated']);

    $tenant_id = $_SESSION['tenant_id'] ?? $_SESSION['user']['tenant_id'] ?? 1;
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) json_out(400, ['ok' => false, 'error' => 'Invalid input']);
    
    $id = isset($input['id']) ? (int)$input['id'] : null;
    $name = trim($input['name'] ?? '');
    $type = $input['type'] ?? 'cash';
    $surcharge_rate = (float)($input['surcharge_rate'] ?? 0);
    $is_active = (int)($input['is_active'] ?? 1);
    
    // Validation
    if (empty($name)) {
        json_out(422, ['ok' => false, 'error' => 'Payment method name is required']);
    }
    
    if ($surcharge_rate < 0 || $surcharge_rate > 100) {
        json_out(422, ['ok' => false, 'error' => 'Surcharge rate must be between 0 and 100']);
    }
    
    // Valid payment types
    $validTypes = ['cash', 'card', 'wallet', 'bank_transfer', 'other'];
    if (!in_array($type, $validTypes)) {
        json_out(422, ['ok' => false, 'error' => 'Invalid payment type']);
    }
    
    $pdo = db();
    
    if ($id && $id > 0) {
        // Check if payment method exists and belongs to this tenant
        $checkSql = "SELECT id FROM `payment_methods` WHERE `id` = :id AND `tenant_id` = :tid";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([':id' => $id, ':tid' => $tenant_id]);
        
        if (!$checkStmt->fetch()) {
            json_out(404, ['ok' => false, 'error' => 'Payment method not found']);
        }
        
        // Update
        $sql = "UPDATE `payment_methods` SET 
                `name` = :name,
                `type` = :type,
                `surcharge_rate` = :surcharge_rate,
                `is_active` = :is_active,
                `updated_at` = CURRENT_TIMESTAMP
                WHERE `id` = :id AND `tenant_id` = :tid";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':type' => $type,
            ':surcharge_rate' => $surcharge_rate,
            ':is_active' => $is_active,
            ':id' => $id,
            ':tid' => $tenant_id
        ]);
        
        $message = 'Payment method updated successfully';
    } else {
        // Insert
        $sql = "INSERT INTO `payment_methods` 
                (`tenant_id`, `name`, `type`, `surcharge_rate`, `is_active`) 
                VALUES (:tid, :name, :type, :surcharge_rate, :is_active)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tid' => $tenant_id,
            ':name' => $name,
            ':type' => $type,
            ':surcharge_rate' => $surcharge_rate,
            ':is_active' => $is_active
        ]);
        
        $id = (int)$pdo->lastInsertId();
        $message = 'Payment method added successfully';
    }
    
    json_out(200, [
        'ok' => true, 
        'id' => $id,
        'message' => $message
    ]);
    
} catch (Throwable $e) {
    error_log('Payment save error: ' . $e->getMessage());
    json_out(500, ['ok' => false, 'error' => 'Failed to save payment method']);
}