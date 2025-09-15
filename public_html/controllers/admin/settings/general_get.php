<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';

// Debug mode
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
    @ini_set('display_errors', '1');
    @ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    @ini_set('display_errors', '0');
}

// Headers for AJAX
header('Content-Type: application/json; charset=utf-8');

try {
    // Authentication required
    auth_require_login();
    
    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Authentication required']);
        exit;
    }
    
    $tenantId = (int)($user['tenant_id'] ?? 0);
    if ($tenantId <= 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid tenant context']);
        exit;
    }
    
    // Database connection
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Load all settings for this tenant
    $stmt = $pdo->prepare("
        SELECT `key`, value 
        FROM settings 
        WHERE tenant_id = :tenant_id 
        ORDER BY `key`
    ");
    $stmt->execute([':tenant_id' => $tenantId]);
    $settingsData = $stmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    
    // Prepare response with defaults
    $response = [
        // Basic restaurant identity
        'brand_name' => $settingsData['brand_name'] ?? 'Smorll',
        'website' => $settingsData['website'] ?? '',
        'contact_email' => $settingsData['contact_email'] ?? '',
        'contact_phone' => $settingsData['contact_phone'] ?? '',
        'description' => $settingsData['description'] ?? '',
        
        // Business operations
        'currency' => $settingsData['currency'] ?? 'EGP',
        'time_zone' => $settingsData['time_zone'] ?? 'Africa/Cairo',
        'language' => $settingsData['language'] ?? 'en',
        'tax_inclusive' => isset($settingsData['tax_inclusive']) ? (bool)(int)$settingsData['tax_inclusive'] : false,
        
        // Receipt settings
        'receipt_footer' => $settingsData['receipt_footer'] ?? 'Thank you for dining with us!',
        
        // Operational settings
        'service_charge_pct' => isset($settingsData['service_charge_pct']) ? (float)$settingsData['service_charge_pct'] : 0.0,
        'aggregator_fees_mode' => $settingsData['aggregator_fees_mode'] ?? 'none',
        'default_branch_id' => isset($settingsData['default_branch_id']) ? 
            ($settingsData['default_branch_id'] === '' || $settingsData['default_branch_id'] === null ? null : (int)$settingsData['default_branch_id']) : null,
            
        // Transfer workflow settings - with defaults
        'transfer_workflow_mode' => $settingsData['transfer_workflow_mode'] ?? 'two_step',
        'transfer_allow_ship_on_create' => isset($settingsData['transfer_allow_ship_on_create']) ? 
            (bool)(int)$settingsData['transfer_allow_ship_on_create'] : false,
        'transfer_separation_of_duties' => isset($settingsData['transfer_separation_of_duties']) ? 
            (bool)(int)$settingsData['transfer_separation_of_duties'] : false,
        'transfer_reserve_on_pending' => isset($settingsData['transfer_reserve_on_pending']) ? 
            (bool)(int)$settingsData['transfer_reserve_on_pending'] : false
    ];
    
    // Validate transfer_workflow_mode
    if (!in_array($response['transfer_workflow_mode'], ['one_step', 'two_step'])) {
        $response['transfer_workflow_mode'] = 'two_step';
    }
    
    // Additional computed fields
    $response['tenant_id'] = $tenantId;
    $response['loaded_at'] = date('Y-m-d H:i:s');
    
    echo json_encode([
        'ok' => true, 
        'data' => $response,
        'debug_info' => $DEBUG ? [
            'raw_settings_count' => count($settingsData),
            'tenant_id' => $tenantId,
            'user_id' => (int)($user['id'] ?? 0),
            'timestamp' => date('Y-m-d H:i:s')
        ] : null
    ], JSON_UNESCAPED_UNICODE);
    
} catch (PDOException $e) {
    error_log('Database error in general_get.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false, 
        'error' => 'Database error occurred',
        'debug' => $DEBUG ? $e->getMessage() : null
    ]);
    
} catch (Exception $e) {
    error_log('General error in general_get.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false, 
        'error' => 'An error occurred while loading settings',
        'debug' => $DEBUG ? $e->getMessage() : null
    ]);
    
} catch (Throwable $e) {
    error_log('Fatal error in general_get.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false, 
        'error' => 'A critical error occurred',
        'debug' => $DEBUG ? $e->getMessage() : null
    ]);
}
?>