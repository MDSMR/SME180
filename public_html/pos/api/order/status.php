<?php
/**
 * SME 180 POS - Get Order Status API
 * Path: /public_html/pos/api/order/status.php
 * Version: 2.0.0 - Production Ready
 * 
 * Returns the current status and details of an order
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Set security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(200);
    exit('{"success":true}');
}

// Only allow GET and POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    exit('{"success":false,"error":"Method not allowed","code":"METHOD_NOT_ALLOWED"}');
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

function sendError($message, $code = 400, $errorCode = 'GENERAL_ERROR') {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'code' => $errorCode,
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
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
    $configFile = __DIR__ . '/../../../config/db.php';
    if (!file_exists($configFile)) {
        throw new Exception('Configuration file not found');
    }
    require_once $configFile;
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

// Get order ID from request
$orderId = 0;

// Check GET parameter
if (isset($_GET['order_id'])) {
    $orderId = filter_var($_GET['order_id'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
    ]);
    if ($orderId === false) {
        sendError('Invalid order ID format', 400, 'INVALID_ORDER_ID');
    }
}

// Check POST body if no GET param
if (!$orderId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    if (strlen($rawInput) > 10000) { // 10KB max
        sendError('Request too large', 413, 'REQUEST_TOO_LARGE');
    }
    
    if (!empty($rawInput)) {
        $input = json_decode($rawInput, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendError('Invalid JSON format', 400, 'INVALID_JSON');
        }
        
        if (isset($input['order_id'])) {
            $orderId = filter_var($input['order_id'], FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
            ]);
            if ($orderId === false) {
                sendError('Invalid order ID format', 400, 'INVALID_ORDER_ID');
            }
        }
    }
}

// For testing - get latest order if no ID provided (development only)
if (!$orderId && isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'development') {
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM orders 
            WHERE tenant_id = :tenant_id 
            AND branch_id = :branch_id
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $orderId = (int)$result['id'];
        }
    } catch (Exception $e) {
        logEvent('ERROR', 'Failed to get latest order', ['error' => $e->getMessage()]);
        sendError('No order ID provided', 400, 'NO_ORDER_ID');
    }
}

if (!$orderId) {
    sendError('Order ID is required', 400, 'ORDER_ID_REQUIRED');
}

try {
    // Get order details with proper tenant/branch filtering
    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            o.receipt_reference,
            o.order_type,
            o.table_id,
            o.customer_name,
            o.customer_phone,
            o.customer_id,
            o.subtotal,
            o.tax_amount,
            o.discount_amount,
            o.discount_type,
            o.discount_value,
            o.tip_amount,
            o.service_charge,
            o.total_amount,
            o.paid_amount,
            o.status,
            o.payment_status,
            o.payment_method,
            COALESCE(o.kitchen_status, 'pending') as kitchen_status,
            o.parked,
            o.park_label,
            o.parked_at,
            o.notes,
            o.created_at,
            o.updated_at,
            o.paid_at,
            o.voided_at,
            o.voided_by,
            o.void_reason,
            dt.table_number,
            dt.status as table_status
        FROM orders o
        LEFT JOIN dining_tables dt ON dt.id = o.table_id
        WHERE o.id = :order_id
        AND o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
    ");
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        sendError('Order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    // Get order items
    $stmt = $pdo->prepare("
        SELECT 
            oi.id,
            oi.product_id,
            oi.product_name,
            oi.quantity,
            oi.unit_price,
            oi.line_total,
            oi.is_voided,
            oi.voided_at,
            oi.voided_by,
            oi.void_reason,
            COALESCE(oi.kitchen_status, oi.state, 'pending') as status,
            oi.fired_at,
            oi.kitchen_notes,
            oi.created_at,
            oi.updated_at
        FROM order_items oi
        WHERE oi.order_id = :order_id
        ORDER BY oi.id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get payment details if paid
    $payments = [];
    if ($order['payment_status'] !== 'unpaid') {
        $stmt = $pdo->prepare("
            SELECT 
                id,
                payment_method,
                amount,
                reference_number,
                status,
                created_at
            FROM order_payments
            WHERE order_id = :order_id
            ORDER BY created_at
        ");
        $stmt->execute(['order_id' => $orderId]);
        $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get currency from settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value`
        FROM settings
        WHERE tenant_id = :tenant_id
        AND `key` IN ('currency', 'currency_code', 'currency_symbol')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $currency = $settings['currency_code'] ?? $settings['currency'] ?? 'EGP';
    $currencySymbol = $settings['currency_symbol'] ?? $currency;
    
    // Calculate summary statistics
    $activeItems = array_filter($items, function($item) {
        return !$item['is_voided'];
    });
    
    $voidedItems = array_filter($items, function($item) {
        return $item['is_voided'];
    });
    
    $firedItems = array_filter($items, function($item) {
        return !empty($item['fired_at']) && !$item['is_voided'];
    });
    
    // Build response
    $response = [
        'order' => [
            'id' => (int)$order['id'],
            'receipt_reference' => $order['receipt_reference'],
            'order_type' => $order['order_type'],
            'table_number' => $order['table_number'],
            'table_status' => $order['table_status'],
            'customer' => [
                'id' => $order['customer_id'] ? (int)$order['customer_id'] : null,
                'name' => $order['customer_name'],
                'phone' => $order['customer_phone']
            ],
            'amounts' => [
                'subtotal' => (float)$order['subtotal'],
                'tax' => (float)$order['tax_amount'],
                'discount' => (float)$order['discount_amount'],
                'discount_type' => $order['discount_type'],
                'discount_value' => (float)$order['discount_value'],
                'tip' => (float)$order['tip_amount'],
                'service_charge' => (float)$order['service_charge'],
                'total' => (float)$order['total_amount'],
                'paid' => (float)$order['paid_amount'],
                'due' => (float)$order['total_amount'] - (float)$order['paid_amount'],
                'currency' => $currency,
                'currency_symbol' => $currencySymbol
            ],
            'status' => [
                'order' => $order['status'],
                'payment' => $order['payment_status'],
                'kitchen' => $order['kitchen_status']
            ],
            'parking' => [
                'is_parked' => (bool)$order['parked'],
                'park_label' => $order['park_label'],
                'parked_at' => $order['parked_at']
            ],
            'void_info' => $order['voided_at'] ? [
                'voided_at' => $order['voided_at'],
                'voided_by' => (int)$order['voided_by'],
                'reason' => $order['void_reason']
            ] : null,
            'notes' => $order['notes'],
            'timestamps' => [
                'created' => $order['created_at'],
                'updated' => $order['updated_at'],
                'paid' => $order['paid_at'],
                'voided' => $order['voided_at']
            ]
        ],
        'items' => array_map(function($item) {
            return [
                'id' => (int)$item['id'],
                'product_id' => $item['product_id'] ? (int)$item['product_id'] : null,
                'name' => $item['product_name'],
                'quantity' => (float)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'line_total' => (float)$item['line_total'],
                'is_voided' => (bool)$item['is_voided'],
                'void_info' => $item['is_voided'] ? [
                    'voided_at' => $item['voided_at'],
                    'voided_by' => $item['voided_by'] ? (int)$item['voided_by'] : null,
                    'reason' => $item['void_reason']
                ] : null,
                'kitchen' => [
                    'status' => $item['status'],
                    'fired_at' => $item['fired_at'],
                    'notes' => $item['kitchen_notes']
                ],
                'created_at' => $item['created_at'],
                'updated_at' => $item['updated_at']
            ];
        }, $items),
        'payments' => array_map(function($payment) {
            return [
                'id' => (int)$payment['id'],
                'method' => $payment['payment_method'],
                'amount' => (float)$payment['amount'],
                'reference' => $payment['reference_number'],
                'status' => $payment['status'],
                'created_at' => $payment['created_at']
            ];
        }, $payments),
        'summary' => [
            'total_items' => count($items),
            'active_items' => count($activeItems),
            'voided_items' => count($voidedItems),
            'fired_items' => count($firedItems),
            'payment_methods' => $order['payment_method'] ? 
                explode(',', $order['payment_method']) : []
        ]
    ];
    
    // Log successful retrieval
    logEvent('INFO', 'Order status retrieved', [
        'order_id' => $orderId,
        'status' => $order['status'],
        'payment_status' => $order['payment_status']
    ]);
    
    sendSuccess($response);
    
} catch (Exception $e) {
    logEvent('ERROR', 'Failed to retrieve order status', [
        'order_id' => $orderId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    sendError('Failed to retrieve order status', 500, 'DATABASE_ERROR');
}
?>