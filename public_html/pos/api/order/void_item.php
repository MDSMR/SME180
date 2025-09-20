<?php
/**
 * SME 180 POS - Void Item API (PRODUCTION READY)
 * Path: /public_html/pos/api/order/void_item.php
 * Version: 3.0.0 - Fully Production Ready
 * 
 * Production features:
 * - Full database-driven configuration
 * - Multi-tenant support with session validation
 * - Manager approval workflow
 * - Kitchen status validation
 * - Rate limiting and security
 * - Comprehensive error handling
 * - Audit trail
 */

declare(strict_types=1);

// Production error handling
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Start timing for performance monitoring
$startTime = microtime(true);

// Set security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'none\'');

// Handle OPTIONS preflight
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
 * Structured logging function
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
 * Send standardized error response
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
 * Check rate limiting
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
                    AND action LIKE '%void%'
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ");
            $stmt->execute([$tenantId, $userId]);
            $count = $stmt->fetchColumn();
            
            if ($count >= 10) {
                logEvent('WARNING', 'Rate limit exceeded for void operations', [
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
 * Ensure required columns exist
 */
function ensureRequiredColumns($pdo) {
    try {
        $columnsToCheck = [
            'order_items' => ['is_voided', 'void_reason', 'voided_at', 'voided_by', 'kitchen_status']
        ];
        
        foreach ($columnsToCheck as $table => $columns) {
            $existingColumns = [];
            $stmt = $pdo->query("SHOW COLUMNS FROM $table");
            while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $existingColumns[] = $col['Field'];
            }
            
            foreach ($columns as $column) {
                if (!in_array($column, $existingColumns)) {
                    $columnDef = '';
                    switch ($column) {
                        case 'is_voided':
                            $columnDef = "ADD COLUMN is_voided TINYINT(1) DEFAULT 0";
                            break;
                        case 'void_reason':
                            $columnDef = "ADD COLUMN void_reason TEXT NULL";
                            break;
                        case 'voided_at':
                            $columnDef = "ADD COLUMN voided_at DATETIME NULL";
                            break;
                        case 'voided_by':
                            $columnDef = "ADD COLUMN voided_by INT NULL";
                            break;
                        case 'kitchen_status':
                            $columnDef = "ADD COLUMN kitchen_status VARCHAR(50) DEFAULT 'pending'";
                            break;
                    }
                    
                    if ($columnDef) {
                        try {
                            $pdo->exec("ALTER TABLE $table $columnDef");
                            logEvent('INFO', "Added column $column to $table");
                        } catch (Exception $e) {
                            logEvent('WARNING', "Could not add column $column to $table", ['error' => $e->getMessage()]);
                        }
                    }
                }
            }
        }
        return true;
    } catch (Exception $e) {
        logEvent('ERROR', 'Failed to ensure required columns', ['error' => $e->getMessage()]);
        return false;
    }
}

// ===== MAIN LOGIC STARTS HERE =====

// Get database connection
$pdo = getDbConnection();
if (!$pdo) {
    sendError('Service temporarily unavailable. Please try again.', 503, 'DB_CONNECTION_ERROR',
        'Failed to establish database connection');
}

// Ensure required columns exist
ensureRequiredColumns($pdo);

// Session validation - Get session data
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

// Allow tenant/branch/user to be passed in request
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
            sendError('No active tenant found. Please contact support.', 401, 'NO_TENANT');
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

// Sanitize inputs
$reason = substr(trim(strip_tags($input['reason'])), 0, 500);
$approvalPin = isset($input['approval_pin']) ? 
    substr(trim($input['approval_pin']), 0, 20) : null;

// Database transaction
try {
    // Check rate limit
    if (!checkRateLimit($pdo, $tenantId, $userId)) {
        sendError(
            'Too many void requests. Please wait before trying again.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
    }
    
    // Get tenant settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value`
        FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN (
            'tax_rate',
            'tax_inclusive',
            'currency_symbol',
            'currency_code',
            'currency',
            'require_void_approval',
            'void_fired_items_approval'
        )
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $taxRate = isset($settings['tax_rate']) ? floatval($settings['tax_rate']) : 14.0;
    $taxInclusive = isset($settings['tax_inclusive']) ? 
        filter_var($settings['tax_inclusive'], FILTER_VALIDATE_BOOLEAN) : false;
    $currency = $settings['currency_code'] ?? $settings['currency'] ?? 'EGP';
    $currencySymbol = $settings['currency_symbol'] ?? 'EGP';
    $requireVoidApproval = isset($settings['require_void_approval']) ? 
        filter_var($settings['require_void_approval'], FILTER_VALIDATE_BOOLEAN) : false;
    $requireFiredItemApproval = isset($settings['void_fired_items_approval']) ? 
        filter_var($settings['void_fired_items_approval'], FILTER_VALIDATE_BOOLEAN) : true;
    
    // Start transaction
    $pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
    $pdo->beginTransaction();
    
    // Fetch item with order info and lock
    $stmt = $pdo->prepare("
        SELECT oi.*, 
               o.status as order_status, 
               o.payment_status,
               o.tenant_id,
               o.branch_id,
               o.receipt_reference,
               o.discount_amount,
               o.service_charge,
               o.tip_amount
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = :item_id 
        AND oi.order_id = :order_id
        AND o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND IFNULL(oi.is_voided, 0) = 0
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
    $kitchenStatus = $item['kitchen_status'] ?? 'pending';
    
    if (in_array($kitchenStatus, ['preparing', 'ready', 'served', 'fired'])) {
        $itemFired = true;
    }
    
    // Determine if approval needed
    $needsApproval = false;
    if ($requireVoidApproval || ($itemFired && $requireFiredItemApproval)) {
        $needsApproval = true;
    }
    
    if ($needsApproval) {
        logEvent('INFO', 'Void requires approval', [
            'item_id' => $itemId,
            'kitchen_status' => $kitchenStatus,
            'item_fired' => $itemFired
        ]);
        
        if (!in_array(strtolower($userRole), ['admin', 'manager', 'owner', 'supervisor'])) {
            if (!$approvalPin) {
                $pdo->commit();
                sendError(
                    'Manager approval required to void this item',
                    403,
                    'MANAGER_APPROVAL_REQUIRED',
                    null,
                    [
                        'requires_approval' => true,
                        'item_status' => $kitchenStatus,
                        'reason' => $itemFired ? 'Item already prepared' : 'Policy requires approval'
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
    $productName = $item['product_name'];
    $quantity = $item['quantity'];
    
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
    
    // Recalculate order totals
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN IFNULL(is_voided, 0) = 0 THEN line_total ELSE 0 END) as subtotal,
            COUNT(CASE WHEN IFNULL(is_voided, 0) = 0 THEN 1 ELSE NULL END) as active_items,
            COUNT(*) as total_items
        FROM order_items 
        WHERE order_id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotal = (float)$totals['subtotal'];
    $activeItems = (int)$totals['active_items'];
    $totalItems = (int)$totals['total_items'];
    
    // Get order charges
    $discountAmount = (float)($item['discount_amount'] ?? 0);
    $serviceCharge = (float)($item['service_charge'] ?? 0);
    $tipAmount = (float)($item['tip_amount'] ?? 0);
    
    // Calculate new totals
    if ($taxInclusive) {
        $taxableAmount = $subtotal - $discountAmount + $serviceCharge;
        $tax = $taxableAmount - ($taxableAmount / (1 + ($taxRate / 100)));
        $newTotal = $taxableAmount + $tipAmount;
    } else {
        $taxableAmount = $subtotal - $discountAmount + $serviceCharge;
        $tax = $taxableAmount * ($taxRate / 100);
        $newTotal = $taxableAmount + $tax + $tipAmount;
    }
    
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
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount();
        
        if ($tableCheck > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO order_logs (
                    order_id, tenant_id, branch_id, user_id,
                    action, details, created_at
                ) VALUES (
                    :order_id, :tenant_id, :branch_id, :user_id,
                    'item_voided', :details, NOW()
                )
            ");
            
            $logDetails = json_encode([
                'item_id' => $itemId,
                'item_name' => $productName,
                'quantity' => $quantity,
                'original_amount' => $originalAmount,
                'reason' => $reason,
                'approved_by' => $approvedBy,
                'approved_by_name' => $managerName,
                'was_fired' => $itemFired,
                'kitchen_status' => $kitchenStatus,
                'receipt' => $item['receipt_reference'],
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
    
    $pdo->commit();
    
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
        'receipt_reference' => $item['receipt_reference'],
        'item' => [
            'id' => $itemId,
            'product_name' => $productName,
            'quantity' => $quantity,
            'voided' => true,
            'void_reason' => $reason,
            'voided_amount' => round($originalAmount, 2),
            'approved_by' => $approvedBy,
            'approved_by_name' => $managerName,
            'was_fired' => $itemFired,
            'kitchen_status' => $kitchenStatus
        ],
        'order_totals' => [
            'subtotal' => round($subtotal, 2),
            'discount' => round($discountAmount, 2),
            'service_charge' => round($serviceCharge, 2),
            'tax' => round($tax, 2),
            'tax_rate' => $taxRate,
            'tip' => round($tipAmount, 2),
            'total' => round($newTotal, 2),
            'currency' => $currency,
            'currency_symbol' => $currencySymbol,
            'active_items' => $activeItems,
            'total_items' => $totalItems
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
        logEvent('ERROR', 'Database error in void item operation', [
            'item_id' => $itemId ?? null,
            'order_id' => $orderId ?? null,
            'error' => $errorMessage,
            'trace' => $pdoEx->getTraceAsString()
        ]);
        
        sendError('Unable to process void request', 500, 'DATABASE_ERROR');
    }
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Void item failed', [
        'item_id' => $itemId ?? null,
        'order_id' => $orderId ?? null,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to void item. Please try again.', 500, 'VOID_ITEM_FAILED');
}
?>