<?php
// controllers/admin/branches/list.php
declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';
auth_require_login();

// Set error reporting for debugging
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { 
    @ini_set('display_errors','1'); 
    error_reporting(E_ALL); 
} else {
    @ini_set('display_errors','0');
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function json_out(int $code, array $payload): void {
    if (!headers_sent()) {
        http_response_code($code);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Get user from session
    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        json_out(401, ['ok' => false, 'error' => 'Not authenticated']);
    }

    // Get tenant_id from session
    $tenant_id = null;
    if (isset($_SESSION['tenant_id'])) {
        $tenant_id = (int)$_SESSION['tenant_id'];
    } elseif (isset($_SESSION['user']['tenant_id'])) {
        $tenant_id = (int)$_SESSION['user']['tenant_id'];
    } elseif (isset($_SESSION['user']['tenant']['id'])) {
        $tenant_id = (int)$_SESSION['user']['tenant']['id'];
    }
    
    // Default to tenant_id = 1 if not found
    if ($tenant_id === null) {
        $tenant_id = 1;
    }

    // Get PDO connection
    $pdo = db();
    
    // Fetch branches for this tenant
    $sql = "SELECT `id`, `name`, `address`, `phone`, `email`, `branch_type`, 
                   `is_production_enabled`, `timezone`, `business_hours`, 
                   `service_modes`, `is_active` 
            FROM `branches` 
            WHERE `tenant_id` = :tid 
            ORDER BY `is_active` DESC, `name` ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':tid' => $tenant_id]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Ensure data types are correct
    foreach ($branches as &$branch) {
        $branch['id'] = (int)$branch['id'];
        $branch['is_active'] = (int)$branch['is_active'];
        $branch['is_production_enabled'] = (int)($branch['is_production_enabled'] ?? 0);
        $branch['name'] = $branch['name'] ?? '';
        $branch['address'] = $branch['address'] ?? '';
        $branch['phone'] = $branch['phone'] ?? '';
        $branch['email'] = $branch['email'] ?? '';
        $branch['branch_type'] = $branch['branch_type'] ?? 'central_kitchen';
        $branch['timezone'] = $branch['timezone'] ?? 'Africa/Cairo';
        $branch['business_hours'] = $branch['business_hours'] ?? null;
        $branch['service_modes'] = $branch['service_modes'] ?? null;
    }
    
    json_out(200, [
        'ok' => true,
        'data' => $branches,
        'count' => count($branches),
        'tenant_id' => $tenant_id
    ]);
    
} catch (Throwable $e) {
    // Log error for debugging
    error_log('Branches list error: ' . $e->getMessage());
    
    json_out(500, [
        'ok' => false,
        'error' => $DEBUG ? $e->getMessage() : 'Failed to load branches',
        'trace' => $DEBUG ? $e->getTraceAsString() : null
    ]);
}