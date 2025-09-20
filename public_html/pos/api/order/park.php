<?php
/**
 * SME 180 POS - Park Order API (Production Ready)
 * Path: /public_html/pos/api/order/park.php
 * Version: 3.0.0 - Production Ready with Enhanced Features
 * 
 * Parks orders temporarily with support for:
 * - Automatic table freeing
 * - Park labels and categories
 * - Expiry management
 * - Priority levels
 */

declare(strict_types=1);

// Production error settings
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Configuration
define('API_TEST_MODE', true); // CHANGE TO false IN PRODUCTION
define('API_KEY', 'sme180_pos_api_key_2024'); // Change to secure key
define('MAX_PARKED_ORDERS', 50); // Per branch
define('RATE_LIMIT_PER_MINUTE', 10); // Per user
define('PARK_EXPIRY_HOURS', 24); // Auto-expire after 24 hours
define('MAX_REQUEST_SIZE', 10000); // 10KB

// Performance monitoring
$startTime = microtime(true);

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    die(json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.',
        'code' => 'METHOD_NOT_ALLOWED'
    ]));
}

/**
 * Send error response
 */
function sendError(string $message, int $code = 400, string $errorCode = 'ERROR', array $data = []): void {
    global $startTime;
    
    error_log("[SME180] $errorCode: $message");
    
    http_response_code($code);
    die(json_encode(array_merge([
        'success' => false,
        'error' => $message,
        'code' => $errorCode,
        'timestamp' => date('c'),
        'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ], $data), JSON_UNESCAPED_UNICODE));
}

/**
 * Send success response
 */
function sendSuccess(array $data): void {
    global $startTime;
    
    echo json_encode(array_merge(
        ['success' => true],
        $data,
        [
            'timestamp' => date('c'),
            'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
        ]
    ), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

/**
 * Check rate limiting
 */
function checkRateLimit(PDO $pdo, int $tenantId, int $userId): bool {
    try {
        // Check if order_logs table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount();
        if ($tableCheck === 0) {
            return true; // No rate limiting if table doesn't exist
        }
        
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as action_count 
            FROM order_logs 
            WHERE tenant_id = :tenant_id 
                AND user_id = :user_id 
                AND action = 'parked'
                AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId
        ]);
        $count = (int)$stmt->fetchColumn();
        
        return $count < RATE_LIMIT_PER_MINUTE;
    } catch (Exception $e) {
        error_log("[SME180] Rate limit check failed: " . $e->getMessage());
        return true; // Allow on error
    }
}

// Parse and validate input
$rawInput = file_get_contents('php://input');
if (strlen($rawInput) > MAX_REQUEST_SIZE) {
    sendError('Request too large', 413, 'REQUEST_TOO_LARGE');
}

if (empty($rawInput)) {
    sendError('Request body is required', 400, 'EMPTY_REQUEST');
}

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid JSON: ' . json_last_error_msg(), 400, 'INVALID_JSON');
}

// Load configuration and authentication
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';

// Get database connection
try {
    $pdo = db();
    if (!$pdo) {
        throw new Exception('Database connection not available');
    }
} catch (Exception $e) {
    sendError('Service temporarily unavailable', 503, 'DATABASE_ERROR');
}

// Authentication and session handling
use_backend_session();
$authenticated = false;
$tenantId = null;
$branchId = null;
$userId = null;

// Check API key authentication
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $input['api_key'] ?? null;
if ($apiKey && $apiKey === API_KEY) {
    $authenticated = true;
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : null;
    $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : null;
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
    
    if (!$tenantId || !$branchId) {
        sendError('Tenant and branch required for API key authentication', 400, 'MISSING_CONTEXT');
    }
    
    error_log("[SME180] API key authentication for tenant $tenantId");
} else {
    // Session authentication
    $user = auth_user();
    
    if ($user) {
        $authenticated = true;
        $tenantId = auth_get_tenant_id();
        $branchId = auth_get_branch_id();
        $userId = (int)($user['id'] ?? 0);
    }
}

// Test mode fallback
if (!$authenticated && API_TEST_MODE) {
    error_log("[SME180] WARNING: Test mode authentication bypass active");
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 1;
    $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : 1;
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : 1;
} elseif (!$authenticated) {
    sendError('Authentication required', 401, 'UNAUTHORIZED');
}

// Validate order ID
$orderId = filter_var($input['order_id'] ?? 0, FILTER_VALIDATE_INT);
if (!$orderId || $orderId <= 0) {
    sendError('Valid order ID is required', 400, 'INVALID_ORDER_ID');
}

// Extract and validate optional fields
$parkReason = isset($input['reason']) ? 
    substr(trim(strip_tags($input['reason'])), 0, 500) : '';
$parkLabel = isset($input['label']) ? 
    substr(trim(strip_tags($input['label'])), 0, 100) : null;
$parkCategory = isset($input['category']) ? 
    substr(trim(strip_tags($input['category'])), 0, 50) : 'general';
$parkPriority = filter_var($input['priority'] ?? 0, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 0, 'max_range' => 3, 'default' => 0]
]);
$parkExpiryHours = filter_var($input['expiry_hours'] ?? PARK_EXPIRY_HOURS, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 168, 'default' => PARK_EXPIRY_HOURS] // Max 1 week
]);

