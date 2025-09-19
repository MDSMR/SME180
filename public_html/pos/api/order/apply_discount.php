<?php
/**
 * SME 180 POS - Apply Discount API
 * Path: /public_html/pos/api/order/apply_discount.php
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

$orderId = filter_var($input['order_id'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
]);

if ($orderId === false) {
    sendError('Invalid order ID format', 400, 'INVALID_ORDER_ID');
}

// Validate discount type
$validDiscountTypes = ['percent', 'amount'];
$discountType = isset($input['discount_type']) ? $input['discount_type'] : 'percent';

if (!in_array($discountType, $validDiscountTypes)) {
    sendError(
        'Invalid discount type. Must be: ' . implode(', ', $validDiscountTypes),
        400,
        'INVALID_DISCOUNT_TYPE'
    );
}

// Validate discount value
if (!isset($input['discount_value'])) {
    sendError('Discount value is required', 400, 'MISSING_DISCOUNT_VALUE');
}

$discountValue = filter_var($input['discount_value'], FILTER_VALIDATE_FLOAT);

if ($discountValue === false || $discountValue < 0) {
    sendError('Invalid discount value', 400, 'INVALID_DISCOUNT_VALUE');
}

if ($discountType === 'percent' && $discountValue > 100) {
    sendError('Discount percentage cannot exceed 100%', 400, 'DISCOUNT_EXCEEDS_MAXIMUM');
}

// Optional fields
$reason = isset($input['reason']) ? 
    substr(trim(strip_tags($input['reason'])), 0, 500) : '';
$managerPin = isset($input['manager_pin']) ? 
    substr(trim($input['manager_pin']), 0, 20) : '';

try {
    // Get settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value`
        FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('tax_rate', 'pos_max_discount_percent', 'currency_symbol', 'currency_code', 'currency')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $taxRate = isset($settings['tax_rate']) ? floatval($settings['tax_rate']) : 14.0;
    $maxDiscountPercent = isset($settings['pos_max_discount_percent']) ? 
        floatval($settings['pos_max_discount_percent']) : 50.0;
    $currency = $settings['currency_code'] ?? $settings['currency'] ?? 'EGP';
    
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
    
    // Check if order can be modified
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        $pdo->rollBack();
        sendError(
            'Cannot modify ' . $order['status'] . ' orders',
            409,
            'INVALID_ORDER_STATUS'
        );
    }
    
    if ($order['payment_status'] === 'paid') {
        $pdo->rollBack();
        sendError('Cannot apply discount to paid orders', 409, 'ORDER_ALREADY_PAID');
    }
    
    // Calculate discount amount
    $subtotal = (float)$order['subtotal'];
    
    if ($subtotal <= 0) {
        $pdo->rollBack();
        sendError('Cannot apply discount to zero value order', 409, 'ZERO_VALUE_ORDER');
    }
    
    $discountAmount = 0;
    $actualDiscountPercent = 0;
    $requiresApproval = false;
    
    if ($discountType === 'percent') {
        $actualDiscountPercent = $discountValue;
        $discountAmount = $subtotal * ($discountValue / 100);
        
        // Check if discount exceeds maximum allowed
        if ($discountValue > $maxDiscountPercent) {
            $requiresApproval = true;
        }
    } else {
        // Fixed amount discount
        $discountAmount = min($discountValue, $subtotal);
        $actualDiscountPercent = ($discountAmount / $subtotal) * 100;
        
        // Check percentage equivalent
        if ($actualDiscountPercent > $maxDiscountPercent) {
            $requiresApproval = true;
        }
    }
    
    // Handle manager approval if required
    $approvedBy = $userId;
    $managerName = null;
    
    if ($requiresApproval) {
        logEvent('INFO', 'Discount requires approval', [
            'order_id' => $orderId,
            'discount_percent' => $actualDiscountPercent,
            'max_allowed' => $maxDiscountPercent
        ]);
        
        // Check if user has permission
        if (!in_array($userRole, ['admin', 'manager', 'owner'])) {
            if (!$managerPin) {
                $pdo->commit();
                sendError(
                    'Manager approval required for discount over ' . $maxDiscountPercent . '%',
                    403,
                    'MANAGER_APPROVAL_REQUIRED',
                    [
                        'requires_approval' => true,
                        'max_allowed' => $maxDiscountPercent,
                        'requested' => round($actualDiscountPercent, 2)
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
                'pin' => hash('sha256', $managerPin)
            ]);
            
            $manager = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$manager) {
                $pdo->rollBack();
                logEvent('WARNING', 'Invalid manager PIN for discount approval', [
                    'order_id' => $orderId,
                    'user_id' => $userId
                ]);
                sendError('Invalid manager PIN', 403, 'INVALID_MANAGER_PIN');
            }
            
            $approvedBy = $manager['id'];
            $managerName = $manager['name'];
            
            logEvent('INFO', 'Manager approval granted for discount', [
                'order_id' => $orderId,
                'manager_id' => $approvedBy,
                'discount_percent' => $actualDiscountPercent
            ]);
        }
    }
    
    // Store old values for logging
    $oldDiscountAmount = (float)$order['discount_amount'];
    $oldDiscountType = $order['discount_type'];
    $oldDiscountValue = (float)$order['discount_value'];
    
    // Recalculate totals
    $serviceCharge = (float)$order['service_charge'];
    $tipAmount = (float)$order['tip_amount'];
    
    $newSubtotal = $subtotal - $discountAmount;
    $taxableAmount = $newSubtotal + $serviceCharge;
    $newTaxAmount = $taxableAmount * ($taxRate / 100);
    $newTotal = $taxableAmount + $newTaxAmount + $tipAmount;
    
    // Check if discount columns exist
    $hasDiscountColumns = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'discount_type'");
        $hasDiscountColumns = ($checkCol->rowCount() > 0);
    } catch (Exception $e) {
        // Column might not exist
    }
    
    // Build update query
    $updateFields = [
        "discount_amount = :discount_amount",
        "tax_amount = :tax_amount",
        "total_amount = :total_amount",
        "updated_at = NOW()"
    ];
    
    $updateParams = [
        'discount_amount' => $discountAmount,
        'tax_amount' => $newTaxAmount,
        'total_amount' => $newTotal,
        'order_id' => $orderId
    ];
    
    if ($hasDiscountColumns) {
        $updateFields[] = "discount_type = :discount_type";
        $updateFields[] = "discount_value = :discount_value";
        $updateParams['discount_type'] = $discountType;
        $updateParams['discount_value'] = $discountValue;
    }
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET " . implode(", ", $updateFields) . "
        WHERE id = :order_id
    ");
    
    $stmt->execute($updateParams);
    
    // Log the discount
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'discount_applied', :details, NOW()
        )
    ");
    
    $logDetails = [
        'type' => $discountType,
        'value' => $discountValue,
        'amount' => $discountAmount,
        'percent' => $actualDiscountPercent,
        'reason' => $reason,
        'approved_by' => $approvedBy,
        'approved_by_name' => $managerName,
        'old_discount' => [
            'type' => $oldDiscountType,
            'value' => $oldDiscountValue,
            'amount' => $oldDiscountAmount
        ],
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
    
    // Log successful discount application
    logEvent('INFO', 'Discount applied successfully', [
        'order_id' => $orderId,
        'receipt' => $order['receipt_reference'],
        'discount_amount' => $discountAmount,
        'discount_percent' => $actualDiscountPercent,
        'approved_by' => $approvedBy
    ]);
    
    sendSuccess([
        'message' => 'Discount applied successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'subtotal' => round($subtotal, 2),
            'discount' => [
                'type' => $discountType,
                'value' => round($discountValue, 2),
                'amount' => round($discountAmount, 2),
                'percent' => round($actualDiscountPercent, 2)
            ],
            'previous_discount' => [
                'amount' => round($oldDiscountAmount, 2)
            ],
            'new_subtotal' => round($newSubtotal, 2),
            'tax_amount' => round($newTaxAmount, 2),
            'total_amount' => round($newTotal, 2),
            'currency' => $currency
        ],
        'approval' => $requiresApproval ? [
            'required' => true,
            'approved_by' => $approvedBy,
            'approved_by_name' => $managerName
        ] : null
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Apply discount failed', [
        'order_id' => $orderId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to apply discount', 500, 'DISCOUNT_FAILED');
}
?>
