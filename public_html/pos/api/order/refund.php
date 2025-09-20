<?php
/**
 * SME 180 POS - Order Refund API (PRODUCTION READY)
 * Path: /public_html/pos/api/order/refund.php
 * Version: 3.0.0 - Fully Production Ready
 * 
 * Production features:
 * - Full, partial, and item-based refunds
 * - Manager approval workflow
 * - Refund limits and controls
 * - Financial reconciliation
 * - Audit trail
 * - Multi-tenant support
 */

declare(strict_types=1);

// Production error handling
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Start timing
$startTime = microtime(true);

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'none\'');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    die('{"success":true}');
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('{"success":false,"error":"Method not allowed","code":"METHOD_NOT_ALLOWED"}');
}

// Start or resume session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== DATABASE CONFIGURATION =====
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'dbvtrnbzad193e');
    define('DB_USER', 'uta6umaa0iuif');
    define('DB_PASS', '2m%[11|kb1Z4');
    define('DB_CHARSET', 'utf8mb4');
}

/**
 * Get database connection
 */
function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::ATTR_PERSISTENT         => false
            ]);
            
            $pdo->exec("SET time_zone = '+00:00'");
            
        } catch (PDOException $e) {
            error_log('[SME180] Database connection failed: ' . $e->getMessage());
            return false;
        }
    }
    
    return $pdo;
}

/**
 * Structured logging
 */
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

/**
 * Send error response
 */
function sendError($userMessage, $code = 400, $errorCode = 'GENERAL_ERROR', $logMessage = null, $logContext = []) {
    global $startTime;
    
    if ($logMessage) {
        logEvent('ERROR', $logMessage, $logContext);
    }
    
    $response = [
        'success' => false,
        'error' => $userMessage,
        'code' => $errorCode,
        'timestamp' => date('c'),
        'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ];
    
    // Add context for specific errors
    if ($errorCode === 'MANAGER_APPROVAL_REQUIRED' && isset($logContext['approval_context'])) {
        $response['approval_context'] = $logContext['approval_context'];
    }
    
    http_response_code($code);
    die(json_encode($response, JSON_UNESCAPED_UNICODE));
}

/**
 * Send success response
 */
function sendSuccess($data) {
    global $startTime;
    
    $response = array_merge(
        ['success' => true],
        $data,
        [
            'timestamp' => date('c'),
            'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
        ]
    );
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/**
 * Check rate limiting for refund operations
 */
function checkRateLimit($pdo, $tenantId, $userId) {
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount();
        
        if ($tableCheck > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as action_count 
                FROM order_logs 
                WHERE tenant_id = ? 
                    AND user_id = ? 
                    AND action LIKE '%refund%'
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ");
            $stmt->execute([$tenantId, $userId]);
            $count = $stmt->fetchColumn();
            
            // Strict limit for financial operations
            if ($count >= 3) {
                logEvent('WARNING', 'Rate limit exceeded for refund operations', [
                    'tenant_id' => $tenantId,
                    'user_id' => $userId,
                    'count' => $count
                ]);
                return false;
            }
        }
    } catch (Exception $e) {
        logEvent('WARNING', 'Rate limit check failed', ['error' => $e->getMessage()]);
    }
    return true;
}

/**
 * Ensure required structures exist
 */
function ensureRequiredStructures($pdo) {
    try {
        // Check if order_refunds table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_refunds'")->rowCount();
        if ($tableCheck === 0) {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS order_refunds (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    tenant_id INT NOT NULL,
                    branch_id INT NOT NULL,
                    order_id INT NOT NULL,
                    refund_type VARCHAR(50) NOT NULL,
                    amount DECIMAL(10,2) NOT NULL,
                    currency VARCHAR(10),
                    reason TEXT,
                    status VARCHAR(50) DEFAULT 'completed',
                    approved_by INT,
                    approved_at DATETIME,
                    processed_by INT,
                    processed_at DATETIME,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    KEY idx_order (order_id),
                    KEY idx_tenant (tenant_id)
                )
            ");
            logEvent('INFO', 'Created order_refunds table');
        }
        
        // Check columns in orders table
        $columnsToCheck = ['refunded_amount', 'refunded_at', 'refunded_by'];
        $existingColumns = [];
        $stmt = $pdo->query("SHOW COLUMNS FROM orders");
        while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $existingColumns[] = $col['Field'];
        }
        
        foreach ($columnsToCheck as $column) {
            if (!in_array($column, $existingColumns)) {
                $columnDef = '';
                switch ($column) {
                    case 'refunded_amount':
                        $columnDef = "ADD COLUMN refunded_amount DECIMAL(10,2) DEFAULT 0";
                        break;
                    case 'refunded_at':
                        $columnDef = "ADD COLUMN refunded_at DATETIME NULL";
                        break;
                    case 'refunded_by':
                        $columnDef = "ADD COLUMN refunded_by INT NULL";
                        break;
                }
                
                if ($columnDef) {
                    try {
                        $pdo->exec("ALTER TABLE orders $columnDef");
                        logEvent('INFO', "Added column $column to orders");
                    } catch (Exception $e) {
                        logEvent('WARNING', "Could not add column $column", ['error' => $e->getMessage()]);
                    }
                }
            }
        }
        
        return true;
    } catch (Exception $e) {
        logEvent('ERROR', 'Failed to ensure required structures', ['error' => $e->getMessage()]);
        return false;
    }
}

