## 2. /public_html/pos/api/kds/update_item_status.php
<?php
/**
 * SME 180 POS - KDS Update Item Status API
 * Path: /public_html/pos/api/kds/update_item_status.php
 * 
 * Updates the status of individual items in the kitchen
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

if (!isset($input['item_id']) || !isset($input['status'])) {
    json_response(['success' => false, 'error' => 'Item ID and status are required'], 400);
}

$itemId = (int)$input['item_id'];
$newStatus = $input['status'];
$notes = $input['notes'] ?? '';

// Validate status
$validStatuses = ['fired', 'preparing', 'ready', 'served'];
if (!in_array($newStatus, $validStatuses)) {
    json_response(['success' => false, 'error' => 'Invalid status'], 400);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Get item details
    $stmt = $pdo->prepare("
        SELECT 
            oi.*,
            o.tenant_id,
            o.branch_id,
            o.kitchen_status as order_kitchen_status
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = :item_id
        FOR UPDATE
    ");
    $stmt->execute(['item_id' => $itemId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        json_response(['success' => false, 'error' => 'Item not found'], 404);
    }
    
    if ($item['tenant_id'] != $tenantId || $item['branch_id'] != $branchId) {
        json_response(['success' => false, 'error' => 'Unauthorized'], 403);
    }
    
    if ($item['is_voided']) {
        json_response(['success' => false, 'error' => 'Cannot update voided item'], 400);
    }
    
    // Update item status
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET state = :status,
            kitchen_status_updated_at = NOW(),
            updated_at = NOW()
        WHERE id = :item_id
    ");
    $stmt->execute([
        'status' => $newStatus,
        'item_id' => $itemId
    ]);
    
    // Set specific timestamps
    if ($newStatus === 'preparing' && !$item['preparing_at']) {
        $stmt = $pdo->prepare("UPDATE order_items SET preparing_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $itemId]);
    } elseif ($newStatus === 'ready' && !$item['ready_at']) {
        $stmt = $pdo->prepare("UPDATE order_items SET ready_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $itemId]);
    } elseif ($newStatus === 'served' && !$item['served_at']) {
        $stmt = $pdo->prepare("UPDATE order_items SET served_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $itemId]);
    }
    
    // Log status change
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, order_item_id, event_type,
            payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, :item_id, 'kitchen_status',
            :payload, :user_id, NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $item['order_id'],
        'item_id' => $itemId,
        'payload' => json_encode([
            'from' => $item['state'],
            'to' => $newStatus,
            'notes' => $notes
        ]),
        'user_id' => $userId
    ]);
    
    // Check if all items in order have same status to update order status
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_items,
            SUM(CASE WHEN state = :status THEN 1 ELSE 0 END) as status_count
        FROM order_items
        WHERE order_id = :order_id
        AND is_voided = 0
    ");
    $stmt->execute([
        'status' => $newStatus,
        'order_id' => $item['order_id']
    ]);
    $counts = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If all items have same status, update order
    if ($counts['total_items'] == $counts['status_count']) {
        $orderKitchenStatus = $newStatus;
        if ($newStatus === 'served') {
            $orderKitchenStatus = 'served';
        }
        
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET kitchen_status = :kitchen_status,
                updated_at = NOW()
            WHERE id = :order_id
        ");
        $stmt->execute([
            'kitchen_status' => $orderKitchenStatus,
            'order_id' => $item['order_id']
        ]);
        
        if ($newStatus === 'ready' && !$item['ready_at']) {
            $stmt = $pdo->prepare("UPDATE orders SET ready_at = NOW() WHERE id = :id");
            $stmt->execute(['id' => $item['order_id']]);
        }
    }
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'item_id' => $itemId,
        'status' => $newStatus,
        'order_id' => $item['order_id']
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Update item status error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to update item status'], 500);
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
