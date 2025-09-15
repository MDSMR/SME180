<?php
// File: /public_html/controllers/admin/settings/pos.php
declare(strict_types=1);

/**
 * Admin - POS Settings Controller
 * Manages POS configuration including PIN rules, session timeouts, etc.
 * 
 * Path: /controllers/admin/settings/pos.php
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';

// Require admin authentication
auth_require_login();
use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /views/auth/login.php');
    exit;
}

// Check admin/manager role
if (!in_array($user['role_key'] ?? $user['role'] ?? '', ['admin', 'manager', 'owner'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
    exit;
}

$tenantId = (int)$user['tenant_id'];
$branchId = (int)($user['branch_id'] ?? $_SESSION['selected_branch_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? 'get';

header('Content-Type: application/json');

try {
    $pdo = db();
    
    switch ($method) {
        case 'GET':
            // Get current POS settings
            $settings = getPosSettings($pdo, $tenantId);
            echo json_encode(['success' => true, 'data' => $settings, 'error' => null]);
            break;
            
        case 'POST':
            // Update POS settings
            $input = json_decode(file_get_contents('php://input'), true);
            if (!$input) {
                throw new Exception('Invalid input data');
            }
            
            $updated = updatePosSettings($pdo, $tenantId, $input, $user['id']);
            echo json_encode(['success' => true, 'data' => $updated, 'error' => null]);
            break;
            
        case 'PUT':
            // Reset PIN for a user
            if ($action === 'reset_pin') {
                $input = json_decode(file_get_contents('php://input'), true);
                $userId = (int)($input['user_id'] ?? 0);
                $newPin = $input['new_pin'] ?? '';
                
                if (!$userId || !$newPin) {
                    throw new Exception('User ID and new PIN are required');
                }
                
                resetUserPin($pdo, $tenantId, $userId, $newPin, $user['id']);
                echo json_encode(['success' => true, 'data' => ['message' => 'PIN reset successfully'], 'error' => null]);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'data' => null, 'error' => $e->getMessage()]);
}

/**
 * Get current POS settings for tenant
 */
function getPosSettings(PDO $pdo, int $tenantId): array {
    // Default settings
    $defaults = [
        'pos_pin_length' => 4,
        'pos_pin_max_attempts' => 3,
        'pos_pin_lockout_minutes' => 15,
        'pos_session_timeout_minutes' => 480, // 8 hours
        'pos_require_manager_pin' => true,
        'pos_enable_offline_mode' => true,
        'pos_offline_sync_interval' => 30,
        'pos_enable_cash_management' => true,
        'pos_require_cash_session' => true,
        'pos_enable_service_charge' => true,
        'pos_service_charge_percent' => 10,
        'pos_enable_tips' => true,
        'pos_tip_suggestions' => '10,15,20',
        'pos_receipt_header' => 'Welcome to SME 180',
        'pos_receipt_footer' => 'Thank you for your visit!',
        'pos_print_receipt_default' => true,
        'pos_enable_kitchen_display' => true,
        'pos_auto_fire_orders' => false,
        'pos_allow_order_modifications' => true,
        'pos_modification_time_limit' => 10, // minutes
        'pos_enable_table_management' => true,
        'pos_enable_customer_display' => false
    ];
    
    // Get saved settings from database
    $stmt = $pdo->prepare(
        "SELECT `key`, `value`, `data_type` 
         FROM settings 
         WHERE tenant_id = :tenant_id 
           AND `key` LIKE 'pos_%'"
    );
    $stmt->execute(['tenant_id' => $tenantId]);
    
    $settings = $defaults;
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $key = $row['key'];
        $value = $row['value'];
        
        // Convert based on data type
        switch ($row['data_type']) {
            case 'boolean':
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                break;
            case 'number':
            case 'integer':
                $value = is_numeric($value) ? (strpos($value, '.') !== false ? (float)$value : (int)$value) : $value;
                break;
        }
        
        $settings[$key] = $value;
    }
    
    // Get PIN complexity rules
    $settings['pin_rules'] = [
        'min_length' => $settings['pos_pin_length'],
        'require_unique' => true,
        'no_sequential' => true,
        'no_repeated' => true,
        'expires_days' => 90
    ];
    
    // Get station types configuration
    $settings['station_types'] = [
        ['value' => 'pos', 'label' => 'POS Terminal', 'capabilities' => ['order', 'payment', 'print']],
        ['value' => 'bar', 'label' => 'Bar Station', 'capabilities' => ['view', 'update_status']],
        ['value' => 'kitchen', 'label' => 'Kitchen Display', 'capabilities' => ['view', 'update_status']],
        ['value' => 'host', 'label' => 'Host Stand', 'capabilities' => ['table_management', 'reservations']],
        ['value' => 'kds', 'label' => 'Kitchen Display System', 'capabilities' => ['view', 'update_status', 'print']],
        ['value' => 'mobile', 'label' => 'Mobile POS', 'capabilities' => ['order', 'payment']]
    ];
    
    return $settings;
}

