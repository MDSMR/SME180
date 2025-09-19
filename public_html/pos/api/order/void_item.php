<?php
/**
 * SME 180 POS - Void Item API
 * Path: /public_html/pos/api/order/void_item.php
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

function checkRateLimit($pdo, $tenantId, $userId, $action = 'item_voided') {
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
        
        // Allow max 10 item void operations per minute per user
        if ($count >= 10) {
            logEvent('WARNING', 'Rate limit exceeded for item void', [
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
$userRole = $_SESSION['role'] ?? 'cashier';

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

if (!isset($input['item_id'])) {
    sendError('Item ID is required', 400, 'MISSING_ITEM_ID');
}

if (!isset($input['reason']) || empty(trim($input['reason']))) {
    sendError('Void reason is required', 400, 'MISSING_REASON');
}

// Validate IDs
$orderId = filter_var($input['order_id'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
]);

if ($orderId === false) {
    sendError('Invalid order ID format', 400, 'INVALID_ORDER_ID');
}

$itemId = filter_var($input['item_id'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
]);

if ($itemId === false) {
    sendError('Invalid item ID format', 400, 'INVALID_ITEM_ID');
}

// Sanitize reason
$reason = substr(trim(strip_tags($input['reason'])), 0, 500);

// Approval PIN
$approvalPin = isset($input['approval_pin']) ? 
    substr(trim($input['approval_pin']), 0, 20) : null;

try {
    // Check rate limit
    if (!checkRateLimit($pdo, $tenantId, $userId, 'item_voided')) {
        sendError(
            'Too many void requests. Please wait before trying again.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
    }
    
    $pdo->beginTransaction();
    
    // Fetch item with order info and lock
    $stmt = $pdo->prepare("
        SELECT oi.*, 
               o.status as order_status, 
               o.payment_status,
               o.tenant_id,
               o.branch_id,
               o.receipt_reference
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = :item_id 
        AND oi.order_id = :order_id
        AND o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND oi.is_voided = 0
        FOR UPDATE
    ");
    $stmt->execute([
        'item_id' => $itemId,
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        $pdo->rollBack();
        logEvent('WARNING', 'Item not found or already voided', [
            'item_id' => $itemId,
            'order_id' => $orderId
        ]);
        sendError('Item not found or already voided', 404, 'ITEM_NOT_FOUND');
    }
    
    // Validate order status
    if ($item['payment_status'] === 'paid') {
        $pdo->rollBack();
        sendError('Cannot void items from paid orders', 409, 'ORDER_ALREADY_PAID');
    }
    
    if (in_array($item['order_status'], ['closed', 'voided', 'refunded'])) {
        $pdo->rollBack();
        sendError(
            'Cannot void items from ' . $item['order_status'] . ' orders',
            409,
            'INVALID_ORDER_STATUS'
        );
    }
    
    $approvedBy = $userId;
    $managerName = null;
    
    // Check if item is fired (requires approval)
    $itemFired = false;
    if (in_array($item['kitchen_status'], ['preparing', 'ready', 'served'])) {
        $itemFired = true;
    }
    
    if ($itemFired) {
        logEvent('INFO', 'Attempting to void fired item', [
            'item_id' => $itemId,
            'kitchen_status' => $item['kitchen_status']
        ]);
        
        if (!in_array($userRole, ['admin', 'manager', 'owner'])) {
            if (!$approvalPin) {
                $pdo->commit();
                sendError(
                    'Manager approval required to void fired item',
                    403,
                    'MANAGER_APPROVAL_REQUIRED',
                    [
                        'requires_approval' => true,
                        'item_status' => $item['kitchen_status']
                    ]
                );
            }
            
            // Validate manager PIN
            $stmt = $pdo->prepare("
                SELECT id, name, role FROM users 
                WHERE tenant_id = :tenant_id 
                AND pin = :pin 
                AND role IN ('admin', 'manager', 'owner')
                AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([
                'tenant_id' => $tenantId,
                'pin' => hash('sha256', $approvalPin)
            ]);
            
            $manager = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$manager) {
                $pdo->rollBack();
                logEvent('WARNING', 'Invalid approval PIN for item void', [
                    'item_id' => $itemId,
                    'user_id' => $userId
                ]);
                sendError('Invalid approval PIN', 403, 'INVALID_APPROVAL_PIN');
            }
            
            $approvedBy = $manager['id'];
            $managerName = $manager['name'];
            
            logEvent('INFO', 'Manager approval granted for item void', [
                'item_id' => $itemId,
                'manager_id' => $approvedBy,
                'manager_role' => $manager['role']
            ]);
        }
    }
    
    // Store original values for logging
    $originalAmount = (float)$item['line_total'];
    
    // Void the item
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET is_voided = 1,
            void_reason = :reason,
            voided_at = NOW(),
            voided_by = :approved_by,
            updated_at = NOW()
        WHERE id = :item_id
    ");
    $stmt->execute([
        'reason' => $reason,
        'approved_by' => $approvedBy,
        'item_id' => $itemId
    ]);
    
    // Get tax rate from settings
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'tax_rate' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $taxRate = (float)($stmt->fetchColumn() ?: 14);
    
    // Recalculate order totals
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN is_voided = 0 THEN line_total ELSE 0 END) as subtotal,
            COUNT(CASE WHEN is_voided = 0 THEN 1 ELSE NULL END) as active_items,
            COUNT(*) as total_items
        FROM order_items 
        WHERE order_id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotal = (float)$totals['subtotal'];
    $activeItems = (int)$totals['active_items'];
    $totalItems = (int)$totals['total_items'];
    
    // Get current order values for discount and service charge
    $stmt = $pdo->prepare("
        SELECT discount_amount, service_charge, tip_amount 
        FROM orders 
        WHERE id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $orderCharges = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate new totals
    $discountAmount = (float)$orderCharges['discount_amount'];
    $serviceCharge = (float)$orderCharges['service_charge'];
    $tipAmount = (float)$orderCharges['tip_amount'];
    
    $taxableAmount = $subtotal - $discountAmount + $serviceCharge;
    $tax = $taxableAmount * ($taxRate / 100);
    $newTotal = $taxableAmount + $tax + $tipAmount;
    
    // Update order totals
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET subtotal = :subtotal,
            tax_amount = :tax,
            total_amount = :total,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'subtotal' => $subtotal,
        'tax' => $tax,
        'total' => $newTotal,
        'order_id' => $orderId
    ]);
    
    // Log void event
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'item_voided', :details, NOW()
        )
    ");
    
    $logDetails = [
        'item_id' => $itemId,
        'item_name' => $item['product_name'],
        'quantity' => $item['quantity'],
        'original_amount' => $originalAmount,
        'reason' => $reason,
        'approved_by' => $approvedBy,
        'approved_by_name' => $managerName,
        'was_fired' => $itemFired,
        'kitchen_status' => $item['kitchen_status'],
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
    
    // Log successful void
    logEvent('INFO', 'Item voided successfully', [
        'item_id' => $itemId,
        'order_id' => $orderId,
        'receipt' => $item['receipt_reference'],
        'amount_removed' => $originalAmount,
        'approved_by' => $approvedBy
    ]);
    
    sendSuccess([
        'message' => 'Item voided successfully',
        'order_id' => $orderId,
        'item' => [
            'id' => $itemId,
            'product_name' => $item['product_name'],
            'voided' => true,
            'void_reason' => $reason,
            'voided_amount' => $originalAmount,
            'approved_by' => $approvedBy,
            'approved_by_name' => $managerName
        ],
        'order_totals' => [
            'subtotal' => round($subtotal, 2),
            'tax' => round($tax, 2),
            'total' => round($newTotal, 2),
            'currency' => $currency,
            'active_items' => $activeItems,
            'total_items' => $totalItems
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Void item failed', [
        'item_id' => $itemId,
        'order_id' => $orderId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to void item', 500, 'VOID_ITEM_FAILED');
}
?>