<?php
/**
 * SME 180 POS - Update Order API (Production Ready)
 * Path: /public_html/pos/api/order/update.php
 * Version: 7.0.0 - Production with configurable test mode
 */

declare(strict_types=1);

// Error reporting for production
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Performance monitoring
$startTime = microtime(true);

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, private');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    header('Access-Control-Max-Age: 86400');
    http_response_code(204);
    exit;
}

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST, OPTIONS');
    die(json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.',
        'code' => 'METHOD_NOT_ALLOWED',
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * Configuration
 * Set API_TEST_MODE to false in production
 */
define('API_TEST_MODE', true); // CHANGE TO false IN PRODUCTION
define('API_KEY', 'sme180_pos_api_key_2024'); // Change this to a secure key
define('MAX_REQUEST_SIZE', 100000); // 100KB
define('RATE_LIMIT_REQUESTS', 100); // Max requests per minute
define('RATE_LIMIT_WINDOW', 60); // Window in seconds

/**
 * Rate limiting
 */
function checkRateLimit(string $identifier): bool {
    if (!defined('RATE_LIMIT_REQUESTS') || RATE_LIMIT_REQUESTS === 0) {
        return true; // Rate limiting disabled
    }
    
    $cacheKey = 'rate_limit_update_order_' . md5($identifier);
    $cacheFile = sys_get_temp_dir() . '/' . $cacheKey;
    
    $requests = [];
    if (file_exists($cacheFile)) {
        $data = @file_get_contents($cacheFile);
        if ($data) {
            $requests = json_decode($data, true) ?: [];
        }
    }
    
    $now = time();
    $requests = array_filter($requests, function($timestamp) use ($now) {
        return ($now - $timestamp) < RATE_LIMIT_WINDOW;
    });
    
    if (count($requests) >= RATE_LIMIT_REQUESTS) {
        return false;
    }
    
    $requests[] = $now;
    @file_put_contents($cacheFile, json_encode($requests), LOCK_EX);
    
    return true;
}

/**
 * Send standardized error response
 */
function sendError(string $message, int $httpCode = 400, string $errorCode = 'ERROR'): void {
    global $startTime;
    
    // Log error internally
    error_log("[SME180] $errorCode: $message");
    
    http_response_code($httpCode);
    die(json_encode([
        'success' => false,
        'error' => $message,
        'code' => $errorCode,
        'timestamp' => date('c'),
        'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ], JSON_UNESCAPED_UNICODE));
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

// Check rate limiting
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!checkRateLimit($clientIp)) {
    sendError('Too many requests. Please wait before trying again.', 429, 'RATE_LIMIT_EXCEEDED');
}

// Parse and validate input
$rawInput = file_get_contents('php://input');

if (strlen($rawInput) > MAX_REQUEST_SIZE) {
    sendError('Request too large', 413, 'PAYLOAD_TOO_LARGE');
}

if (empty($rawInput)) {
    sendError('Request body is required', 400, 'EMPTY_REQUEST');
}

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid JSON: ' . json_last_error_msg(), 400, 'INVALID_JSON');
}

// Load configuration and database
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';

// Get database connection
try {
    $pdo = db();
    if (!$pdo) {
        throw new Exception('Database connection unavailable');
    }
} catch (Exception $e) {
    sendError('Service temporarily unavailable', 503, 'DATABASE_ERROR');
}

// Authentication and authorization
$tenantId = null;
$branchId = null;
$userId = null;
$authenticated = false;

// Start session
use_backend_session();

// Check API key authentication (for external integrations)
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $input['api_key'] ?? null;
if ($apiKey && $apiKey === API_KEY) {
    // API key authentication - require tenant/branch in request
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : null;
    $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : null;
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
    
    if (!$tenantId || !$branchId) {
        sendError('Tenant and branch IDs required for API key authentication', 400, 'MISSING_CONTEXT');
    }
    
    $authenticated = true;
    error_log("[SME180] API key authentication used for tenant $tenantId");
    
} else {
    // Session authentication (normal web usage)
    $user = auth_user();
    
    if ($user) {
        $authenticated = true;
        $tenantId = auth_get_tenant_id();
        $branchId = auth_get_branch_id();
        $userId = (int)($user['id'] ?? 0);
        
        if (!$tenantId || !$branchId) {
            sendError('Session missing tenant/branch context', 400, 'INVALID_SESSION');
        }
    }
}

// Test mode fallback (ONLY if enabled and not authenticated)
if (!$authenticated && API_TEST_MODE) {
    error_log("[SME180] WARNING: Test mode authentication bypass active");
    
    // Accept tenant/branch from request for testing
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 1;
    $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : 1;
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : null;
    
    // Log test mode usage
    error_log("[SME180] Test mode: tenant=$tenantId, branch=$branchId, user=$userId");
} elseif (!$authenticated) {
    // Production mode - require authentication
    sendError('Authentication required. Please login or provide valid API key.', 401, 'UNAUTHORIZED');
}

// Validate tenant exists
try {
    $stmt = $pdo->prepare("SELECT id, is_active FROM tenants WHERE id = ? LIMIT 1");
    $stmt->execute([$tenantId]);
    $tenant = $stmt->fetch();
    
    if (!$tenant) {
        sendError('Invalid tenant', 400, 'INVALID_TENANT');
    }
    
    if (!$tenant['is_active']) {
        sendError('Tenant account is inactive', 403, 'TENANT_INACTIVE');
    }
} catch (PDOException $e) {
    error_log("[SME180] Tenant validation failed: " . $e->getMessage());
}

// Get or validate user
if (!$userId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM users 
            WHERE tenant_id = ? 
            ORDER BY id ASC 
            LIMIT 1
        ");
        $stmt->execute([$tenantId]);
        $userId = $stmt->fetchColumn();
        
        if (!$userId) {
            sendError('No valid user found for tenant', 400, 'NO_USER');
        }
    } catch (PDOException $e) {
        sendError('User validation failed', 500, 'USER_ERROR');
    }
}

// Extract and validate order data
$orderId = filter_var($input['order_id'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
$updateType = $input['update_type'] ?? 'add_items';

// Validate update type
$validUpdateTypes = ['add_items', 'remove_items', 'update_info', 'update_status'];
if (!in_array($updateType, $validUpdateTypes, true)) {
    sendError('Invalid update type. Must be: ' . implode(', ', $validUpdateTypes), 400, 'INVALID_UPDATE_TYPE');
}

// Find order if not provided (for testing convenience)
if (!$orderId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM orders 
            WHERE tenant_id = :tenant_id 
            AND branch_id = :branch_id
            AND status NOT IN ('completed', 'cancelled')
            AND payment_status = 'unpaid'
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId
        ]);
        $orderId = (int)$stmt->fetchColumn();
        
        if (!$orderId) {
            sendError('No active orders found. Please specify order_id.', 404, 'NO_ACTIVE_ORDERS');
        }
        
        error_log("[SME180] Auto-selected order ID: $orderId");
    } catch (PDOException $e) {
        sendError('Failed to find order', 500, 'ORDER_LOOKUP_FAILED');
    }
}

// Main transaction processing
try {
    // Set transaction isolation level
    $pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
    $pdo->beginTransaction();
    
    // Lock and fetch order
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
        throw new Exception("Order #$orderId not found or access denied");
    }
    
    // Verify branch access
    if ($order['branch_id'] != $branchId && !API_TEST_MODE) {
        throw new Exception("Order belongs to different branch");
    }
    
    // Check if order can be modified
    if (in_array($order['payment_status'], ['paid', 'refunded'], true)) {
        throw new Exception("Cannot modify paid or refunded orders");
    }
    
    if (in_array($order['status'], ['cancelled', 'completed'], true)) {
        throw new Exception("Cannot modify cancelled or completed orders");
    }
    
    $updateMessage = '';
    $changes = [];
    
    // Process based on update type
    switch ($updateType) {
        case 'add_items':
            if (!isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
                throw new Exception('Items array is required and must not be empty');
            }
            
            // Get default product for foreign key constraints
            $defaultProductId = null;
            $needsProductId = false;
            
            // Check if product_id is required
            $colStmt = $pdo->query("SHOW COLUMNS FROM order_items WHERE Field = 'product_id'");
            $colInfo = $colStmt->fetch();
            if ($colInfo && $colInfo['Null'] === 'NO') {
                $needsProductId = true;
                
                // Find or create default product
                $prodStmt = $pdo->prepare("
                    SELECT id FROM products 
                    WHERE tenant_id = :tenant_id 
                    ORDER BY id ASC 
                    LIMIT 1
                ");
                $prodStmt->execute(['tenant_id' => $tenantId]);
                $defaultProductId = $prodStmt->fetchColumn();
                
                if (!$defaultProductId) {
                    // Create default product
                    $createProdStmt = $pdo->prepare("
                        INSERT INTO products (tenant_id, name, price, created_at, updated_at)
                        VALUES (:tenant_id, 'Generic Item', 0, NOW(), NOW())
                    ");
                    $createProdStmt->execute(['tenant_id' => $tenantId]);
                    $defaultProductId = $pdo->lastInsertId();
                    error_log("[SME180] Created default product ID: $defaultProductId");
                }
            }
            
            // Prepare item insert
            $itemStmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id, tenant_id, branch_id, product_id,
                    product_name, quantity, unit_price, line_total,
                    created_at, updated_at
                ) VALUES (
                    :order_id, :tenant_id, :branch_id, :product_id,
                    :product_name, :quantity, :unit_price, :line_total,
                    NOW(), NOW()
                )
            ");
            
            $addedCount = 0;
            $addedTotal = 0.0;
            $errors = [];
            
            foreach ($input['items'] as $index => $item) {
                // Validate item data
                $productId = null;
                if (isset($item['product_id']) && $item['product_id'] > 0) {
                    // Verify product exists
                    $checkStmt = $pdo->prepare("SELECT id FROM products WHERE id = :id AND tenant_id = :tenant_id");
                    $checkStmt->execute(['id' => $item['product_id'], 'tenant_id' => $tenantId]);
                    if ($checkStmt->fetchColumn()) {
                        $productId = (int)$item['product_id'];
                    }
                }
                
                if (!$productId && $needsProductId) {
                    $productId = $defaultProductId;
                }
                
                $productName = substr(trim($item['product_name'] ?? 'Item ' . ($index + 1)), 0, 100);
                $quantity = filter_var($item['quantity'] ?? 0, FILTER_VALIDATE_FLOAT);
                $unitPrice = filter_var($item['unit_price'] ?? 0, FILTER_VALIDATE_FLOAT);
                
                if ($quantity === false || $quantity <= 0 || $quantity > 9999) {
                    $errors[] = "Item " . ($index + 1) . ": Invalid quantity";
                    continue;
                }
                
                if ($unitPrice === false || $unitPrice < 0 || $unitPrice > 999999) {
                    $errors[] = "Item " . ($index + 1) . ": Invalid price";
                    continue;
                }
                
                $lineTotal = round($quantity * $unitPrice, 2);
                
                try {
                    $itemStmt->execute([
                        'order_id' => $orderId,
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'product_id' => $productId,
                        'product_name' => $productName,
                        'quantity' => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal
                    ]);
                    
                    $addedCount++;
                    $addedTotal += $lineTotal;
                } catch (PDOException $e) {
                    $errors[] = "Item " . ($index + 1) . ": Database error";
                    error_log("[SME180] Failed to add item: " . $e->getMessage());
                }
            }
            
            if ($addedCount === 0 && !empty($errors)) {
                throw new Exception('No items could be added: ' . implode('; ', $errors));
            }
            
            $updateMessage = "Added $addedCount items to order";
            $changes = [
                'items_added' => $addedCount,
                'amount_added' => $addedTotal
            ];
            
            if (!empty($errors)) {
                $changes['warnings'] = $errors;
            }
            break;
            
        case 'remove_items':
            if (!isset($input['item_ids']) || !is_array($input['item_ids']) || empty($input['item_ids'])) {
                throw new Exception('item_ids array is required and must not be empty');
            }
            
            $itemIds = array_filter(array_map('intval', $input['item_ids']), function($id) {
                return $id > 0;
            });
            
            if (empty($itemIds)) {
                throw new Exception('No valid item IDs provided');
            }
            
            // Verify items belong to this order
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            $verifyStmt = $pdo->prepare("
                SELECT COUNT(*) FROM order_items 
                WHERE id IN ($placeholders) 
                AND order_id = ?
            ");
            $verifyParams = array_merge($itemIds, [$orderId]);
            $verifyStmt->execute($verifyParams);
            
            $foundCount = (int)$verifyStmt->fetchColumn();
            if ($foundCount !== count($itemIds)) {
                throw new Exception("Some items do not belong to this order");
            }
            
            // Delete items
            $deleteStmt = $pdo->prepare("
                DELETE FROM order_items 
                WHERE id IN ($placeholders) 
                AND order_id = ?
            ");
            $deleteStmt->execute($verifyParams);
            $removedCount = $deleteStmt->rowCount();
            
            $updateMessage = "Removed $removedCount items from order";
            $changes = ['items_removed' => $removedCount];
            break;
            
        case 'update_info':
            $updates = [];
            $params = [];
            
            // Validate and collect updates
            if (array_key_exists('customer_name', $input)) {
                $customerName = substr(trim($input['customer_name']), 0, 100);
                $updates[] = 'customer_name = :customer_name';
                $params['customer_name'] = $customerName ?: null;
            }
            
            if (array_key_exists('customer_phone', $input)) {
                $customerPhone = substr(trim($input['customer_phone']), 0, 20);
                $updates[] = 'customer_phone = :customer_phone';
                $params['customer_phone'] = $customerPhone ?: null;
            }
            
            if (array_key_exists('table_id', $input)) {
                $tableId = filter_var($input['table_id'], FILTER_VALIDATE_INT);
                if ($tableId !== false && $tableId > 0) {
                    $updates[] = 'table_id = :table_id';
                    $params['table_id'] = $tableId;
                }
            }
            
            if (array_key_exists('notes', $input)) {
                $notes = substr(trim($input['notes']), 0, 500);
                $updates[] = 'notes = :notes';
                $params['notes'] = $notes ?: null;
            }
            
            if (empty($updates)) {
                throw new Exception('No valid fields to update');
            }
            
            $updates[] = 'updated_at = NOW()';
            $params['order_id'] = $orderId;
            
            $sql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = :order_id";
            $updateStmt = $pdo->prepare($sql);
            $updateStmt->execute($params);
            
            $updateMessage = 'Order information updated';
            $changes = ['fields_updated' => count($updates) - 1];
            break;
            
        case 'update_status':
            $validStatuses = ['open', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
            $newStatus = $input['status'] ?? null;
            
            if (!in_array($newStatus, $validStatuses, true)) {
                throw new Exception('Invalid status. Must be: ' . implode(', ', $validStatuses));
            }
            
            $statusStmt = $pdo->prepare("
                UPDATE orders 
                SET status = :status, updated_at = NOW() 
                WHERE id = :order_id
            ");
            $statusStmt->execute([
                'status' => $newStatus,
                'order_id' => $orderId
            ]);
            
            $updateMessage = "Order status updated to $newStatus";
            $changes = ['new_status' => $newStatus, 'previous_status' => $order['status']];
            break;
    }
    
    // Recalculate totals if items were modified
    if (in_array($updateType, ['add_items', 'remove_items'], true)) {
        // Get new subtotal
        $sumStmt = $pdo->prepare("
            SELECT COALESCE(SUM(line_total), 0) as subtotal
            FROM order_items 
            WHERE order_id = :order_id
        ");
        $sumStmt->execute(['order_id' => $orderId]);
        $subtotal = (float)$sumStmt->fetchColumn();
        
        // Get tax rate from settings
        $taxRate = 14.0; // Default
        try {
            $taxStmt = $pdo->prepare("
                SELECT `value` FROM settings 
                WHERE tenant_id = :tenant_id 
                AND `key` = 'tax_rate' 
                LIMIT 1
            ");
            $taxStmt->execute(['tenant_id' => $tenantId]);
            $taxValue = $taxStmt->fetchColumn();
            if ($taxValue !== false) {
                $taxRate = (float)$taxValue;
            }
        } catch (PDOException $e) {
            // Use default tax rate
        }
        
        $taxAmount = round($subtotal * ($taxRate / 100), 2);
        $totalAmount = round($subtotal + $taxAmount, 2);
        
        // Update order totals
        $totalsStmt = $pdo->prepare("
            UPDATE orders 
            SET subtotal = :subtotal,
                tax_amount = :tax_amount,
                total_amount = :total_amount,
                updated_at = NOW()
            WHERE id = :order_id
        ");
        $totalsStmt->execute([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'order_id' => $orderId
        ]);
        
        $changes['new_subtotal'] = $subtotal;
        $changes['new_tax'] = $taxAmount;
        $changes['new_total'] = $totalAmount;
    }
    
    // Audit logging
    try {
        // Check if audit table exists
        $auditTableExists = $pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount() > 0;
        
        if ($auditTableExists) {
            $auditStmt = $pdo->prepare("
                INSERT INTO order_logs (
                    order_id, tenant_id, branch_id, user_id,
                    action, details, created_at
                ) VALUES (
                    :order_id, :tenant_id, :branch_id, :user_id,
                    :action, :details, NOW()
                )
            ");
            
            $auditStmt->execute([
                'order_id' => $orderId,
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'action' => 'updated',
                'details' => json_encode([
                    'update_type' => $updateType,
                    'changes' => $changes,
                    'ip' => $clientIp,
                    'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255),
                    'api_mode' => $apiKey ? 'api_key' : ($authenticated ? 'session' : 'test')
                ])
            ]);
        }
    } catch (PDOException $e) {
        // Audit logging is non-critical
        error_log("[SME180] Audit log failed: " . $e->getMessage());
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Fetch updated order details
    $finalStmt = $pdo->prepare("
        SELECT 
            o.*,
            (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count,
            (SELECT COALESCE(SUM(line_total), 0) FROM order_items WHERE order_id = o.id) as items_total
        FROM orders o
        WHERE o.id = :order_id
    ");
    $finalStmt->execute(['order_id' => $orderId]);
    $updatedOrder = $finalStmt->fetch(PDO::FETCH_ASSOC);
    
    // Fetch order items
    $itemsStmt = $pdo->prepare("
        SELECT 
            id, product_id, product_name,
            quantity, unit_price, line_total,
            created_at, updated_at
        FROM order_items 
        WHERE order_id = :order_id
        ORDER BY id DESC
        LIMIT 50
    ");
    $itemsStmt->execute(['order_id' => $orderId]);
    $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Prepare response
    $response = [
        'message' => $updateMessage,
        'update_type' => $updateType,
        'order' => [
            'id' => (int)$updatedOrder['id'],
            'receipt_reference' => $updatedOrder['receipt_reference'],
            'status' => $updatedOrder['status'],
            'payment_status' => $updatedOrder['payment_status'],
            'order_type' => $updatedOrder['order_type'] ?? 'dine_in',
            'customer_name' => $updatedOrder['customer_name'],
            'customer_phone' => $updatedOrder['customer_phone'],
            'table_id' => $updatedOrder['table_id'] ? (int)$updatedOrder['table_id'] : null,
            'subtotal' => (float)$updatedOrder['subtotal'],
            'tax_amount' => (float)$updatedOrder['tax_amount'],
            'total_amount' => (float)$updatedOrder['total_amount'],
            'item_count' => (int)$updatedOrder['item_count'],
            'notes' => $updatedOrder['notes'],
            'created_at' => $updatedOrder['created_at'],
            'updated_at' => $updatedOrder['updated_at'],
            'items' => array_map(function($item) {
                return [
                    'id' => (int)$item['id'],
                    'product_id' => $item['product_id'] ? (int)$item['product_id'] : null,
                    'product_name' => $item['product_name'],
                    'quantity' => (float)$item['quantity'],
                    'unit_price' => (float)$item['unit_price'],
                    'line_total' => (float)$item['line_total']
                ];
            }, $items)
        ]
    ];
    
    if (!empty($changes)) {
        $response['changes'] = $changes;
    }
    
    sendSuccess($response);
    
} catch (PDOException $e) {
    // Database error - rollback
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("[SME180] Database error in update order: " . $e->getMessage());
    
    // Determine appropriate error message
    if (strpos($e->getMessage(), 'foreign key constraint') !== false) {
        sendError('Invalid product or reference', 400, 'FOREIGN_KEY_ERROR');
    } elseif (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        sendError('Duplicate entry detected', 409, 'DUPLICATE_ENTRY');
    } else {
        sendError('Database operation failed', 500, 'DATABASE_ERROR');
    }
    
} catch (Exception $e) {
    // Application error - rollback
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $errorMsg = $e->getMessage();
    error_log("[SME180] Update order error: $errorMsg");
    
    // Return appropriate error
    if (strpos($errorMsg, 'not found') !== false) {
        sendError($errorMsg, 404, 'ORDER_NOT_FOUND');
    } elseif (strpos($errorMsg, 'different branch') !== false) {
        sendError('Access denied: ' . $errorMsg, 403, 'ACCESS_DENIED');
    } elseif (strpos($errorMsg, 'Cannot modify') !== false) {
        sendError($errorMsg, 400, 'ORDER_LOCKED');
    } elseif (strpos($errorMsg, 'required') !== false || strpos($errorMsg, 'Invalid') !== false) {
        sendError($errorMsg, 400, 'VALIDATION_ERROR');
    } else {
        sendError('Failed to update order', 500, 'UPDATE_FAILED');
    }
}
?>