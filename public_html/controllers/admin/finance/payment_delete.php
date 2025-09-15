<?php
// controllers/admin/finance/payment_delete.php
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
    
    // Check if payment method exists and belongs to this tenant
    $checkSql = "SELECT `name`, `type` FROM `payment_methods` WHERE `id` = :id AND `tenant_id` = :tid";
    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([':id' => $id, ':tid' => $tenant_id]);
    $method = $checkStmt->fetch();
    
    if (!$method) {
        json_out(404, ['ok' => false, 'error' => 'Payment method not found']);
    }
    
    // Don't allow deletion of the last active cash payment method
    if ($method['type'] === 'cash') {
        $countSql = "SELECT COUNT(*) as count FROM `payment_methods` 
                     WHERE `tenant_id` = :tid AND `type` = 'cash' 
                     AND `is_active` = 1 AND `id` != :id";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute([':tid' => $tenant_id, ':id' => $id]);
        $count = $countStmt->fetch()['count'];
        
        if ($count == 0) {
            json_out(400, [
                'ok' => false, 
                'error' => 'Cannot delete the last cash payment method. At least one cash method must remain.'
            ]);
        }
    }
    
    // Delete the payment method
    $sql = "DELETE FROM `payment_methods` WHERE `id` = :id AND `tenant_id` = :tid";
    $stmt = $pdo->prepare($sql);
    $result = $stmt->execute([':id' => $id, ':tid' => $tenant_id]);
    
    if ($result) {
        json_out(200, [
            'ok' => true, 
            'message' => 'Payment method deleted successfully',
            'deleted_name' => $method['name']
        ]);
    } else {
        json_out(500, ['ok' => false, 'error' => 'Failed to delete payment method']);
    }
    
} catch (Throwable $e) {
    error_log('Payment delete error: ' . $e->getMessage());
    json_out(500, ['ok' => false, 'error' => 'Failed to delete payment method']);
}