<?php
/**
 * SME 180 POS - Device Validation API (WITH ERROR DETAILS)
 * File: /public_html/pos/api/device/validate_setup.php
 * 
 * This version shows the actual error for debugging
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', '1');

header('Content-Type: application/json; charset=utf-8');

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

// Include database config with error checking
$configFile = dirname(__DIR__, 3) . '/config/db.php';
if (!file_exists($configFile)) {
    die(json_encode(['success' => false, 'error' => 'Config file not found at: ' . $configFile]));
}
require_once $configFile;

// Get input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (!$input) {
    die(json_encode(['success' => false, 'error' => 'Invalid JSON input', 'raw' => substr($rawInput, 0, 100)]));
}

$tenantCode = strtoupper(trim($input['tenant_code'] ?? ''));
$username = trim($input['username'] ?? '');
$pin = trim($input['pin'] ?? '');

// Validate inputs
if (empty($tenantCode) || empty($username) || empty($pin)) {
    die(json_encode(['success' => false, 'error' => 'Missing required fields']));
}

if (!preg_match('/^\d{4,6}$/', $pin)) {
    die(json_encode(['success' => false, 'error' => 'PIN must be 4-6 digits']));
}

try {
    // Get database connection
    $pdo = db();
    if (!$pdo) {
        throw new Exception('Database connection failed - db() returned null');
    }
    
    // Step 1: Get tenant
    $stmt = $pdo->prepare("SELECT id, name, is_active FROM tenants WHERE tenant_code = ?");
    $stmt->execute([$tenantCode]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tenant) {
        throw new Exception('Invalid restaurant code: ' . $tenantCode);
    }
    
    if (!$tenant['is_active']) {
        throw new Exception('Restaurant account is inactive');
    }
    
    // Step 2: Get user
    $stmt = $pdo->prepare("
        SELECT u.*, r.name as role_name 
        FROM users u
        LEFT JOIN roles r ON u.role_key = r.role_key
        WHERE u.tenant_id = ? AND u.username = ?
    ");
    $stmt->execute([$tenant['id'], $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        throw new Exception('User not found: ' . $username);
    }
    
    if ($user['disabled_at']) {
        throw new Exception('User account is disabled');
    }
    
    // Step 3: Verify PIN
    if (!$user['pos_pin']) {
        throw new Exception('User has no PIN set');
    }
    
    if (!password_verify($pin, $user['pos_pin'])) {
        throw new Exception('Invalid PIN');
    }
    
    // Step 4: Get branches
    $stmt = $pdo->prepare("
        SELECT b.id, b.name, b.branch_type, b.address
        FROM branches b
        JOIN user_branches ub ON b.id = ub.branch_id
        WHERE ub.user_id = ? AND b.tenant_id = ? AND b.is_active = 1
        ORDER BY b.name
    ");
    $stmt->execute([$user['id'], $tenant['id']]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($branches)) {
        throw new Exception('No branches available for user');
    }
    
    // Step 5: Get settings (optional - may not exist)
    $settings = [];
    try {
        $stmt = $pdo->prepare("
            SELECT `key`, `value` 
            FROM settings 
            WHERE tenant_id = ? AND `key` LIKE 'pos_%'
        ");
        $stmt->execute([$tenant['id']]);
        $settingsRaw = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        foreach ($settingsRaw as $key => $value) {
            $key = str_replace('pos_', '', $key);
            // Convert booleans
            if ($value === '1' || $value === 'true') $value = true;
            elseif ($value === '0' || $value === 'false') $value = false;
            elseif (is_numeric($value)) $value = (strpos($value, '.') !== false) ? (float)$value : (int)$value;
            $settings[$key] = $value;
        }
    } catch (Exception $e) {
        // Settings table might not exist - that's OK
        $settings = [];
    }
    
    // Add defaults
    $settings = array_merge([
        'require_cash_session' => true,
        'enable_offline_mode' => true,
        'offline_sync_interval' => 30,
        'pin_max_attempts' => 5,
        'pin_lockout_minutes' => 15
    ], $settings);
    
    // Step 6: Generate device token
    $deviceToken = bin2hex(random_bytes(32));
    $deviceFingerprint = hash('sha256', json_encode([
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'time' => time()
    ]));
    
    // Step 7: Try to log (optional - may fail)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                tenant_id, user_id, action,
                entity_type, details, ip_address, device_fingerprint, created_at
            ) VALUES (
                :tenant_id, :user_id, 'device_setup_validated',
                'device', :details, :ip, :fingerprint, NOW()
            )
        ");
        
        $stmt->execute([
            'tenant_id' => $tenant['id'],
            'user_id' => $user['id'],
            'details' => json_encode(['device_token' => substr($deviceToken, 0, 20) . '...']),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'fingerprint' => $deviceFingerprint
        ]);
    } catch (Exception $e) {
        // Logging failed - not critical, continue
        error_log("Audit log failed: " . $e->getMessage());
    }
    
    // Success response
    $response = [
        'success' => true,
        'data' => [
            'valid' => true,
            'tenant' => [
                'id' => (int)$tenant['id'],
                'name' => $tenant['name'],
                'code' => $tenantCode
            ],
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'username' => $user['username'],
                'role' => $user['role_key'],
                'role_name' => $user['role_name'] ?? $user['role_key']
            ],
            'branches' => array_map(function($b) {
                return [
                    'id' => (int)$b['id'],
                    'name' => $b['name'],
                    'type' => $b['branch_type'] ?? 'restaurant',
                    'address' => $b['address'] ?? null
                ];
            }, $branches),
            'device' => [
                'token' => $deviceToken,
                'fingerprint' => $deviceFingerprint
            ],
            'settings' => $settings,
            'requires_branch_selection' => count($branches) > 1
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    // Return the ACTUAL error message for debugging
    error_log("Device validation error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'trace' => array_slice($e->getTrace(), 0, 3)
        ]
    ]);
}