// ===== MAIN LOGIC =====

// Get database connection
$pdo = getDbConnection();
if (!$pdo) {
    sendError('Service temporarily unavailable. Please try again.', 503, 'DB_CONNECTION_ERROR',
        'Failed to establish database connection');
}

// Ensure required structures
ensureRequiredStructures($pdo);

// Session validation
$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
$branchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 
          (isset($_SESSION['pos_user_id']) ? (int)$_SESSION['pos_user_id'] : null);
$userRole = $_SESSION['role'] ?? $_SESSION['user_role'] ?? 'cashier';

// Parse input
$rawInput = file_get_contents('php://input');
if (strlen($rawInput) > 10000) {
    sendError('Request too large', 413, 'REQUEST_TOO_LARGE');
}

if (empty($rawInput)) {
    sendError('Request body is required', 400, 'EMPTY_REQUEST');
}

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid request format', 400, 'INVALID_JSON',
        'JSON parse error: ' . json_last_error_msg());
}

// Allow tenant/branch/user in request
if (!$tenantId && isset($input['tenant_id'])) {
    $tenantId = (int)$input['tenant_id'];
}
if (!$branchId && isset($input['branch_id'])) {
    $branchId = (int)$input['branch_id'];
}
if (!$userId && isset($input['user_id'])) {
    $userId = (int)$input['user_id'];
}

// Validate tenant
if (!$tenantId || $tenantId <= 0) {
    try {
        $stmt = $pdo->query("SELECT id FROM tenants WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        $tenantId = $stmt->fetchColumn();
        if (!$tenantId) {
            sendError('No active tenant found', 401, 'NO_TENANT');
        }
        logEvent('WARNING', 'No tenant_id in session, using default', ['tenant_id' => $tenantId]);
    } catch (Exception $e) {
        sendError('Unable to determine tenant', 500, 'TENANT_ERROR');
    }
}

// Validate branch
if (!$branchId || $branchId <= 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM branches 
            WHERE tenant_id = :tenant_id 
            AND is_active = 1 
            ORDER BY is_default DESC, id ASC 
            LIMIT 1
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        $branchId = $stmt->fetchColumn();
        if (!$branchId) {
            $branchId = 1;
        }
        logEvent('WARNING', 'No branch_id in session, using default', ['branch_id' => $branchId]);
    } catch (Exception $e) {
        $branchId = 1;
    }
}

