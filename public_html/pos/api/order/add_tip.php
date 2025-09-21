<?php
/**
 * SME 180 POS - Add Tip API (PRODUCTION READY)
 * Path: /public_html/pos/api/order/add_tip.php
 * Version: 4.0.0 - Production Ready
 * 
 * Features:
 * - Accepts both GET and POST methods for compatibility
 * - Full multi-tenant support
 * - Database-driven configuration
 * - Comprehensive error handling
 * - Transaction safety
 * - Audit logging
 */

declare(strict_types=1);

// Production error handling
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Start timing
$startTime = microtime(true);

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    die('{"success":true}');
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'dbvtrnbzad193e');
define('DB_USER', 'uta6umaa0iuif');
define('DB_PASS', '2m%[11|kb1Z4');
define('API_KEY', 'sme180_pos_api_key_2024');

// Get input from either POST body or GET parameters
$input = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $inputRaw = file_get_contents('php://input');
    if (strlen($inputRaw) > 10000) {
        http_response_code(413);
        die('{"success":false,"error":"Request too large","code":"REQUEST_TOO_LARGE"}');
    }
    $input = json_decode($inputRaw, true);
    if (json_last_error() !== JSON_ERROR_NONE && !empty($inputRaw)) {
        http_response_code(400);
        die('{"success":false,"error":"Invalid JSON input","code":"INVALID_JSON"}');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $input = $_GET;
} else {
    http_response_code(405);
    die('{"success":false,"error":"Method not allowed","code":"METHOD_NOT_ALLOWED"}');
}

// If no input from either source, try REQUEST as fallback
if (empty($input)) {
    $input = $_REQUEST;
}

// Connect to database
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    error_log("[SME180] Database connection failed: " . $e->getMessage());
    http_response_code(503);
    die('{"success":false,"error":"Service temporarily unavailable","code":"DB_CONNECTION_FAILED"}');
}

// Get context with validation
$orderId = isset($input['order_id']) ? filter_var($input['order_id'], FILTER_VALIDATE_INT) : 0;
$tipValue = isset($input['tip_value']) ? filter_var($input['tip_value'], FILTER_VALIDATE_FLOAT) : 0;
$tipType = isset($input['tip_type']) ? trim($input['tip_type']) : 'amount';
$tenantId = isset($input['tenant_id']) ? filter_var($input['tenant_id'], FILTER_VALIDATE_INT) : 1;
$branchId = isset($input['branch_id']) ? filter_var($input['branch_id'], FILTER_VALIDATE_INT) : 1;
$userId = isset($input['user_id']) ? filter_var($input['user_id'], FILTER_VALIDATE_INT) : 1;

// Check for API key authentication
$apiKey = $input['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
$authenticated = ($apiKey === API_KEY);

// If not authenticated via API key, check session
if (!$authenticated && session_status() === PHP_SESSION_NONE) {
    session_start();
    if (isset($_SESSION['user_id']) && $_SESSION['user_id'] > 0) {
        $authenticated = true;
        $tenantId = (int)($_SESSION['tenant_id'] ?? $tenantId);
        $branchId = (int)($_SESSION['branch_id'] ?? $branchId);
        $userId = (int)($_SESSION['user_id'] ?? $userId);
    }
}

// Validate required fields
if (!$orderId || $orderId <= 0) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error' => 'Order ID is required',
        'code' => 'MISSING_ORDER_ID',
        'timestamp' => date('c')
    ]));
}

if (!in_array($tipType, ['amount', 'percent'])) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error' => 'Invalid tip type. Must be: amount or percent',
        'code' => 'INVALID_TIP_TYPE',
        'timestamp' => date('c')
    ]));
}

if ($tipValue === false || $tipValue < 0) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error' => 'Invalid tip value',
        'code' => 'INVALID_TIP_VALUE',
        'timestamp' => date('c')
    ]));
}

// Validate maximum tip
if ($tipType === 'percent' && $tipValue > 100) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error' => 'Tip percentage cannot exceed 100%',
        'code' => 'TIP_EXCEEDS_MAXIMUM',
        'timestamp' => date('c')
    ]));
}

if ($tipType === 'amount' && $tipValue > 99999) {
    http_response_code(400);
    die(json_encode([
        'success' => false,
        'error' => 'Tip amount exceeds maximum allowed',
        'code' => 'TIP_AMOUNT_TOO_HIGH',
        'timestamp' => date('c')
    ]));
}

