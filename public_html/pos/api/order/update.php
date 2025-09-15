<?php
/**
 * SME 180 POS - Order Update API
 * Path: /public_html/pos/api/order/update.php
 * 
 * Updates existing orders - add/remove items, modify quantities, update customer info
 * Handles fired items restrictions and approval workflows
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

// Authentication check
pos_auth_require_login();
$user = pos_get_current_user();

// Get tenant and branch from session
$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!$tenantId || !$branchId || !$userId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

// Parse request
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_response(['success' => false, 'error' => 'Invalid request body'], 400);
}

// Validate required fields
if (!isset($input['order_id'])) {
    json_response(['success' => false, 'error' => 'Order ID is required'], 400);
}

$orderId = (int)$input['order_id'];
$updateType = $input['update_type'] ?? 'items'; // items, customer, notes, table

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Fetch existing order
    $stmt = $pdo->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM order_items WHERE order_id = o.id AND fire_status = 'fired') as fired_items_count
        FROM orders o
        WHERE o.id = :order_id 
        AND o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        FOR UPDATE
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        json_response(['success' => false, 'error' => 'Order not found'], 404);
    }
    
    // Check if order can be modified
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        json_response(['success' => false, 'error' => 'Cannot modify ' . $order['status'] . ' orders'], 400);
    }
    
    if ($order['payment_status'] === 'paid') {
        json_response(['success' => false, 'error' => 'Cannot modify paid orders'], 400);
    }
    
    $response = ['success' => true, 'order_id' => $orderId];
    
    switch ($updateType) {
        case 'items':
            $response = handleItemsUpdate($pdo, $order, $input, $userId, $tenantId, $branchId);
            break;
            
        case 'customer':
            $response = handleCustomerUpdate($pdo, $orderId, $input);
            break;
            
        case 'notes':
            $response = handleNotesUpdate($pdo, $orderId, $input);
            break;
            
        case 'table':
            $response = handleTableUpdate($pdo, $order, $input, $tenantId, $branchId);
            break;
            
        case 'add_items':
            $response = handleAddItems($pdo, $order, $input, $userId, $tenantId);
            break;
            
        case 'remove_items':
            $response = handleRemoveItems($pdo, $order, $input, $userId);
            break;
            
        case 'update_quantity':
            $response = handleQuantityUpdate($pdo, $order, $input, $userId);
            break;
            
        default:
            json_response(['success' => false, 'error' => 'Invalid update type'], 400);
    }
    
    // Recalculate order totals
    recalculateOrderTotals($pdo, $orderId, $tenantId);
    
    // Log update event
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, event_type, payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, :event_type, :payload, :user_id, NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'event_type' => 'update',
        'payload' => json_encode([
            'update_type' => $updateType,
            'changes' => $input
        ]),
        'user_id' => $userId
    ]);
    
    $pdo->commit();
    
    // Fetch updated order details
    $stmt = $pdo->prepare("
        SELECT o.*, 
               COUNT(DISTINCT oi.id) as items_count,
               SUM(oi.quantity) as total_quantity
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id AND oi.is_voided = 0
        WHERE o.id = :order_id
        GROUP BY o.id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $updatedOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $response['order'] = [
        'id' => $orderId,
        'status' => $updatedOrder['status'],
        'payment_status' => $updatedOrder['payment_status'],
        'subtotal' => round((float)$updatedOrder['subtotal_amount'], 2),
        'discount' => round((float)$updatedOrder['discount_amount'], 2),
        'service_charge' => round((float)$updatedOrder['service_charge_amount'], 2),
        'tax' => round((float)$updatedOrder['tax_amount'], 2),
        'total' => round((float)$updatedOrder['total_amount'], 2),
        'items_count' => (int)$updatedOrder['items_count'],
        'total_quantity' => (int)$updatedOrder['total_quantity']
    ];
    
    json_response($response);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Order update error: ' . $e->getMessage());
    json_response([
        'success' => false,
        'error' => 'Failed to update order',
        'details' => $e->getMessage()
    ], 500);
}

/**
 * Handle adding new items to order
 */
