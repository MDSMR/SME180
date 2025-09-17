## 4. /public_html/pos/api/order/void_order.php
```php
<?php
/**
 * SME 180 POS - Void Entire Order API
 * Path: /public_html/pos/api/order/void_order.php
 * 
 * Voids an entire order with approval workflow
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

if (!isset($input['order_id']) || !isset($input['reason'])) {
    json_response(['success' => false, 'error' => 'Order ID and reason are required'], 400);
}

$orderId = (int)$input['order_id'];
$reason = trim($input['reason']);
$approvalPin = $input['approval_pin'] ?? null;

if (empty($reason)) {
    json_response(['success' => false, 'error' => 'Void reason cannot be empty'], 400);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Check user permission
    $hasPermission = check_user_permission($pdo, $userId, 'pos.void_order');
    $requiresApproval = !check_user_permission($pdo, $userId, 'pos.approve_void');
    
    if (!$hasPermission && !$approvalPin) {
        json_response(['success' => false, 'error' => 'No permission to void orders'], 403);
    }
    
    // Fetch and lock order
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
    
    // Check if order can be voided
    if ($order['payment_status'] === 'paid') {
        json_response(['success' => false, 'error' => 'Cannot void paid orders. Use refund instead.'], 400);
    }
    
    if (in_array($order['status'], ['voided', 'refunded'])) {
        json_response(['success' => false, 'error' => 'Order is already ' . $order['status']], 400);
    }
    
    // Check if approval is needed
    $approvedBy = null;
    if ($requiresApproval) {
        if (!$approvalPin) {
            json_response([
                'success' => false, 
                'error' => 'Manager approval required',
                'requires_approval' => true
            ], 403);
        }
        
        $approvedBy = validateApprovalPin($pdo, $approvalPin, 'pos.approve_void', $tenantId);
        if (!$approvedBy) {
            json_response(['success' => false, 'error' => 'Invalid approval PIN'], 403);
        }
    }
    
    // Void the order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET status = 'voided',
            payment_status = 'voided',
            void_reason = :reason,
            voided_at = NOW(),
            voided_by_user_id = :user_id,
            void_approved_by = :approved_by,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'reason' => $reason,
        'user_id' => $userId,
        'approved_by' => $approvedBy ?: $userId,
        'order_id' => $orderId
    ]);
    
    // Void all items
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET is_voided = 1,
            void_reason = :reason,
            void_at = NOW(),
            void_approved_by = :user_id
        WHERE order_id = :order_id
        AND is_voided = 0
    ");
    $stmt->execute([
        'reason' => 'Order voided: ' . $reason,
        'user_id' => $approvedBy ?: $userId,
        'order_id' => $orderId
    ]);
    
    // Update table status if dine-in
    if ($order['order_type'] === 'dine_in' && $order['table_id']) {
        $stmt = $pdo->prepare("
            UPDATE dining_tables 
            SET is_occupied = 0 
            WHERE id = :table_id
        ");
        $stmt->execute(['table_id' => $order['table_id']]);
    }
    
    // Log void event
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, event_type, payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, 'void', :payload, :user_id, NOW())
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'payload' => json_encode([
            'reason' => $reason,
            'approved_by' => $approvedBy,
            'type' => 'full_order'
        ]),
        'user_id' => $userId
    ]);
    
    // Create approval record if approved
    if ($approvedBy) {
        $stmt = $pdo->prepare("
            INSERT INTO pos_approvals (
                tenant_id, branch_id, approval_type, reference_type, reference_id,
                requested_by, approved_by, status, reason, requested_at, responded_at
            ) VALUES (
                :tenant_id, :branch_id, 'void_order', 'order', :order_id,
                :requested_by, :approved_by, 'approved', :reason, NOW(), NOW()
            )
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'requested_by' => $userId,
            'approved_by' => $approvedBy,
            'reason' => $reason
        ]);
    }
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'order_id' => $orderId,
        'status' => 'voided',
        'approved_by' => $approvedBy ?: $userId
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Void order error: ' . $e->getMessage());
    json_response([
        'success' => false,
        'error' => 'Failed to void order',
        'details' => $e->getMessage()
    ], 500);
}

function validateApprovalPin($pdo, $pin, $capability, $tenantId) {
    if (!$pin) return null;
    
    $stmt = $pdo->prepare("
        SELECT u.id 
        FROM users u
        JOIN pos_role_capabilities rc ON rc.role_key = u.role_key
        WHERE u.tenant_id = :tenant_id
        AND u.pos_pin = :pin
        AND rc.capability_key = :capability
        AND (u.disabled_at IS NULL OR u.disabled_at > NOW())
        LIMIT 1
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'pin' => $pin, // Assuming plain text PINs for now
        'capability' => $capability
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $result['id'] : null;
}

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

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
