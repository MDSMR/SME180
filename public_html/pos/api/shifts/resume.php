## 3. /public_html/pos/api/order/resume.php
```php
<?php
/**
 * SME 180 POS - Resume Order API
 * Path: /public_html/pos/api/order/resume.php
 * 
 * Resumes a parked order
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
    // Get list of parked orders
    try {
        $pdo = db();
        
        $stmt = $pdo->prepare("
            SELECT 
                o.id,
                o.receipt_reference,
                o.park_label,
                o.park_reason,
                o.parked_at,
                o.total_amount,
                o.order_type,
                o.customer_name,
                u.name as parked_by_name
            FROM orders o
            LEFT JOIN users u ON u.id = o.parked_by
            WHERE o.tenant_id = :tenant_id
            AND o.branch_id = :branch_id
            AND o.parked = 1
            AND o.status = 'held'
            ORDER BY o.parked_at DESC
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId
        ]);
        
        $parkedOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        json_response([
            'success' => true,
            'parked_orders' => $parkedOrders
        ]);
        
    } catch (Exception $e) {
        error_log('Get parked orders error: ' . $e->getMessage());
        json_response(['success' => false, 'error' => 'Failed to get parked orders'], 500);
    }
    exit;
}

$orderId = (int)$input['order_id'];
$tableId = isset($input['table_id']) ? (int)$input['table_id'] : null;

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Get parked order
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE id = :order_id 
        AND tenant_id = :tenant_id
        AND branch_id = :branch_id
        AND parked = 1
        FOR UPDATE
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        json_response(['success' => false, 'error' => 'Parked order not found'], 404);
    }
    
    // If dine-in and table provided, check availability
    if ($order['order_type'] === 'dine_in' && $tableId) {
        $stmt = $pdo->prepare("
            SELECT is_occupied 
            FROM dining_tables 
            WHERE id = :table_id 
            AND tenant_id = :tenant_id
            AND is_active = 1
        ");
        $stmt->execute([
            'table_id' => $tableId,
            'tenant_id' => $tenantId
        ]);
        
        $table = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$table) {
            json_response(['success' => false, 'error' => 'Table not found'], 404);
        }
        
        if ($table['is_occupied']) {
            json_response(['success' => false, 'error' => 'Table is occupied'], 400);
        }
    }
    
    // Resume the order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET status = 'open',
            parked = 0,
            parked_at = NULL,
            parked_by = NULL,
            park_label = NULL,
            park_reason = NULL,
            resumed_at = NOW(),
            resumed_by = :user_id,
            table_id = COALESCE(:table_id, table_id),
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'user_id' => $userId,
        'table_id' => $tableId,
        'order_id' => $orderId
    ]);
    
    // Occupy table if dine-in
    if ($order['order_type'] === 'dine_in' && ($tableId || $order['table_id'])) {
        $stmt = $pdo->prepare("
            UPDATE dining_tables 
            SET is_occupied = 1,
                last_occupied_at = NOW()
            WHERE id = :table_id
        ");
        $stmt->execute(['table_id' => $tableId ?: $order['table_id']]);
    }
    
    // Log resume event
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, event_type, payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, 'resume', :payload, :user_id, NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'payload' => json_encode([
            'table_id' => $tableId
        ]),
        'user_id' => $userId
    ]);
    
    // Get updated order details
    $stmt = $pdo->prepare("
        SELECT 
            o.*,
            (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
        FROM orders o
        WHERE o.id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $updatedOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $updatedOrder['receipt_reference'],
            'status' => $updatedOrder['status'],
            'table_id' => $updatedOrder['table_id'],
            'item_count' => (int)$updatedOrder['item_count'],
            'total_amount' => round((float)$updatedOrder['total_amount'], 2)
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Resume order error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to resume order'], 500);
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
