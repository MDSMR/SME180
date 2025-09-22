<?php
/**
 * SME 180 POS - Device Registration API
 * File: /public_html/pos/api/device/register.php
 * 
 * Registers a device after successful validation
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

require_once __DIR__ . '/../../../config/db.php';

function respond($success, $data = null, $code = 200) {
    http_response_code($code);
    echo json_encode($success ? ['success' => true, 'data' => $data] : ['success' => false, 'error' => $data]);
    exit;
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    respond(false, 'Invalid JSON input', 400);
}

$deviceToken = trim($input['device_token'] ?? '');
$tenantId = (int)($input['tenant_id'] ?? 0);
$branchId = (int)($input['branch_id'] ?? 0);
$userId = (int)($input['user_id'] ?? 0);
$deviceName = trim($input['device_name'] ?? 'POS Terminal');
$deviceFingerprint = trim($input['device_fingerprint'] ?? '');

// Validate required fields
if (empty($deviceToken) || !$tenantId || !$branchId || !$userId) {
    respond(false, 'Missing required fields', 400);
}

try {
    $pdo = db();
    
    // Check if device already registered
    $stmt = $pdo->prepare("
        SELECT id, is_active 
        FROM pos_device_registry 
        WHERE device_token = :token
    ");
    $stmt->execute(['token' => $deviceToken]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing registration
        $stmt = $pdo->prepare("
            UPDATE pos_device_registry 
            SET tenant_id = :tenant_id,
                branch_id = :branch_id,
                device_name = :device_name,
                device_fingerprint = :fingerprint,
                user_agent = :user_agent,
                ip_address = :ip,
                registered_by = :user_id,
                last_activity = NOW(),
                is_active = 1
            WHERE device_token = :token
        ");
        
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'device_name' => $deviceName,
            'fingerprint' => $deviceFingerprint,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_id' => $userId,
            'token' => $deviceToken
        ]);
        
        $deviceId = $existing['id'];
        $message = 'Device registration updated';
        
    } else {
        // Create new registration
        $stmt = $pdo->prepare("
            INSERT INTO pos_device_registry (
                tenant_id, branch_id, device_token,
                device_name, device_fingerprint,
                user_agent, ip_address, registered_by
            ) VALUES (
                :tenant_id, :branch_id, :token,
                :device_name, :fingerprint,
                :user_agent, :ip, :user_id
            )
        ");
        
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'token' => $deviceToken,
            'device_name' => $deviceName,
            'fingerprint' => $deviceFingerprint,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_id' => $userId
        ]);
        
        $deviceId = $pdo->lastInsertId();
        $message = 'Device registered successfully';
    }
    
    // Update user's last branch
    $stmt = $pdo->prepare("
        UPDATE users 
        SET last_branch_id = :branch_id 
        WHERE id = :user_id
    ");
    $stmt->execute([
        'branch_id' => $branchId,
        'user_id' => $userId
    ]);
    
    // Get branch and tenant info with currency from settings
    $stmt = $pdo->prepare("
        SELECT 
            b.name as branch_name,
            t.name as tenant_name,
            COALESCE(s.value, '$') as currency_symbol
        FROM branches b
        JOIN tenants t ON b.tenant_id = t.id
        LEFT JOIN settings s ON s.tenant_id = t.id AND s.key = 'currency_symbol'
        WHERE b.id = :branch_id
    ");
    $stmt->execute(['branch_id' => $branchId]);
    $info = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log registration
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (
            tenant_id, branch_id, user_id,
            action, entity_type, entity_id, details, ip_address
        ) VALUES (
            :tenant_id, :branch_id, :user_id,
            'device_registered', 'device', :device_id, :details, :ip
        )
    ");
    
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'device_id' => $deviceId,
        'details' => json_encode([
            'device_name' => $deviceName,
            'device_token' => substr($deviceToken, 0, 8) . '...'
        ]),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Set session variables
    $_SESSION['tenant_id'] = $tenantId;
    $_SESSION['branch_id'] = $branchId;
    $_SESSION['pos_device_id'] = $deviceId;
    $_SESSION['pos_device_token'] = $deviceToken;
    $_SESSION['tenant_name'] = $info['tenant_name'] ?? '';
    $_SESSION['branch_name'] = $info['branch_name'] ?? '';
    $_SESSION['currency_symbol'] = $info['currency_symbol'] ?? '$';
    
    // Response
    respond(true, [
        'message' => $message,
        'device_id' => $deviceId,
        'tenant_name' => $info['tenant_name'] ?? '',
        'branch_name' => $info['branch_name'] ?? '',
        'currency_symbol' => $info['currency_symbol'] ?? '$',
        'session_id' => session_id()
    ]);
    
} catch (Exception $e) {
    error_log("Device registration error: " . $e->getMessage());
    respond(false, 'Registration failed', 500);
}