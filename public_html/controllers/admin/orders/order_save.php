<?php
// File: /public_html/controllers/admin/orders/order_save.php
declare(strict_types=1);

/**
 * Order Save Controller - Updated for POS Integration
 * Now captures station_id and cash_session_id for POS orders
 * 
 * Path: /controllers/admin/orders/order_save.php
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';
require_once __DIR__ . '/_helpers.php';

// Authentication
auth_require_login();
use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) {
    error_response('Unauthorized', 401);
}

$tenantId = (int)$user['tenant_id'];
$branchId = (int)($user['branch_id'] ?? $_SESSION['selected_branch_id'] ?? 0);
$userId = (int)$user['id'];

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    error_response('Invalid input data', 400);
}

header('Content-Type: application/json');

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Extract order data
    $orderId = isset($input['order_id']) ? (int)$input['order_id'] : null;
    $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
    $tableId = isset($input['table_id']) ? (int)$input['table_id'] : null;
    $orderType = $input['order_type'] ?? 'dine_in';
    $status = $input['status'] ?? 'open';
    $paymentStatus = $input['payment_status'] ?? 'pending';
    $source = $input['source'] ?? 'pos';
    $notes = $input['notes'] ?? '';
    
    // POS-specific fields (NEW)
    $stationId = isset($input['station_id']) ? (int)$input['station_id'] : null;
    $cashSessionId = isset($input['cash_session_id']) ? (int)$input['cash_session_id'] : null;
    
    // If coming from POS, try to get from session if not in input
    if ($source === 'pos' || isset($_SESSION['pos_user_id'])) {
        if (!$stationId && isset($_SESSION['station_id'])) {
            $stationId = (int)$_SESSION['station_id'];
        }
        if (!$cashSessionId && isset($_SESSION['cash_session_id'])) {
            $cashSessionId = (int)$_SESSION['cash_session_id'];
        }
    }
    
    // Financial data
    $subtotalAmount = (float)($input['subtotal_amount'] ?? 0);
    $discountAmount = (float)($input['discount_amount'] ?? 0);
    $taxAmount = (float)($input['tax_amount'] ?? 0);
    $serviceChargeAmount = (float)($input['service_charge_amount'] ?? 0);
    $tipAmount = (float)($input['tip_amount'] ?? 0);
    $totalAmount = $subtotalAmount - $discountAmount + $taxAmount + $serviceChargeAmount + $tipAmount;
    
    // Items
    $items = $input['items'] ?? [];
    
    // Guest count and other metadata
    $guestCount = isset($input['guest_count']) ? (int)$input['guest_count'] : null;
    $aggregatorId = isset($input['aggregator_id']) ? (int)$input['aggregator_id'] : null;
    $aggregatorOrderId = $input['aggregator_order_id'] ?? null;
    
    // Offline sync support
    $clientId = $input['client_id'] ?? null;
    $createdOffline = isset($input['created_offline']) ? (bool)$input['created_offline'] : false;
    
    if ($orderId) {
        // UPDATE existing order
        
        // Check if order exists and belongs to tenant
        $checkStmt = $pdo->prepare(
            "SELECT id, status, payment_status, station_id, cash_session_id 
             FROM orders 
             WHERE id = :id AND tenant_id = :tenant_id AND branch_id = :branch_id"
        );
        $checkStmt->execute([
            'id' => $orderId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId
        ]);
        
        $existingOrder = $checkStmt->fetch(PDO::FETCH_ASSOC);
        if (!$existingOrder) {
            throw new Exception('Order not found');
        }
        
        // Preserve original station_id and cash_session_id if not provided
        if (!$stationId && $existingOrder['station_id']) {
            $stationId = $existingOrder['station_id'];
        }
        if (!$cashSessionId && $existingOrder['cash_session_id']) {
            $cashSessionId = $existingOrder['cash_session_id'];
        }
        
        // Update order
        $updateStmt = $pdo->prepare(
            "UPDATE orders SET
                customer_id = :customer_id,
                table_id = :table_id,
                order_type = :order_type,
                status = :status,
                payment_status = :payment_status,
                subtotal_amount = :subtotal_amount,
                discount_amount = :discount_amount,
                tax_amount = :tax_amount,
                service_charge_amount = :service_charge_amount,
                tip_amount = :tip_amount,
                total_amount = :total_amount,
                guest_count = :guest_count,
                notes = :notes,
                aggregator_id = :aggregator_id,
                aggregator_order_id = :aggregator_order_id,
                station_id = :station_id,
                cash_session_id = :cash_session_id,
                updated_by = :updated_by,
                updated_at = NOW()
             WHERE id = :id"
        );
        
        $updateStmt->execute([
            'customer_id' => $customerId,
            'table_id' => $tableId,
            'order_type' => $orderType,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'subtotal_amount' => $subtotalAmount,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'service_charge_amount' => $serviceChargeAmount,
            'tip_amount' => $tipAmount,
            'total_amount' => $totalAmount,
            'guest_count' => $guestCount,
            'notes' => $notes,
            'aggregator_id' => $aggregatorId,
            'aggregator_order_id' => $aggregatorOrderId,
            'station_id' => $stationId,
            'cash_session_id' => $cashSessionId,
            'updated_by' => $userId,
            'id' => $orderId
        ]);
        
        $action = 'updated';
        
    } else {
        // CREATE new order
        
        // Generate order number
        $orderNumber = generateOrderNumber($pdo, $tenantId, $branchId);
        
        // For POS orders, validate cash session if required
        if ($source === 'pos' && $cashSessionId) {
            $sessionCheck = $pdo->prepare(
                "SELECT id FROM cash_sessions 
                 WHERE id = :id AND tenant_id = :tenant_id 
                   AND branch_id = :branch_id AND status = 'open'"
            );
            $sessionCheck->execute([
                'id' => $cashSessionId,
                'tenant_id' => $tenantId,
                'branch_id' => $branchId
            ]);
            
            if (!$sessionCheck->fetch()) {
                throw new Exception('Invalid or closed cash session');
            }
        }
        
        $insertStmt = $pdo->prepare(
            "INSERT INTO orders (
                tenant_id, branch_id, order_number, customer_id, table_id,
                order_type, status, payment_status, source,
                subtotal_amount, discount_amount, tax_amount, 
                service_charge_amount, tip_amount, total_amount,
                guest_count, notes, aggregator_id, aggregator_order_id,
                station_id, cash_session_id, client_id, created_offline,
                created_by, created_at, created_date
            ) VALUES (
                :tenant_id, :branch_id, :order_number, :customer_id, :table_id,
                :order_type, :status, :payment_status, :source,
                :subtotal_amount, :discount_amount, :tax_amount,
                :service_charge_amount, :tip_amount, :total_amount,
                :guest_count, :notes, :aggregator_id, :aggregator_order_id,
                :station_id, :cash_session_id, :client_id, :created_offline,
                :created_by, NOW(), CURDATE()
            )"
        );
        
        $insertStmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'order_number' => $orderNumber,
            'customer_id' => $customerId,
            'table_id' => $tableId,
            'order_type' => $orderType,
            'status' => $status,
            'payment_status' => $paymentStatus,
            'source' => $source,
            'subtotal_amount' => $subtotalAmount,
            'discount_amount' => $discountAmount,
            'tax_amount' => $taxAmount,
            'service_charge_amount' => $serviceChargeAmount,
            'tip_amount' => $tipAmount,
            'total_amount' => $totalAmount,
            'guest_count' => $guestCount,
            'notes' => $notes,
            'aggregator_id' => $aggregatorId,
            'aggregator_order_id' => $aggregatorOrderId,
            'station_id' => $stationId,
            'cash_session_id' => $cashSessionId,
            'client_id' => $clientId,
            'created_offline' => $createdOffline ? 1 : 0,
            'created_by' => $userId
        ]);
        
        $orderId = (int)$pdo->lastInsertId();
        $action = 'created';
    }
    
    // Handle order items
    if (!empty($items)) {
        // Delete existing items if updating
        if ($action === 'updated') {
            $deleteItemsStmt = $pdo->prepare("DELETE FROM order_items WHERE order_id = :order_id");
            $deleteItemsStmt->execute(['order_id' => $orderId]);
        }
        
        // Insert new items
        $itemStmt = $pdo->prepare(
            "INSERT INTO order_items (
                order_id, product_id, quantity, unit_price, 
                subtotal, discount_amount, tax_amount, total_amount,
                notes, kitchen_status, sort_order
            ) VALUES (
                :order_id, :product_id, :quantity, :unit_price,
                :subtotal, :discount_amount, :tax_amount, :total_amount,
                :notes, :kitchen_status, :sort_order
            )"
        );
        
        foreach ($items as $index => $item) {
            $itemSubtotal = (float)$item['quantity'] * (float)$item['unit_price'];
            $itemDiscount = (float)($item['discount_amount'] ?? 0);
            $itemTax = (float)($item['tax_amount'] ?? 0);
            $itemTotal = $itemSubtotal - $itemDiscount + $itemTax;
            
            $itemStmt->execute([
                'order_id' => $orderId,
                'product_id' => (int)$item['product_id'],
                'quantity' => (int)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'subtotal' => $itemSubtotal,
                'discount_amount' => $itemDiscount,
                'tax_amount' => $itemTax,
                'total_amount' => $itemTotal,
                'notes' => $item['notes'] ?? '',
                'kitchen_status' => $item['kitchen_status'] ?? 'pending',
                'sort_order' => $index + 1
            ]);
            
            $itemId = (int)$pdo->lastInsertId();
            
            // Handle item variations/modifiers if present
            if (!empty($item['variations'])) {
                $varStmt = $pdo->prepare(
                    "INSERT INTO order_item_variations 
                     (order_item_id, modifier_group_id, modifier_value_id, price_delta)
                     VALUES (:item_id, :group_id, :value_id, :price_delta)"
                );
                
                foreach ($item['variations'] as $variation) {
                    $varStmt->execute([
                        'item_id' => $itemId,
                        'group_id' => (int)$variation['group_id'],
                        'value_id' => (int)$variation['value_id'],
                        'price_delta' => (float)($variation['price_delta'] ?? 0)
                    ]);
                }
            }
        }
    }
    
    // Log the action
    $logStmt = $pdo->prepare(
        "INSERT INTO audit_logs (tenant_id, branch_id, user_id, action, entity_type, entity_id, details)
         VALUES (:tenant_id, :branch_id, :user_id, :action, 'order', :order_id, :details)"
    );
    
    $logDetails = [
        'order_number' => $orderNumber ?? null,
        'total_amount' => $totalAmount,
        'source' => $source,
        'station_id' => $stationId,
        'cash_session_id' => $cashSessionId
    ];
    
    $logStmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'action' => 'order_' . $action,
        'order_id' => $orderId,
        'details' => json_encode($logDetails)
    ]);
    
    $pdo->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'data' => [
            'order_id' => $orderId,
            'order_number' => $orderNumber ?? null,
            'action' => $action,
            'total_amount' => $totalAmount,
            'station_id' => $stationId,
            'cash_session_id' => $cashSessionId
        ],
        'error' => null
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    error_response($e->getMessage(), 500);
}

/**
 * Generate unique order number
 */
function generateOrderNumber(PDO $pdo, int $tenantId, int $branchId): string {
    // Get the last order number for today
    $stmt = $pdo->prepare(
        "SELECT MAX(CAST(SUBSTRING_INDEX(order_number, '-', -1) AS UNSIGNED)) as last_num
         FROM orders 
         WHERE tenant_id = :tenant_id 
           AND branch_id = :branch_id 
           AND created_date = CURDATE()"
    );
    $stmt->execute(['tenant_id' => $tenantId, 'branch_id' => $branchId]);
    
    $lastNum = (int)$stmt->fetchColumn();
    $nextNum = $lastNum + 1;
    
    // Format: BRANCH-YYYYMMDD-0001
    return sprintf('B%d-%s-%04d', $branchId, date('Ymd'), $nextNum);
}