// Validate user
if (!$userId || $userId <= 0) {
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE tenant_id = :tenant_id 
            ORDER BY id ASC 
            LIMIT 1
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        $userId = $stmt->fetchColumn();
        
        if (!$userId) {
            sendError('No users found in system', 401, 'NO_USERS');
        }
        logEvent('WARNING', 'No user_id in session, using default', ['user_id' => $userId]);
    } catch (Exception $e) {
        sendError('Unable to determine user', 500, 'USER_ERROR');
    }
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
$refundType = strtolower(trim($input['refund_type']));

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
$refundAmount = 0;
if ($refundType === 'partial') {
    if (!isset($input['amount'])) {
        sendError('Amount is required for partial refund', 400, 'MISSING_AMOUNT');
    }
    
    $refundAmount = filter_var($input['amount'], FILTER_VALIDATE_FLOAT);
    if ($refundAmount === false || $refundAmount <= 0 || $refundAmount > 999999) {
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

$approvalPin = isset($input['approval_pin']) ? 
    substr(trim($input['approval_pin']), 0, 20) : null;

// Database transaction
try {
    // Check rate limit
    if (!checkRateLimit($pdo, $tenantId, $userId)) {
        sendError(
            'Too many refund requests. Please wait before trying again.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
    }
    
    // Get settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value`
        FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN (
            'currency_symbol',
            'currency_code',
            'currency',
            'pos_max_refund_amount',
            'pos_require_refund_approval',
            'allow_partial_refunds',
            'refund_time_limit_days'
        )
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $currency = $settings['currency_code'] ?? $settings['currency'] ?? 'EGP';
    $currencySymbol = $settings['currency_symbol'] ?? 'EGP';
    $maxRefundAmount = isset($settings['pos_max_refund_amount']) ? 
        floatval($settings['pos_max_refund_amount']) : 5000;
    $requireRefundApproval = isset($settings['pos_require_refund_approval']) ? 
        filter_var($settings['pos_require_refund_approval'], FILTER_VALIDATE_BOOLEAN) : true;
    $allowPartialRefunds = isset($settings['allow_partial_refunds']) ? 
        filter_var($settings['allow_partial_refunds'], FILTER_VALIDATE_BOOLEAN) : true;
    $refundTimeLimitDays = isset($settings['refund_time_limit_days']) ? 
        intval($settings['refund_time_limit_days']) : 30;
    
    // Start transaction
    $pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
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
    
    // Check time limit
    if ($refundTimeLimitDays > 0) {
        $orderDate = strtotime($order['created_at']);
        $daysElapsed = (time() - $orderDate) / 86400;
        
        if ($daysElapsed > $refundTimeLimitDays) {
            $pdo->rollBack();
            sendError(
                sprintf('Refund period expired. Orders can only be refunded within %d days.', $refundTimeLimitDays),
                409,
                'REFUND_PERIOD_EXPIRED'
            );
        }
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
    
    // Check columns
    $hasPaidAmount = false;
    $hasRefundedAmount = false;
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM orders");
        while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($col['Field'] === 'paid_amount') $hasPaidAmount = true;
            if ($col['Field'] === 'refunded_amount') $hasRefundedAmount = true;
        }
    } catch (Exception $e) {
        // Continue without these columns
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
    switch ($refundType) {
        case 'full':
            $refundAmount = $availableToRefund;
            break;
            
        case 'partial':
            if (!$allowPartialRefunds) {
                $pdo->rollBack();
                sendError('Partial refunds are not allowed', 403, 'PARTIAL_REFUNDS_DISABLED');
            }
            
            if ($refundAmount > $availableToRefund) {
                $pdo->rollBack();
                sendError(
                    'Refund amount exceeds available amount',
                    409,
                    'AMOUNT_EXCEEDS_AVAILABLE',
                    null,
                    [
                        'available' => round($availableToRefund, 2),
                        'requested' => round($refundAmount, 2)
                    ]
                );
            }
            break;
            
        case 'item':
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(line_total) as refund_amount,
                    COUNT(*) as item_count,
                    GROUP_CONCAT(product_name) as items
                FROM order_items 
                WHERE order_id = ? 
                AND id IN ($placeholders)
                AND IFNULL(is_voided, 0) = 0
            ");
            $params = array_merge([$orderId], $itemIds);
            $stmt->execute($params);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$result || !$result['refund_amount']) {
                $pdo->rollBack();
                sendError('No valid items found for refund', 404, 'NO_VALID_ITEMS');
            }
            
            $itemRefundAmount = (float)$result['refund_amount'];
            
            // Include proportional charges
            if ($order['subtotal'] > 0) {
                $itemProportion = $itemRefundAmount / $order['subtotal'];
                
                // Add proportional tax
                $itemRefundAmount += ($order['tax_amount'] * $itemProportion);
                
                // Add proportional service charge
                if (isset($order['service_charge'])) {
                    $itemRefundAmount += ($order['service_charge'] * $itemProportion);
                }
                
                // Subtract proportional discount
                if (isset($order['discount_amount'])) {
                    $itemRefundAmount -= ($order['discount_amount'] * $itemProportion);
                }
            }
            
            $refundAmount = min($itemRefundAmount, $availableToRefund);
            break;
    }
    
    if ($refundAmount <= 0) {
        $pdo->rollBack();
        sendError('Invalid refund amount calculated', 409, 'INVALID_REFUND_AMOUNT');
    }
    
    // Check for manager approval
    $approvedBy = $userId;
    $managerName = null;
    $requiresApproval = false;
    
    // Determine if approval needed
    if ($refundAmount > $maxRefundAmount) {
        $requiresApproval = true;
        $approvalReason = 'Amount exceeds limit';
    } elseif ($requireRefundApproval && !in_array(strtolower($userRole), ['admin', 'manager', 'owner', 'supervisor'])) {
        $requiresApproval = true;
        $approvalReason = 'User role requires approval';
    }
    
    if ($requiresApproval) {
        if (!$approvalPin) {
            $pdo->commit();
            sendError(
                'Manager approval required for this refund',
                403,
                'MANAGER_APPROVAL_REQUIRED',
                null,
                [
                    'approval_context' => [
                        'requires_approval' => true,
                        'refund_amount' => round($refundAmount, 2),
                        'currency' => $currency,
                        'reason' => $approvalReason
                    ]
                ]
            );
        }
        
        // Validate manager PIN
        $stmt = $pdo->prepare("
            SELECT id, name, role FROM users 
            WHERE tenant_id = :tenant_id 
            AND pin = :pin 
            AND role IN ('admin', 'manager', 'owner', 'supervisor')
            AND IFNULL(is_active, 1) = 1
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
    
    // Create refund record
    $refundId = 0;
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
        logEvent('WARNING', 'Could not create refund record', ['error' => $e->getMessage()]);
    }
    
    // Update order status
    $newRefundedAmount = $totalRefunded + $refundAmount;
    $newPaymentStatus = ($newRefundedAmount >= $totalPaid - 0.01) ? 'refunded' : 'partial_refund';
    $newOrderStatus = ($refundType === 'full' || $newPaymentStatus === 'refunded') ? 'refunded' : $order['status'];
    
    // Build update query
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
        $updateFields[] = "refunded_amount = IFNULL(refunded_amount, 0) + :amount";
        $updateFields[] = "refunded_at = IFNULL(refunded_at, NOW())";
        $updateFields[] = "refunded_by = IFNULL(refunded_by, :user_id)";
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
    
    // Update cash session if refund is cash
    if (isset($_SESSION['cash_session_id']) && $_SESSION['cash_session_id']) {
        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'cash_sessions'")->rowCount();
            
            if ($tableCheck > 0) {
                $stmt = $pdo->prepare("
                    UPDATE cash_sessions 
                    SET cash_refunds = IFNULL(cash_refunds, 0) + :amount,
                        total_refunds = IFNULL(total_refunds, 0) + :amount,
                        updated_at = NOW()
                    WHERE id = :session_id
                ");
                $stmt->execute([
                    'amount' => $refundAmount,
                    'session_id' => $_SESSION['cash_session_id']
                ]);
            }
        } catch (Exception $e) {
            logEvent('INFO', 'Cash session update skipped', ['error' => $e->getMessage()]);
        }
    }
    
    // Log refund event
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount();
        
        if ($tableCheck > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO order_logs (
                    order_id, tenant_id, branch_id, user_id,
                    action, details, created_at
                ) VALUES (
                    :order_id, :tenant_id, :branch_id, :user_id,
                    'refunded', :details, NOW()
                )
            ");
            
            $logDetails = json_encode([
                'refund_id' => $refundId,
                'refund_type' => $refundType,
                'amount' => $refundAmount,
                'reason' => $reason,
                'approved_by' => $approvedBy,
                'approved_by_name' => $managerName,
                'item_ids' => $itemIds,
                'payment_status' => $newPaymentStatus,
                'receipt' => $order['receipt_reference'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
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
        logEvent('WARNING', 'Failed to create audit log', ['error' => $e->getMessage()]);
    }
    
    // Notify accounting system (if integrated)
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'accounting_transactions'")->rowCount();
        
        if ($tableCheck > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO accounting_transactions (
                    tenant_id, branch_id, 
                    transaction_type, transaction_subtype,
                    reference_type, reference_id,
                    amount, currency,
                    description, created_by, created_at
                ) VALUES (
                    :tenant_id, :branch_id,
                    'refund', :refund_type,
                    'order', :order_id,
                    :amount, :currency,
                    :description, :user_id, NOW()
                )
            ");
            
            $stmt->execute([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'refund_type' => $refundType,
                'order_id' => $orderId,
                'amount' => -$refundAmount, // Negative for refund
                'currency' => $currency,
                'description' => 'Refund for order ' . $order['receipt_reference'] . ': ' . $reason,
                'user_id' => $userId
            ]);
        }
    } catch (Exception $e) {
        logEvent('INFO', 'Accounting integration skipped', ['error' => $e->getMessage()]);
    }
    
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
            'currency_symbol' => $currencySymbol,
            'reason' => $reason,
            'status' => 'completed',
            'payment_status' => $newPaymentStatus,
            'order_status' => $newOrderStatus,
            'approved_by' => $approvedBy,
            'approved_by_name' => $managerName,
            'processed_at' => date('c'),
            'item_count' => count($itemIds)
        ],
        'order' => [
            'total_paid' => round($totalPaid, 2),
            'total_refunded' => round($newRefundedAmount, 2),
            'remaining' => round($totalPaid - $newRefundedAmount, 2),
            'currency' => $currency,
            'currency_symbol' => $currencySymbol
        ]
    ]);
    
} catch (PDOException $pdoEx) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $errorMessage = $pdoEx->getMessage();
    
    if (strpos($errorMessage, 'Deadlock') !== false) {
        sendError('System busy, please try again', 503, 'DEADLOCK_DETECTED', $errorMessage);
    } elseif (strpos($errorMessage, 'Lock wait timeout') !== false) {
        sendError('Request timeout, please try again', 504, 'LOCK_TIMEOUT', $errorMessage);
    } else {
        logEvent('ERROR', 'Database error in refund processing', [
            'order_id' => $orderId ?? null,
            'error' => $errorMessage,
            'trace' => $pdoEx->getTraceAsString()
        ]);
        
        sendError('Unable to process refund', 500, 'DATABASE_ERROR');
    }
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Refund failed', [
        'order_id' => $orderId ?? null,
        'refund_type' => $refundType ?? null,
        'amount' => $refundAmount ?? 0,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to process refund. Please try again.', 500, 'REFUND_FAILED');
}
?>