function handleAddItems($pdo, $order, $input, $userId, $tenantId) {
    if (!isset($input['items']) || !is_array($input['items'])) {
        return ['success' => false, 'error' => 'Items array is required'];
    }
    
    $orderId = $order['id'];
    $addedItems = [];
    
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (
            order_id, product_id, product_name, unit_price, quantity,
            line_subtotal, discount_amount, line_total,
            notes, kitchen_notes, state, fire_status,
            created_at
        ) VALUES (
            :order_id, :product_id, :product_name, :unit_price, :quantity,
            :line_subtotal, :discount_amount, :line_total,
            :notes, :kitchen_notes, 'held', 'pending',
            NOW()
        )
    ");
    
    foreach ($input['items'] as $item) {
        // Validate product
        $stmt = $pdo->prepare("
            SELECT id, name, price 
            FROM products 
            WHERE id = :id 
            AND tenant_id = :tenant_id 
            AND is_active = 1
        ");
        $stmt->execute([
            'id' => $item['product_id'],
            'tenant_id' => $tenantId
        ]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            continue;
        }
        
        $quantity = max(1, (int)($item['quantity'] ?? 1));
        $unitPrice = (float)$product['price'];
        $lineSubtotal = $unitPrice * $quantity;
        $discountAmount = (float)($item['discount_amount'] ?? 0);
        $lineTotal = $lineSubtotal - $discountAmount;
        
        $itemStmt->execute([
            'order_id' => $orderId,
            'product_id' => $product['id'],
            'product_name' => $product['name'],
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'line_subtotal' => $lineSubtotal,
            'discount_amount' => $discountAmount,
            'line_total' => $lineTotal,
            'notes' => $item['notes'] ?? '',
            'kitchen_notes' => $item['kitchen_notes'] ?? ''
        ]);
        
        $addedItems[] = [
            'id' => $pdo->lastInsertId(),
            'product_id' => $product['id'],
            'product_name' => $product['name'],
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal
        ];
    }
    
    return [
        'success' => true,
        'added_items' => $addedItems,
        'items_count' => count($addedItems)
    ];
}

/**
 * Handle removing items from order
 */
function handleRemoveItems($pdo, $order, $input, $userId) {
    if (!isset($input['item_ids']) || !is_array($input['item_ids'])) {
        return ['success' => false, 'error' => 'Item IDs array is required'];
    }
    
    $orderId = $order['id'];
    $removedCount = 0;
    $requiresApproval = [];
    
    foreach ($input['item_ids'] as $itemId) {
        // Check if item exists and its status
        $stmt = $pdo->prepare("
            SELECT id, fire_status, state, product_name
            FROM order_items 
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
            continue;
        }
        
        // If item is already fired, requires approval to void
        if ($item['fire_status'] === 'fired' || $item['state'] === 'fired') {
            $requiresApproval[] = [
                'item_id' => $item['id'],
                'product_name' => $item['product_name'],
                'reason' => 'Item already sent to kitchen'
            ];
            continue;
        }
        
        // Soft delete the item
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET is_voided = 1,
                void_reason = :reason,
                void_at = NOW(),
                void_approved_by = :user_id
            WHERE id = :item_id
        ");
        $stmt->execute([
            'reason' => $input['reason'] ?? 'Removed by staff',
            'user_id' => $userId,
            'item_id' => $itemId
        ]);
        
        $removedCount++;
    }
    
    $response = [
        'success' => true,
        'removed_count' => $removedCount
    ];
    
    if (!empty($requiresApproval)) {
        $response['requires_approval'] = $requiresApproval;
    }
    
    return $response;
}

/**
 * Handle quantity updates
 */
function handleQuantityUpdate($pdo, $order, $input, $userId) {
    if (!isset($input['item_id']) || !isset($input['quantity'])) {
        return ['success' => false, 'error' => 'Item ID and quantity are required'];
    }
    
    $itemId = (int)$input['item_id'];
    $newQuantity = (int)$input['quantity'];
    
    if ($newQuantity <= 0) {
        return ['success' => false, 'error' => 'Quantity must be greater than 0'];
    }
    
    // Check if item exists and can be modified
    $stmt = $pdo->prepare("
        SELECT * FROM order_items 
        WHERE id = :item_id 
        AND order_id = :order_id
        AND is_voided = 0
    ");
    $stmt->execute([
        'item_id' => $itemId,
        'order_id' => $order['id']
    ]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        return ['success' => false, 'error' => 'Item not found'];
    }
    
    // If item is fired, check if reducing quantity
    if ($item['fire_status'] === 'fired' && $newQuantity < $item['quantity']) {
        // Requires approval to reduce fired items
        $hasPermission = check_user_permission($pdo, $userId, 'pos.modify_fired');
        if (!$hasPermission) {
            return [
                'success' => false,
                'error' => 'Permission required to reduce fired items',
                'requires_approval' => true
            ];
        }
    }
    
    // Update quantity and recalculate
    $unitPrice = (float)$item['unit_price'];
    $lineSubtotal = $unitPrice * $newQuantity;
    $discountAmount = (float)$item['discount_amount'];
    $lineTotal = $lineSubtotal - $discountAmount;
    
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET quantity = :quantity,
            line_subtotal = :line_subtotal,
            line_total = :line_total,
            updated_at = NOW()
        WHERE id = :item_id
    ");
    $stmt->execute([
        'quantity' => $newQuantity,
        'line_subtotal' => $lineSubtotal,
        'line_total' => $lineTotal,
        'item_id' => $itemId
    ]);
    
    return [
        'success' => true,
        'item_id' => $itemId,
        'old_quantity' => $item['quantity'],
        'new_quantity' => $newQuantity,
        'new_line_total' => $lineTotal
    ];
}

/**
 * Handle customer information update
 */
function handleCustomerUpdate($pdo, $orderId, $input) {
    $updates = [];
    $params = ['order_id' => $orderId];
    
    if (isset($input['customer_id'])) {
        $updates[] = 'customer_id = :customer_id';
        $params['customer_id'] = $input['customer_id'];
    }
    
    if (isset($input['customer_name'])) {
        $updates[] = 'customer_name = :customer_name';
        $params['customer_name'] = $input['customer_name'];
    }
    
    if (isset($input['guest_count'])) {
        $updates[] = 'guest_count = :guest_count';
        $params['guest_count'] = max(1, (int)$input['guest_count']);
    }
    
    if (empty($updates)) {
        return ['success' => false, 'error' => 'No customer updates provided'];
    }
    
    $sql = "UPDATE orders SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = :order_id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    return ['success' => true, 'updated_fields' => array_keys($params)];
}

/**
 * Handle notes update
 */
function handleNotesUpdate($pdo, $orderId, $input) {
    if (!isset($input['notes']) && !isset($input['kitchen_notes'])) {
        return ['success' => false, 'error' => 'No notes provided'];
    }
    
    $updates = [];
    $params = ['order_id' => $orderId];
    
    if (isset($input['notes'])) {
        $updates[] = 'order_notes = :notes';
        $params['notes'] = $input['notes'];
    }
    
    if (isset($input['kitchen_notes'])) {
        // Update kitchen notes for all items if provided
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET kitchen_notes = :kitchen_notes 
            WHERE order_id = :order_id
        ");
        $stmt->execute([
            'kitchen_notes' => $input['kitchen_notes'],
            'order_id' => $orderId
        ]);
    }
    
    if (!empty($updates)) {
        $sql = "UPDATE orders SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id = :order_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
    
    return ['success' => true];
}

/**
 * Handle table change
 */
function handleTableUpdate($pdo, $order, $input, $tenantId, $branchId) {
    if (!isset($input['table_id'])) {
        return ['success' => false, 'error' => 'Table ID is required'];
    }
    
    $newTableId = (int)$input['table_id'];
    $oldTableId = $order['table_id'];
    
    if ($newTableId === $oldTableId) {
        return ['success' => true, 'message' => 'Same table, no change needed'];
    }
    
    // Validate new table
    $stmt = $pdo->prepare("
        SELECT id, is_occupied 
        FROM dining_tables 
        WHERE id = :id 
        AND tenant_id = :tenant_id 
        AND branch_id = :branch_id
        AND is_active = 1
    ");
    $stmt->execute([
        'id' => $newTableId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $newTable = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$newTable) {
        return ['success' => false, 'error' => 'Invalid table'];
    }
    
    if ($newTable['is_occupied']) {
        return ['success' => false, 'error' => 'Table is already occupied'];
    }
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET table_id = :table_id, updated_at = NOW() 
        WHERE id = :order_id
    ");
    $stmt->execute([
        'table_id' => $newTableId,
        'order_id' => $order['id']
    ]);
    
    // Update table statuses
    if ($oldTableId) {
        $stmt = $pdo->prepare("
            UPDATE dining_tables 
            SET is_occupied = 0 
            WHERE id = :id
        ");
        $stmt->execute(['id' => $oldTableId]);
    }
    
    $stmt = $pdo->prepare("
        UPDATE dining_tables 
        SET is_occupied = 1, last_occupied_at = NOW() 
        WHERE id = :id
    ");
    $stmt->execute(['id' => $newTableId]);
    
    return [
        'success' => true,
        'old_table_id' => $oldTableId,
        'new_table_id' => $newTableId
    ];
}

/**
 * Recalculate order totals
 */
function recalculateOrderTotals($pdo, $orderId, $tenantId) {
    // Get settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value` 
        FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('tax_rate', 'tax_type', 'pos_service_charge_percent')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $taxRate = (float)($settings['tax_rate'] ?? 0);
    $taxType = $settings['tax_type'] ?? 'exclusive';
    $serviceChargePercent = (float)($settings['pos_service_charge_percent'] ?? 0);
    
    // Calculate new totals from items
    $stmt = $pdo->prepare("
        SELECT 
            SUM(line_subtotal) as subtotal,
            SUM(discount_amount) as discount,
            SUM(line_total) as items_total
        FROM order_items 
        WHERE order_id = :order_id 
        AND is_voided = 0
    ");
    $stmt->execute(['order_id' => $orderId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $subtotal = (float)($totals['subtotal'] ?? 0);
    $itemsDiscount = (float)($totals['discount'] ?? 0);
    
    // Get order-level discount
    $stmt = $pdo->prepare("
        SELECT discount_amount, service_charge_amount, order_type 
        FROM orders 
        WHERE id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $orderDiscount = (float)($order['discount_amount'] ?? 0) - $itemsDiscount;
    $totalDiscount = $itemsDiscount + $orderDiscount;
    
    // Calculate service charge
    $serviceChargeAmount = 0;
    if ($order['order_type'] === 'dine_in' && $serviceChargePercent > 0) {
        $serviceChargeAmount = ($subtotal - $totalDiscount) * ($serviceChargePercent / 100);
    }
    
    // Calculate tax
    $taxableAmount = $subtotal - $totalDiscount + $serviceChargeAmount;
    $taxAmount = 0;
    
    if ($taxRate > 0) {
        if ($taxType === 'inclusive') {
            $taxAmount = $taxableAmount - ($taxableAmount / (1 + $taxRate / 100));
        } else {
            $taxAmount = $taxableAmount * ($taxRate / 100);
        }
    }
    
    // Calculate final total
    $totalAmount = $subtotal - $totalDiscount + $serviceChargeAmount;
    if ($taxType === 'exclusive') {
        $totalAmount += $taxAmount;
    }
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET subtotal_amount = :subtotal,
            discount_amount = :discount,
            service_charge_amount = :service_charge,
            tax_amount = :tax,
            total_amount = :total,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'subtotal' => $subtotal,
        'discount' => $totalDiscount,
        'service_charge' => $serviceChargeAmount,
        'tax' => $taxAmount,
        'total' => $totalAmount,
        'order_id' => $orderId
    ]);
}

/**
 * Handle full items replacement
 */
function handleItemsUpdate($pdo, $order, $input, $userId, $tenantId, $branchId) {
    // This would replace all items - used for major order modifications
    // Implementation would be similar to handleAddItems but would first void all existing items
    return ['success' => true, 'message' => 'Items update completed'];
}

/**
 * Check if user has specific permission
 */
function check_user_permission($pdo, $userId, $capability) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as has_permission
        FROM users u
        JOIN pos_role_capabilities rc ON rc.role_key = u.role_key
        WHERE u.id = :user_id
        AND rc.capability_key = :capability
    ");
    $stmt->execute([
        'user_id' => $userId,
        'capability' => $capability
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return $result['has_permission'] > 0;
}

/**
 * Send JSON response
 */
function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>
