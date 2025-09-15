<?php
// controllers/admin/finance/settings_get.php
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
    
    // Default finance settings
    $defaults = [
        'default_tax_rate' => '',
        'auto_calculate_tax' => 1,
        'compound_tax' => 0,
        'tax_on_service_charge' => 0,
        'round_tax_calculations' => 1,
        'receipt_tax_breakdown' => 1
    ];
    
    $data = $defaults;
    
    // Check if settings table exists
    $tableCheckSql = "SHOW TABLES LIKE 'settings'";
    $tableCheck = $pdo->query($tableCheckSql)->fetch();
    
    if (!$tableCheck) {
        // Create settings table if it doesn't exist
        $createTableSql = "
            CREATE TABLE IF NOT EXISTS `settings` (
                `id` int(11) NOT NULL AUTO_INCREMENT,
                `tenant_id` int(11) NOT NULL,
                `key` varchar(255) NOT NULL,
                `value` text,
                `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `unique_tenant_key` (`tenant_id`, `key`),
                KEY `idx_tenant` (`tenant_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";
        $pdo->exec($createTableSql);
    }
    
    // Load settings from database
    $sql = "SELECT `key`, `value` FROM `settings` 
            WHERE `tenant_id` = :tid 
            AND `key` IN ('default_tax_rate', 'auto_calculate_tax', 'compound_tax', 
                         'tax_on_service_charge', 'round_tax_calculations', 'receipt_tax_breakdown')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tid' => $tenant_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $row) {
        $key = $row['key'];
        $value = $row['value'];
        
        if (isset($defaults[$key])) {
            // Convert to appropriate data type
            if (in_array($key, ['auto_calculate_tax', 'compound_tax', 'tax_on_service_charge', 
                                'round_tax_calculations', 'receipt_tax_breakdown'])) {
                $data[$key] = (int)$value;
            } else {
                $data[$key] = $value;
            }
        }
    }
    
    json_out(200, [
        'ok' => true, 
        'data' => $data,
        '_meta' => [
            'tenant_id' => $tenant_id,
            'rows_count' => count($rows)
        ]
    ]);
    
} catch (Throwable $e) {
    error_log('Finance settings get error: ' . $e->getMessage());
    json_out(500, ['ok' => false, 'error' => 'Failed to load settings']);
}