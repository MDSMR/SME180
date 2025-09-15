<?php
// controllers/admin/finance/payment_list.php
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
    $pdo = db();
    
    // Create table if not exists
    $createTableSql = "
        CREATE TABLE IF NOT EXISTS `payment_methods` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `type` varchar(50) DEFAULT 'cash',
            `surcharge_rate` decimal(5,2) DEFAULT '0.00',
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_tenant_active` (`tenant_id`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($createTableSql);
    
    // Check if table is empty and insert defaults
    $countSql = "SELECT COUNT(*) as count FROM `payment_methods` WHERE `tenant_id` = :tid";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute([':tid' => $tenant_id]);
    $count = $countStmt->fetch()['count'];
    
    if ($count == 0) {
        // Insert default payment methods
        $defaultSql = "INSERT INTO `payment_methods` (`tenant_id`, `name`, `type`, `surcharge_rate`, `is_active`) VALUES
                       (:tid1, 'Cash', 'cash', 0.00, 1),
                       (:tid2, 'Credit Card', 'card', 0.00, 1),
                       (:tid3, 'Debit Card', 'card', 0.00, 1)";
        $defaultStmt = $pdo->prepare($defaultSql);
        $defaultStmt->execute([
            ':tid1' => $tenant_id,
            ':tid2' => $tenant_id,
            ':tid3' => $tenant_id
        ]);
    }
    
    // Fetch payment methods
    $sql = "SELECT * FROM `payment_methods` WHERE `tenant_id` = :tid ORDER BY `is_active` DESC, `name` ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tid' => $tenant_id]);
    $methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clean data types
    foreach ($methods as &$method) {
        $method['id'] = (int)$method['id'];
        $method['surcharge_rate'] = (float)$method['surcharge_rate'];
        $method['is_active'] = (int)$method['is_active'];
    }
    
    json_out(200, ['ok' => true, 'data' => $methods]);
    
} catch (Throwable $e) {
    error_log('Payment list error: ' . $e->getMessage());
    json_out(500, ['ok' => false, 'error' => 'Failed to load payment methods']);
}