<?php
// File: /public_html/pos/api/auth/pin_login.php
// UPDATED VERSION - Works with your pos_pin column
declare(strict_types=1);

/**
 * POS Auth - PIN Login
 * Updated to work with pos_pin column
 * Body: { pin, station_code, [tenant_id], [branch_id] }
 */

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
    
    // Check if account is locked
    $lockCheck = $pdo->prepare(
        "SELECT id, pin_locked_until 
         FROM users 
         WHERE pos_pin = :pin 
         LIMIT 1"
    );
    $lockCheck->execute(['pin' => $pin]);
    $lockInfo = $lockCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($lockInfo && $lockInfo['pin_locked_until']) {
        $lockTime = strtotime($lockInfo['pin_locked_until']);
        if ($lockTime > time()) {
            respond(false, 'Account locked. Try again later.', 403);
        }
    }
    
    // Main authentication query using pos_pin
    $sql = "SELECT 
                id, 
                tenant_id,
                name, 
                username,
                email,
                role_key AS role,
                user_type,
                default_station_id,
                can_work_all_stations,
                CASE 
                    WHEN disabled_at IS NOT NULL THEN 'disabled'
                    ELSE 'active'
                END AS status
            FROM users
            WHERE pos_pin = :pin
              AND (disabled_at IS NULL OR disabled_at > NOW())
            LIMIT 1";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['pin' => $pin]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        // Increment failed attempts for the PIN
        if ($lockInfo) {
            $updateAttempts = $pdo->prepare(
                "UPDATE users 
                 SET pin_attempts = COALESCE(pin_attempts, 0) + 1,
                     pin_locked_until = CASE 
                         WHEN COALESCE(pin_attempts, 0) >= 2 
                         THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                         ELSE NULL
                     END
                 WHERE id = :id"
            );
            $updateAttempts->execute(['id' => $lockInfo['id']]);
        }
        
        respond(false, 'Invalid PIN', 401);
    }
    
    // Check if user is disabled
    if ($user['status'] === 'disabled') {
        respond(false, 'User account is disabled', 403);
    }
    
    // Check if user can work at any station or specific station
    if (!$user['can_work_all_stations'] && $user['default_station_id']) {
        // Verify station assignment
        $stationCheck = $pdo->prepare(
            "SELECT id FROM pos_stations 
             WHERE id = :station_id 
               AND station_code = :station_code 
               AND is_active = 1"
        );
        $stationCheck->execute([
            'station_id' => $user['default_station_id'],
            'station_code' => $stationCode
        ]);
        
        if (!$stationCheck->fetch()) {
            respond(false, 'Not authorized for this station', 403);
        }
    }
    
    // Reset failed attempts on successful login
    $resetAttempts = $pdo->prepare(
        "UPDATE users 
         SET pin_attempts = 0, 
             pin_locked_until = NULL,
             last_login = NOW()
         WHERE id = :id"
    );
    $resetAttempts->execute(['id' => $user['id']]);
    
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
    
    // Prepare response
    $response = [
        'user_id' => (int)$user['id'],
        'name' => $user['name'] ?? $user['username'],
        'role' => $user['role'],
        'tenant_id' => (int)$user['tenant_id'],
        'station_code' => $stationCode,
        'session_token' => session_id(),
        'user_type' => $user['user_type'],
        'can_work_all_stations' => (bool)$user['can_work_all_stations']
    ];
    
    if ($station) {
        $response['station_id'] = (int)$station['id'];
        $response['station_name'] = $station['station_name'];
    }
    
    respond(true, $response);
    
} catch (Throwable $e) {
    respond(false, 'Login failed: ' . $e->getMessage(), 500);
}
