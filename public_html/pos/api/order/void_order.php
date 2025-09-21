<?php
/**
 * SME 180 POS - Void Order API (FINAL WORKING VERSION)
 * Path: /public_html/pos/api/order/void_order.php
 * Version: 6.0.0 - Fixed for your database structure
 * 
 * CRITICAL FIX: Using manager_pin column instead of user_pin
 */

declare(strict_types=1);

// Production error handling
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Configuration
define('API_KEY', 'sme180_pos_api_key_2024');
define('MAX_REQUEST_SIZE', 10000);
define('RATE_LIMIT_PER_MINUTE', 5);

// Performance monitoring
$startTime = microtime(true);

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'none\'');

// Handle OPTIONS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key, X-Manager-Auth');
    header('Access-Control-Max-Age: 86400');
    http_response_code(200);
    die('{"success":true}');
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    die('{"success":false,"error":"Method not allowed","code":"METHOD_NOT_ALLOWED"}');
}

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'dbvtrnbzad193e');
    define('DB_USER', 'uta6umaa0iuif');
    define('DB_PASS', '2m%[11|kb1Z4');
    define('DB_CHARSET', 'utf8mb4');
}

/**
 * Send error response
 */
function sendError(string $message, int $code = 400, string $errorCode = 'ERROR', array $context = []): void {
    global $startTime;
    
    error_log("[SME180 Void] $errorCode: $message" . (!empty($context) ? ' | ' . json_encode($context) : ''));
    
    http_response_code($code);
    
    $response = [
        'success' => false,
        'error' => $message,
        'code' => $errorCode,
        'timestamp' => date('c'),
        'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ];
    
    die(json_encode($response, JSON_UNESCAPED_UNICODE));
}

/**
 * Send success response
 */
function sendSuccess(array $data): void {
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
function checkRateLimit($pdo, $tenantId, $userId): bool {
    try {
        // Check if order_logs table exists
        $stmt = $pdo->query("SHOW TABLES LIKE 'order_logs'");
        if ($stmt->rowCount() > 0) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) 
                FROM order_logs 
                WHERE tenant_id = ? 
                    AND user_id = ? 
                    AND action IN ('voided', 'void_order')
                    AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ");
            $stmt->execute([$tenantId, $userId]);
            
            if ($stmt->fetchColumn() >= RATE_LIMIT_PER_MINUTE) {
                return false;
            }
        }
    } catch (Exception $e) {
        // Continue without rate limiting if check fails
    }
    return true;
}

// Get database connection
try {
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
    ]);
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    error_log("[SME180] Database connection failed: " . $e->getMessage());
    sendError('Service temporarily unavailable', 503, 'DB_CONNECTION_FAILED');
}

// Get and validate JSON input
$inputRaw = file_get_contents('php://input');
if (strlen($inputRaw) > MAX_REQUEST_SIZE) {
    sendError('Request too large', 413, 'REQUEST_TOO_LARGE');
}

$input = json_decode($inputRaw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid JSON input', 400, 'INVALID_JSON');
}

// Get authentication context (flexible approach)
$tenantId = null;
$branchId = null;
$userId = null;

