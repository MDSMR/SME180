<?php
/**
 * SME 180 POS - User Branches API
 * File: /public_html/pos/api/branches/user_branches.php
 * 
 * Returns branches available to a user
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

// Get user ID and tenant ID
$userId = (int)($_REQUEST['user_id'] ?? $_SESSION['pos_user_id'] ?? 0);
$tenantId = (int)($_REQUEST['tenant_id'] ?? $_SESSION['tenant_id'] ?? 0);

if (!$userId) {
    respond(false, 'User ID required', 400);
}

try {
    $pdo = db();
    
    // Get user info
    $stmt = $pdo->prepare("
        SELECT 
            u.id,
            u.tenant_id,
            u.name,
            u.username,
            u.last_branch_id,
            u.role_key
        FROM users u
        WHERE u.id = :user_id
    ");
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        respond(false, 'User not found', 404);
    }
    
    // Use user's tenant if not specified
    if (!$tenantId) {
        $tenantId = (int)$user['tenant_id'];
    }
    
    // Verify tenant match
    if ($user['tenant_id'] != $tenantId) {
        respond(false, 'Tenant mismatch', 403);
    }
    
    // Get user's branches
    $stmt = $pdo->prepare("
        SELECT 
            b.id,
            b.name,
            b.branch_type,
            b.address,
            b.phone,
            b.email,
            b.timezone,
            b.is_active,
            COALESCE(s.value, '$') as currency_symbol,
            (SELECT COUNT(*) FROM pos_stations WHERE branch_id = b.id AND is_active = 1) as station_count,
            (SELECT COUNT(*) FROM users u2 
             JOIN user_branches ub2 ON u2.id = ub2.user_id 
             WHERE ub2.branch_id = b.id AND u2.disabled_at IS NULL) as user_count,
            CASE WHEN b.id = :last_branch_id THEN 1 ELSE 0 END as is_last_used
        FROM branches b
        JOIN user_branches ub ON b.id = ub.branch_id
        LEFT JOIN settings s ON s.tenant_id = b.tenant_id AND s.key = 'currency_symbol'
        WHERE ub.user_id = :user_id
          AND b.tenant_id = :tenant_id
          AND b.is_active = 1
        ORDER BY is_last_used DESC, b.name
    ");
    
    $stmt->execute([
        'user_id' => $userId,
        'tenant_id' => $tenantId,
        'last_branch_id' => $user['last_branch_id'] ?? 0
    ]);
    
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($branches)) {
        respond(false, 'No branches available', 404);
    }
    
    // Format branches
    $formattedBranches = array_map(function($branch) {
        return [
            'id' => (int)$branch['id'],
            'name' => $branch['name'],
            'type' => $branch['branch_type'],
            'address' => $branch['address'],
            'phone' => $branch['phone'],
            'email' => $branch['email'],
            'timezone' => $branch['timezone'] ?? 'UTC',
            'currency_symbol' => $branch['currency_symbol'],
            'station_count' => (int)$branch['station_count'],
            'user_count' => (int)$branch['user_count'],
            'is_last_used' => (bool)$branch['is_last_used']
        ];
    }, $branches);
    
    // Determine recommended branch
    $recommendedBranch = null;
    if (count($formattedBranches) === 1) {
        $recommendedBranch = $formattedBranches[0];
    } else {
        // Find last used or first
        foreach ($formattedBranches as $branch) {
            if ($branch['is_last_used']) {
                $recommendedBranch = $branch;
                break;
            }
        }
        if (!$recommendedBranch) {
            $recommendedBranch = $formattedBranches[0];
        }
    }
    
    // Response
    respond(true, [
        'user' => [
            'id' => (int)$user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role_key']
        ],
        'branches' => $formattedBranches,
        'recommended_branch' => $recommendedBranch,
        'requires_selection' => count($formattedBranches) > 1
    ]);
    
} catch (Exception $e) {
    error_log("User branches error: " . $e->getMessage());
    respond(false, 'Failed to load branches', 500);
}

/**
 * Set selected branch (POST request)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $selectedBranchId = (int)($input['branch_id'] ?? 0);
    
    if ($selectedBranchId) {
        try {
            // Verify user has access to this branch
            $hasAccess = false;
            foreach ($branches as $branch) {
                if ($branch['id'] == $selectedBranchId) {
                    $hasAccess = true;
                    break;
                }
            }
            
            if ($hasAccess) {
                // Update user's last branch
                $stmt = $pdo->prepare("
                    UPDATE users 
                    SET last_branch_id = :branch_id 
                    WHERE id = :user_id
                ");
                $stmt->execute([
                    'branch_id' => $selectedBranchId,
                    'user_id' => $userId
                ]);
                
                // Update session
                $_SESSION['branch_id'] = $selectedBranchId;
                $_SESSION['branch_name'] = $branch['name'];
                $_SESSION['currency_symbol'] = $branch['currency_symbol'];
                
                respond(true, [
                    'message' => 'Branch selected',
                    'branch_id' => $selectedBranchId
                ]);
            } else {
                respond(false, 'Access denied to this branch', 403);
            }
        } catch (Exception $e) {
            respond(false, 'Failed to set branch', 500);
        }
    }
}