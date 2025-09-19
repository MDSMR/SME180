<?php
/**
 * SME 180 POS - Void Order API
 * Path: /public_html/pos/api/order/void_order.php
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

function checkRateLimit($pdo, $tenantId, $userId, $action = 'void') {
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
        
        // Allow max 5 void operations per minute per user
        if ($count >= 5) {
            logEvent('WARNING', 'Rate limit exceeded for void operation', [
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

$orderId = filter_var($input['order_id'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
]);

if ($orderId === false) {
    sendError('Invalid order ID format', 400, 'INVALID_ORDER_ID');
}

// Validate reason (required for audit trail)
$reason = isset($input['reason']) ? 
    substr(trim(strip_tags($input['reason'])), 0, 500) : '';

if (empty($reason)) {
    sendError('Void reason is required', 400, 'MISSING_REASON');
}

$managerPin = isset($input['manager_pin']) ? 
    substr(trim($input['manager_pin']), 0, 20) : '';

try {
    // Check rate limit
    if (!checkRateLimit($pdo, $tenantId, $userId, 'voided')) {
        sendError(
            'Too many void requests. Please wait before trying again.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Check if manager approval is required
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'pos_require_manager_void' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $requireManagerApproval = $stmt->fetchColumn() === 'true';
    
    $managerId = $userId;
    $managerName = null;
    
    if ($requireManagerApproval) {
        if (!in_array($userRole, ['admin', 'manager', 'owner'])) {
            if (!$managerPin) {
                $pdo->commit();
                sendError(
                    'Manager approval required to void orders',
                    403,
                    'MANAGER_APPROVAL_REQUIRED',
                    ['requires_approval' => true]
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
                $pdo->commit();
                logEvent('WARNING', 'Invalid manager PIN attempt', [
                    'order_id' => $orderId,
                    'user_id' => $userId
                ]);
                sendError('Invalid manager PIN', 403, 'INVALID_MANAGER_PIN');
            }
            
            $managerId = $manager['id'];
            $managerName = $manager['name'];
            
            logEvent('INFO', 'Manager approval granted', [
                'order_id' => $orderId,
                'manager_id' => $managerId,
                'manager_role' => $manager['role']
            ]);
        }
    }
    
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
    
    // Validate order status
    if ($order['status'] === 'voided') {
        $pdo->rollBack();
        sendError('Order is already voided', 409, 'ORDER_ALREADY_VOIDED');
    }
    
    if ($order['payment_status'] === 'paid') {
        $pdo->rollBack();
        sendError(
            'Cannot void paid orders. Please process a refund instead.',
            409,
            'CANNOT_VOID_PAID_ORDER'
        );
    }
    
    if (in_array($order['status'], ['closed', 'refunded'])) {
        $pdo->rollBack();
        sendError(
            'Cannot void ' . $order['status'] . ' orders',
            409,
            'INVALID_ORDER_STATUS'
        );
    }
    
    // Void the order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET status = 'voided',
            voided_at = NOW(),
            voided_by = :voided_by,
            void_reason = :reason,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    
    $stmt->execute([
        'voided_by' => $managerId,
        'reason' => $reason,
        'order_id' => $orderId
    ]);
    
    // Void all order items
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET is_voided = 1,
            voided_at = NOW(),
            voided_by = :voided_by,
            void_reason = 'Order voided',
            updated_at = NOW()
        WHERE order_id = :order_id
        AND is_voided = 0
    ");
    
    $stmt->execute([
        'voided_by' => $managerId,
        'order_id' => $orderId
    ]);
    
    $voidedItemCount = $stmt->rowCount();
    
    // Free the table if dine-in
    $tableFreed = false;
    if ($order['order_type'] === 'dine_in' && $order['table_id']) {
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
            // Table might not exist - log but don't fail
            logEvent('WARNING', 'Could not free table', [
                'table_id' => $order['table_id'],
                'error' => $e->getMessage()
            ]);
        }
    }
    
    // Log the void
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'voided', :details, NOW()
        )
    ");
    
    $logDetails = [
        'reason' => $reason,
        'approved_by' => $managerId,
        'approved_by_name' => $managerName,
        'total_amount' => $order['total_amount'],
        'items_voided' => $voidedItemCount,
        'table_freed' => $tableFreed,
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
    
    // Log successful void
    logEvent('INFO', 'Order voided successfully', [
        'order_id' => $orderId,
        'receipt' => $order['receipt_reference'],
        'amount' => $order['total_amount'],
        'items_voided' => $voidedItemCount,
        'manager_id' => $managerId
    ]);
    
    // Get currency from settings
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('currency_symbol', 'currency_code', 'currency')
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $currency = $stmt->fetchColumn() ?: 'EGP';
    
    sendSuccess([
        'message' => 'Order voided successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'status' => 'voided',
            'voided_at' => date('c'),
            'voided_by' => $managerId,
            'voided_by_name' => $managerName,
            'reason' => $reason,
            'total_amount' => (float)$order['total_amount'],
            'currency' => $currency,
            'items_voided' => $voidedItemCount,
            'table_freed' => $tableFreed
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Void order failed', [
        'order_id' => $orderId,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to void order', 500, 'VOID_FAILED');
}
?>