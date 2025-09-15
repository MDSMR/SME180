<?php
// /mohamedk10.sg-host.com/public_html/controllers/admin/orders/order_items.php
// CRUD operations for order items with variations
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/middleware/auth_login.php';
require_once __DIR__ . '/_helpers.php';

auth_require_login();
use_backend_session();

header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user) {
    error_response('Unauthorized', 401);
}

$tenantId = (int)$user['tenant_id'];
$userId = (int)$user['id'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    error_response('Invalid order ID');
}

try {
    $pdo = db();
    
    // Verify tenant access
    if (!ensure_tenant_access($pdo, $orderId, $tenantId)) {
        error_response('Order not found or access denied', 404);
    }
    
    // Check if order can be modified
    $stmt = $pdo->prepare("SELECT status, payment_locked, locked_by FROM orders WHERE id = :id");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!can_modify_order($order)) {
        error_response('Order cannot be modified in status: ' . $order['status']);
    }
    
    // Check lock for write operations
    if (in_array($action, ['add', 'update', 'delete', 'fire', 'void'])) {
        if ($order['payment_locked'] && $order['locked_by'] != $userId) {
            error_response('Order is locked by another user', 423);
        }
    }
    
    switch ($action) {
        case 'list':
            // Get all items for the order
            $stmt = $pdo->prepare("
                SELECT 
                    oi.*,
                    p.name_en as product_name_en,
                    p.name_ar as product_name_ar,
                    p.is_inventory_tracked
                FROM order_items oi
                LEFT JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id = :id
                ORDER BY oi.id
            ");
            $stmt->execute([':id' => $orderId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get variations for each item
            foreach ($items as &$item) {
                $stmt = $pdo->prepare("
                    SELECT * FROM order_item_variations 
                    WHERE order_item_id = :id
                    ORDER BY id
                ");
                $stmt->execute([':id' => $item['id']]);
                $item['variations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
            success_response('', ['items' => $items]);
            break;
            
        case 'add':
            $productId = (int)($_POST['product_id'] ?? 0);
            $quantity = max(1, (int)($_POST['quantity'] ?? 1));
            $notes = clean_string($_POST['notes'] ?? '');
            $variations = json_decode($_POST['variations'] ?? '[]', true) ?: [];
            
            if ($productId <= 0) {
                error_response('Invalid product ID');
            }
            
            // Get product details
            $stmt = $pdo->prepare("
                SELECT id, name_en, name_ar, price, is_open_price, standard_cost
                FROM products 
                WHERE id = :id AND tenant_id = :t AND is_active = 1
            ");
            $stmt->execute([':id' => $productId, ':t' => $tenantId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                error_response('Product not found');
            }
            
            // Calculate unit price with variations
            $unitPrice = (float)$product['price'];
            $variationDetails = [];
            
            foreach ($variations as $var) {
                $groupId = (int)($var['group_id'] ?? 0);
                $valueId = (int)($var['value_id'] ?? 0);
                
                if ($groupId <= 0 || $valueId <= 0) continue;
                
                // Get variation details
                $stmt = $pdo->prepare("
                    SELECT 
                        vg.name as group_name,
                        vv.value_en,
                        vv.value_ar,
                        vv.price_delta
                    FROM variation_groups vg
                    JOIN variation_values vv ON vv.group_id = vg.id
                    WHERE vg.id = :gid AND vv.id = :vid
                ");
                $stmt->execute([':gid' => $groupId, ':vid' => $valueId]);
                $varDetail = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($varDetail) {
                    $unitPrice += (float)$varDetail['price_delta'];
                    $variationDetails[] = [
                        'group_id' => $groupId,
                        'value_id' => $valueId,
                        'group' => $varDetail['group_name'],
                        'value' => $varDetail['value_en'],
                        'price_delta' => $varDetail['price_delta']
                    ];
                }
            }
            
            $lineSubtotal = round($unitPrice * $quantity, 3);
            
            $pdo->beginTransaction();
            
            // Insert order item
            $stmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id, product_id, product_name, unit_price, 
                    quantity, line_subtotal, notes, state, created_at
                ) VALUES (
                    :oid, :pid, :pname, :uprice, 
                    :qty, :subtotal, :notes, 'held', NOW()
                )
            ");
            $stmt->execute([
                ':oid' => $orderId,
                ':pid' => $productId,
                ':pname' => $product['name_en'],
                ':uprice' => $unitPrice,
                ':qty' => $quantity,
                ':subtotal' => $lineSubtotal,
                ':notes' => $notes
            ]);
            $itemId = (int)$pdo->lastInsertId();
            
            // Insert variations
            foreach ($variationDetails as $vd) {
                $stmt = $pdo->prepare("
                    INSERT INTO order_item_variations (
                        order_item_id, variation_group, variation_value, price_delta
                    ) VALUES (
                        :iid, :group, :value, :delta
                    )
                ");
                $stmt->execute([
                    ':iid' => $itemId,
                    ':group' => $vd['group'],
                    ':value' => $vd['value'],
                    ':delta' => $vd['price_delta']
                ]);
            }
            
            // Update order subtotal
            $totals = calculate_order_totals($pdo, $orderId, $tenantId);
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET subtotal_amount = :sub,
                    tax_amount = :tax,
                    service_amount = :svc,
                    total_amount = :total,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':sub' => $totals['subtotal_amount'],
                ':tax' => $totals['tax_amount'],
                ':svc' => $totals['service_amount'],
                ':total' => $totals['total_amount'],
                ':id' => $orderId
            ]);
            
            // Log event
            log_order_event($pdo, $tenantId, $orderId, 'add', $userId, [
                'item_id' => $itemId,
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price' => $unitPrice
            ]);
            
            $pdo->commit();
            
            success_response('Item added', [
                'item_id' => $itemId,
                'totals' => $totals
            ]);
            break;
            
        case 'update':
            $itemId = (int)($_POST['item_id'] ?? 0);
            $quantity = max(1, (int)($_POST['quantity'] ?? 1));
            $notes = clean_string($_POST['notes'] ?? '');
            
            if ($itemId <= 0) {
                error_response('Invalid item ID');
            }
            
            $pdo->beginTransaction();
            
            // Get current item
            $stmt = $pdo->prepare("
                SELECT * FROM order_items 
                WHERE id = :id AND order_id = :oid
            ");
            $stmt->execute([':id' => $itemId, ':oid' => $orderId]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                $pdo->rollBack();
                error_response('Item not found');
            }
            
            // Can't update fired/ready/voided items
            if (in_array($item['state'], ['fired', 'in_prep', 'ready', 'voided'])) {
                $pdo->rollBack();
                error_response('Cannot modify item in state: ' . $item['state']);
            }
            
            // Update item
            $lineSubtotal = round((float)$item['unit_price'] * $quantity, 3);
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET quantity = :qty,
                    line_subtotal = :sub,
                    notes = :notes,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':qty' => $quantity,
                ':sub' => $lineSubtotal,
                ':notes' => $notes,
                ':id' => $itemId
            ]);
            
            // Recalculate order totals
            $totals = calculate_order_totals($pdo, $orderId, $tenantId);
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET subtotal_amount = :sub,
                    tax_amount = :tax,
                    service_amount = :svc,
                    total_amount = :total,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':sub' => $totals['subtotal_amount'],
                ':tax' => $totals['tax_amount'],
                ':svc' => $totals['service_amount'],
                ':total' => $totals['total_amount'],
                ':id' => $orderId
            ]);
            
            // Log event
            log_order_event($pdo, $tenantId, $orderId, 'qty_change', $userId, [
                'item_id' => $itemId,
                'old_quantity' => $item['quantity'],
                'new_quantity' => $quantity
            ]);
            
            $pdo->commit();
            
            success_response('Item updated', ['totals' => $totals]);
            break;
            
        case 'delete':
            $itemId = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
            
            if ($itemId <= 0) {
                error_response('Invalid item ID');
            }
            
            $pdo->beginTransaction();
            
            // Check item state
            $stmt = $pdo->prepare("
                SELECT state FROM order_items 
                WHERE id = :id AND order_id = :oid
            ");
            $stmt->execute([':id' => $itemId, ':oid' => $orderId]);
            $state = $stmt->fetchColumn();
            
            if (!$state) {
                $pdo->rollBack();
                error_response('Item not found');
            }
            
            if (in_array($state, ['fired', 'in_prep', 'ready'])) {
                $pdo->rollBack();
                error_response('Cannot delete item in state: ' . $state);
            }
            
            // Delete variations first
            $stmt = $pdo->prepare("DELETE FROM order_item_variations WHERE order_item_id = :id");
            $stmt->execute([':id' => $itemId]);
            
            // Delete item
            $stmt = $pdo->prepare("DELETE FROM order_items WHERE id = :id");
            $stmt->execute([':id' => $itemId]);
            
            // Recalculate totals
            $totals = calculate_order_totals($pdo, $orderId, $tenantId);
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET subtotal_amount = :sub,
                    tax_amount = :tax,
                    service_amount = :svc,
                    total_amount = :total,
                    updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([
                ':sub' => $totals['subtotal_amount'],
                ':tax' => $totals['tax_amount'],
                ':svc' => $totals['service_amount'],
                ':total' => $totals['total_amount'],
                ':id' => $orderId
            ]);
            
            // Log event
            log_order_event($pdo, $tenantId, $orderId, 'void', $userId, [
                'item_id' => $itemId
            ]);
            
            $pdo->commit();
            
            success_response('Item deleted', ['totals' => $totals]);
            break;
            
        case 'fire':
            // Fire items to kitchen
            $itemIds = json_decode($_POST['item_ids'] ?? '[]', true) ?: [];
            
            if (empty($itemIds)) {
                error_response('No items to fire');
            }
            
            $pdo->beginTransaction();
            
            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET state = 'fired',
                    fired_at = NOW(),
                    updated_at = NOW()
                WHERE order_id = ? 
                AND id IN ($placeholders)
                AND state = 'held'
            ");
            $stmt->execute(array_merge([$orderId], $itemIds));
            
            $firedCount = $stmt->rowCount();
            
            // Log event
            log_order_event($pdo, $tenantId, $orderId, 'fire', $userId, [
                'item_ids' => $itemIds,
                'fired_count' => $firedCount
            ]);
            
            $pdo->commit();
            
            success_response($firedCount . ' items fired to kitchen', [
                'fired_count' => $firedCount
            ]);
            break;
            
        default:
            error_response('Invalid action');
    }
    
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_response('Operation failed: ' . $e->getMessage(), 500);
}