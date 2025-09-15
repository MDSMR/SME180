<?php
// controllers/admin/finance/tax_save.php
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
    $rate = (float)($input['rate'] ?? 0);
    $type = $input['type'] ?? 'vat';
    $is_inclusive = (int)($input['is_inclusive'] ?? 0);
    $is_active = (int)($input['is_active'] ?? 1);
    
    if (empty($name)) json_out(422, ['ok' => false, 'error' => 'Tax name is required']);
    if ($rate < 0 || $rate > 100) json_out(422, ['ok' => false, 'error' => 'Invalid tax rate']);
    
    $pdo = db();
    
    if ($id && $id > 0) {
        // Update
        $sql = "UPDATE `tax_rates` SET 
                `name` = :name,
                `rate` = :rate,
                `type` = :type,
                `is_inclusive` = :is_inclusive,
                `is_active` = :is_active,
                `updated_at` = CURRENT_TIMESTAMP
                WHERE `id` = :id AND `tenant_id` = :tid";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':name' => $name,
            ':rate' => $rate,
            ':type' => $type,
            ':is_inclusive' => $is_inclusive,
            ':is_active' => $is_active,
            ':id' => $id,
            ':tid' => $tenant_id
        ]);
    } else {
        // Insert
        $sql = "INSERT INTO `tax_rates` 
                (`tenant_id`, `name`, `rate`, `type`, `is_inclusive`, `is_active`) 
                VALUES (:tid, :name, :rate, :type, :is_inclusive, :is_active)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':tid' => $tenant_id,
            ':name' => $name,
            ':rate' => $rate,
            ':type' => $type,
            ':is_inclusive' => $is_inclusive,
            ':is_active' => $is_active
        ]);
        $id = (int)$pdo->lastInsertId();
    }
    
    json_out(200, ['ok' => true, 'id' => $id, 'message' => 'Tax rate saved successfully']);
    
} catch (Throwable $e) {
    error_log('Tax save error: ' . $e->getMessage());
    json_out(500, ['ok' => false, 'error' => 'Failed to save tax rate']);
}