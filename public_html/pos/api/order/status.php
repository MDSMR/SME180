<?php
/**
 * SME 180 POS - Get Order Status API
 * Path: /public_html/pos/api/order/status.php
 * Version: 3.0.0 - Production Ready with Dynamic Schema Support
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Performance monitoring
$startTime = microtime(true);

// Set security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);
    exit;
}

// Allow GET and POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    die(json_encode([
        'success' => false,
        'error' => 'Method not allowed',
        'code' => 'METHOD_NOT_ALLOWED'
    ]));
}

/**
 * Send error response
 */
function sendError(string $message, int $code = 400, string $errorCode = 'ERROR'): void {
    global $startTime;
    
    error_log("[SME180] $errorCode: $message");
    
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

// Load configuration
require_once __DIR__ . '/../../../config/db.php';

try {
    $pdo = db();
    if (!$pdo) {
        throw new Exception('Database connection not available');
    }
} catch (Exception $e) {
    sendError('Service temporarily unavailable', 503, 'DATABASE_ERROR');
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Get tenant/branch from session or request
$tenantId = $_SESSION['tenant_id'] ?? null;
$branchId = $_SESSION['branch_id'] ?? null;

// Get order ID from request
$orderId = null;

// Check GET parameter
if (isset($_GET['order_id'])) {
    $orderId = filter_var($_GET['order_id'], FILTER_VALIDATE_INT);
}

// Check POST body if no GET param
if (!$orderId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    if (!empty($rawInput)) {
        $input = json_decode($rawInput, true);
        if (isset($input['order_id'])) {
            $orderId = filter_var($input['order_id'], FILTER_VALIDATE_INT);
        }
        // Allow tenant/branch from request for testing
        if (isset($input['tenant_id'])) {
            $tenantId = (int)$input['tenant_id'];
        }
        if (isset($input['branch_id'])) {
            $branchId = (int)$input['branch_id'];
        }
    }
}

// Use defaults if not set
if (!$tenantId) $tenantId = 1;
if (!$branchId) $branchId = 1;

// If no order ID, try to get the latest
if (!$orderId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM orders 
            WHERE tenant_id = :tenant_id 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        $orderId = $stmt->fetchColumn();
        
        if (!$orderId) {
            sendError('No orders found', 404, 'NO_ORDERS');
        }
        
        error_log("[SME180] Using most recent order ID: $orderId");
    } catch (Exception $e) {
        sendError('Order ID is required', 400, 'ORDER_ID_REQUIRED');
    }
}

try {
    // First, check what columns exist in orders table
    $columnsStmt = $pdo->query("SHOW COLUMNS FROM orders");
    $orderColumns = [];
    while ($col = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
        $orderColumns[] = $col['Field'];
    }
    
    // Build dynamic SELECT query based on available columns
    $selectFields = ['o.id', 'o.receipt_reference', 'o.order_type', 'o.status', 'o.payment_status'];
    $optionalFields = [
        'o.table_id', 'o.customer_name', 'o.customer_phone', 'o.customer_id',
        'o.subtotal', 'o.tax_amount', 'o.total_amount', 'o.notes',
        'o.created_at', 'o.updated_at', 'o.tenant_id', 'o.branch_id'
    ];
    
    // Add optional fields that exist
    $availableOptionalFields = [
        'discount_amount', 'discount_type', 'discount_value', 'tip_amount', 
        'service_charge', 'paid_amount', 'payment_method', 'kitchen_status',
        'parked', 'park_label', 'parked_at', 'paid_at', 'voided_at', 
        'voided_by', 'void_reason', 'fired_at'
    ];
    
    foreach ($availableOptionalFields as $field) {
        if (in_array($field, $orderColumns)) {
            $selectFields[] = "o.$field";
        }
    }
    
    // Basic query without table join (which might not exist)
    $orderQuery = "
        SELECT " . implode(', ', $selectFields) . "
        FROM orders o
        WHERE o.id = :order_id
    ";
    
    // Add tenant/branch filter only if columns exist
    if (in_array('tenant_id', $orderColumns)) {
        $orderQuery .= " AND o.tenant_id = :tenant_id";
    }
    if (in_array('branch_id', $orderColumns)) {
        $orderQuery .= " AND o.branch_id = :branch_id";
    }
    
    $stmt = $pdo->prepare($orderQuery);
    $params = ['order_id' => $orderId];
    if (in_array('tenant_id', $orderColumns)) {
        $params['tenant_id'] = $tenantId;
    }
    if (in_array('branch_id', $orderColumns)) {
        $params['branch_id'] = $branchId;
    }
    
    $stmt->execute($params);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        sendError('Order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    // Check what columns exist in order_items table
    $itemColumnsStmt = $pdo->query("SHOW COLUMNS FROM order_items");
    $itemColumns = [];
    while ($col = $itemColumnsStmt->fetch(PDO::FETCH_ASSOC)) {
        $itemColumns[] = $col['Field'];
    }
    
    // Get order items with available columns
    $itemSelectFields = ['oi.id', 'oi.product_name', 'oi.quantity', 'oi.unit_price', 'oi.line_total'];
    $itemOptionalFields = [
        'product_id', 'is_voided', 'voided_at', 'voided_by', 'void_reason',
        'kitchen_status', 'state', 'fired_at', 'kitchen_notes', 'notes',
        'created_at', 'updated_at'
    ];
    
    foreach ($itemOptionalFields as $field) {
        if (in_array($field, $itemColumns)) {
            $itemSelectFields[] = "oi.$field";
        }
    }
    
    $itemQuery = "
        SELECT " . implode(', ', $itemSelectFields) . "
        FROM order_items oi
        WHERE oi.order_id = :order_id
        ORDER BY oi.id
    ";
    
    $stmt = $pdo->prepare($itemQuery);
    $stmt->execute(['order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if order_payments table exists
    $payments = [];
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_payments'")->rowCount();
        if ($tableCheck > 0 && ($order['payment_status'] ?? 'unpaid') !== 'unpaid') {
            $stmt = $pdo->prepare("
                SELECT id, payment_method, amount, reference_number, status, created_at
                FROM order_payments
                WHERE order_id = :order_id
                ORDER BY created_at
            ");
            $stmt->execute(['order_id' => $orderId]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // Payments table doesn't exist, continue without it
    }
    
    // Get currency from settings
    $currency = 'EGP';
    $currencySymbol = 'EGP';
    try {
        $stmt = $pdo->prepare("
            SELECT `key`, `value` FROM settings
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
    } catch (Exception $e) {
        // Use defaults
    }
    
    // Calculate summary
    $activeItems = 0;
    $voidedItems = 0;
    $totalQuantity = 0;
    
    foreach ($items as $item) {
        $totalQuantity += (float)($item['quantity'] ?? 0);
        if (isset($item['is_voided']) && $item['is_voided']) {
            $voidedItems++;
        } else {
            $activeItems++;
        }
    }
    
    // Build response with safe access to potentially missing fields
    $response = [
        'order' => [
            'id' => (int)$order['id'],
            'receipt_reference' => $order['receipt_reference'] ?? 'N/A',
            'order_type' => $order['order_type'] ?? 'dine_in',
            'status' => $order['status'] ?? 'open',
            'payment_status' => $order['payment_status'] ?? 'unpaid',
            'customer' => [
                'id' => isset($order['customer_id']) ? (int)$order['customer_id'] : null,
                'name' => $order['customer_name'] ?? 'Walk-in Customer',
                'phone' => $order['customer_phone'] ?? null
            ],
            'amounts' => [
                'subtotal' => (float)($order['subtotal'] ?? 0),
                'tax' => (float)($order['tax_amount'] ?? 0),
                'total' => (float)($order['total_amount'] ?? 0),
                'paid' => (float)($order['paid_amount'] ?? 0),
                'due' => (float)($order['total_amount'] ?? 0) - (float)($order['paid_amount'] ?? 0),
                'currency' => $currency,
                'currency_symbol' => $currencySymbol
            ],
            'table_id' => isset($order['table_id']) ? (int)$order['table_id'] : null,
            'notes' => $order['notes'] ?? null,
            'created_at' => $order['created_at'] ?? date('Y-m-d H:i:s'),
            'updated_at' => $order['updated_at'] ?? date('Y-m-d H:i:s')
        ],
        'items' => array_map(function($item) {
            return [
                'id' => (int)$item['id'],
                'product_id' => isset($item['product_id']) ? (int)$item['product_id'] : null,
                'name' => $item['product_name'] ?? 'Item',
                'quantity' => (float)($item['quantity'] ?? 0),
                'unit_price' => (float)($item['unit_price'] ?? 0),
                'line_total' => (float)($item['line_total'] ?? 0),
                'is_voided' => (bool)($item['is_voided'] ?? false),
                'status' => $item['kitchen_status'] ?? $item['state'] ?? 'pending'
            ];
        }, $items),
        'summary' => [
            'total_items' => count($items),
            'active_items' => $activeItems,
            'voided_items' => $voidedItems,
            'total_quantity' => $totalQuantity,
            'has_payments' => count($payments) > 0
        ]
    ];
    
    // Add payments if available
    if (!empty($payments)) {
        $response['payments'] = array_map(function($payment) {
            return [
                'id' => (int)$payment['id'],
                'method' => $payment['payment_method'],
                'amount' => (float)$payment['amount'],
                'reference' => $payment['reference_number'] ?? null,
                'status' => $payment['status'] ?? 'completed'
            ];
        }, $payments);
    }
    
    sendSuccess($response);
    
} catch (PDOException $e) {
    error_log("[SME180] Database error in get status: " . $e->getMessage());
    sendError('Database error occurred', 500, 'DATABASE_ERROR');
} catch (Exception $e) {
    error_log("[SME180] Error in get status: " . $e->getMessage());
    sendError('Failed to retrieve order status', 500, 'GENERAL_ERROR');
}
?>