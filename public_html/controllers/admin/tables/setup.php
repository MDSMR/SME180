<?php
declare(strict_types=1);

/**
 * Tables Management Setup Controller
 * Path: /public_html/controllers/admin/tables/setup.php
 */

// Enable error reporting for debugging (comment out in production)
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, $data = null, ?string $error = null, int $code = 200): void {
    http_response_code($code);
    $response = ['ok' => $ok, 'data' => $data, 'error' => $error];
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

try {
    // Check and include required files
    $configPath = dirname(__DIR__, 3) . '/config/db.php';
    $authPath = dirname(__DIR__, 3) . '/middleware/auth_login.php';
    
    if (!file_exists($configPath)) {
        respond(false, null, 'Config file not found at: ' . $configPath, 500);
    }
    
    if (!file_exists($authPath)) {
        respond(false, null, 'Auth file not found at: ' . $authPath, 500);
    }
    
    require_once $configPath;
    require_once $authPath;
    
    // Authenticate user
    auth_require_login();
    
    // Use backend session if available
    if (function_exists('use_backend_session')) { 
        use_backend_session(); 
    }
    
    // Start session if not already started
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    
    // Get user from session
    $user = $_SESSION['user'] ?? null;
    if (!$user) {
        respond(false, null, 'User not logged in', 401);
    }
    
    $tenantId = (int)($user['tenant_id'] ?? 0);
    $userId = (int)($user['id'] ?? 0);
    $branchId = (int)($user['branch_id'] ?? 1);
    
    if ($tenantId <= 0) {
        respond(false, null, 'Invalid tenant ID', 403);
    }
    
    // Get database connection
    if (!function_exists('db')) {
        respond(false, null, 'Database function not found', 500);
    }
    
    $pdo = db();
    if (!$pdo) {
        respond(false, null, 'Database connection failed', 500);
    }
    
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get request method and action
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    
    // Handle GET requests
    if ($method === 'GET') {
        $action = $_GET['action'] ?? 'list';
        
        if ($action === 'list') {
            try {
                // Get all tables - simplified query without JOIN first
                $sql = "
                    SELECT 
                        id,
                        table_number,
                        section,
                        seats,
                        status,
                        assigned_waiter_id,
                        needs_cleaning,
                        notes
                    FROM dining_tables
                    WHERE tenant_id = :tenant_id
                    ORDER BY section, table_number
                ";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([':tenant_id' => $tenantId]);
                $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Convert data types
                foreach ($tables as &$table) {
                    $table['id'] = (int)$table['id'];
                    $table['seats'] = (int)$table['seats'];
                    $table['assigned_waiter_id'] = $table['assigned_waiter_id'] ? (int)$table['assigned_waiter_id'] : null;
                    $table['needs_cleaning'] = (bool)($table['needs_cleaning'] ?? 0);
                    $table['status'] = $table['status'] ?? 'free';
                    $table['notes'] = $table['notes'] ?? '';
                    $table['waiter_name'] = null; // Add this field even if empty
                }
                
                respond(true, $tables);
                
            } catch (PDOException $e) {
                respond(false, null, 'Database query error: ' . $e->getMessage(), 500);
            }
        }
        
        respond(false, null, 'Unknown action', 400);
    }
    
    // Handle POST requests
    if ($method === 'POST') {
        $rawInput = file_get_contents('php://input');
        if (empty($rawInput)) {
            respond(false, null, 'Empty request body', 400);
        }
        
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            respond(false, null, 'Invalid JSON: ' . json_last_error_msg(), 400);
        }
        
        $action = $input['action'] ?? '';
        
        switch ($action) {
            case 'create':
                $tableNumber = trim($input['table_number'] ?? '');
                $section = trim($input['section'] ?? 'main');
                $seats = (int)($input['seats'] ?? 4);
                
                if (empty($tableNumber)) {
                    respond(false, null, 'Table number is required', 400);
                }
                
                if ($seats < 1 || $seats > 50) {
                    respond(false, null, 'Seats must be between 1 and 50', 400);
                }
                
                try {
                    // Check if table exists
                    $checkSql = "
                        SELECT COUNT(*) 
                        FROM dining_tables 
                        WHERE tenant_id = :tenant_id 
                        AND table_number = :table_number
                        AND branch_id = :branch_id
                    ";
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->execute([
                        ':tenant_id' => $tenantId,
                        ':table_number' => $tableNumber,
                        ':branch_id' => $branchId
                    ]);
                    
                    if ($checkStmt->fetchColumn() > 0) {
                        respond(false, null, 'Table number already exists', 400);
                    }
                    
                    // Insert new table
                    $insertSql = "
                        INSERT INTO dining_tables 
                        (tenant_id, branch_id, table_number, section, seats, status, needs_cleaning, created_at)
                        VALUES 
                        (:tenant_id, :branch_id, :table_number, :section, :seats, 'free', 0, NOW())
                    ";
                    
                    $insertStmt = $pdo->prepare($insertSql);
                    $insertStmt->execute([
                        ':tenant_id' => $tenantId,
                        ':branch_id' => $branchId,
                        ':table_number' => $tableNumber,
                        ':section' => $section,
                        ':seats' => $seats
                    ]);
                    
                    $newId = (int)$pdo->lastInsertId();
                    
                    respond(true, [
                        'id' => $newId,
                        'table_number' => $tableNumber,
                        'section' => $section,
                        'seats' => $seats,
                        'status' => 'free',
                        'needs_cleaning' => false
                    ]);
                    
                } catch (PDOException $e) {
                    respond(false, null, 'Database error: ' . $e->getMessage(), 500);
                }
                break;
                
            case 'update':
                $id = (int)($input['id'] ?? 0);
                $tableNumber = trim($input['table_number'] ?? '');
                $section = trim($input['section'] ?? '');
                $seats = (int)($input['seats'] ?? 0);
                
                if ($id <= 0) {
                    respond(false, null, 'Invalid table ID', 400);
                }
                
                if (empty($tableNumber) || empty($section) || $seats <= 0) {
                    respond(false, null, 'All fields are required', 400);
                }
                
                try {
                    // Check for duplicate
                    $checkSql = "
                        SELECT COUNT(*) 
                        FROM dining_tables 
                        WHERE tenant_id = :tenant_id 
                        AND table_number = :table_number
                        AND id != :id
                        AND branch_id = :branch_id
                    ";
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->execute([
                        ':tenant_id' => $tenantId,
                        ':table_number' => $tableNumber,
                        ':id' => $id,
                        ':branch_id' => $branchId
                    ]);
                    
                    if ($checkStmt->fetchColumn() > 0) {
                        respond(false, null, 'Table number already exists', 400);
                    }
                    
                    // Update table
                    $updateSql = "
                        UPDATE dining_tables 
                        SET table_number = :table_number,
                            section = :section,
                            seats = :seats,
                            updated_at = NOW()
                        WHERE id = :id 
                        AND tenant_id = :tenant_id
                    ";
                    
                    $updateStmt = $pdo->prepare($updateSql);
                    $updateStmt->execute([
                        ':table_number' => $tableNumber,
                        ':section' => $section,
                        ':seats' => $seats,
                        ':id' => $id,
                        ':tenant_id' => $tenantId
                    ]);
                    
                    respond(true, [
                        'id' => $id,
                        'table_number' => $tableNumber,
                        'section' => $section,
                        'seats' => $seats,
                        'updated' => true
                    ]);
                    
                } catch (PDOException $e) {
                    respond(false, null, 'Database error: ' . $e->getMessage(), 500);
                }
                break;
                
            case 'delete':
                $id = (int)($input['id'] ?? 0);
                
                if ($id <= 0) {
                    respond(false, null, 'Invalid table ID', 400);
                }
                
                try {
                    // Check for active orders
                    $checkOrdersSql = "
                        SELECT COUNT(*) 
                        FROM orders 
                        WHERE table_id = :table_id 
                        AND tenant_id = :tenant_id
                        AND status NOT IN ('closed', 'voided', 'refunded')
                    ";
                    $checkOrdersStmt = $pdo->prepare($checkOrdersSql);
                    $checkOrdersStmt->execute([
                        ':table_id' => $id,
                        ':tenant_id' => $tenantId
                    ]);
                    
                    if ($checkOrdersStmt->fetchColumn() > 0) {
                        respond(false, null, 'Cannot delete table with active orders', 400);
                    }
                    
                    // Delete table
                    $deleteSql = "
                        DELETE FROM dining_tables 
                        WHERE id = :id 
                        AND tenant_id = :tenant_id
                    ";
                    
                    $deleteStmt = $pdo->prepare($deleteSql);
                    $deleteStmt->execute([
                        ':id' => $id,
                        ':tenant_id' => $tenantId
                    ]);
                    
                    respond(true, ['id' => $id, 'deleted' => true]);
                    
                } catch (PDOException $e) {
                    respond(false, null, 'Database error: ' . $e->getMessage(), 500);
                }
                break;
                
            default:
                respond(false, null, 'Unknown action: ' . $action, 400);
        }
    }
    
    respond(false, null, 'Method not allowed', 405);
    
} catch (Exception $e) {
    error_log('Error in tables/setup.php: ' . $e->getMessage());
    respond(false, null, 'Server error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    error_log('Fatal error in tables/setup.php: ' . $e->getMessage());
    respond(false, null, 'Fatal error: ' . $e->getMessage(), 500);
}