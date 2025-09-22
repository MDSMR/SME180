<?php
/**
 * SME 180 POS - Updated PIN Login with Device Support
 * File: /public_html/pos/api/auth/pin_login.php
 * Version: 4.0.0 - Device-aware login
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

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

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

$userId = (int)($input['user_id'] ?? 0);
$pin = trim($input['pin'] ?? '');
$deviceToken = trim($input['device_token'] ?? '');

// Validate
if (!$userId || empty($pin)) {
    respond(false, 'User ID and PIN required', 400);
}

if (!preg_match('/^\d{4,6}$/', $pin)) {
    respond(false, 'Invalid PIN format', 400);
}

try {
    $pdo = db();
    
    // Get device registration if token provided
    $device = null;
    $tenantId = null;
    $branchId = null;
    
    if ($deviceToken) {
        $stmt = $pdo->prepare("
            SELECT 
                id, tenant_id, branch_id, 
                device_name, is_active
            FROM pos_device_registry 
            WHERE device_token = :token
        ");
        $stmt->execute(['token' => $deviceToken]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($device && $device['is_active']) {
            $tenantId = $device['tenant_id'];
            $branchId = $device['branch_id'];
            
            // Update last activity
            $stmt = $pdo->prepare("
                UPDATE pos_device_registry 
                SET last_activity = NOW() 
                WHERE id = :id
            ");
            $stmt->execute(['id' => $device['id']]);
        }
    }
    
    // Get user with tenant context
    $sql = "
        SELECT 
            u.id,
            u.tenant_id,
            u.name,
            u.username,
            u.email,
            u.role_key,
            u.user_type,
            u.pos_pin,
            u.pin_attempts,
            u.pin_locked_until,
            u.disabled_at,
            u.last_branch_id,
            r.name as role_name,
            t.name as tenant_name,
            t.tenant_code,
            t.is_active as tenant_active
        FROM users u
        LEFT JOIN roles r ON u.role_key = r.role_key
        LEFT JOIN tenants t ON u.tenant_id = t.id
        WHERE u.id = :user_id
    ";
    
    // Add tenant filter if device is registered
    if ($tenantId) {
        $sql .= " AND u.tenant_id = :tenant_id";
    }
    
    $stmt = $pdo->prepare($sql);
    $params = ['user_id' => $userId];
    if ($tenantId) {
        $params['tenant_id'] = $tenantId;
    }
    
    $stmt->execute($params);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        respond(false, 'Invalid user or device context', 401);
    }
    
    // Check various conditions
    if ($user['disabled_at']) {
        respond(false, 'User account is disabled', 403);
    }
    
    if (!$user['tenant_active']) {
        respond(false, 'Restaurant account is inactive', 403);
    }
    
    if (!in_array($user['user_type'], ['pos', 'both'])) {
        respond(false, 'User does not have POS access', 403);
    }
    
    // Check rate limiting
    if ($user['pin_locked_until'] && strtotime($user['pin_locked_until']) > time()) {
        $minutesLeft = ceil((strtotime($user['pin_locked_until']) - time()) / 60);
        respond(false, "Account locked. Try again in $minutesLeft minutes", 429);
    }
    
    // Verify PIN
    if (!password_verify($pin, $user['pos_pin'])) {
        // Increment failed attempts
        $attempts = ($user['pin_attempts'] ?? 0) + 1;
        $lockUntil = null;
        
        if ($attempts >= 5) {
            $lockUntil = date('Y-m-d H:i:s', time() + 900);
        }
        
        $stmt = $pdo->prepare("
            UPDATE users 
            SET pin_attempts = :attempts,
                pin_locked_until = :lock_until
            WHERE id = :user_id
        ");
        
        $stmt->execute([
            'attempts' => $attempts,
            'lock_until' => $lockUntil,
            'user_id' => $userId
        ]);
        
        if ($lockUntil) {
            respond(false, 'Too many attempts. Locked for 15 minutes', 429);
        } else {
            respond(false, "Invalid PIN. " . (5 - $attempts) . " attempts left", 401);
        }
    }
    
    // Success - reset attempts
    $stmt = $pdo->prepare("
        UPDATE users 
        SET pin_attempts = 0,
            pin_locked_until = NULL,
            last_login = NOW()
        WHERE id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    
    // Get user's branches (with device context)
    $sql = "
        SELECT 
            b.id,
            b.name,
            b.branch_type,
            b.address,
            COALESCE(s.value, '$') as currency_symbol
        FROM branches b
        JOIN user_branches ub ON b.id = ub.branch_id
        LEFT JOIN settings s ON s.tenant_id = b.tenant_id AND s.key = 'currency_symbol'
        WHERE ub.user_id = :user_id
          AND b.tenant_id = :tenant_id
          AND b.is_active = 1
    ";
    
    // If device has branch, prioritize it
    if ($branchId) {
        $sql .= " ORDER BY (b.id = :branch_id) DESC, b.name";
    } else {
        $sql .= " ORDER BY b.name";
    }
    
    $stmt = $pdo->prepare($sql);
    $params = [
        'user_id' => $userId,
        'tenant_id' => $user['tenant_id']
    ];
    if ($branchId) {
        $params['branch_id'] = $branchId;
    }
    
    $stmt->execute($params);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Set session
    $_SESSION['pos_user_id'] = (int)$user['id'];
    $_SESSION['pos_user_name'] = $user['name'];
    $_SESSION['pos_username'] = $user['username'];
    $_SESSION['pos_role'] = $user['role_key'];
    $_SESSION['tenant_id'] = (int)$user['tenant_id'];
    $_SESSION['tenant_name'] = $user['tenant_name'];
    $_SESSION['tenant_code'] = $user['tenant_code'];
    
    // Set branch from device or user preference
    $selectedBranch = null;
    if ($branchId) {
        // Use device branch
        foreach ($branches as $branch) {
            if ($branch['id'] == $branchId) {
                $selectedBranch = $branch;
                break;
            }
        }
    } elseif (count($branches) === 1) {
        // Single branch
        $selectedBranch = $branches[0];
    } elseif ($user['last_branch_id']) {
        // Use last branch
        foreach ($branches as $branch) {
            if ($branch['id'] == $user['last_branch_id']) {
                $selectedBranch = $branch;
                break;
            }
        }
    }
    
    if ($selectedBranch) {
        $_SESSION['branch_id'] = (int)$selectedBranch['id'];
        $_SESSION['branch_name'] = $selectedBranch['name'];
        $_SESSION['currency_symbol'] = $selectedBranch['currency_symbol'];
    }
    
    // Log successful login
    $stmt = $pdo->prepare("
        INSERT INTO audit_logs (
            tenant_id, branch_id, user_id,
            action, entity_type, details, ip_address, device_fingerprint
        ) VALUES (
            :tenant_id, :branch_id, :user_id,
            'pos_login', 'authentication', :details, :ip, :fingerprint
        )
    ");
    
    $stmt->execute([
        'tenant_id' => $user['tenant_id'],
        'branch_id' => $_SESSION['branch_id'] ?? null,
        'user_id' => $user['id'],
        'details' => json_encode([
            'method' => 'pin',
            'device' => $device ? $device['device_name'] : 'unknown'
        ]),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'fingerprint' => $input['device_fingerprint'] ?? null
    ]);
    
    // Response
    respond(true, [
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role_key'],
            'role_name' => $user['role_name']
        ],
        'tenant' => [
            'id' => (int)$user['tenant_id'],
            'name' => $user['tenant_name'],
            'code' => $user['tenant_code']
        ],
        'branches' => array_map(function($b) {
            return [
                'id' => (int)$b['id'],
                'name' => $b['name'],
                'type' => $b['branch_type'],
                'currency_symbol' => $b['currency_symbol']
            ];
        }, $branches),
        'selected_branch' => $selectedBranch ? [
            'id' => (int)$selectedBranch['id'],
            'name' => $selectedBranch['name']
        ] : null,
        'session' => [
            'id' => session_id(),
            'branch_set' => isset($_SESSION['branch_id'])
        ]
    ]);
    
} catch (Exception $e) {
    error_log("PIN login error: " . $e->getMessage());
    respond(false, 'Login failed', 500);
}