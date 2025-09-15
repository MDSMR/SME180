<?php
// controllers/admin/finance/aggregator_save.php
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
    $commission_percent = (float)($input['commission_percent'] ?? 0);
    $is_active = (int)($input['is_active'] ?? 1);
    
    // Validation
    if (empty($name)) {
        json_out(422, ['ok' => false, 'error' => 'Aggregator name is required']);
    }
    
    if ($commission_percent < 0 || $commission_percent > 100) {
        json_out(422, ['ok' => false, 'error' => 'Commission must be between 0 and 100']);
    }
    
    $pdo = db();
    
    if ($id && $id > 0) {
        // Check if aggregator exists and belongs to this tenant
        $checkSql = "SELECT id FROM `aggregators` WHERE `id` = :id AND `tenant_id` = :tid";
        $checkStmt = $pdo->prepare($checkSql);
        $checkStmt->execute([':id' => $id, ':tid' => $tenant_id]);
        
        if (!$checkStmt->fetch()) {
            json_out(404, ['ok' => false, 'error' => 'Aggregator not found']);
        }
        
        // Update
        $sql = "UPDATE `aggregators` SET 
                `name` = :name,
                `commission_percent` = :commission_percent,
                `is_active` = :is_active,
                `updated_at` = CURRENT_TIMESTAMP
                WHERE `id` = :id AND `tenant_id` = :tid";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':commission_percent' => $commission_percent,
            ':is_active' => $is_active,
            ':id' => $id,
            ':tid' => $tenant_id
        ]);
        
        $message = 'Aggregator updated successfully';
    } else {
        // Check if aggregator with same name already exists
        $checkNameSql = "SELECT id FROM `aggregators` WHERE `name` = :name AND `tenant_id` = :tid";
        $checkNameStmt = $pdo->prepare($checkNameSql);
        $checkNameStmt->execute([':name' => $name, ':tid' => $tenant_id]);
        
        if ($checkNameStmt->fetch()) {
            json_out(422, ['ok' => false, 'error' => 'An aggregator with this name already exists']);
        }
        
        // Insert
        $sql = "INSERT INTO `aggregators` 
                (`tenant_id`, `name`, `commission_percent`, `is_active`) 
                VALUES (:tid, :name, :commission_percent, :is_active)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tid' => $tenant_id,
            ':name' => $name,
            ':commission_percent' => $commission_percent,
            ':is_active' => $is_active
        ]);
        
        $id = (int)$pdo->lastInsertId();
        $message = 'Aggregator added successfully';
    }
    
    json_out(200, [
        'ok' => true, 
        'id' => $id,
        'message' => $message
    ]);
    
} catch (Throwable $e) {
    error_log('Aggregator save error: ' . $e->getMessage());
    json_out(500, ['ok' => false, 'error' => 'Failed to save aggregator']);
}