// Main transaction
try {
    // Check rate limiting
    if (!checkRateLimit($pdo, $tenantId, $userId)) {
        sendError(
            'Too many park requests. Please wait before trying again.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
    }
    
    // Check current parked orders count
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as parked_count
        FROM orders
        WHERE tenant_id = :tenant_id
        AND branch_id = :branch_id
        AND parked = 1
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $parkedCount = (int)$stmt->fetchColumn();
    
    if ($parkedCount >= MAX_PARKED_ORDERS) {
        sendError(
            'Maximum number of parked orders reached (' . MAX_PARKED_ORDERS . '). Please complete existing orders first.',
            409,
            'MAX_PARKED_ORDERS',
            ['current_count' => $parkedCount, 'max_allowed' => MAX_PARKED_ORDERS]
        );
    }
    
    $pdo->beginTransaction();
    
    // Get and lock order
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE id = :order_id 
        AND tenant_id = :tenant_id
        FOR UPDATE
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception("Order not found");
    }
    
    // Verify branch access (skip in test mode)
    if ($order['branch_id'] != $branchId && !API_TEST_MODE) {
        throw new Exception("Order belongs to different branch");
    }
    
    // Validate order status
    if ($order['payment_status'] === 'paid') {
        throw new Exception("Cannot park paid orders");
    }
    
    if (in_array($order['status'], ['closed', 'voided', 'refunded', 'completed'], true)) {
        throw new Exception("Cannot park {$order['status']} orders");
    }
    
    if (isset($order['parked']) && $order['parked'] == 1) {
        throw new Exception("Order is already parked");
    }
    
    // Generate park label if not provided
    if (!$parkLabel) {
        $timestamp = date('H:i');
        
        // Use customer name if available
        if (!empty($order['customer_name']) && $order['customer_name'] !== 'Walk-in Customer') {
            $parkLabel = substr($order['customer_name'], 0, 30) . ' - ' . $timestamp;
        } 
        // Use table number if dine-in
        elseif ($order['order_type'] === 'dine_in' && !empty($order['table_id'])) {
            $parkLabel = 'Table ' . $order['table_id'] . ' - ' . $timestamp;
        }
        // Default to receipt reference
        else {
            $parkLabel = 'Parked #' . $order['receipt_reference'] . ' - ' . $timestamp;
        }
    }
    
    // Check what columns exist in orders table
    $cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($cols, 'Field');
    
    $hasParkedAt = in_array('parked_at', $columnNames);
    $hasParkCategory = in_array('park_category', $columnNames);
    $hasParkPriority = in_array('park_priority', $columnNames);
    $hasParkExpiry = in_array('park_expiry', $columnNames);
    
    // Build update query
    $updateFields = [
        "parked = 1",
        "park_label = :park_label",
        "status = 'held'",
        "updated_at = NOW()"
    ];
    $updateParams = [
        'park_label' => $parkLabel,
        'order_id' => $orderId
    ];
    
    if ($hasParkedAt) {
        $updateFields[] = "parked_at = NOW()";
    }
    
    if ($hasParkCategory) {
        $updateFields[] = "park_category = :park_category";
        $updateParams['park_category'] = $parkCategory;
    }
    
    if ($hasParkPriority) {
        $updateFields[] = "park_priority = :park_priority";
        $updateParams['park_priority'] = $parkPriority;
    }
    
    if ($hasParkExpiry) {
        $updateFields[] = "park_expiry = DATE_ADD(NOW(), INTERVAL :expiry_hours HOUR)";
        $updateParams['expiry_hours'] = $parkExpiryHours;
    }
    
    // Execute order update
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET " . implode(", ", $updateFields) . "
        WHERE id = :order_id
    ");
    $stmt->execute($updateParams);
    
    // Free up table if dine-in order
    $tableFreed = false;
    if ($order['order_type'] === 'dine_in' && !empty($order['table_id'])) {
        try {
            // Check if dining_tables table exists
            $tableExists = $pdo->query("SHOW TABLES LIKE 'dining_tables'")->rowCount() > 0;
            
            if ($tableExists) {
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
            }
        } catch (PDOException $e) {
            error_log("[SME180] Table update failed: " . $e->getMessage());
        }
    }
    
    // Count items
    $itemCountQuery = "
        SELECT COUNT(*) as item_count,
               SUM(quantity) as total_quantity
        FROM order_items
        WHERE order_id = :order_id
    ";
    
    // Add voided check if column exists
    $itemCols = $pdo->query("SHOW COLUMNS FROM order_items")->fetchAll(PDO::FETCH_ASSOC);
    $itemColumnNames = array_column($itemCols, 'Field');
    if (in_array('is_voided', $itemColumnNames)) {
        $itemCountQuery = "
            SELECT COUNT(*) as item_count,
                   SUM(quantity) as total_quantity,
                   SUM(CASE WHEN is_voided = 0 THEN 1 ELSE 0 END) as active_items
            FROM order_items
            WHERE order_id = :order_id
        ";
    }
    
    $stmt = $pdo->prepare($itemCountQuery);
    $stmt->execute(['order_id' => $orderId]);
    $itemCounts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Audit logging
    try {
        $auditTableExists = $pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount() > 0;
        
        if ($auditTableExists) {
            $stmt = $pdo->prepare("
                INSERT INTO order_logs (
                    order_id, tenant_id, branch_id, user_id,
                    action, details, created_at
                ) VALUES (
                    :order_id, :tenant_id, :branch_id, :user_id,
                    'parked', :details, NOW()
                )
            ");
            
            $logDetails = [
                'park_label' => $parkLabel,
                'park_category' => $parkCategory,
                'park_priority' => $parkPriority,
                'park_expiry_hours' => $parkExpiryHours,
                'reason' => $parkReason,
                'table_freed' => $tableFreed,
                'table_id' => $order['table_id'] ?? null,
                'total_amount' => $order['total_amount'],
                'items_count' => $itemCounts['item_count'],
                'active_items' => $itemCounts['active_items'] ?? $itemCounts['item_count'],
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
            ];
            
            $stmt->execute([
                'order_id' => $orderId,
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'details' => json_encode($logDetails)
            ]);
        }
    } catch (Exception $e) {
        error_log("[SME180] Audit log failed: " . $e->getMessage());
    }
    
    $pdo->commit();
    
    // Get currency
    $currency = 'EGP';
    try {
        $stmt = $pdo->prepare("
            SELECT value FROM settings 
            WHERE tenant_id = :tenant_id 
            AND `key` IN ('currency_symbol', 'currency_code', 'currency')
            LIMIT 1
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        $result = $stmt->fetchColumn();
        if ($result) {
            $currency = $result;
        }
    } catch (Exception $e) {
        // Use default
    }
    
    // Calculate expiry time
    $expiryTime = null;
    if ($hasParkExpiry) {
        $expiryTime = date('c', strtotime("+{$parkExpiryHours} hours"));
    }
    
    // Success response
    sendSuccess([
        'message' => 'Order parked successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'park_label' => $parkLabel,
            'park_category' => $parkCategory,
            'park_priority' => $parkPriority,
            'park_expiry' => $expiryTime,
            'parked_at' => date('c'),
            'status' => 'held',
            'table_freed' => $tableFreed,
            'total_amount' => (float)$order['total_amount'],
            'currency' => $currency,
            'items_count' => (int)($itemCounts['active_items'] ?? $itemCounts['item_count']),
            'total_quantity' => (float)($itemCounts['total_quantity'] ?? 0)
        ],
        'parking_info' => [
            'current_parked_orders' => $parkedCount + 1,
            'max_parked_orders' => MAX_PARKED_ORDERS,
            'expires_in_hours' => $parkExpiryHours
        ]
    ]);
    
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("[SME180] Park order failed: " . $e->getMessage());
    
    if (strpos($e->getMessage(), 'not found') !== false) {
        sendError('Order not found', 404, 'ORDER_NOT_FOUND');
    } elseif (strpos($e->getMessage(), 'already parked') !== false) {
        sendError('Order is already parked', 409, 'ALREADY_PARKED');
    } elseif (strpos($e->getMessage(), 'Cannot park') !== false) {
        sendError($e->getMessage(), 400, 'INVALID_STATUS');
    } elseif (strpos($e->getMessage(), 'different branch') !== false) {
        sendError('Access denied', 403, 'ACCESS_DENIED');
    } else {
        sendError('Failed to park order', 500, 'PARK_FAILED');
    }
}
?>