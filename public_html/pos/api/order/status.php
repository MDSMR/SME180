## 1. /public_html/pos/api/order/status.php
<?php
/**
 * SME 180 POS - Order Status API
 * Path: /public_html/pos/api/order/status.php
 * 
 * Gets and updates order status through lifecycle
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

pos_auth_require_login();
$user = pos_get_current_user();

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!$tenantId || !$branchId || !$userId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Get order status
    $orderId = (int)($_GET['order_id'] ?? 0);
    
    if (!$orderId) {
        json_response(['success' => false, 'error' => 'Order ID is required'], 400);
    }
    
    try {
        $pdo = db();
        
        $stmt = $pdo->prepare("
            SELECT 
                o.id,
                o.receipt_reference,
                o.status,
                o.kitchen_status,
                o.payment_status,
                o.created_at,
                o.fired_at,
                o.ready_at,
                o.closed_at,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id AND fire_status = 'pending' AND is_voided = 0) as pending_items,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id AND fire_status = 'fired' AND is_voided = 0) as fired_items,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id AND state = 'ready' AND is_voided = 0) as ready_items
            FROM orders o
            WHERE o.id = :order_id
            AND o.tenant_id = :tenant_id
            AND o.branch_id = :branch_id
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
        
        // Get item details
        $stmt = $pdo->prepare("
            SELECT 
                id,
                product_name,
                quantity,
                fire_status,
                state,
                is_voided
            FROM order_items
            WHERE order_id = :order_id
            ORDER BY id
        ");
        $stmt->execute(['order_id' => $orderId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_response([
            'success' => true,
            'order' => [
                'id' => (int)$order['id'],
                'reference' => $order['receipt_reference'],
                'status' => $order['status'],
                'kitchen_status' => $order['kitchen_status'],
                'payment_status' => $order['payment_status'],
                'timestamps' => [
                    'created' => $order['created_at'],
                    'fired' => $order['fired_at'],
                    'ready' => $order['ready_at'],
                    'closed' => $order['closed_at']
                ],
                'counts' => [
                    'pending' => (int)$order['pending_items'],
                    'fired' => (int)$order['fired_items'],
                    'ready' => (int)$order['ready_items']
                ],
                'items' => $items
            ]
        ]);
        
    } catch (Exception $e) {
        error_log('Get order status error: ' . $e->getMessage());
        json_response(['success' => false, 'error' => 'Failed to get order status'], 500);
    }
    
} else if ($method === 'POST') {
    // Update order status
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['order_id']) || !isset($input['status'])) {
        json_response(['success' => false, 'error' => 'Order ID and status are required'], 400);
    }
    
    $orderId = (int)$input['order_id'];
    $newStatus = $input['status'];
    $kitchenStatus = $input['kitchen_status'] ?? null;
    
    // Validate status
    $validStatuses = ['open', 'held', 'sent', 'preparing', 'ready', 'served', 'closed'];
    if (!in_array($newStatus, $validStatuses)) {
        json_response(['success' => false, 'error' => 'Invalid status'], 400);
    }
    
    try {
        $pdo = db();
        $pdo->beginTransaction();
        
        // Get current order
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
            json_response(['success' => false, 'error' => 'Order not found'], 404);
        }
        
        // Check if status transition is valid
        $invalidTransitions = [
            'closed' => ['open', 'held', 'sent'], // Can't go back from closed
            'voided' => array_diff($validStatuses, ['voided']), // Can't change voided
            'refunded' => array_diff($validStatuses, ['refunded']) // Can't change refunded
        ];
        
        if (isset($invalidTransitions[$order['status']]) && 
            in_array($newStatus, $invalidTransitions[$order['status']])) {
            json_response([
                'success' => false, 
                'error' => 'Cannot transition from ' . $order['status'] . ' to ' . $newStatus
            ], 400);
        }
        
        // Update order status
        $updates = ['status = :status'];
        $params = [
            'status' => $newStatus,
            'order_id' => $orderId
        ];
        
        if ($kitchenStatus) {
            $updates[] = 'kitchen_status = :kitchen_status';
            $params['kitchen_status'] = $kitchenStatus;
        }
        
        // Set timestamps based on status
        if ($newStatus === 'ready' && !$order['ready_at']) {
            $updates[] = 'ready_at = NOW()';
        }
        if ($newStatus === 'closed' && !$order['closed_at']) {
            $updates[] = 'closed_at = NOW()';
        }
        
        $updates[] = 'updated_at = NOW()';
        
        $sql = "UPDATE orders SET " . implode(', ', $updates) . " WHERE id = :order_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Log status change
        $stmt = $pdo->prepare("
            INSERT INTO order_item_events (
                tenant_id, order_id, event_type, payload, created_by, created_at
            ) VALUES (
                :tenant_id, :order_id, 'status_change', :payload, :user_id, NOW()
            )
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'payload' => json_encode([
                'from' => $order['status'],
                'to' => $newStatus,
                'kitchen_status' => $kitchenStatus
            ]),
            'user_id' => $userId
        ]);
        
        $pdo->commit();
        
        json_response([
            'success' => true,
            'order_id' => $orderId,
            'status' => $newStatus,
            'kitchen_status' => $kitchenStatus
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Update order status error: ' . $e->getMessage());
        json_response(['success' => false, 'error' => 'Failed to update status'], 500);
    }
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
