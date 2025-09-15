<?php
// controllers/admin/finance/aggregator_list.php
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
        CREATE TABLE IF NOT EXISTS `aggregators` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `commission_percent` decimal(5,2) DEFAULT '0.00',
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_tenant_active` (`tenant_id`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($createTableSql);
    
    // Check if table is empty and insert defaults
    $countSql = "SELECT COUNT(*) as count FROM `aggregators` WHERE `tenant_id` = :tid";
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute([':tid' => $tenant_id]);
    $count = $countStmt->fetch()['count'];
    
    if ($count == 0) {
        // Insert default aggregators
        $defaultSql = "INSERT INTO `aggregators` (`tenant_id`, `name`, `commission_percent`, `is_active`) VALUES
                       (:tid1, 'Talabat', 18.00, 1),
                       (:tid2, 'Uber Eats', 20.00, 1),
                       (:tid3, 'Deliveroo', 25.00, 1)";
        $defaultStmt = $pdo->prepare($defaultSql);
        $defaultStmt->execute([
            ':tid1' => $tenant_id,
            ':tid2' => $tenant_id,
            ':tid3' => $tenant_id
        ]);
    }
    
    // Fetch aggregators
    $sql = "SELECT * FROM `aggregators` WHERE `tenant_id` = :tid ORDER BY `is_active` DESC, `name` ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tid' => $tenant_id]);
    $aggregators = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clean data types
    foreach ($aggregators as &$ag) {
        $ag['id'] = (int)$ag['id'];
        $ag['commission_percent'] = (float)$ag['commission_percent'];
        $ag['is_active'] = (int)$ag['is_active'];
    }
    
    json_out(200, ['ok' => true, 'data' => $aggregators]);
    
} catch (Throwable $e) {
    error_log('Aggregator list error: ' . $e->getMessage());
    json_out(500, ['ok' => false, 'error' => 'Failed to load aggregators']);
}