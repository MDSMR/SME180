<?php
// controllers/admin/finance/tax_list.php
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
        CREATE TABLE IF NOT EXISTS `tax_rates` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `tenant_id` int(11) NOT NULL,
            `name` varchar(255) NOT NULL,
            `rate` decimal(5,2) NOT NULL DEFAULT '0.00',
            `type` varchar(50) DEFAULT 'vat',
            `is_inclusive` tinyint(1) DEFAULT 0,
            `is_active` tinyint(1) DEFAULT 1,
            `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_tenant_active` (`tenant_id`, `is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";
    $pdo->exec($createTableSql);
    
    // Fetch tax rates
    $sql = "SELECT * FROM `tax_rates` WHERE `tenant_id` = :tid ORDER BY `is_active` DESC, `name` ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tid' => $tenant_id]);
    $rates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Clean data types
    foreach ($rates as &$rate) {
        $rate['id'] = (int)$rate['id'];
        $rate['rate'] = (float)$rate['rate'];
        $rate['is_inclusive'] = (int)$rate['is_inclusive'];
        $rate['is_active'] = (int)$rate['is_active'];
    }
    
    json_out(200, ['ok' => true, 'data' => $rates]);
    
} catch (Throwable $e) {
    error_log('Tax list error: ' . $e->getMessage());
    json_out(500, ['ok' => false, 'error' => 'Failed to load tax rates']);
}