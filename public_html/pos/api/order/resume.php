<?php
/**
 * SME 180 POS - Resume Order API
 * Path: /public_html/pos/api/order/resume.php
 * Version: 2.0.0 - Production Ready
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(200);
    die('{"success":true}');
}

// Only allow GET and POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    die('{"success":false,"error":"Method not allowed","code":"METHOD_NOT_ALLOWED"}');
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
function logEvent($level, $message, $context = []) {
    $logEntry = [
        'timestamp' => date('c'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'request_id' => $_SERVER['REQUEST_TIME_FLOAT'] ?? null
    ];
    error_log('[SME180] ' . json_encode($logEntry));
}

function sendError($message, $code = 400, $errorCode = 'GENERAL_ERROR', $additionalData = []) {
    http_response_code($code);
    $response = array_merge(
        [
            'success' => false,
            'error' => $message,
            'code' => $errorCode,
            'timestamp' => date('c')
        ],
        $additionalData
    );
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendSuccess($data) {
    echo json_encode(array_merge(
        ['success' => true],
        $data,
        ['timestamp' => date('c')]
    ), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// Load configuration
try {
    require_once __DIR__ . '/../../../config/db.php';
    $pdo = db();
} catch (Exception $e) {
    logEvent('ERROR', 'Database connection failed', ['error' => $e->getMessage()]);
    sendError('Database connection failed', 503, 'DB_CONNECTION_ERROR');
}

// Session validation
$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
$branchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

// Use defaults with warning
if (!$tenantId) {
    $tenantId = 1;
    logEvent('WARNING', 'No tenant_id in session, using default', ['session_id' => session_id()]);
}
if (!$branchId) {
    $branchId = 1;
    logEvent('WARNING', 'No branch_id in session, using default', ['session_id' => session_id()]);
}
if (!$userId) {
    $userId = 1;
    logEvent('WARNING', 'No user_id in session, using default', ['session_id' => session_id()]);
}

// Get currency from settings
$stmt = $pdo->prepare("
    SELECT value FROM settings 
    WHERE tenant_id = :tenant_id 
    AND `key` IN ('currency_symbol', 'currency_code', 'currency')
    LIMIT 1
");
$stmt->execute(['tenant_id' => $tenantId]);
$currency = $stmt->fetchColumn() ?: 'EGP';

// Check if it's a GET request (list parked orders)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        // Check for pagination parameters
        $page = isset($_GET['page']) ? 
            filter_var($_GET['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : 1;
        $limit = isset($_GET['limit']) ? 
            filter_var($_GET['limit'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) : 20;
        
        if ($page === false) $page = 1;
        if ($limit === false) $limit = 20;
        
        $offset = ($page - 1) * $limit;
        
        // Get total count
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as total
            FROM orders
            WHERE tenant_id = :tenant_id
            AND branch_id = :branch_id
            AND parked = 1
            AND status = 'held'
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId
        ]);
        $totalCount = $stmt->fetchColumn();
        
        // Check if parked_at column exists
        $hasParkedAt = false;
        try {
            $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'parked_at'");
            $hasParkedAt = ($checkCol->rowCount() > 0);
        } catch (Exception $e) {
            $hasParkedAt = false;
        }
        
        // Build query based on available columns
        $orderBy = $hasParkedAt ? "o.parked_at DESC" : "o.updated_at DESC";
        $parkedAtField = $hasParkedAt ? "o.parked_at" : "o.updated_at as parked_at";
        
        // Get parked orders with pagination
        $stmt = $pdo->prepare("
            SELECT 
                o.id,
                o.receipt_reference,
                o.park_label,
                $parkedAtField,
                o.total_amount,
                o.order_type,
                o.customer_name,
                o.customer_phone,
                o.table_id,
                o.notes,
                dt.table_number,
                COUNT(oi.id) as total_items,
                SUM(CASE WHEN oi.is_voided = 0 THEN 1 ELSE 0 END) as active_items
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id
            LEFT JOIN dining_tables dt ON dt.id = o.table_id
            WHERE o.tenant_id = :tenant_id
            AND o.branch_id = :branch_id
            AND o.parked = 1
            AND o.status = 'held'
            GROUP BY o.id
            ORDER BY $orderBy
            LIMIT :limit OFFSET :offset
        ");
        
        $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        
        $parkedOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format response
        $formattedOrders = array_map(function($order) use ($currency) {
            return [
                'id' => (int)$order['id'],
                'receipt_reference' => $order['receipt_reference'],
                'park_label' => $order['park_label'],
                'parked_at' => $order['parked_at'],
                'total_amount' => (float)$order['total_amount'],
                'currency' => $currency,
                'order_type' => $order['order_type'],
                'customer_name' => $order['customer_name'],
                'customer_phone' => $order['customer_phone'],
                'table_number' => $order['table_number'],
                'items_count' => (int)$order['active_items'],
                'total_items' => (int)$order['total_items'],
                'notes' => $order['notes']
            ];
        }, $parkedOrders);
        
        logEvent('INFO', 'Parked orders retrieved', [
            'count' => count($parkedOrders),
            'page' => $page,
            'limit' => $limit
        ]);
        
        sendSuccess([
            'parked_orders' => $formattedOrders,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_count' => (int)$totalCount,
                'total_pages' => ceil($totalCount / $limit),
                'has_more' => ($offset + $limit) < $totalCount
            ]
        ]);
        
    } catch (Exception $e) {
        logEvent('ERROR', 'Failed to retrieve parked orders', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
        sendError('Failed to retrieve parked orders', 500, 'RETRIEVE_FAILED');
    }
    exit;
}

// POST request - resume a specific order
$rawInput = file_get_contents('php://input');
if (strlen($rawInput) > 10000) { // 10KB max
    sendError('Request too large', 413, 'REQUEST_TOO_LARGE');
}

if (empty($rawInput)) {
    sendError('Request body is required', 400, 'EMPTY_REQUEST');
}

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid JSON format', 400, 'INVALID_JSON');
}

// Validate required fields
if (!isset($input['order_id'])) {
    sendError('Order ID is required', 400, 'MISSING_ORDER_ID');
}

$orderId = filter_var($input['order_id'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
]);

if ($orderId === false) {
    sendError('Invalid order ID format', 400, 'INVALID_ORDER_ID');
}

// Validate optional table ID
$tableId = null;
if (isset($input['table_id'])) {
    $tableId = filter_var($input['table_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
    ]);
    if ($tableId === false) {
        sendError('Invalid table ID format', 400, 'INVALID_TABLE_ID');
    }
}

try {
    $pdo->beginTransaction();
    
    // Check if parked_at column exists
    $hasParkedAt = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'parked_at'");
        $hasParkedAt = ($checkCol->rowCount() > 0);
    } catch (Exception $e) {
        $hasParkedAt = false;
    }
    
    // Get the parked order with lock
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE id = :order_id 
        AND tenant_id = :tenant_id
        AND branch_id = :branch_id
        AND parked = 1
        FOR UPDATE
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $pdo->rollBack();
        sendError('Parked order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    // Validate table if provided for dine-in orders
    if ($tableId && $order['order_type'] === 'dine_in') {
        // Check if table is available
        $stmt = $pdo->prepare("
            SELECT status, current_order_id 
            FROM dining_tables 
            WHERE id = :table_id 
            AND tenant_id = :tenant_id
            FOR UPDATE
        ");
        
        try {
            $stmt->execute([
                'table_id' => $tableId,
                'tenant_id' => $tenantId
            ]);
            $table = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($table && $table['status'] === 'occupied' && $table['current_order_id']) {
                $pdo->rollBack();
                sendError('Table is already occupied', 409, 'TABLE_OCCUPIED');
            }
        } catch (PDOException $e) {
            // Table system might not be implemented
            logEvent('INFO', 'Table validation skipped', ['error' => $e->getMessage()]);
        }
    }
    
    // Calculate parked duration
    $parkedDuration = 0;
    if ($hasParkedAt && $order['parked_at']) {
        $parkedDuration = round((time() - strtotime($order['parked_at'])) / 60); // in minutes
    }
    
    // Build update query based on available columns
    $updateFields = [
        "parked = 0",
        "park_label = NULL",
        "status = 'open'",
        "table_id = :table_id",
        "updated_at = NOW()"
    ];
    
    if ($hasParkedAt) {
        $updateFields[] = "parked_at = NULL";
    }
    
    // Resume the order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET " . implode(", ", $updateFields) . "
        WHERE id = :order_id
    ");
    
    $stmt->execute([
        'table_id' => $tableId ?: $order['table_id'],
        'order_id' => $orderId
    ]);
    
    // Update table status if dine-in
    $tableAssigned = false;
    if ($order['order_type'] === 'dine_in' && ($tableId || $order['table_id'])) {
        try {
            $assignTableId = $tableId ?: $order['table_id'];
            $stmt = $pdo->prepare("
                UPDATE dining_tables 
                SET status = 'occupied',
                    current_order_id = :order_id,
                    updated_at = NOW()
                WHERE id = :table_id
                AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                'order_id' => $orderId,
                'table_id' => $assignTableId,
                'tenant_id' => $tenantId
            ]);
            $tableAssigned = $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // Table system might not be implemented
            logEvent('INFO', 'Table assignment skipped', ['error' => $e->getMessage()]);
        }
    }
    
    // Log the resume action
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'resumed', :details, NOW()
        )
    ");
    
    $logDetails = [
        'park_label' => $order['park_label'],
        'parked_duration_minutes' => $parkedDuration,
        'table_id' => $tableId ?: $order['table_id'],
        'table_assigned' => $tableAssigned,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ];
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode($logDetails)
    ]);
    
    // Get order items for response
    $stmt = $pdo->prepare("
        SELECT 
            oi.*,
            COUNT(*) OVER() as total_items,
            SUM(CASE WHEN is_voided = 0 THEN 1 ELSE 0 END) OVER() as active_items
        FROM order_items oi
        WHERE oi.order_id = :order_id 
        ORDER BY oi.created_at ASC
    ");
    $stmt->execute(['order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalItems = $items[0]['total_items'] ?? 0;
    $activeItems = $items[0]['active_items'] ?? 0;
    
    $pdo->commit();
    
    // Log successful resume
    logEvent('INFO', 'Order resumed successfully', [
        'order_id' => $orderId,
        'receipt' => $order['receipt_reference'],
        'parked_duration' => $parkedDuration,
        'table_assigned' => $tableAssigned
    ]);
    
    sendSuccess([
        'message' => 'Order resumed successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'order_type' => $order['order_type'],
            'table_id' => $tableId ?: $order['table_id'],
            'customer_name' => $order['customer_name'],
            'customer_phone' => $order['customer_phone'],
            'subtotal' => (float)$order['subtotal'],
            'tax_amount' => (float)$order['tax_amount'],
            'service_charge' => (float)$order['service_charge'],
            'discount_amount' => (float)$order['discount_amount'],
            'total_amount' => (float)$order['total_amount'],
            'currency' => $currency,
            'status' => 'open',
            'parked_duration_minutes' => $parkedDuration,
            'items_count' => (int)$activeItems,
            'total_items' => (int)$totalItems
        ],
        'items' => array_map(function($item) {
            return [
                'id' => (int)$item['id'],
                'product_id' => $item['product_id'] ? (int)$item['product_id'] : null,
                'product_name' => $item['product_name'],
                'quantity' => (float)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'line_total' => (float)$item['line_total'],
                'is_voided' => (bool)$item['is_voided']
            ];
        }, $items)
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Resume order failed', [
        'order_id' => $orderId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to resume order', 500, 'RESUME_FAILED');
}
?>
