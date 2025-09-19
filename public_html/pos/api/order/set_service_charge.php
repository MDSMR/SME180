<?php
/**
 * SME 180 POS - Set Service Charge API
 * Path: /public_html/pos/api/order/set_service_charge.php
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

// Validate action
$validActions = ['set', 'remove', 'adjust'];
$action = isset($input['action']) ? $input['action'] : 'set';

if (!in_array($action, $validActions)) {
    sendError(
        'Invalid action. Must be: ' . implode(', ', $validActions),
        400,
        'INVALID_ACTION'
    );
}

// Validate charge type and value
$validChargeTypes = ['percent', 'amount'];
$chargeType = isset($input['charge_type']) ? $input['charge_type'] : 'percent';

if (!in_array($chargeType, $validChargeTypes)) {
    sendError(
        'Invalid charge type. Must be: ' . implode(', ', $validChargeTypes),
        400,
        'INVALID_CHARGE_TYPE'
    );
}

$chargeValue = 0;
if ($action !== 'remove') {
    if (!isset($input['charge_value'])) {
        sendError('Charge value is required', 400, 'MISSING_CHARGE_VALUE');
    }
    
    $chargeValue = filter_var($input['charge_value'], FILTER_VALIDATE_FLOAT);
    
    if ($chargeValue === false) {
        sendError('Invalid charge value format', 400, 'INVALID_CHARGE_VALUE');
    }
    
    if ($chargeType === 'percent') {
        if ($chargeValue < 0 || $chargeValue > 100) {
            sendError('Service charge percentage must be between 0 and 100', 400, 'INVALID_PERCENTAGE');
        }
    } else {
        if ($chargeValue < 0 || $chargeValue > 99999) {
            sendError('Service charge amount must be between 0 and 99999', 400, 'INVALID_AMOUNT');
        }
    }
}

try {
    // Get settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value`
        FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('tax_rate', 'max_service_charge_percent', 'currency_symbol', 'currency_code', 'currency')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $taxRate = isset($settings['tax_rate']) ? floatval($settings['tax_rate']) : 14.0;
    $maxServiceChargePercent = isset($settings['max_service_charge_percent']) ? 
        floatval($settings['max_service_charge_percent']) : 20.0;
    $currency = $settings['currency_code'] ?? $settings['currency'] ?? 'EGP';
    
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
    
    // Check if order can be modified
    if ($order['payment_status'] === 'paid') {
        $pdo->rollBack();
        sendError('Cannot modify service charge on paid orders', 409, 'ORDER_ALREADY_PAID');
    }
    
    if (in_array($order['status'], ['voided', 'refunded'])) {
        $pdo->rollBack();
        sendError(
            'Cannot modify ' . $order['status'] . ' orders',
            409,
            'INVALID_ORDER_STATUS'
        );
    }
    
    // Handle service charge based on action
    $serviceChargeAmount = 0;
    $serviceChargePercent = 0;
    
    switch ($action) {
        case 'remove':
            $serviceChargeAmount = 0;
            $serviceChargePercent = 0;
            logEvent('INFO', 'Removing service charge', ['order_id' => $orderId]);
            break;
            
        case 'adjust':
        case 'set':
            if ($chargeType === 'percent') {
                $serviceChargePercent = min($maxServiceChargePercent, max(0, $chargeValue));
                
                if ($chargeValue > $maxServiceChargePercent) {
                    logEvent('WARNING', 'Service charge percent capped', [
                        'requested' => $chargeValue,
                        'max_allowed' => $maxServiceChargePercent
                    ]);
                }
                
                $baseAmount = (float)$order['subtotal'] - (float)$order['discount_amount'];
                $serviceChargeAmount = $baseAmount * ($serviceChargePercent / 100);
            } else {
                $serviceChargeAmount = max(0, $chargeValue);
                $baseAmount = (float)$order['subtotal'] - (float)$order['discount_amount'];
                
                if ($baseAmount > 0) {
                    $serviceChargePercent = ($serviceChargeAmount / $baseAmount) * 100;
                    
                    // Check if percentage equivalent exceeds max
                    if ($serviceChargePercent > $maxServiceChargePercent) {
                        // Cap at maximum percentage
                        $serviceChargePercent = $maxServiceChargePercent;
                        $serviceChargeAmount = $baseAmount * ($maxServiceChargePercent / 100);
                        
                        logEvent('WARNING', 'Service charge amount capped', [
                            'requested' => $chargeValue,
                            'capped_at' => $serviceChargeAmount
                        ]);
                    }
                }
            }
            break;
            
        default:
            $pdo->rollBack();
            sendError('Invalid action', 400, 'INVALID_ACTION');
    }
    
    $oldServiceCharge = (float)$order['service_charge'];
    $changeAmount = $serviceChargeAmount - $oldServiceCharge;
    
    // Recalculate totals
    $subtotal = (float)$order['subtotal'];
    $discountAmount = (float)$order['discount_amount'];
    $tipAmount = (float)$order['tip_amount'];
    
    $taxableAmount = $subtotal - $discountAmount + $serviceChargeAmount;
    $taxAmount = $taxableAmount * ($taxRate / 100);
    $newTotal = $taxableAmount + $taxAmount + $tipAmount;
    
    // Check if service_charge column exists
    $hasServiceCharge = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'service_charge'");
        $hasServiceCharge = ($checkCol->rowCount() > 0);
    } catch (Exception $e) {
        $hasServiceCharge = false;
    }
    
    if (!$hasServiceCharge) {
        $pdo->rollBack();
        sendError('Service charge feature not available', 501, 'FEATURE_NOT_AVAILABLE');
    }
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET service_charge = :service_amount,
            tax_amount = :tax_amount,
            total_amount = :total,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'service_amount' => $serviceChargeAmount,
        'tax_amount' => $taxAmount,
        'total' => $newTotal,
        'order_id' => $orderId
    ]);
    
    // Log service charge event
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'service_charge_updated', :details, NOW())
    ");
    
    $logDetails = [
        'action' => $action,
        'charge_type' => $chargeType,
        'charge_value' => $chargeValue,
        'old_amount' => $oldServiceCharge,
        'new_amount' => $serviceChargeAmount,
        'percent' => $serviceChargePercent,
        'change' => $changeAmount,
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
    
    // Log successful update
    logEvent('INFO', 'Service charge updated', [
        'order_id' => $orderId,
        'action' => $action,
        'old_amount' => $oldServiceCharge,
        'new_amount' => $serviceChargeAmount
    ]);
    
    sendSuccess([
        'message' => 'Service charge updated successfully',
        'order_id' => $orderId,
        'service_charge' => [
            'amount' => round($serviceChargeAmount, 2),
            'percent' => round($serviceChargePercent, 2),
            'previous' => round($oldServiceCharge, 2),
            'change' => round($changeAmount, 2)
        ],
        'order_totals' => [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discountAmount, 2),
            'service_charge' => round($serviceChargeAmount, 2),
            'tax' => round($taxAmount, 2),
            'tip' => round($tipAmount, 2),
            'total' => round($newTotal, 2),
            'currency' => $currency
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Set service charge failed', [
        'order_id' => $orderId,
        'action' => $action,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to set service charge', 500, 'SERVICE_CHARGE_FAILED');
}
?>
