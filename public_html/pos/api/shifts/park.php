## 2. /public_html/pos/api/order/park.php
```php
<?php
/**
 * SME 180 POS - Park Order API
 * Path: /public_html/pos/api/order/park.php
 * 
 * Parks an order for later completion
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

if (!isset($input['order_id'])) {
    json_response(['success' => false, 'error' => 'Order ID is required'], 400);
}

$orderId = (int)$input['order_id'];
$parkReason = $input['reason'] ?? '';
$parkLabel = $input['label'] ?? 'Parked Order #' . $orderId;

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
    
    // Check if order can be parked
    if ($order['payment_status'] === 'paid') {
        json_response(['success' => false, 'error' => 'Cannot park paid orders'], 400);
    }
    
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        json_response(['success' => false, 'error' => 'Cannot park ' . $order['status'] . ' orders'], 400);
    }
    
    // Park the order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET status = 'held',
            parked = 1,
            parked_at = NOW(),
            parked_by = :user_id,
            park_label = :label,
            park_reason = :reason,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'user_id' => $userId,
        'label' => $parkLabel,
        'reason' => $parkReason,
        'order_id' => $orderId
    ]);
    
    // Free the table if dine-in
    if ($order['order_type'] === 'dine_in' && $order['table_id']) {
        $stmt = $pdo->prepare("
            UPDATE dining_tables 
            SET is_occupied = 0 
            WHERE id = :table_id
        ");
        $stmt->execute(['table_id' => $order['table_id']]);
    }
    
    // Log park event
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, event_type, payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, 'park', :payload, :user_id, NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'payload' => json_encode([
            'label' => $parkLabel,
            'reason' => $parkReason
        ]),
        'user_id' => $userId
    ]);
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'order_id' => $orderId,
        'parked' => true,
        'label' => $parkLabel
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Park order error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to park order'], 500);
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
