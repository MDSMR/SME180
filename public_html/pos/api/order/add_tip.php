<?php
/**
 * SME 180 POS - Add Tip API
 * Path: /public_html/pos/api/order/add_tip.php
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

function checkRateLimit($pdo, $tenantId, $userId, $action = 'tip_added') {
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
        
        // Allow max 20 tip operations per minute per user
        if ($count >= 20) {
            logEvent('WARNING', 'Rate limit exceeded for tip operation', [
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

// Validate tip type
$validTipTypes = ['amount', 'percent'];
$tipType = isset($input['tip_type']) ? $input['tip_type'] : 'amount';

if (!in_array($tipType, $validTipTypes)) {
    sendError(
        'Invalid tip type. Must be: ' . implode(', ', $validTipTypes),
        400,
        'INVALID_TIP_TYPE'
    );
}

// Validate tip value
if (!isset($input['tip_value'])) {
    sendError('Tip value is required', 400, 'MISSING_TIP_VALUE');
}

$tipValue = filter_var($input['tip_value'], FILTER_VALIDATE_FLOAT);

if ($tipValue === false || $tipValue < 0) {
    sendError('Invalid tip value', 400, 'INVALID_TIP_VALUE');
}

// Validate maximum tip
if ($tipType === 'percent' && $tipValue > 100) {
    sendError('Tip percentage cannot exceed 100%', 400, 'TIP_EXCEEDS_MAXIMUM');
}

if ($tipType === 'amount' && $tipValue > 99999) {
    sendError('Tip amount exceeds maximum allowed', 400, 'TIP_AMOUNT_TOO_HIGH');
}

try {
    // Check rate limit
    if (!checkRateLimit($pdo, $tenantId, $userId)) {
        sendError(
            'Too many tip requests. Please wait before trying again.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
    }
    
    // Get settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value`
        FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('max_tip_percent', 'currency_symbol', 'currency_code', 'currency')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $maxTipPercent = isset($settings['max_tip_percent']) ? 
        floatval($settings['max_tip_percent']) : 50.0;
    $currency = $settings['currency_code'] ?? $settings['currency'] ?? 'EGP';
    $currencySymbol = $settings['currency_symbol'] ?? $currency;
    
    $pdo->beginTransaction();
    
    // Fetch order
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
    
    // Check if order can accept tips
    if ($order['status'] === 'voided' || $order['status'] === 'refunded') {
        $pdo->rollBack();
        sendError(
            'Cannot add tip to ' . $order['status'] . ' orders',
            409,
            'INVALID_ORDER_STATUS'
        );
    }
    
    // Check if already paid - warn but allow
    $isPaid = ($order['payment_status'] === 'paid');
    if ($isPaid) {
        logEvent('WARNING', 'Adding tip to paid order', [
            'order_id' => $orderId,
            'receipt' => $order['receipt_reference']
        ]);
    }
    
    // Check if tip_amount column exists
    $hasTipAmount = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'tip_amount'");
        $hasTipAmount = ($checkCol->rowCount() > 0);
    } catch (Exception $e) {
        $hasTipAmount = false;
    }
    
    if (!$hasTipAmount) {
        $pdo->rollBack();
        sendError('Tip feature not available', 501, 'FEATURE_NOT_AVAILABLE');
    }
    
    // Calculate base amount for tip calculation
    $baseAmount = (float)$order['subtotal'] - (float)$order['discount_amount'];
    
    if ($baseAmount <= 0) {
        $pdo->rollBack();
        sendError('Cannot add tip to zero value order', 409, 'ZERO_VALUE_ORDER');
    }
    
    // Calculate tip amount
    $tipAmount = 0;
    $tipPercent = 0;
    $wasCapped = false;
    
    if ($tipType === 'percent') {
        if ($tipValue > $maxTipPercent) {
            $tipPercent = $maxTipPercent;
            $wasCapped = true;
            
            logEvent('WARNING', 'Tip percentage capped', [
                'order_id' => $orderId,
                'requested' => $tipValue,
                'max_allowed' => $maxTipPercent
            ]);
        } else {
            $tipPercent = $tipValue;
        }
        
        $tipAmount = $baseAmount * ($tipPercent / 100);
    } else {
        // Direct amount
        $tipAmount = $tipValue;
        
        // Calculate percentage for reference
        $tipPercent = ($tipAmount / $baseAmount) * 100;
        
        // Check if exceeds maximum percentage
        if ($tipPercent > $maxTipPercent) {
            // Cap at maximum percentage
            $tipPercent = $maxTipPercent;
            $tipAmount = $baseAmount * ($maxTipPercent / 100);
            $wasCapped = true;
            
            logEvent('WARNING', 'Tip amount capped', [
                'order_id' => $orderId,
                'requested' => $tipValue,
                'capped_at' => $tipAmount,
                'max_percent' => $maxTipPercent
            ]);
        }
    }
    
    // Round tip amount
    $tipAmount = round($tipAmount, 2);
    
    // Update order with tip
    $oldTipAmount = (float)$order['tip_amount'];
    $tipDifference = $tipAmount - $oldTipAmount;
    
    // Only update if tip changed significantly (more than 1 cent)
    if (abs($tipDifference) < 0.01) {
        $pdo->commit();
        
        logEvent('INFO', 'Tip unchanged', [
            'order_id' => $orderId,
            'tip_amount' => $tipAmount
        ]);
        
        sendSuccess([
            'message' => 'Tip unchanged',
            'order_id' => $orderId,
            'tip' => [
                'amount' => round($tipAmount, 2),
                'percent' => round($tipPercent, 2),
                'currency' => $currency,
                'currency_symbol' => $currencySymbol
            ],
            'total' => round((float)$order['total_amount'], 2)
        ]);
    }
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET tip_amount = :tip_amount,
            total_amount = total_amount + :tip_difference,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'tip_amount' => $tipAmount,
        'tip_difference' => $tipDifference,
        'order_id' => $orderId
    ]);
    
    // Log tip event
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'tip_added', :details, NOW())
    ");
    
    $logDetails = [
        'old_tip' => $oldTipAmount,
        'new_tip' => $tipAmount,
        'tip_percent' => $tipPercent,
        'type' => $tipType,
        'value' => $tipValue,
        'difference' => $tipDifference,
        'base_amount' => $baseAmount,
        'was_capped' => $wasCapped,
        'order_paid' => $isPaid,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
    ];
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode($logDetails)
    ]);
    
    $pdo->commit();
    
    // Get updated totals
    $stmt = $pdo->prepare("
        SELECT 
            tip_amount, 
            total_amount, 
            subtotal, 
            discount_amount, 
            service_charge, 
            tax_amount,
            payment_status,
            receipt_reference
        FROM orders 
        WHERE id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $updatedOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check if we need to update payment records for paid orders
    if ($isPaid && $tipDifference > 0) {
        // This would typically trigger a supplementary payment process
        logEvent('INFO', 'Tip added to paid order - supplementary payment may be required', [
            'order_id' => $orderId,
            'additional_amount' => $tipDifference
        ]);
    }
    
    // Log successful tip addition
    logEvent('INFO', 'Tip added successfully', [
        'order_id' => $orderId,
        'receipt' => $updatedOrder['receipt_reference'],
        'old_tip' => $oldTipAmount,
        'new_tip' => $tipAmount,
        'tip_percent' => $tipPercent,
        'was_capped' => $wasCapped
    ]);
    
    // Prepare response
    $response = [
        'message' => 'Tip added successfully',
        'order_id' => $orderId,
        'receipt_reference' => $updatedOrder['receipt_reference'],
        'tip' => [
            'amount' => round((float)$updatedOrder['tip_amount'], 2),
            'percent' => round($tipPercent, 2),
            'previous' => round($oldTipAmount, 2),
            'change' => round($tipDifference, 2),
            'type' => $tipType,
            'currency' => $currency,
            'currency_symbol' => $currencySymbol
        ],
        'order_totals' => [
            'subtotal' => round((float)$updatedOrder['subtotal'], 2),
            'discount' => round((float)$updatedOrder['discount_amount'], 2),
            'service_charge' => round((float)$updatedOrder['service_charge'], 2),
            'tax' => round((float)$updatedOrder['tax_amount'], 2),
            'tip' => round((float)$updatedOrder['tip_amount'], 2),
            'total' => round((float)$updatedOrder['total_amount'], 2),
            'currency' => $currency,
            'currency_symbol' => $currencySymbol
        ],
        'payment_status' => $updatedOrder['payment_status']
    ];
    
    // Add warning if tip was capped
    if ($wasCapped) {
        $response['warning'] = 'Tip was capped at maximum allowed percentage: ' . $maxTipPercent . '%';
    }
    
    // Add notice if order is already paid
    if ($isPaid && $tipDifference > 0) {
        $response['notice'] = 'Order is already paid. Additional payment of ' . 
                              $currencySymbol . round($tipDifference, 2) . ' may be required.';
    }
    
    sendSuccess($response);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Add tip failed', [
        'order_id' => $orderId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to add tip', 500, 'TIP_FAILED');
}
?>
