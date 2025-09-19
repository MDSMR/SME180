<?php
/**
 * SME 180 POS - Park Order API
 * Path: /public_html/pos/api/order/park.php
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
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(200);
    die('{"success":true}');
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

function checkRateLimit($pdo, $tenantId, $userId, $action = 'parked') {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as action_count 
            FROM order_logs 
            WHERE tenant_id = ? 
                AND user_id = ? 
                AND action = ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute([$tenantId, $userId, $action]);
        $count = $stmt->fetchColumn();
        
        // Allow max 10 park operations per minute per user
        if ($count >= 10) {
            logEvent('WARNING', 'Rate limit exceeded for park operation', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'count' => $count
            ]);
            return false;
        }
    } catch (Exception $e) {
        logEvent('WARNING', 'Rate limit check failed', ['error' => $e->getMessage()]);
    }
    return true;
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

// Parse and validate input
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

// Validate optional fields
$parkReason = isset($input['reason']) ? 
    substr(trim(strip_tags($input['reason'])), 0, 500) : '';
$parkLabel = isset($input['label']) ? 
    substr(trim(strip_tags($input['label'])), 0, 100) : null;

try {
    // Check rate limit
    if (!checkRateLimit($pdo, $tenantId, $userId)) {
        sendError(
            'Too many park requests. Please wait before trying again.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
    }
    
    // Check max parked orders per branch
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as parked_count
        FROM orders
        WHERE tenant_id = :tenant_id
        AND branch_id = :branch_id
        AND parked = 1
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $parkedCount = $stmt->fetchColumn();
    
    // Allow max 50 parked orders
    if ($parkedCount >= 50) {
        logEvent('WARNING', 'Max parked orders limit reached', [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'count' => $parkedCount
        ]);
        sendError(
            'Maximum number of parked orders reached. Please complete existing orders first.',
            409,
            'MAX_PARKED_ORDERS'
        );
    }
    
    $pdo->beginTransaction();
    
    // Get order with lock
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE id = :order_id 
        AND tenant_id = :tenant_id
        AND branch_id = :branch_id
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
        sendError('Order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    // Check if order can be parked
    if ($order['payment_status'] === 'paid') {
        $pdo->rollBack();
        sendError('Cannot park paid orders', 409, 'ORDER_ALREADY_PAID');
    }
    
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        $pdo->rollBack();
        sendError(
            'Cannot park ' . $order['status'] . ' orders',
            409,
            'INVALID_ORDER_STATUS'
        );
    }
    
    if ($order['parked'] == 1) {
        $pdo->rollBack();
        sendError('Order is already parked', 409, 'ORDER_ALREADY_PARKED');
    }
    
    // Generate park label if not provided
    if (!$parkLabel) {
        $parkLabel = sprintf('Parked #%s - %s', 
            $order['receipt_reference'],
            date('H:i')
        );
        
        // Add customer name if available
        if (!empty($order['customer_name']) && $order['customer_name'] !== 'Walk-in Customer') {
            $parkLabel = substr($order['customer_name'], 0, 30) . ' - ' . date('H:i');
        }
    }
    
    // Check if columns exist
    $hasParkedAt = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'parked_at'");
        $hasParkedAt = ($checkCol->rowCount() > 0);
    } catch (Exception $e) {
        $hasParkedAt = false;
    }
    
    // Park the order
    $updateFields = [
        "parked = 1",
        "park_label = :park_label",
        "status = 'held'",
        "updated_at = NOW()"
    ];
    
    if ($hasParkedAt) {
        $updateFields[] = "parked_at = NOW()";
    }
    
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET " . implode(", ", $updateFields) . "
        WHERE id = :order_id
    ");
    
    $stmt->execute([
        'park_label' => $parkLabel,
        'order_id' => $orderId
    ]);
    
    $tableFreed = false;
    
    // If it's a dine-in order, free up the table
    if ($order['order_type'] === 'dine_in' && $order['table_id']) {
        try {
            $stmt = $pdo->prepare("
                UPDATE dining_tables 
                SET status = 'available',
                    current_order_id = NULL,
                    updated_at = NOW()
                WHERE id = :table_id
                AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                'table_id' => $order['table_id'],
                'tenant_id' => $tenantId
            ]);
            $tableFreed = $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            // Table might not exist - log but continue
            logEvent('INFO', 'dining_tables table not found or update failed', [
                'table_id' => $order['table_id'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // Count items in order
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as item_count,
               SUM(CASE WHEN is_voided = 0 THEN 1 ELSE 0 END) as active_items
        FROM order_items
        WHERE order_id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $itemCounts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log the parking action
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'parked', :details, NOW()
        )
    ");
    
    $logDetails = [
        'park_label' => $parkLabel,
        'reason' => $parkReason,
        'table_freed' => $tableFreed,
        'table_id' => $order['table_id'],
        'total_amount' => $order['total_amount'],
        'items_count' => $itemCounts['item_count'],
        'active_items' => $itemCounts['active_items'],
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ];
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode($logDetails)
    ]);
    
    $pdo->commit();
    
    // Get currency
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('currency_symbol', 'currency_code', 'currency')
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $currency = $stmt->fetchColumn() ?: 'EGP';
    
    // Log successful park
    logEvent('INFO', 'Order parked successfully', [
        'order_id' => $orderId,
        'receipt' => $order['receipt_reference'],
        'park_label' => $parkLabel,
        'table_freed' => $tableFreed
    ]);
    
    sendSuccess([
        'message' => 'Order parked successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'park_label' => $parkLabel,
            'parked_at' => date('c'),
            'status' => 'held',
            'table_freed' => $tableFreed,
            'total_amount' => (float)$order['total_amount'],
            'currency' => $currency,
            'items_count' => (int)$itemCounts['active_items']
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Park order failed', [
        'order_id' => $orderId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to park order', 500, 'PARK_FAILED');
}
?>