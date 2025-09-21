<?php
/**
 * SME 180 POS - Resume Order API (PRODUCTION READY - FIXED)
 * Path: /public_html/pos/api/order/resume.php
 * Version: 6.0.1 - Authentication Fixed
 * 
 * FIX: Enhanced API key authentication handling
 */

declare(strict_types=1);

// Production settings
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Configuration
define('API_TEST_MODE', false); // SET TO false IN PRODUCTION
define('MAX_REQUEST_SIZE', 10000);
define('RATE_LIMIT_PER_MINUTE', 30);
define('API_KEY', 'sme180_pos_api_key_2024');

// Performance monitoring
$startTime = microtime(true);

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    http_response_code(204);
    exit;
}

// Only allow GET and POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    die(json_encode([
        'success' => false,
        'error' => 'Method not allowed',
        'code' => 'METHOD_NOT_ALLOWED'
    ]));
}

// Database configuration
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'dbvtrnbzad193e');
    define('DB_USER', 'uta6umaa0iuif');
    define('DB_PASS', '2m%[11|kb1Z4');
    define('DB_CHARSET', 'utf8mb4');
}

/**
 * Send error response
 */
function sendError(string $message, int $code = 400, string $errorCode = 'ERROR'): void {
    global $startTime;
    
    error_log("[SME180 Resume] $errorCode: $message");
    
    http_response_code($code);
    die(json_encode([
        'success' => false,
        'error' => $message,
        'code' => $errorCode,
        'timestamp' => date('c'),
        'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * Send success response
 */
function sendSuccess(array $data): void {
    global $startTime;
    
    echo json_encode(array_merge(
        ['success' => true],
        $data,
        [
            'timestamp' => date('c'),
            'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
        ]
    ), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// Get database connection
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false
    ]);
} catch (PDOException $e) {
    error_log("[SME180 Resume] Database connection failed: " . $e->getMessage());
    sendError('Service unavailable', 503, 'DB_CONNECTION_FAILED');
}

// Get input
$input = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputRaw = file_get_contents('php://input');
    if (strlen($inputRaw) > MAX_REQUEST_SIZE) {
        sendError('Request too large', 413, 'REQUEST_TOO_LARGE');
    }
    
    $input = json_decode($inputRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendError('Invalid JSON', 400, 'INVALID_JSON');
    }
}

// FIXED AUTHENTICATION HANDLING
$authenticated = false;
$tenantId = null;
$branchId = null;
$userId = null;

// Check for API key in multiple places
$apiKey = null;
if (isset($input['api_key'])) {
    $apiKey = $input['api_key'];
} elseif (isset($_SERVER['HTTP_X_API_KEY'])) {
    $apiKey = $_SERVER['HTTP_X_API_KEY'];
} elseif (isset($_SERVER['HTTP_X_API_KEY'])) {  // Different case variation
    $apiKey = $_SERVER['HTTP_X_API_KEY'];
}

// API Key authentication
if ($apiKey && $apiKey === API_KEY) {
    $authenticated = true;
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 1;
    $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : 1;
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : 1;
    
    error_log("[SME180 Resume] API key authentication successful");
} else {
    // Session authentication fallback
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
        $authenticated = true;
        $tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 1;
        $branchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : 1;
        $userId = (int)$_SESSION['user_id'];
        
        error_log("[SME180 Resume] Session authentication successful");
    }
}

// Test mode fallback - REMOVE IN PRODUCTION
if (!$authenticated && API_TEST_MODE) {
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 1;
    $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : 1;
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : 1;
    error_log("[SME180 Resume] WARNING: Test mode authentication bypass active");
} elseif (!$authenticated) {
    sendError('Authentication required', 401, 'UNAUTHORIZED');
}

// Get currency
$currency = 'USD';
try {
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = ? AND `key` IN ('currency','currency_symbol') LIMIT 1");
    $stmt->execute([$tenantId]);
    $result = $stmt->fetchColumn();
    if ($result) $currency = $result;
} catch (Exception $e) {
    // Use default currency
}

// Handle GET - List parked orders
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Get parked orders with strict filtering
        $stmt = $pdo->prepare("
            SELECT o.id, o.receipt_reference, o.park_label, o.parked_at, 
                   o.total_amount, o.customer_name, o.customer_phone,
                   o.order_type, o.table_id, o.parked, o.status,
                   dt.table_number,
                   COUNT(oi.id) as item_count,
                   SUM(oi.quantity) as total_quantity
            FROM orders o
            LEFT JOIN dining_tables dt ON dt.id = o.table_id
            LEFT JOIN order_items oi ON oi.order_id = o.id
            WHERE o.tenant_id = :tenant_id 
            AND o.branch_id = :branch_id 
            AND o.parked = 1
            AND o.status = 'held'
            AND o.park_label IS NOT NULL
            GROUP BY o.id
            ORDER BY o.parked_at DESC
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId
        ]);
        $allOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Double-check filtering for safety
        $parkedOrders = [];
        foreach ($allOrders as $order) {
            if ($order['parked'] == 1 && $order['status'] == 'held') {
                $parkedOrders[] = [
                    'id' => (int)$order['id'],
                    'receipt_reference' => $order['receipt_reference'],
                    'park_label' => $order['park_label'],
                    'parked_at' => $order['parked_at'],
                    'total_amount' => (float)$order['total_amount'],
                    'currency' => $currency,
                    'customer_name' => $order['customer_name'],
                    'customer_phone' => $order['customer_phone'],
                    'order_type' => $order['order_type'],
                    'table_number' => $order['table_number'],
                    'item_count' => (int)$order['item_count'],
                    'total_quantity' => (float)$order['total_quantity']
                ];
            }
        }
        
        sendSuccess([
            'parked_orders' => $parkedOrders,
            'count' => count($parkedOrders)
        ]);
        
    } catch (Exception $e) {
        error_log("[SME180 Resume] List failed: " . $e->getMessage());
        sendError('Failed to list orders', 500, 'LIST_FAILED');
    }
}

