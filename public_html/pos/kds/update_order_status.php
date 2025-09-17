## 3. /public_html/pos/api/kds/update_order_status.php
```php
<?php
/**
 * SME 180 POS - KDS Update Order Status API
 * Path: /public_html/pos/api/kds/update_order_status.php
 * 
 * Updates the kitchen status of an entire order
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

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['order_id']) || !isset($input['status'])) {
    json_response(['success' => false, 'error' => 'Order ID and status are required'], 400);
}

$orderId = (int)$input['order_id'];
$newStatus = $input['status'];
$notes = $input['notes'] ?? '';

// Validate status
$validStatuses = ['fired', 'preparing', 'ready', 'served', 'cancelled'];
if (!in_array($newStatus, $validStatuses)) {
    json_response(['success' => false, 'error' => 'Invalid status'], 400);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Get order
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
    
    if (in_array($order['status'], ['voided', 'refunded'])) {
        json_response(['success' => false, 'error' => 'Cannot update ' . $order['status'] . ' orders'], 400);
    }
    
    // Update order kitchen status
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET kitchen_status = :kitchen_status,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'kitchen_status' => $newStatus,
        'order_id' => $orderId
    ]);
    
    // Update timestamps
    if ($newStatus === 'preparing' && !$order['preparing_at']) {
        $stmt = $pdo->prepare("UPDATE orders SET preparing_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
    } elseif ($newStatus === 'ready' && !$order['ready_at']) {
        $stmt = $pdo->prepare("UPDATE orders SET ready_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
    } elseif ($newStatus === 'served') {
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET status = CASE 
                    WHEN payment_status = 'paid' THEN 'closed'
                    ELSE 'served'
                END,
                served_at = COALESCE(served_at, NOW())
            WHERE id = :id
        ");
        $stmt->execute(['id' => $orderId]);
    }
    
    // Update all non-voided items
    if ($newStatus !== 'cancelled') {
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET state = :status,
                kitchen_status_updated_at = NOW(),
                updated_at = NOW()
            WHERE order_id = :order_id
            AND is_voided = 0
        ");
        $stmt->execute([
            'status' => $newStatus,
            'order_id' => $orderId
        ]);
    }
    
    // Log status change
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, event_type,
            payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, 'kitchen_status',
            :payload, :user_id, NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'payload' => json_encode([
            'from' => $order['kitchen_status'],
            'to' => $newStatus,
            'notes' => $notes
        ]),
        'user_id' => $userId
    ]);
    
    // If marking as ready, send notification to POS
    if ($newStatus === 'ready') {
        // This would trigger a notification system
        // For now, just log it
        $stmt = $pdo->prepare("
            INSERT INTO system_notifications (
                tenant_id, branch_id, type, title, message,
                data, created_at
            ) VALUES (
                :tenant_id, :branch_id, 'order_ready', :title, :message,
                :data, NOW()
            )
        ");
        
        try {
            $stmt->execute([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'title' => 'Order Ready',
                'message' => 'Order #' . $order['receipt_reference'] . ' is ready',
                'data' => json_encode(['order_id' => $orderId])
            ]);
        } catch (Exception $e) {
            // Table might not exist, ignore
        }
    }
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'order_id' => $orderId,
        'kitchen_status' => $newStatus
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Update order status error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to update order status'], 500);
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