/**
 * Update POS settings
 */
function updatePosSettings(PDO $pdo, int $tenantId, array $input, int $userId): array {
    $pdo->beginTransaction();
    
    try {
        $updatedSettings = [];
        
        foreach ($input as $key => $value) {
            // Only update keys that start with 'pos_'
            if (strpos($key, 'pos_') !== 0) {
                continue;
            }
            
            // Determine data type
            $dataType = 'string';
            if (is_bool($value)) {
                $dataType = 'boolean';
                $value = $value ? '1' : '0';
            } elseif (is_numeric($value)) {
                $dataType = 'number';
                $value = (string)$value;
            } else {
                $value = (string)$value;
            }
            
            // Upsert setting
            $stmt = $pdo->prepare(
                "INSERT INTO settings (tenant_id, `key`, `value`, `data_type`, created_at, updated_at)
                 VALUES (:tenant_id, :key, :value, :data_type, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE 
                   `value` = VALUES(`value`),
                   `data_type` = VALUES(`data_type`),
                   `updated_at` = NOW()"
            );
            
            $stmt->execute([
                'tenant_id' => $tenantId,
                'key' => $key,
                'value' => $value,
                'data_type' => $dataType
            ]);
            
            $updatedSettings[$key] = $value;
        }
        
        // Log the update
        $logStmt = $pdo->prepare(
            "INSERT INTO audit_logs (tenant_id, branch_id, user_id, action, entity_type, entity_id, details)
             VALUES (:tenant_id, NULL, :user_id, 'settings_updated', 'pos_settings', :tenant_id, :details)"
        );
        $logStmt->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'details' => json_encode([
                'updated_settings' => array_keys($updatedSettings),
                'timestamp' => date('Y-m-d H:i:s')
            ])
        ]);
        
        $pdo->commit();
        
        return ['message' => 'Settings updated successfully', 'updated' => count($updatedSettings)];
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Reset user PIN
 */
function resetUserPin(PDO $pdo, int $tenantId, int $userId, string $newPin, int $adminId): void {
    // Validate PIN format
    if (!preg_match('/^\d{4,6}$/', $newPin)) {
        throw new Exception('PIN must be 4-6 digits');
    }
    
    // Check if user exists and belongs to tenant
    $stmt = $pdo->prepare(
        "SELECT id, username, name FROM users 
         WHERE id = :user_id AND tenant_id = :tenant_id"
    );
    $stmt->execute(['user_id' => $userId, 'tenant_id' => $tenantId]);
    $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$targetUser) {
        throw new Exception('User not found');
    }
    
    // Update PIN
    $updateStmt = $pdo->prepare(
        "UPDATE users 
         SET pin_code = :pin,
             pin_updated_at = NOW(),
             updated_at = NOW()
         WHERE id = :user_id"
    );
    $updateStmt->execute([
        'pin' => $newPin,
        'user_id' => $userId
    ]);
    
    // Log PIN reset
    $logStmt = $pdo->prepare(
        "INSERT INTO audit_logs (tenant_id, branch_id, user_id, action, entity_type, entity_id, details)
         VALUES (:tenant_id, NULL, :admin_id, 'pin_reset', 'user', :user_id, :details)"
    );
    $logStmt->execute([
        'tenant_id' => $tenantId,
        'admin_id' => $adminId,
        'user_id' => $userId,
        'details' => json_encode([
            'target_user' => $targetUser['username'] ?? $targetUser['name'],
            'reset_by' => $adminId,
            'timestamp' => date('Y-m-d H:i:s')
        ])
    ]);
}
