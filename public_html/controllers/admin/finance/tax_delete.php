<?php
// controllers/admin/finance/tax_delete.php
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
    
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) json_out(400, ['ok' => false, 'error' => 'Invalid ID']);
    
    $pdo = db();
    
    // Check if tax rate exists and belongs to this tenant
    $checkSql = "SELECT `name` FROM `tax_rates` WHERE `id` = :id AND `tenant_id` = :tid";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([':id' => $id, ':tid' => $tenant_id]);
    $taxRate = $checkStmt->fetch();
    
    if (!$taxRate) {
        json_out(404, ['ok' => false, 'error' => 'Tax rate not found']);
    }
    
    // Delete the tax rate
    $sql = "DELETE FROM `tax_rates` WHERE `id` = :id AND `tenant_id` = :tid";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([':id' => $id, ':tid' => $tenant_id]);
    
    if ($result) {
        json_out(200, [
            'ok' => true, 
            'message' => 'Tax rate deleted successfully',
            'deleted_name' => $taxRate['name']
        ]);
    } else {
        json_out(500, ['ok' => false, 'error' => 'Failed to delete tax rate']);
    }
    
} catch (Throwable $e) {
    error_log('Tax delete error: ' . $e->getMessage());
    json_out(500, ['ok' => false, 'error' => 'Failed to delete tax rate']);
}