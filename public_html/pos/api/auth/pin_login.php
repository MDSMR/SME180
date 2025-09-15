<?php
// File: /public_html/pos/api/auth/pin_login.php
// FIXED VERSION - Resolves parameter mismatch
declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

$in = read_input();
$pin = $in['pin'] ?? '';
$stationCode = $in['station_code'] ?? '';

if ($pin === '' || $stationCode === '') {
    respond(false, 'Missing pin or station_code', 400);
}

try {
    $pdo = db();
    
    // Main authentication query - simple version that works
    $sql = "SELECT 
                id, 
                tenant_id,
                name, 
                username,
                email,
                role_key AS role,
                user_type,
                can_work_all_stations
            FROM users
            WHERE pos_pin = :pin
              AND (disabled_at IS NULL OR disabled_at > NOW())
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['pin' => $pin]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        respond(false, 'Invalid PIN', 401);
    }
    
    // Update last login
    $updateStmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
    $updateStmt->execute(['id' => $user['id']]);
    
    // Get or register station
    $stationStmt = $pdo->prepare(
        "SELECT id, station_name 
         FROM pos_stations 
         WHERE tenant_id = :tenant_id 
           AND station_code = :station_code 
         LIMIT 1"
    );
    $stationStmt->execute([
        'tenant_id' => $user['tenant_id'],
        'station_code' => $stationCode
    ]);
    $station = $stationStmt->fetch(PDO::FETCH_ASSOC);
    
    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
    
    // Set session variables
    $_SESSION['pos_user_id'] = (int)$user['id'];
    $_SESSION['pos_user_name'] = $user['name'] ?? $user['username'];
    $_SESSION['pos_user_role'] = $user['role'];
    $_SESSION['tenant_id'] = (int)$user['tenant_id'];
    $_SESSION['station_code'] = $stationCode;
    
    if ($station) {
        $_SESSION['station_id'] = (int)$station['id'];
    }
    
    // Simple audit log without entity_type issues
    try {
        $logStmt = $pdo->prepare(
            "INSERT INTO audit_logs (tenant_id, user_id, action, entity_type, entity_id, details, created_at)
             VALUES (:tenant_id, :user_id, 'pos_login', 'user', :entity_id, :details, NOW())"
        );
        $logStmt->execute([
            'tenant_id' => $user['tenant_id'],
            'user_id' => $user['id'],
            'entity_id' => $user['id'],
            'details' => json_encode([
                'station_code' => $stationCode,
                'login_time' => date('Y-m-d H:i:s')
            ])
        ]);
    } catch (Exception $e) {
        // Ignore audit log errors
    }
    
    // Prepare response
    respond(true, [
        'user_id' => (int)$user['id'],
        'name' => $user['name'] ?? $user['username'],
        'role' => $user['role'],
        'tenant_id' => (int)$user['tenant_id'],
        'station_code' => $stationCode,
        'station_id' => isset($station['id']) ? (int)$station['id'] : null,
        'session_token' => session_id()
    ]);
    
} catch (Throwable $e) {
    respond(false, 'Login failed: ' . $e->getMessage(), 500);
}
