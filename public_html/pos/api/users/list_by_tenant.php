<?php
/**
 * SME 180 POS - Users List by Tenant API
 * File: /public_html/pos/api/users/list_by_tenant.php
 * 
 * Returns all users for tenant (for sidebar display)
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

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
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

// Get tenant ID from various sources
$tenantId = null;

// 1. From session (if device is registered)
if (isset($_SESSION['tenant_id'])) {
    $tenantId = (int)$_SESSION['tenant_id'];
}

// 2. From request parameter
if (!$tenantId && isset($_REQUEST['tenant_id'])) {
    $tenantId = (int)$_REQUEST['tenant_id'];
}

// 3. From device token
$deviceToken = $_REQUEST['device_token'] ?? '';
if (!$tenantId && $deviceToken) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT tenant_id 
            FROM pos_device_registry 
            WHERE device_token = :token AND is_active = 1
        ");
        $stmt->execute(['token' => $deviceToken]);
        $device = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($device) {
            $tenantId = (int)$device['tenant_id'];
        }
    } catch (Exception $e) {
        // Continue without tenant from device
    }
}

if (!$tenantId) {
    respond(false, 'Tenant context required', 400);
}

try {
    $pdo = db();
    
    // Get tenant info
    $stmt = $pdo->prepare("
        SELECT name, tenant_code, is_active 
        FROM tenants 
        WHERE id = :tenant_id
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tenant || !$tenant['is_active']) {
        respond(false, 'Invalid or inactive tenant', 403);
    }
    
    // Get all POS users for this tenant
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.name,
            u.username,
            u.email,
            u.role_key,
            u.user_type,
            u.disabled_at,
            u.last_login,
            r.name as role_name,
            CASE 
                WHEN u.role_key IN ('admin', 'manager') THEN 1
                WHEN u.role_key LIKE 'pos_manager%' THEN 2
                WHEN u.role_key LIKE 'pos_headwaiter%' THEN 3
                WHEN u.role_key LIKE 'pos_cashier%' THEN 4
                WHEN u.role_key LIKE 'pos_waiter%' THEN 5
                ELSE 6
            END as role_priority
        FROM users u
        LEFT JOIN roles r ON u.role_key = r.role_key
        WHERE u.tenant_id = :tenant_id
          AND u.user_type IN ('pos', 'both')
          AND u.disabled_at IS NULL
        ORDER BY role_priority, u.name
    ");
    
    $stmt->execute(['tenant_id' => $tenantId]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group users by role
    $groupedUsers = [
        'managers' => [],
        'cashiers' => [],
        'waiters' => [],
        'others' => []
    ];
    
    foreach ($users as $user) {
        // Create user object
        $userObj = [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role_key'],
            'role_name' => $user['role_name'],
            'initials' => getInitials($user['name']),
            'color' => getColorForUser($user['id']),
            'last_login' => $user['last_login']
        ];
        
        // Group by role
        if (in_array($user['role_key'], ['admin', 'manager', 'pos_manager'])) {
            $groupedUsers['managers'][] = $userObj;
        } elseif (strpos($user['role_key'], 'cashier') !== false) {
            $groupedUsers['cashiers'][] = $userObj;
        } elseif (strpos($user['role_key'], 'waiter') !== false || strpos($user['role_key'], 'headwaiter') !== false) {
            $groupedUsers['waiters'][] = $userObj;
        } else {
            $groupedUsers['others'][] = $userObj;
        }
    }
    
    // Response
    respond(true, [
        'tenant' => [
            'id' => $tenantId,
            'name' => $tenant['name'],
            'code' => $tenant['tenant_code']
        ],
        'users' => $users,
        'grouped_users' => $groupedUsers,
        'counts' => [
            'total' => count($users),
            'managers' => count($groupedUsers['managers']),
            'cashiers' => count($groupedUsers['cashiers']),
            'waiters' => count($groupedUsers['waiters']),
            'others' => count($groupedUsers['others'])
        ]
    ]);
    
} catch (Exception $e) {
    error_log("Users list error: " . $e->getMessage());
    respond(false, 'Failed to load users', 500);
}

/**
 * Get initials from name
 */
function getInitials(string $name): string {
    $parts = explode(' ', trim($name));
    $initials = '';
    
    foreach ($parts as $i => $part) {
        if ($i < 2 && strlen($part) > 0) {
            $initials .= strtoupper($part[0]);
        }
    }
    
    return $initials ?: 'U';
}

/**
 * Get color based on user ID
 */
function getColorForUser(int $id): string {
    $colors = [
        '#3B82F6', // Blue
        '#10B981', // Green
        '#F59E0B', // Amber
        '#8B5CF6', // Purple
        '#EC4899', // Pink
        '#14B8A6', // Teal
        '#F97316', // Orange
        '#06B6D4'  // Cyan
    ];
    
    return $colors[$id % count($colors)];
}