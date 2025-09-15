<?php
// controllers/admin/settings/general_save.php
declare(strict_types=1);

// Include required files
$base_path = dirname(__DIR__, 3);
require_once $base_path . '/config/db.php';
require_once $base_path . '/middleware/auth_login.php';

// Check authentication
auth_require_login();

// Disable error display for production
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Set JSON headers
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function json_out(int $code, array $payload): void {
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Check authentication
    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        json_out(401, ['ok' => false, 'error' => 'Not authenticated']);
    }

    // Get tenant_id
    $tenant_id = 1; // Default
    if (isset($_SESSION['tenant_id'])) {
        $tenant_id = (int)$_SESSION['tenant_id'];
    } elseif (isset($_SESSION['user']['tenant_id'])) {
        $tenant_id = (int)$_SESSION['user']['tenant_id'];
    } elseif (isset($_SESSION['user']['tenant']['id'])) {
        $tenant_id = (int)$_SESSION['user']['tenant']['id'];
    }

    // Parse JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_out(400, ['ok' => false, 'error' => 'Invalid JSON input']);
    }

    // Get PDO connection
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
        'brand_name' => $input['brand_name'] ?? 'Smorll',
        'website' => $input['website'] ?? '',
        'contact_email' => $input['contact_email'] ?? '',
        'contact_phone' => $input['contact_phone'] ?? '',
        'description' => $input['description'] ?? '',
        'currency' => $input['currency'] ?? 'EGP',
        'tax_inclusive' => isset($input['tax_inclusive']) ? (int)$input['tax_inclusive'] : 0,
        'language' => $input['language'] ?? 'en',
        'time_zone' => $input['time_zone'] ?? 'Africa/Cairo',
        'receipt_footer' => $input['receipt_footer'] ?? '',
        'service_charge_pct' => $input['service_charge_pct'] ?? 0,
        'aggregator_fees_mode' => $input['aggregator_fees_mode'] ?? 'none',
        'default_branch_id' => $input['default_branch_id'] ?? null
    ];

    // Validate email if provided
    if (!empty($settings['contact_email']) && !filter_var($settings['contact_email'], FILTER_VALIDATE_EMAIL)) {
        json_out(422, [
            'ok' => false,
            'error' => 'Validation failed',
            'fields' => ['contact_email' => 'Invalid email format']
        ]);
    }

    // Validate website URL if provided
    if (!empty($settings['website']) && !filter_var($settings['website'], FILTER_VALIDATE_URL)) {
        // Try adding http:// if not present
        if (!preg_match('/^https?:\/\//', $settings['website'])) {
            $settings['website'] = 'https://' . $settings['website'];
            if (!filter_var($settings['website'], FILTER_VALIDATE_URL)) {
                json_out(422, [
                    'ok' => false,
                    'error' => 'Validation failed',
                    'fields' => ['website' => 'Invalid website URL']
                ]);
            }
        }
    }

    // Begin transaction
    $pdo->beginTransaction();
    
    $written = 0;
    
    try {
        // Prepare the upsert query
        $sql = "INSERT INTO `settings` (`tenant_id`, `key`, `value`) 
                VALUES (:tid, :key, :value) 
                ON DUPLICATE KEY UPDATE 
                `value` = VALUES(`value`),
                `updated_at` = CURRENT_TIMESTAMP";
        
        $stmt = $pdo->prepare($sql);
        
        // Save each setting
        foreach ($settings as $key => $value) {
            // Convert value to string for storage
            if ($value === null) {
                $value = '';
            } elseif (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif (is_array($value) || is_object($value)) {
                $value = json_encode($value);
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
        
        // Return success response
        json_out(200, [
            'ok' => true,
            'message' => 'Settings saved successfully',
            'written' => $written,
            'data' => [
                'default_branch_id' => $settings['default_branch_id']
            ]
        ]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    error_log('Database error in general_save.php: ' . $e->getMessage());
    json_out(500, [
        'ok' => false,
        'error' => 'Database error occurred',
        'debug' => isset($_GET['debug']) ? $e->getMessage() : null
    ]);
} catch (Throwable $e) {
    error_log('Error in general_save.php: ' . $e->getMessage());
    json_out(500, [
        'ok' => false,
        'error' => 'Failed to save settings',
        'debug' => isset($_GET['debug']) ? $e->getMessage() : null
    ]);
}