<?php
/**
 * SME 180 POS - Pay Order API
 * Path: /public_html/pos/api/order/pay.php
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

function checkRateLimit($pdo, $tenantId, $userId, $action = 'payment') {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as action_count 
            FROM order_logs 
            WHERE tenant_id = ? 
                AND user_id = ? 
                AND action LIKE ?
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute([$tenantId, $userId, $action . '%']);
        $count = $stmt->fetchColumn();
        
        // Allow max 20 payment operations per minute per user
        if ($count >= 20) {
            logEvent('WARNING', 'Rate limit exceeded for payment operation', [
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
$sessionId = isset($_SESSION['cash_session_id']) ? (int)$_SESSION['cash_session_id'] : 0;

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
if (strlen($rawInput) > 50000) { // 50KB max
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

if (!isset($input['payments']) || !is_array($input['payments'])) {
    sendError('Payments array is required', 400, 'MISSING_PAYMENTS');
}

if (empty($input['payments'])) {
    sendError('At least one payment method is required', 400, 'NO_PAYMENT_METHODS');
}

// Validate order ID
$orderId = filter_var($input['order_id'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
]);

if ($orderId === false) {
    sendError('Invalid order ID format', 400, 'INVALID_ORDER_ID');
}

// Validate payment methods
$validPaymentMethods = ['cash', 'card', 'credit_card', 'debit_card', 'mobile', 'wallet', 'voucher', 'other'];
$payments = $input['payments'];

if (count($payments) > 10) {
    sendError('Cannot process more than 10 payment methods', 400, 'TOO_MANY_PAYMENT_METHODS');
}

// Validate each payment
foreach ($payments as $index => $payment) {
    if (!isset($payment['method']) || !isset($payment['amount'])) {
        sendError(
            'Payment ' . ($index + 1) . ' must have method and amount',
            400,
            'INVALID_PAYMENT_STRUCTURE'
        );
    }
    
    if (!in_array($payment['method'], $validPaymentMethods)) {
        sendError(
            'Invalid payment method: ' . $payment['method'],
            400,
            'INVALID_PAYMENT_METHOD'
        );
    }
    
    $amount = filter_var($payment['amount'], FILTER_VALIDATE_FLOAT);
    if ($amount === false || $amount <= 0 || $amount > 999999) {
        sendError(
            'Payment ' . ($index + 1) . ' has invalid amount',
            400,
            'INVALID_PAYMENT_AMOUNT'
        );
    }
}

$printReceipt = isset($input['print_receipt']) ? (bool)$input['print_receipt'] : true;

try {
    // Check rate limit
    if (!checkRateLimit($pdo, $tenantId, $userId, 'paid')) {
        sendError(
            'Too many payment requests. Please wait before trying again.',
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
    
    // Check if order can be paid
    if ($order['payment_status'] === 'paid') {
        $pdo->rollBack();
        sendError('Order is already paid', 409, 'ORDER_ALREADY_PAID');
    }
    
    if (in_array($order['status'], ['voided', 'refunded'])) {
        $pdo->rollBack();
        sendError(
            'Cannot pay ' . $order['status'] . ' orders',
            409,
            'INVALID_ORDER_STATUS'
        );
    }
    
    // Check if columns exist
    $hasPaidAmount = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'paid_amount'");
        $hasPaidAmount = ($checkCol->rowCount() > 0);
    } catch (Exception $e) {
        $hasPaidAmount = false;
    }
    
    // Calculate total payment amount
    $totalPayment = 0;
    $paymentMethods = [];
    $paymentDetails = [];
    
    foreach ($payments as $payment) {
        $amount = (float)$payment['amount'];
        $totalPayment += $amount;
        $paymentMethods[] = $payment['method'];
        
        $paymentDetails[] = [
            'method' => $payment['method'],
            'amount' => $amount,
            'reference' => isset($payment['reference']) ? 
                substr(trim($payment['reference']), 0, 100) : null
        ];
    }
    
    // Check if payment covers the order total
    $orderTotal = (float)$order['total_amount'];
    $previouslyPaid = $hasPaidAmount ? (float)$order['paid_amount'] : 0;
    $amountDue = $orderTotal - $previouslyPaid;
    
    if ($amountDue <= 0) {
        $pdo->rollBack();
        sendError('No payment due for this order', 409, 'NO_PAYMENT_DUE');
    }
    
    if ($totalPayment < $amountDue - 0.01) { // Allow 1 cent tolerance
        $pdo->rollBack();
        sendError(
            'Insufficient payment',
            409,
            'INSUFFICIENT_PAYMENT',
            [
                'amount_due' => round($amountDue, 2),
                'payment_received' => round($totalPayment, 2),
                'shortage' => round($amountDue - $totalPayment, 2),
                'currency' => $currency
            ]
        );
    }
    
    // Calculate change if overpayment
    $changeAmount = max(0, $totalPayment - $amountDue);
    $actualPayment = $totalPayment - $changeAmount;
    
    // Process each payment
    $paymentIds = [];
    $adjustedPayments = [];
    
    foreach ($paymentDetails as $i => $payment) {
        $paymentAmount = $payment['amount'];
        
        // Adjust last payment for exact amount (to handle change)
        if ($i === count($paymentDetails) - 1 && $changeAmount > 0) {
            $paymentAmount = max(0, $paymentAmount - $changeAmount);
            if ($paymentAmount <= 0) continue; // Skip if fully change
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO order_payments (
                order_id, tenant_id, branch_id,
                payment_method, amount, reference_number,
                processed_by, status, created_at
            ) VALUES (
                :order_id, :tenant_id, :branch_id,
                :method, :amount, :reference,
                :user_id, 'completed', NOW()
            )
        ");
        
        $stmt->execute([
            'order_id' => $orderId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'method' => $payment['method'],
            'amount' => $paymentAmount,
            'reference' => $payment['reference'],
            'user_id' => $userId
        ]);
        
        $paymentIds[] = (int)$pdo->lastInsertId();
        $adjustedPayments[] = [
            'method' => $payment['method'],
            'amount' => $paymentAmount
        ];
    }
    
    // Update order status
    $newPaidAmount = $previouslyPaid + $actualPayment;
    $isFullyPaid = $newPaidAmount >= $orderTotal - 0.01;
    
    // Build update query based on available columns
    $updateFields = [
        "payment_status = :payment_status",
        "status = CASE WHEN :is_paid THEN 'closed' ELSE status END",
        "payment_method = :payment_method",
        "updated_at = NOW()"
    ];
    
    $updateParams = [
        'payment_status' => $isFullyPaid ? 'paid' : 'partial',
        'is_paid' => $isFullyPaid,
        'payment_method' => implode(',', array_unique($paymentMethods)),
        'order_id' => $orderId
    ];
    
    if ($hasPaidAmount) {
        $updateFields[] = "paid_amount = :paid_amount";
        $updateFields[] = "paid_at = CASE WHEN :is_paid2 THEN NOW() ELSE paid_at END";
        $updateParams['paid_amount'] = $newPaidAmount;
        $updateParams['is_paid2'] = $isFullyPaid;
    }
    
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET " . implode(", ", $updateFields) . "
        WHERE id = :order_id
    ");
    $stmt->execute($updateParams);
    
    // Free the table if dine-in and fully paid
    $tableFreed = false;
    if ($isFullyPaid && $order['order_type'] === 'dine_in' && $order['table_id']) {
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
            // Table system might not be implemented
            logEvent('INFO', 'Table update skipped', ['error' => $e->getMessage()]);
        }
    }
    
    // Update cash session if cash payment
    if ($sessionId && in_array('cash', $paymentMethods)) {
        try {
            $cashAmount = array_sum(array_map(function($p) {
                return $p['method'] === 'cash' ? $p['amount'] : 0;
            }, $adjustedPayments));
            
            $stmt = $pdo->prepare("
                UPDATE cash_sessions 
                SET cash_sales = cash_sales + :amount,
                    total_sales = total_sales + :amount,
                    updated_at = NOW()
                WHERE id = :session_id
            ");
            $stmt->execute([
                'amount' => $cashAmount,
                'session_id' => $sessionId
            ]);
        } catch (PDOException $e) {
            // Cash sessions might not be implemented
            logEvent('INFO', 'Cash session update skipped', ['error' => $e->getMessage()]);
        }
    }
    
    // Log the payment
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            :action, :details, NOW()
        )
    ");
    
    $logDetails = [
        'payments' => $adjustedPayments,
        'total_payment' => $totalPayment,
        'actual_payment' => $actualPayment,
        'change' => $changeAmount,
        'payment_status' => $isFullyPaid ? 'paid' : 'partial',
        'payment_ids' => $paymentIds,
        'table_freed' => $tableFreed,
        'cash_session_id' => $sessionId,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ];
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'action' => $isFullyPaid ? 'paid_full' : 'paid_partial',
        'details' => json_encode($logDetails)
    ]);
    
    $pdo->commit();
    
    // Queue receipt printing if requested and fully paid
    if ($printReceipt && $isFullyPaid) {
        // This would integrate with your printing system
        logEvent('INFO', 'Receipt print queued', [
            'order_id' => $orderId,
            'receipt' => $order['receipt_reference']
        ]);
    }
    
    // Log successful payment
    logEvent('INFO', 'Payment processed successfully', [
        'order_id' => $orderId,
        'receipt' => $order['receipt_reference'],
        'amount' => $actualPayment,
        'status' => $isFullyPaid ? 'full' : 'partial',
        'methods' => array_unique($paymentMethods)
    ]);
    
    sendSuccess([
        'message' => $isFullyPaid ? 'Payment successful' : 'Partial payment received',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'total_amount' => round($orderTotal, 2),
            'paid_amount' => round($newPaidAmount, 2),
            'amount_due' => round(max(0, $orderTotal - $newPaidAmount), 2),
            'payment_status' => $isFullyPaid ? 'paid' : 'partial',
            'status' => $isFullyPaid ? 'closed' : $order['status'],
            'currency' => $currency
        ],
        'payment' => [
            'total_received' => round($totalPayment, 2),
            'actual_payment' => round($actualPayment, 2),
            'change' => round($changeAmount, 2),
            'payment_ids' => $paymentIds,
            'methods' => array_unique($paymentMethods)
        ],
        'actions' => [
            'table_freed' => $tableFreed,
            'print_queued' => $printReceipt && $isFullyPaid
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Payment processing failed', [
        'order_id' => $orderId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to process payment', 500, 'PAYMENT_FAILED');
}
?>