// Try API key authentication first
$apiKey = $input['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;

if ($apiKey === API_KEY) {
    // API key authentication - get context from request
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : null;
    $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : null;
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
} else {
    // Try session authentication
    $tenantId = $_SESSION['tenant_id'] ?? null;
    $branchId = $_SESSION['branch_id'] ?? null;
    $userId = $_SESSION['user_id'] ?? null;
}

// Fallback to request data if still missing
if (!$tenantId || !$branchId) {
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 1;
    $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : 1;
}

if (!$userId) {
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : 1;
}

// Validate order ID
$orderId = isset($input['order_id']) ? filter_var($input['order_id'], FILTER_VALIDATE_INT) : false;
if ($orderId === false || $orderId <= 0) {
    sendError('Valid order ID is required', 400, 'INVALID_ORDER_ID');
}

// Validate reason
$reason = isset($input['reason']) ? substr(trim(strip_tags($input['reason'])), 0, 500) : '';
if (empty($reason)) {
    sendError('Void reason is required', 400, 'MISSING_REASON');
}

// Get manager PIN
$managerPin = isset($input['manager_pin']) ? trim($input['manager_pin']) : '';
if (empty($managerPin)) {
    sendError('Manager PIN is required for void operations', 401, 'MANAGER_PIN_REQUIRED');
}

// Main transaction
try {
    // Check rate limiting
    if (!checkRateLimit($pdo, $tenantId, $userId)) {
        sendError('Too many void requests. Please wait before trying again.', 429, 'RATE_LIMIT_EXCEEDED');
    }
    
    // CRITICAL FIX: Using manager_pin column instead of user_pin
    // Check both manager_pin and pos_pin columns for flexibility
    $stmt = $pdo->prepare("
        SELECT id, name, role_key 
        FROM users 
        WHERE tenant_id = :tenant_id 
        AND (manager_pin = :pin OR (pos_pin = :pin2 AND role_key IN ('admin', 'pos_manager')))
        AND role_key IN ('admin', 'pos_manager')
        LIMIT 1
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'pin' => $managerPin,
        'pin2' => $managerPin
    ]);
    $manager = $stmt->fetch();
    
    if (!$manager) {
        error_log("[SME180] Invalid manager PIN for tenant $tenantId");
        sendError('Invalid manager PIN or insufficient permissions', 401, 'INVALID_MANAGER_PIN');
    }
    
    $managerId = (int)$manager['id'];
    $managerName = $manager['name'];
    
    // Get tenant settings for currency
    $currency = 'EGP';  // Default for Egypt
    $currencySymbol = 'EGP';
    
    try {
        $stmt = $pdo->prepare("
            SELECT `key`, `value` 
            FROM settings 
            WHERE tenant_id = ? 
            AND `key` IN ('currency_symbol', 'currency_code', 'currency')
        ");
        $stmt->execute([$tenantId]);
        
        while ($row = $stmt->fetch()) {
            if ($row['key'] === 'currency_symbol') {
                $currencySymbol = $row['value'];
            } elseif ($row['key'] === 'currency_code' || $row['key'] === 'currency') {
                $currency = $row['value'];
            }
        }
    } catch (Exception $e) {
        // Use defaults if settings query fails
    }
    
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
        sendError('Order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    // Check if order can be voided
    if ($order['payment_status'] === 'paid') {
        $pdo->rollBack();
        sendError('Cannot void paid orders. Please use refund instead.', 409, 'ORDER_ALREADY_PAID');
    }
    
    if ($order['is_voided'] == 1 || $order['status'] === 'voided' || $order['voided_at'] !== null) {
        $pdo->rollBack();
        sendError('Order is already voided', 409, 'ALREADY_VOIDED');
    }
    
    if (in_array($order['status'], ['refunded', 'closed', 'completed']) && $order['payment_status'] === 'paid') {
        $pdo->rollBack();
        sendError('Cannot void ' . $order['status'] . ' orders', 409, 'INVALID_ORDER_STATUS');
    }
    
    // Update order - using correct column names
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET status = 'voided',
            is_voided = 1,
            voided_at = NOW(),
            voided_by = :manager_id,
            void_reason = :reason,
            void_approved_by = :approved_by,
            payment_status = 'voided',
            updated_at = NOW()
        WHERE id = :order_id
        AND tenant_id = :tenant_id
        AND branch_id = :branch_id
    ");
    
    $result = $stmt->execute([
        'manager_id' => $managerId,
        'reason' => $reason,
        'approved_by' => $managerId,
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    
    if ($stmt->rowCount() === 0) {
        $pdo->rollBack();
        sendError('Failed to void order', 500, 'VOID_FAILED');
    }
    
    // Void all order items
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET is_voided = 1,
            voided_at = NOW(),
            voided_by = :manager_id,
            void_reason = :reason,
            updated_at = NOW()
        WHERE order_id = :order_id
    ");
    
    $stmt->execute([
        'manager_id' => $managerId,
        'reason' => $reason,
        'order_id' => $orderId
    ]);
    
    $voidedItemCount = $stmt->rowCount();
    
    // Free table if dine-in (using dining_tables with correct column names)
    $tableFreed = false;
    $tableNumber = null;
    
    if ($order['order_type'] === 'dine_in' && $order['table_id']) {
        try {
            // Check if dining_tables exists
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'dining_tables'")->rowCount();
            
            if ($tableCheck > 0) {
                // Update dining_tables with correct column name (status='free' not 'available')
                $stmt = $pdo->prepare("
                    UPDATE dining_tables 
                    SET status = 'free',
                        updated_at = NOW()
                    WHERE id = :table_id
                    AND tenant_id = :tenant_id
                    AND branch_id = :branch_id
                ");
                
                $stmt->execute([
                    'table_id' => $order['table_id'],
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId
                ]);
                
                if ($stmt->rowCount() > 0) {
                    $tableFreed = true;
                    
                    // Get table number for response
                    $stmt = $pdo->prepare("SELECT table_number FROM dining_tables WHERE id = ?");
                    $stmt->execute([$order['table_id']]);
                    $tableNumber = $stmt->fetchColumn();
                }
            }
        } catch (Exception $e) {
            // Log but don't fail if table update fails
            error_log("[SME180] Failed to free table: " . $e->getMessage());
        }
    }
    
    // Cancel any kitchen orders (if table exists)
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'kitchen_orders'")->rowCount();
        
        if ($tableCheck > 0) {
            $stmt = $pdo->prepare("
                UPDATE kitchen_orders 
                SET status = 'cancelled',
                    cancelled_at = NOW(),
                    cancelled_by = :user_id,
                    cancel_reason = 'Order voided'
                WHERE order_id = :order_id
                AND status IN ('pending', 'preparing')
            ");
            $stmt->execute([
                'user_id' => $managerId,
                'order_id' => $orderId
            ]);
        }
    } catch (Exception $e) {
        // Continue without kitchen cancellation
    }
    
    // Log the void (if table exists)
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount();
        
        if ($tableCheck > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO order_logs (
                    order_id, tenant_id, branch_id, user_id,
                    action, details, created_at
                ) VALUES (
                    :order_id, :tenant_id, :branch_id, :user_id,
                    'voided', :details, NOW()
                )
            ");
            
            $logDetails = json_encode([
                'reason' => $reason,
                'approved_by' => $managerId,
                'approved_by_name' => $managerName,
                'total_amount' => $order['total_amount'],
                'items_voided' => $voidedItemCount,
                'table_freed' => $tableFreed,
                'table_number' => $tableNumber,
                'receipt' => $order['receipt_reference'] ?? null,
                'payment_status' => $order['payment_status'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null
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
        // Continue without logging
        error_log("[SME180] Failed to create audit log: " . $e->getMessage());
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Log successful void
    error_log("[SME180] Order $orderId voided successfully by manager $managerId");
    
    // Send success response
    sendSuccess([
        'message' => 'Order voided successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'] ?? 'N/A',
            'status' => 'voided',
            'voided_at' => date('c'),
            'voided_by' => $managerId,
            'voided_by_name' => $managerName,
            'reason' => $reason,
            'total_amount' => round((float)($order['total_amount'] ?? 0), 2),
            'currency' => $currency,
            'currency_symbol' => $currencySymbol,
            'items_voided' => $voidedItemCount,
            'table_freed' => $tableFreed,
            'table_number' => $tableNumber,
            'payment_status' => 'voided'
        ]
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $errorMessage = $e->getMessage();
    error_log("[SME180] PDO Error in void order: " . $errorMessage);
    
    // Handle specific database errors
    if (strpos($errorMessage, 'Deadlock') !== false) {
        sendError('System busy, please try again', 503, 'DEADLOCK_DETECTED');
    } elseif (strpos($errorMessage, 'Lock wait timeout') !== false) {
        sendError('Request timeout, please try again', 504, 'LOCK_TIMEOUT');
    } else {
        sendError('Unable to process void request', 500, 'DATABASE_ERROR', [
            'order_id' => $orderId
        ]);
    }
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("[SME180] General error in void order: " . $e->getMessage());
    sendError('Failed to void order. Please try again.', 500, 'VOID_FAILED');
}
?>