<?php
/**
 * SME 180 POS - Device Forget API
 * File: /public_html/pos/api/device/forget.php
 * 
 * Removes device registration (switch restaurant)
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

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get input
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Get device token from input or session
$deviceToken = trim($input['device_token'] ?? $_SESSION['pos_device_token'] ?? '');
$managerPin = trim($input['manager_pin'] ?? '');
$reason = trim($input['reason'] ?? 'User requested');

// Validate
if (empty($deviceToken)) {
    respond(false, 'Device token required', 400);
}

// Optional: Require manager PIN for security
if (!empty($managerPin)) {
    // Verify manager PIN
    $userId = (int)($_SESSION['pos_user_id'] ?? 0);
    
    if ($userId) {
        try {
            $pdo = db();
            
            $stmt = $pdo->prepare("
                SELECT manager_pin 
                FROM users 
                WHERE id = :user_id
                AND role_key IN ('admin', 'manager', 'pos_manager')
            ");
            $stmt->execute(['user_id' => $userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($managerPin, $user['manager_pin'])) {
                respond(false, 'Invalid manager PIN', 401);
            }
        } catch (Exception $e) {
            respond(false, 'PIN verification failed', 500);
        }
    }
}

try {
    $pdo = db();
    
    // Get device info before deletion
    $stmt = $pdo->prepare("
        SELECT 
            id, tenant_id, branch_id, 
            device_name, registered_by
        FROM pos_device_registry 
        WHERE device_token = :token
    ");
    $stmt->execute(['token' => $deviceToken]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        respond(false, 'Device not found', 404);
    }
    
    // Mark device as inactive (soft delete)
    $stmt = $pdo->prepare("
        UPDATE pos_device_registry 
        SET is_active = 0,
            last_activity = NOW()
        WHERE device_token = :token
    ");
    $stmt->execute(['token' => $deviceToken]);
    
    // Log the unregistration
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (
            tenant_id, branch_id, user_id,
            action, entity_type, entity_id, 
            details, ip_address
        ) VALUES (
            :tenant_id, :branch_id, :user_id,
            'device_unregistered', 'device', :device_id,
            :details, :ip
        )
    ");
    
    $stmt->execute([
        'tenant_id' => $device['tenant_id'],
        'branch_id' => $device['branch_id'],
        'user_id' => $_SESSION['pos_user_id'] ?? $device['registered_by'],
        'device_id' => $device['id'],
        'details' => json_encode([
            'device_name' => $device['device_name'],
            'reason' => $reason
        ]),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);
    
    // Clear session data
    session_destroy();
    
    // Clear any cookies
    if (isset($_COOKIE['pos_device_token'])) {
        setcookie('pos_device_token', '', time() - 3600, '/');
    }
    if (isset($_COOKIE['tenant_code'])) {
        setcookie('tenant_code', '', time() - 3600, '/');
    }
    
    // Response
    respond(true, [
        'message' => 'Device unregistered successfully',
        'cleared' => [
            'session' => true,
            'cookies' => true,
            'device_registration' => true
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Device forget error: " . $e->getMessage());
    respond(false, 'Failed to unregister device', 500);
}