// Main transaction
try {
    // Get tenant settings
    $settings = [];
    $stmt = $pdo->prepare("
        SELECT `key`, `value`
        FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('max_tip_percent', 'currency_symbol', 'currency_code', 'tip_enabled')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    
    while ($row = $stmt->fetch()) {
        $settings[$row['key']] = $row['value'];
    }
    
    // Check if tips are enabled
    $tipsEnabled = !isset($settings['tip_enabled']) || $settings['tip_enabled'] !== '0';
    if (!$tipsEnabled) {
        http_response_code(403);
        die(json_encode([
            'success' => false,
            'error' => 'Tips are not enabled for this tenant',
            'code' => 'TIPS_DISABLED',
            'timestamp' => date('c')
        ]));
    }
    
    // Get configuration values
    $maxTipPercent = isset($settings['max_tip_percent']) ? (float)$settings['max_tip_percent'] : 50.0;
    $currency = $settings['currency_code'] ?? 'USD';
    $currencySymbol = $settings['currency_symbol'] ?? '$';
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Get and lock order
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
    $order = $stmt->fetch();
    
    if (!$order) {
        $pdo->rollBack();
        http_response_code(404);
        die(json_encode([
            'success' => false,
            'error' => 'Order not found',
            'code' => 'ORDER_NOT_FOUND',
            'timestamp' => date('c')
        ]));
    }
    
    // Check order status
    if (in_array($order['status'], ['voided', 'refunded', 'cancelled'])) {
        $pdo->rollBack();
        http_response_code(409);
        die(json_encode([
            'success' => false,
            'error' => 'Cannot add tip to ' . $order['status'] . ' orders',
            'code' => 'INVALID_ORDER_STATUS',
            'timestamp' => date('c')
        ]));
    }
    
    // Calculate base amount for tip
    $subtotal = (float)$order['subtotal'];
    $discountAmount = (float)($order['discount_amount'] ?? 0);
    $baseAmount = $subtotal - $discountAmount;
    
    if ($baseAmount <= 0) {
        $pdo->rollBack();
        http_response_code(409);
        die(json_encode([
            'success' => false,
            'error' => 'Cannot add tip to zero value order',
            'code' => 'ZERO_VALUE_ORDER',
            'timestamp' => date('c')
        ]));
    }
    
    // Calculate tip amount
    $tipAmount = 0;
    $tipPercent = 0;
    $wasCapped = false;
    
    if ($tipType === 'percent') {
        if ($tipValue > $maxTipPercent) {
            $tipPercent = $maxTipPercent;
            $wasCapped = true;
        } else {
            $tipPercent = $tipValue;
        }
        $tipAmount = $baseAmount * ($tipPercent / 100);
    } else {
        $tipAmount = $tipValue;
        $tipPercent = ($baseAmount > 0) ? ($tipAmount / $baseAmount) * 100 : 0;
        
        if ($tipPercent > $maxTipPercent) {
            $tipPercent = $maxTipPercent;
            $tipAmount = $baseAmount * ($maxTipPercent / 100);
            $wasCapped = true;
        }
    }
    
    $tipAmount = round($tipAmount, 2);
    $oldTipAmount = (float)($order['tip_amount'] ?? 0);
    $tipDifference = $tipAmount - $oldTipAmount;
    
    // Update order with new tip
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET tip_amount = :tip_amount,
            total_amount = subtotal - IFNULL(discount_amount, 0) + IFNULL(tax_amount, 0) + IFNULL(service_charge, 0) + :tip_amount2,
            updated_at = NOW()
        WHERE id = :order_id
        AND tenant_id = :tenant_id
        AND branch_id = :branch_id
    ");
    
    $result = $stmt->execute([
        'tip_amount' => $tipAmount,
        'tip_amount2' => $tipAmount,
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    
    if (!$result || $stmt->rowCount() === 0) {
        $pdo->rollBack();
        http_response_code(500);
        die(json_encode([
            'success' => false,
            'error' => 'Failed to update order',
            'code' => 'UPDATE_FAILED',
            'timestamp' => date('c')
        ]));
    }
    
    // Log the tip update (optional, won't fail transaction)
    try {
        if ($pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount() > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO order_logs (
                    order_id, tenant_id, branch_id, user_id,
                    action, details, created_at
                ) VALUES (
                    :order_id, :tenant_id, :branch_id, :user_id,
                    'tip_updated', :details, NOW()
                )
            ");
            
            $logDetails = json_encode([
                'old_tip' => $oldTipAmount,
                'new_tip' => $tipAmount,
                'tip_percent' => round($tipPercent, 2),
                'type' => $tipType,
                'value' => $tipValue,
                'difference' => $tipDifference,
                'was_capped' => $wasCapped
            ]);
            
            $stmt->execute([
                'order_id' => $orderId,
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'details' => $logDetails
            ]);
        }
    } catch (Exception $e) {
        // Log but don't fail the transaction
        error_log("[SME180] Failed to create audit log: " . $e->getMessage());
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Get updated order totals
    $stmt = $pdo->prepare("
        SELECT tip_amount, total_amount, subtotal, discount_amount, 
               service_charge, tax_amount, payment_status, receipt_reference
        FROM orders 
        WHERE id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $updatedOrder = $stmt->fetch();
    
    // Prepare success response
    $response = [
        'success' => true,
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
            'discount' => round((float)($updatedOrder['discount_amount'] ?? 0), 2),
            'service_charge' => round((float)($updatedOrder['service_charge'] ?? 0), 2),
            'tax' => round((float)($updatedOrder['tax_amount'] ?? 0), 2),
            'tip' => round((float)$updatedOrder['tip_amount'], 2),
            'total' => round((float)$updatedOrder['total_amount'], 2),
            'currency' => $currency,
            'currency_symbol' => $currencySymbol
        ],
        'payment_status' => $updatedOrder['payment_status'],
        'timestamp' => date('c'),
        'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ];
    
    // Add warning if tip was capped
    if ($wasCapped) {
        $response['warning'] = sprintf(
            'Tip was capped at maximum allowed percentage: %.2f%%',
            $maxTipPercent
        );
    }
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("[SME180] Database error in tip operation: " . $e->getMessage());
    
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'error' => 'Database error occurred',
        'code' => 'DATABASE_ERROR',
        'timestamp' => date('c'),
        'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ]));
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("[SME180] General error in tip operation: " . $e->getMessage());
    
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'error' => 'An error occurred',
        'code' => 'ERROR',
        'timestamp' => date('c'),
        'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ]));
}
?>
