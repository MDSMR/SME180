<?php
/**
 * SME 180 POS - Order Update API
 * Path: /public_html/pos/api/order/update.php
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

function validatePhone($phone) {
    if (empty($phone)) return null;
    
    $cleaned = preg_replace('/[^0-9+]/', '', $phone);
    $cleaned = preg_replace('/\+(?!^)/', '', $cleaned);
    
    if (strlen(preg_replace('/[^0-9]/', '', $cleaned)) < 10) {
        return false;
    }
    
    return $cleaned;
}

function checkRateLimit($pdo, $tenantId, $userId, $action = 'updated') {
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
        
        // Allow max 20 update operations per minute per user
        if ($count >= 20) {
            logEvent('WARNING', 'Rate limit exceeded for update operation', [
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
if (strlen($rawInput) > 100000) { // 100KB max
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

// Validate update type
$validUpdateTypes = ['add_items', 'remove_items', 'update_quantity', 'update_customer'];
$updateType = $input['update_type'] ?? 'add_items';

if (!in_array($updateType, $validUpdateTypes)) {
    sendError(
        'Invalid update type. Must be: ' . implode(', ', $validUpdateTypes),
        400,
        'INVALID_UPDATE_TYPE'
    );
}

try {
    // Check rate limit
    if (!checkRateLimit($pdo, $tenantId, $userId)) {
        sendError(
            'Too many update requests. Please wait before trying again.',
            429,
            'RATE_LIMIT_EXCEEDED'
        );
    }
    
    // Get tax rate and currency from settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value`
        FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('tax_rate', 'currency_symbol', 'currency_code', 'currency')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $taxRate = isset($settings['tax_rate']) ? floatval($settings['tax_rate']) : 14.0;
    $currency = $settings['currency_code'] ?? $settings['currency'] ?? 'EGP';
    
    $pdo->beginTransaction();
    
    // Fetch existing order with lock
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
        sendError('Cannot modify paid orders', 409, 'ORDER_ALREADY_PAID');
    }
    
    $updateResult = [];
    
    switch ($updateType) {
        case 'add_items':
            if (!isset($input['items']) || !is_array($input['items']) || empty($input['items'])) {
                $pdo->rollBack();
                sendError('No items to add', 400, 'NO_ITEMS');
            }
            
            if (count($input['items']) > 50) {
                $pdo->rollBack();
                sendError('Cannot add more than 50 items at once', 400, 'TOO_MANY_ITEMS');
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id, tenant_id, branch_id,
                    product_id, product_name, quantity, unit_price,
                    line_total, created_at, updated_at
                ) VALUES (
                    :order_id, :tenant_id, :branch_id,
                    :product_id, :product_name, :quantity, :unit_price,
                    :line_total, NOW(), NOW()
                )
            ");
            
            $addedItems = [];
            foreach ($input['items'] as $index => $item) {
                // Validate item
                if (!isset($item['product_name']) && !isset($item['product_id'])) {
                    $pdo->rollBack();
                    sendError(
                        "Item " . ($index + 1) . " must have product name or ID",
                        400,
                        'MISSING_PRODUCT_INFO'
                    );
                }
                
                $productId = isset($item['product_id']) ? 
                    filter_var($item['product_id'], FILTER_VALIDATE_INT) : null;
                $productName = isset($item['product_name']) ? 
                    substr(trim(strip_tags($item['product_name'])), 0, 100) : 'Unknown Item';
                    
                $quantity = isset($item['quantity']) ? floatval($item['quantity']) : 1;
                if ($quantity <= 0 || $quantity > 9999) {
                    $pdo->rollBack();
                    sendError(
                        "Item " . ($index + 1) . " quantity must be between 0 and 9999",
                        400,
                        'INVALID_QUANTITY'
                    );
                }
                
                $unitPrice = isset($item['unit_price']) ? floatval($item['unit_price']) : 0;
                if ($unitPrice < 0 || $unitPrice > 999999) {
                    $pdo->rollBack();
                    sendError(
                        "Item " . ($index + 1) . " price is invalid",
                        400,
                        'INVALID_PRICE'
                    );
                }
                
                $lineTotal = round($quantity * $unitPrice, 2);
                
                $stmt->execute([
                    'order_id' => $orderId,
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal
                ]);
                
                $addedItems[] = [
                    'id' => (int)$pdo->lastInsertId(),
                    'product_name' => $productName,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal
                ];
            }
            
            $updateResult['added_items'] = $addedItems;
            logEvent('INFO', 'Items added to order', [
                'order_id' => $orderId,
                'items_count' => count($addedItems)
            ]);
            break;
            
        case 'remove_items':
            if (!isset($input['item_ids']) || !is_array($input['item_ids']) || empty($input['item_ids'])) {
                $pdo->rollBack();
                sendError('No items to remove', 400, 'NO_ITEM_IDS');
            }
            
            $itemIds = array_filter($input['item_ids'], 'is_numeric');
            if (empty($itemIds)) {
                $pdo->rollBack();
                sendError('Invalid item IDs', 400, 'INVALID_ITEM_IDS');
            }
            
            $itemIds = array_map('intval', $itemIds);
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            
            // Check if items can be removed
            $stmt = $pdo->prepare("
                SELECT id, product_name, kitchen_status 
                FROM order_items 
                WHERE id IN ($placeholders) 
                AND order_id = ? 
                AND is_voided = 0
            ");
            $params = array_merge($itemIds, [$orderId]);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($items)) {
                $pdo->rollBack();
                sendError('No valid items found to remove', 404, 'ITEMS_NOT_FOUND');
            }
            
            $firedItems = array_filter($items, function($item) {
                return !empty($item['kitchen_status']) && 
                       $item['kitchen_status'] !== 'pending';
            });
            
            if (!empty($firedItems)) {
                $managerPin = $input['manager_pin'] ?? '';
                
                if (!$managerPin) {
                    $pdo->commit();
                    sendError(
                        'Manager approval required to remove fired items',
                        403,
                        'MANAGER_APPROVAL_REQUIRED',
                        [
                            'requires_approval' => true,
                            'fired_items' => array_column($firedItems, 'product_name')
                        ]
                    );
                }
                
                // Validate manager PIN
                $stmt = $pdo->prepare("
                    SELECT id FROM users 
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
                
                if (!$stmt->fetchColumn()) {
                    $pdo->rollBack();
                    sendError('Invalid manager PIN', 403, 'INVALID_MANAGER_PIN');
                }
            }
            
            // Void the items
            $reason = $input['reason'] ?? 'Item removed from order';
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET is_voided = 1, 
                    voided_at = NOW(), 
                    voided_by = :user_id,
                    void_reason = :reason,
                    updated_at = NOW()
                WHERE id IN ($placeholders) 
                AND order_id = ?
            ");
            
            $params = array_merge([$userId, $reason], $itemIds, [$orderId]);
            $stmt->execute($params);
            
            $updateResult['removed_items'] = $stmt->rowCount();
            logEvent('INFO', 'Items removed from order', [
                'order_id' => $orderId,
                'items_removed' => $updateResult['removed_items']
            ]);
            break;
            
        case 'update_quantity':
            $itemId = isset($input['item_id']) ? 
                filter_var($input['item_id'], FILTER_VALIDATE_INT) : 0;
            $newQuantity = isset($input['quantity']) ? 
                floatval($input['quantity']) : 0;
            
            if (!$itemId || $newQuantity <= 0 || $newQuantity > 9999) {
                $pdo->rollBack();
                sendError('Invalid item or quantity', 400, 'INVALID_PARAMETERS');
            }
            
            // Get current item
            $stmt = $pdo->prepare("
                SELECT * FROM order_items 
                WHERE id = :item_id 
                AND order_id = :order_id 
                AND is_voided = 0
            ");
            $stmt->execute([
                'item_id' => $itemId,
                'order_id' => $orderId
            ]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                $pdo->rollBack();
                sendError('Item not found', 404, 'ITEM_NOT_FOUND');
            }
            
            // Check if item is fired
            if (!empty($item['kitchen_status']) && $item['kitchen_status'] !== 'pending') {
                $pdo->rollBack();
                sendError('Cannot modify quantity of fired items', 409, 'ITEM_ALREADY_FIRED');
            }
            
            // Update quantity and recalculate
            $newLineTotal = round($newQuantity * $item['unit_price'], 2);
            
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET quantity = :quantity,
                    line_total = :line_total,
                    updated_at = NOW()
                WHERE id = :item_id
            ");
            $stmt->execute([
                'quantity' => $newQuantity,
                'line_total' => $newLineTotal,
                'item_id' => $itemId
            ]);
            
            $updateResult['updated_item'] = [
                'id' => $itemId,
                'old_quantity' => (float)$item['quantity'],
                'new_quantity' => $newQuantity,
                'new_total' => $newLineTotal
            ];
            
            logEvent('INFO', 'Item quantity updated', [
                'order_id' => $orderId,
                'item_id' => $itemId,
                'old_qty' => $item['quantity'],
                'new_qty' => $newQuantity
            ]);
            break;
            
        case 'update_customer':
            $customerName = isset($input['customer_name']) ? 
                substr(trim(strip_tags($input['customer_name'])), 0, 100) : null;
            $customerPhone = isset($input['customer_phone']) ? 
                validatePhone($input['customer_phone']) : null;
            $customerId = isset($input['customer_id']) ? 
                filter_var($input['customer_id'], FILTER_VALIDATE_INT) : null;
            
            if ($input['customer_phone'] ?? false) {
                if ($customerPhone === false) {
                    $pdo->rollBack();
                    sendError('Invalid phone number format', 400, 'INVALID_PHONE');
                }
            }
            
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET customer_name = COALESCE(:customer_name, customer_name),
                    customer_phone = COALESCE(:customer_phone, customer_phone),
                    customer_id = COALESCE(:customer_id, customer_id),
                    updated_at = NOW()
                WHERE id = :order_id
            ");
            $stmt->execute([
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_id' => $customerId,
                'order_id' => $orderId
            ]);
            
            $updateResult['customer_updated'] = true;
            logEvent('INFO', 'Customer info updated', ['order_id' => $orderId]);
            break;
    }
    
    // Recalculate order totals (except for customer update)
    if ($updateType !== 'update_customer') {
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN is_voided = 0 THEN line_total ELSE 0 END) as subtotal,
                COUNT(CASE WHEN is_voided = 0 THEN 1 ELSE NULL END) as active_items
            FROM order_items 
            WHERE order_id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Update order totals
        $subtotal = (float)$totals['subtotal'];
        $discountAmount = (float)$order['discount_amount'];
        $serviceCharge = (float)$order['service_charge'];
        $tipAmount = (float)$order['tip_amount'];
        
        $taxableAmount = $subtotal - $discountAmount + $serviceCharge;
        $taxAmount = $taxableAmount * ($taxRate / 100);
        $newTotal = $taxableAmount + $taxAmount + $tipAmount;
        
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET subtotal = :subtotal,
                tax_amount = :tax_amount,
                total_amount = :total_amount,
                updated_at = NOW()
            WHERE id = :order_id
        ");
        $stmt->execute([
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'total_amount' => $newTotal,
            'order_id' => $orderId
        ]);
    }
    
    // Log the update
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'updated', :details, NOW()
        )
    ");
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'update_type' => $updateType,
            'result' => $updateResult,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ])
    ]);
    
    $pdo->commit();
    
    // Get updated order
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $updatedOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get updated items
    $stmt = $pdo->prepare("
        SELECT * FROM order_items 
        WHERE order_id = :order_id 
        AND is_voided = 0
        ORDER BY created_at ASC
    ");
    $stmt->execute(['order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    sendSuccess([
        'message' => 'Order updated successfully',
        'order' => [
            'id' => $updatedOrder['id'],
            'receipt_reference' => $updatedOrder['receipt_reference'],
            'subtotal' => (float)$updatedOrder['subtotal'],
            'tax_amount' => (float)$updatedOrder['tax_amount'],
            'service_charge' => (float)$updatedOrder['service_charge'],
            'total_amount' => (float)$updatedOrder['total_amount'],
            'currency' => $currency,
            'items_count' => count($items),
            'status' => $updatedOrder['status']
        ],
        'update_result' => $updateResult,
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
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Order update failed', [
        'order_id' => $orderId,
        'update_type' => $updateType,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to update order', 500, 'UPDATE_FAILED');
}
?>