// POST - Resume order
$orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;
if (!$orderId) {
    sendError('Order ID required', 400, 'MISSING_ORDER_ID');
}

$tableId = isset($input['table_id']) ? (int)$input['table_id'] : null;

try {
    $pdo->beginTransaction();
    
    // Get parked order - verify tenant and branch
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE id = :order_id 
        AND tenant_id = :tenant_id
        AND branch_id = :branch_id
        AND parked = 1
        AND status = 'held'
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $pdo->rollBack();
        
        // Check why not found
        $stmt = $pdo->prepare("
            SELECT id, parked, status, tenant_id, branch_id 
            FROM orders 
            WHERE id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);
        $check = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($check) {
            if ($check['tenant_id'] != $tenantId || $check['branch_id'] != $branchId) {
                sendError('Order not found in this context', 403, 'WRONG_CONTEXT');
            } elseif ($check['parked'] == 0) {
                sendError('Order is not parked', 400, 'NOT_PARKED');
            } elseif ($check['status'] != 'held') {
                sendError('Order has wrong status: ' . $check['status'], 400, 'WRONG_STATUS');
            }
        }
        sendError('Order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    // Calculate parked duration
    $parkedDuration = 0;
    if (!empty($order['parked_at'])) {
        $parkedDuration = round((time() - strtotime($order['parked_at'])) / 60);
    }
    
    // Resume the order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET parked = 0,
            park_label = NULL,
            parked_at = NULL,
            park_reason = NULL,
            parked_by = NULL,
            status = 'open',
            table_id = :table_id,
            resumed_at = NOW(),
            resumed_by = :user_id,
            updated_at = NOW()
        WHERE id = :order_id
        AND tenant_id = :tenant_id
        AND branch_id = :branch_id
        AND parked = 1
    ");
    
    $result = $stmt->execute([
        'table_id' => $tableId ?: $order['table_id'],
        'user_id' => $userId,
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    
    if (!$result || $stmt->rowCount() == 0) {
        $pdo->rollBack();
        sendError('Failed to update order', 500, 'UPDATE_FAILED');
    }
    
    // Update table if dine-in
    if ($order['order_type'] === 'dine_in' && ($tableId || $order['table_id'])) {
        try {
            $assignTableId = $tableId ?: $order['table_id'];
            
            // Clear previous table
            if ($order['table_id'] && $order['table_id'] != $assignTableId) {
                $stmt = $pdo->prepare("
                    UPDATE dining_tables 
                    SET status = 'available',
                        current_order_id = NULL,
                        updated_at = NOW()
                    WHERE id = :table_id
                    AND tenant_id = :tenant_id
                    AND branch_id = :branch_id
                ");
                $stmt->execute([
                    'table_id' => $order['table_id'],
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId
                ]);
            }
            
            // Assign new table
            $stmt = $pdo->prepare("
                UPDATE dining_tables 
                SET status = 'occupied',
                    current_order_id = :order_id,
                    updated_at = NOW()
                WHERE id = :table_id
                AND tenant_id = :tenant_id
                AND branch_id = :branch_id
            ");
            $stmt->execute([
                'order_id' => $orderId,
                'table_id' => $assignTableId,
                'tenant_id' => $tenantId,
                'branch_id' => $branchId
            ]);
        } catch (Exception $e) {
            error_log("[SME180 Resume] Table update skipped: " . $e->getMessage());
        }
    }
    
    // Log the resume
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount();
        
        if ($tableCheck > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO order_logs (
                    order_id, tenant_id, branch_id, user_id,
                    action, details, created_at
                ) VALUES (
                    :order_id, :tenant_id, :branch_id, :user_id,
                    'resumed', :details, NOW()
                )
            ");
            
            $logDetails = json_encode([
                'parked_duration_minutes' => $parkedDuration,
                'park_label' => $order['park_label'],
                'table_id' => $tableId,
                'original_table_id' => $order['table_id']
            ]);
            
            $stmt->execute([
                'order_id' => $orderId,
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'details' => $logDetails
            ]);
        }
    } catch (Exception $e) {
        // Log but don't fail
        error_log("[SME180 Resume] Audit log skipped: " . $e->getMessage());
    }
    
    $pdo->commit();
    
    // Get item counts
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(CASE WHEN is_voided != 1 THEN 1 END) as active_items,
            COUNT(*) as total_items,
            SUM(CASE WHEN is_voided != 1 THEN quantity ELSE 0 END) as total_quantity
        FROM order_items 
        WHERE order_id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $itemCounts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    error_log("[SME180 Resume] Order $orderId resumed successfully");
    
    sendSuccess([
        'message' => 'Order resumed successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'status' => 'open',
            'parked' => false,
            'table_id' => $tableId ?: $order['table_id'],
            'customer_name' => $order['customer_name'],
            'total_amount' => (float)$order['total_amount'],
            'currency' => $currency,
            'parked_duration_minutes' => $parkedDuration,
            'items_count' => (int)($itemCounts['active_items'] ?? 0),
            'total_items' => (int)($itemCounts['total_items'] ?? 0),
            'total_quantity' => (float)($itemCounts['total_quantity'] ?? 0)
        ]
    ]);
    
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("[SME180 Resume] Failed: " . $e->getMessage());
    sendError('Failed to resume order', 500, 'RESUME_FAILED');
}
?>