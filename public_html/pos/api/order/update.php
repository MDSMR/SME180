<?php
/**
 * SME 180 POS - Order Update API
 * Path: /public_html/pos/api/order/update.php
 * 
 * Updates existing orders - add/remove items, modify quantities, update customer info
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function for JSON responses
function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Authentication check
    pos_auth_require_login();
    $user = pos_get_current_user();
    
    if (!$user) {
        json_response(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
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
    $updateType = $input['update_type'] ?? 'add_items'; // add_items, remove_items, update_quantity, update_customer
    
    // Get database connection
    $pdo = db();
    $pdo->beginTransaction();
    
    // Fetch existing order
    $stmt = $pdo->prepare("
        SELECT o.*, 
               (SELECT COUNT(*) FROM order_items WHERE order_id = o.id AND kitchen_status != 'pending') as fired_items_count
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
    
    // Get tax rate for calculations
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'pos_tax_rate' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $taxRate = (float)($stmt->fetchColumn() ?: 0);
    
    $updateResult = [];
    
    switch ($updateType) {
        case 'add_items':
            if (empty($input['items'])) {
                json_response(['success' => false, 'error' => 'No items to add'], 400);
            }
            
            $stmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id, tenant_id, branch_id,
                    product_id, product_name, quantity, unit_price,
                    subtotal, tax_amount, total_amount,
                    notes, kitchen_status, created_at
                ) VALUES (
                    :order_id, :tenant_id, :branch_id,
                    :product_id, :product_name, :quantity, :unit_price,
                    :subtotal, :tax_amount, :total_amount,
                    :notes, 'pending', NOW()
                )
            ");
            
            $addedItems = [];
            foreach ($input['items'] as $item) {
                $productId = isset($item['product_id']) ? (int)$item['product_id'] : null;
                $productName = $item['product_name'] ?? 'Unknown Item';
                $quantity = (float)($item['quantity'] ?? 1);
                $unitPrice = (float)($item['unit_price'] ?? 0);
                $itemSubtotal = $quantity * $unitPrice;
                $itemTax = $taxRate > 0 ? ($itemSubtotal * $taxRate / 100) : 0;
                $itemTotal = $itemSubtotal + $itemTax;
                
                $stmt->execute([
                    'order_id' => $orderId,
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'product_id' => $productId,
                    'product_name' => $productName,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'subtotal' => $itemSubtotal,
                    'tax_amount' => $itemTax,
                    'total_amount' => $itemTotal,
                    'notes' => $item['notes'] ?? ''
                ]);
                
                $addedItems[] = [
                    'id' => (int)$pdo->lastInsertId(),
                    'product_name' => $productName,
                    'quantity' => $quantity,
                    'total' => $itemTotal
                ];
            }
            $updateResult['added_items'] = $addedItems;
            break;
            
        case 'remove_items':
            if (empty($input['item_ids'])) {
                json_response(['success' => false, 'error' => 'No items to remove'], 400);
            }
            
            // Check if items can be removed (not fired)
            $itemIds = array_map('intval', $input['item_ids']);
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            
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
            
            $firedItems = array_filter($items, function($item) {
                return $item['kitchen_status'] !== 'pending';
            });
            
            if (!empty($firedItems)) {
                // Need manager approval for fired items
                $requiresApproval = true;
                $managerPin = $input['manager_pin'] ?? '';
                
                if (!$managerPin) {
                    json_response([
                        'success' => false, 
                        'error' => 'Manager approval required to remove fired items',
                        'requires_approval' => true,
                        'fired_items' => array_column($firedItems, 'product_name')
                    ], 403);
                }
                
                // Validate manager PIN
                $stmt = $pdo->prepare("
                    SELECT id FROM users 
                    WHERE tenant_id = :tenant_id 
                    AND pin = :pin 
                    AND role IN ('admin', 'manager')
                    AND is_active = 1
                    LIMIT 1
                ");
                $stmt->execute([
                    'tenant_id' => $tenantId,
                    'pin' => hash('sha256', $managerPin)
                ]);
                
                if (!$stmt->fetchColumn()) {
                    json_response(['success' => false, 'error' => 'Invalid manager PIN'], 403);
                }
            }
            
            // Void the items instead of deleting
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET is_voided = 1, 
                    voided_at = NOW(), 
                    voided_by = :user_id,
                    void_reason = :reason
                WHERE id IN ($placeholders) 
                AND order_id = ?
            ");
            
            $reason = $input['reason'] ?? 'Item removed from order';
            $params = array_merge([$userId, $reason], $itemIds, [$orderId]);
            $stmt->execute($params);
            
            $updateResult['removed_items'] = count($itemIds);
            break;
            
        case 'update_quantity':
            $itemId = (int)($input['item_id'] ?? 0);
            $newQuantity = (float)($input['quantity'] ?? 0);
            
            if (!$itemId || $newQuantity <= 0) {
                json_response(['success' => false, 'error' => 'Invalid item or quantity'], 400);
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
                json_response(['success' => false, 'error' => 'Item not found'], 404);
            }
            
            // Check if item is fired
            if ($item['kitchen_status'] !== 'pending') {
                json_response(['success' => false, 'error' => 'Cannot modify quantity of fired items'], 400);
            }
            
            // Update quantity and recalculate
            $newSubtotal = $newQuantity * $item['unit_price'];
            $newTax = $taxRate > 0 ? ($newSubtotal * $taxRate / 100) : 0;
            $newTotal = $newSubtotal + $newTax;
            
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET quantity = :quantity,
                    subtotal = :subtotal,
                    tax_amount = :tax_amount,
                    total_amount = :total_amount
                WHERE id = :item_id
            ");
            $stmt->execute([
                'quantity' => $newQuantity,
                'subtotal' => $newSubtotal,
                'tax_amount' => $newTax,
                'total_amount' => $newTotal,
                'item_id' => $itemId
            ]);
            
            $updateResult['updated_item'] = [
                'id' => $itemId,
                'new_quantity' => $newQuantity,
                'new_total' => $newTotal
            ];
            break;
            
        case 'update_customer':
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET customer_name = :customer_name,
                    customer_phone = :customer_phone,
                    customer_id = :customer_id,
                    updated_at = NOW()
                WHERE id = :order_id
            ");
            $stmt->execute([
                'customer_name' => $input['customer_name'] ?? $order['customer_name'],
                'customer_phone' => $input['customer_phone'] ?? $order['customer_phone'],
                'customer_id' => isset($input['customer_id']) ? (int)$input['customer_id'] : $order['customer_id'],
                'order_id' => $orderId
            ]);
            
            $updateResult['customer_updated'] = true;
            break;
            
        default:
            json_response(['success' => false, 'error' => 'Invalid update type'], 400);
    }
    
    // Recalculate order totals
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN is_voided = 0 THEN subtotal ELSE 0 END) as subtotal,
            SUM(CASE WHEN is_voided = 0 THEN tax_amount ELSE 0 END) as tax_amount,
            SUM(CASE WHEN is_voided = 0 THEN total_amount ELSE 0 END) as total_amount
        FROM order_items 
        WHERE order_id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Update order totals
    $serviceCharge = $order['service_charge']; // Keep existing service charge
    $newTotal = $totals['total_amount'] + $serviceCharge;
    
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET subtotal = :subtotal,
            tax_amount = :tax_amount,
            total_amount = :total_amount,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'subtotal' => $totals['subtotal'],
        'tax_amount' => $totals['tax_amount'],
        'total_amount' => $newTotal,
        'order_id' => $orderId
    ]);
    
    // Log the update
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            :action, :details, NOW()
        )
    ");
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'action' => 'updated',
        'details' => json_encode([
            'update_type' => $updateType,
            'result' => $updateResult
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
    
    json_response([
        'success' => true,
        'message' => 'Order updated successfully',
        'order' => [
            'id' => $updatedOrder['id'],
            'receipt_reference' => $updatedOrder['receipt_reference'],
            'subtotal' => (float)$updatedOrder['subtotal'],
            'tax_amount' => (float)$updatedOrder['tax_amount'],
            'service_charge' => (float)$updatedOrder['service_charge'],
            'total_amount' => (float)$updatedOrder['total_amount'],
            'items_count' => count($items),
            'status' => $updatedOrder['status']
        ],
        'update_result' => $updateResult,
        'items' => $items
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order update DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order update error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
