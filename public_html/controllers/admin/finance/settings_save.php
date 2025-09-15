<?php
// controllers/admin/finance/settings_save.php
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
    
    $pdo = db();
    
    // Ensure settings table exists
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
    
    // Settings to save
    $settings = [
        'default_tax_rate' => $input['default_tax_rate'] ?? '',
        'auto_calculate_tax' => isset($input['auto_calculate_tax']) ? (int)$input['auto_calculate_tax'] : 1,
        'compound_tax' => isset($input['compound_tax']) ? (int)$input['compound_tax'] : 0,
        'tax_on_service_charge' => isset($input['tax_on_service_charge']) ? (int)$input['tax_on_service_charge'] : 0,
        'round_tax_calculations' => isset($input['round_tax_calculations']) ? (int)$input['round_tax_calculations'] : 1,
        'receipt_tax_breakdown' => isset($input['receipt_tax_breakdown']) ? (int)$input['receipt_tax_breakdown'] : 1
    ];
    
    // Validate default tax rate if provided
    if (!empty($settings['default_tax_rate'])) {
        $taxCheckSql = "SELECT id FROM `tax_rates` 
                        WHERE `id` = :tax_id AND `tenant_id` = :tid AND `is_active` = 1";
        $taxCheckStmt = $pdo->prepare($taxCheckSql);
        $taxCheckStmt->execute([
            ':tax_id' => $settings['default_tax_rate'],
            ':tid' => $tenant_id
        ]);
        
        if (!$taxCheckStmt->fetch()) {
            json_out(422, ['ok' => false, 'error' => 'Invalid default tax rate selected']);
        }
    }
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Prepare the upsert query
        $sql = "INSERT INTO `settings` (`tenant_id`, `key`, `value`) 
                VALUES (:tid, :key, :value) 
                ON DUPLICATE KEY UPDATE 
                `value` = VALUES(`value`),
                `updated_at` = CURRENT_TIMESTAMP";
        
        $stmt = $pdo->prepare($sql);
        $written = 0;
        
        // Save each setting
        foreach ($settings as $key => $value) {
            // Convert value to string for storage
            if ($value === null) {
                $value = '';
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } else {
                $value = (string)$value;
            }
            
            $stmt->execute([
                ':tid' => $tenant_id,
                ':key' => $key,
                ':value' => $value
            ]);
            
            if ($stmt->rowCount() > 0) {
                $written++;
            }
        }
        
        // Commit transaction
        $pdo->commit();
        
        json_out(200, [
            'ok' => true,
            'message' => 'Finance settings saved successfully',
            'written' => $written
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Throwable $e) {
    error_log('Finance settings save error: ' . $e->getMessage());
    json_out(500, ['ok' => false, 'error' => 'Failed to save settings']);
}