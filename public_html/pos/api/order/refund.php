<?php
/**
 * SME 180 POS - Order Refund API
 * Path: /public_html/pos/api/order/refund.php
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

function checkRateLimit($pdo, $tenantId, $userId, $action = 'refunded') {
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
        
        // Allow max 3 refund operations per minute per user (strict limit for financial operations)
        if ($count >= 3) {
            logEvent('WARNING', 'Rate limit exceeded for refund operation', [
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

if (!isset($input['refund_type'])) {
    sendError('Refund type is required', 400, 'MISSING_REFUND_TYPE');
}

if (!isset($input['reason']) || empty(trim($input['reason']))) {
    sendError('Refund reason is required', 400, 'MISSING_REASON');
}

// Validate order ID
$orderId = filter_var($input['order_id'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
]);

if ($orderId === false) {
    sendError('Invalid order ID format', 400, 'INVALID_ORDER_ID');
}

// Validate refund type
$validRefundTypes = ['full', 'partial', 'item'];
$refundType = $input['refund_type'];

if (!in_array($refundType, $validRefundTypes)) {
    sendError(
        'Invalid refund type. Must be: ' . implode(', ', $validRefundTypes),
        400,
        'INVALID_REFUND_TYPE'
    );
}

// Validate reason
$reason = substr(trim(strip_tags($input['reason'])), 0, 500);

// Validate amount for partial refunds
$amount = 0;
if ($refundType === 'partial') {
    if (!isset($input['amount'])) {
        sendError('Amount is required for partial refund', 400, 'MISSING_AMOUNT');
    }
    
    $amount = filter_var($input['amount'], FILTER_VALIDATE_FLOAT);
    if ($amount === false || $amount <= 0 || $amount > 999999) {
        sendError('Invalid refund amount', 400, 'INVALID_AMOUNT');
    }
}

// Validate item IDs for item refunds
$itemIds = [];
if ($refundType === 'item') {
    if (!isset($input['item_ids']) || !is_array($input['item_ids']) || empty($input['item_ids'])) {
        sendError('Item IDs are required for item refund', 400, 'MISSING_ITEM_IDS');
    }
    
    $itemIds = array_filter($input['item_ids'], 'is_numeric');
    if (empty($itemIds)) {
        sendError('Invalid item IDs', 400, 'INVALID_ITEM_IDS');
    }
    
    $itemIds = array_map('intval', $itemIds);
    if (count($itemIds) > 100) {
        sendError('Cannot refund more than 100 items at once', 400, 'TOO_MANY_ITEMS');
    }
}

// Approval PIN
$approvalPin = isset($input['approval_pin']) ? 
    substr(trim($input['approval_pin']), 0, 20) : null;

try {
    // Check rate limit
    if (!checkRateLimit($pdo, $tenantId, $userId)) {
        sendError(
            'Too many refund requests. Please wait before trying again.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
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
    
    $pdo->beginTransaction();
    
    // Fetch and lock order
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
    
    // Validate order status
    if (!in_array($order['payment_status'], ['paid', 'partial', 'partial_refund'])) {
        $pdo->rollBack();
        sendError('Can only refund paid orders', 409, 'ORDER_NOT_PAID');
    }
    
    if ($order['status'] === 'refunded') {
        $pdo->rollBack();
        sendError('Order is already fully refunded', 409, 'ORDER_ALREADY_REFUNDED');
    }
    
    // Check if columns exist
    $hasPaidAmount = false;
    $hasRefundedAmount = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'paid_amount'");
        $hasPaidAmount = ($checkCol->rowCount() > 0);
        
        $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'refunded_amount'");
        $hasRefundedAmount = ($checkCol->rowCount() > 0);
    } catch (Exception $e) {
        // Columns might not exist
    }
    
    // Calculate amounts
    $totalPaid = $hasPaidAmount ? (float)$order['paid_amount'] : (float)$order['total_amount'];
    $totalRefunded = $hasRefundedAmount ? (float)$order['refunded_amount'] : 0;
    $availableToRefund = $totalPaid - $totalRefunded;
    
    if ($availableToRefund <= 0) {
        $pdo->rollBack();
        sendError('No amount available to refund', 409, 'NO_REFUNDABLE_AMOUNT');
    }
    
    // Calculate refund amount based on type
    $refundAmount = 0;
    
    switch ($refundType) {
        case 'full':
            $refundAmount = $availableToRefund;
            break;
            
        case 'partial':
            if ($amount > $availableToRefund) {
                $pdo->rollBack();
                sendError(
                    'Refund amount exceeds available amount',
                    409,
                    'AMOUNT_EXCEEDS_AVAILABLE',
                    ['available' => $availableToRefund]
                );
            }
            $refundAmount = $amount;
            break;
            
        case 'item':
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT SUM(line_total) as refund_amount,
                       COUNT(*) as item_count 
                FROM order_items 
                WHERE order_id = ? 
                AND id IN ($placeholders)
                AND is_voided = 0
            ");
            $params = array_merge([$orderId], $itemIds);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result || !$result['refund_amount']) {
                $pdo->rollBack();
                sendError('No valid items found for refund', 404, 'NO_VALID_ITEMS');
            }
            
            $refundAmount = (float)$result['refund_amount'];
            
            // Include proportional tax and charges
            $itemProportion = $refundAmount / $order['subtotal'];
            $refundAmount += ($order['tax_amount'] * $itemProportion);
            $refundAmount -= ($order['discount_amount'] * $itemProportion);
            
            if ($refundAmount > $availableToRefund) {
                $refundAmount = $availableToRefund;
            }
            break;
    }
    
    if ($refundAmount <= 0) {
        $pdo->rollBack();
        sendError('Invalid refund amount calculated', 409, 'INVALID_REFUND_AMOUNT');
    }
    
    // Check for manager approval if needed
    $approvedBy = $userId;
    $managerName = null;
    
    // Check refund limits
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'pos_max_refund_amount' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $maxRefundAmount = (float)($stmt->fetchColumn() ?: 5000);
    
    $requiresApproval = false;
    
    // Require approval for high-value refunds or non-manager users
    if ($refundAmount > $maxRefundAmount || !in_array($userRole, ['admin', 'manager', 'owner'])) {
        $requiresApproval = true;
    }
    
    if ($requiresApproval) {
        if (!$approvalPin) {
            $pdo->commit();
            sendError(
                'Manager approval required for this refund',
                403,
                'MANAGER_APPROVAL_REQUIRED',
                [
                    'requires_approval' => true,
                    'refund_amount' => $refundAmount,
                    'reason' => $refundAmount > $maxRefundAmount ? 
                        'Amount exceeds limit' : 'User role requires approval'
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
            logEvent('WARNING', 'Invalid approval PIN for refund', [
                'order_id' => $orderId,
                'user_id' => $userId,
                'amount' => $refundAmount
            ]);
            sendError('Invalid approval PIN', 403, 'INVALID_APPROVAL_PIN');
        }
        
        $approvedBy = $manager['id'];
        $managerName = $manager['name'];
        
        logEvent('INFO', 'Manager approval granted for refund', [
            'order_id' => $orderId,
            'manager_id' => $approvedBy,
            'amount' => $refundAmount
        ]);
    }
    
    // Check if order_refunds table exists
    $hasRefundsTable = false;
    try {
        $result = $pdo->query("SHOW TABLES LIKE 'order_refunds'");
        $hasRefundsTable = ($result->rowCount() > 0);
    } catch (Exception $e) {
        $hasRefundsTable = false;
    }
    
    // Create refund record if table exists
    $refundId = 0;
    if ($hasRefundsTable) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO order_refunds (
                    tenant_id, branch_id, order_id,
                    refund_type, amount, currency, reason,
                    status, approved_by, approved_at,
                    processed_by, processed_at, created_at
                ) VALUES (
                    :tenant_id, :branch_id, :order_id,
                    :refund_type, :amount, :currency, :reason,
                    'completed', :approved_by, NOW(),
                    :processed_by, NOW(), NOW()
                )
            ");
            $stmt->execute([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'refund_type' => $refundType,
                'amount' => $refundAmount,
                'currency' => $currency,
                'reason' => $reason,
                'approved_by' => $approvedBy,
                'processed_by' => $userId
            ]);
            $refundId = (int)$pdo->lastInsertId();
        } catch (PDOException $e) {
            // Table might not have all columns
            logEvent('WARNING', 'Could not create refund record', ['error' => $e->getMessage()]);
        }
    }
    
    // Update order status
    $newRefundedAmount = $totalRefunded + $refundAmount;
    $newPaymentStatus = ($newRefundedAmount >= $totalPaid - 0.01) ? 'refunded' : 'partial_refund';
    $newOrderStatus = ($refundType === 'full' || $newPaymentStatus === 'refunded') ? 'refunded' : $order['status'];
    
    // Build update query based on available columns
    $updateFields = [
        "payment_status = :payment_status",
        "status = :status",
        "updated_at = NOW()"
    ];
    
    $updateParams = [
        'payment_status' => $newPaymentStatus,
        'status' => $newOrderStatus,
        'order_id' => $orderId
    ];
    
    if ($hasRefundedAmount) {
        $updateFields[] = "refunded_amount = COALESCE(refunded_amount, 0) + :amount";
        $updateFields[] = "refunded_at = COALESCE(refunded_at, NOW())";
        $updateFields[] = "refunded_by = COALESCE(refunded_by, :user_id)";
        $updateParams['amount'] = $refundAmount;
        $updateParams['user_id'] = $userId;
    }
    
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET " . implode(", ", $updateFields) . "
        WHERE id = :order_id
    ");
    $stmt->execute($updateParams);
    
    // Void refunded items if item refund
    if ($refundType === 'item' && !empty($itemIds)) {
        $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET is_voided = 1,
                void_reason = 'Refunded',
                voided_at = NOW(),
                voided_by = ?,
                updated_at = NOW()
            WHERE id IN ($placeholders)
        ");
        $params = array_merge([$userId], $itemIds);
        $stmt->execute($params);
    }
    
    // Log refund event
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'refunded', :details, NOW()
        )
    ");
    
    $logDetails = [
        'refund_id' => $refundId,
        'refund_type' => $refundType,
        'amount' => $refundAmount,
        'reason' => $reason,
        'approved_by' => $approvedBy,
        'approved_by_name' => $managerName,
        'item_ids' => $itemIds,
        'payment_status' => $newPaymentStatus,
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
    
    // Log successful refund
    logEvent('INFO', 'Refund processed successfully', [
        'order_id' => $orderId,
        'receipt' => $order['receipt_reference'],
        'refund_id' => $refundId,
        'type' => $refundType,
        'amount' => $refundAmount,
        'approved_by' => $approvedBy
    ]);
    
    sendSuccess([
        'message' => 'Refund processed successfully',
        'refund' => [
            'id' => $refundId,
            'order_id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'type' => $refundType,
            'amount' => round($refundAmount, 2),
            'currency' => $currency,
            'reason' => $reason,
            'status' => 'completed',
            'payment_status' => $newPaymentStatus,
            'order_status' => $newOrderStatus,
            'approved_by' => $approvedBy,
            'approved_by_name' => $managerName,
            'processed_at' => date('c')
        ],
        'order' => [
            'total_paid' => round($totalPaid, 2),
            'total_refunded' => round($newRefundedAmount, 2),
            'remaining' => round($totalPaid - $newRefundedAmount, 2)
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Refund failed', [
        'order_id' => $orderId,
        'refund_type' => $refundType,
        'amount' => $refundAmount ?? 0,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to process refund', 500, 'REFUND_FAILED');
}
?>
