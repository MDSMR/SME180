<?php
/**
 * SME 180 POS - Order Refund API
 * Path: /public_html/pos/api/order/refund.php
 * 
 * Processes full or partial refunds with approval workflow
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
if (!isset($input['order_id']) || !isset($input['refund_type']) || !isset($input['reason'])) {
    json_response(['success' => false, 'error' => 'Order ID, refund type, and reason are required'], 400);
}

$orderId = (int)$input['order_id'];
$refundType = $input['refund_type']; // full, partial, item
$reason = $input['reason'];
$amount = (float)($input['amount'] ?? 0);
$itemIds = $input['item_ids'] ?? [];
$approvalPin = $input['approval_pin'] ?? null;

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Check permission
    $hasPermission = check_user_permission($pdo, $userId, 'pos.process_refund');
    $requiresApproval = !check_user_permission($pdo, $userId, 'pos.approve_refund');
    
    if (!$hasPermission && !$approvalPin) {
        json_response(['success' => false, 'error' => 'No permission to process refunds'], 403);
    }
    
    // Fetch and lock order
    $stmt = $pdo->prepare("
        SELECT o.*, 
               (SELECT SUM(amount) FROM order_payments WHERE order_id = o.id AND payment_type = 'payment' AND status = 'completed') as total_paid,
               (SELECT SUM(amount) FROM order_refunds WHERE order_id = o.id AND status = 'completed') as total_refunded
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
    
    // Validate order status
    if ($order['payment_status'] !== 'paid' && $order['payment_status'] !== 'partial') {
        json_response(['success' => false, 'error' => 'Can only refund paid orders'], 400);
    }
    
    if ($order['status'] === 'refunded') {
        json_response(['success' => false, 'error' => 'Order is already refunded'], 400);
    }
    
    $totalPaid = (float)($order['total_paid'] ?? 0);
    $totalRefunded = (float)($order['total_refunded'] ?? 0);
    $availableToRefund = $totalPaid - $totalRefunded;
    
    // Calculate refund amount based on type
    $refundAmount = 0;
    $refundItems = [];
    
    switch ($refundType) {
        case 'full':
            $refundAmount = $availableToRefund;
            break;
            
        case 'partial':
            if ($amount <= 0 || $amount > $availableToRefund) {
                json_response([
                    'success' => false,
                    'error' => 'Invalid refund amount',
                    'available' => $availableToRefund
                ], 400);
            }
            $refundAmount = $amount;
            break;
            
        case 'item':
            if (empty($itemIds)) {
                json_response(['success' => false, 'error' => 'No items selected for refund'], 400);
            }
            
            // Calculate refund amount from selected items
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT id, product_name, line_total 
                FROM order_items 
                WHERE order_id = ? 
                AND id IN ($placeholders)
                AND is_voided = 0
            ");
            $params = array_merge([$orderId], $itemIds);
            $stmt->execute($params);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                $refundAmount += (float)$item['line_total'];
                $refundItems[] = $item;
            }
            break;
            
        default:
            json_response(['success' => false, 'error' => 'Invalid refund type'], 400);
    }
    
    if ($refundAmount <= 0) {
        json_response(['success' => false, 'error' => 'Invalid refund amount'], 400);
    }
    
    // Handle approval if required
    $approvedBy = null;
    if ($requiresApproval) {
        if (!$approvalPin) {
            // Create approval request
            $stmt = $pdo->prepare("
                INSERT INTO order_void_requests (
                    tenant_id, branch_id, order_id, void_type,
                    reason, requested_by, requested_at, status,
                    expires_at
                ) VALUES (
                    :tenant_id, :branch_id, :order_id, 'order',
                    :reason, :user_id, NOW(), 'pending',
                    DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                )
            ");
            $stmt->execute([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'reason' => 'Refund: ' . $reason,
                'user_id' => $userId
            ]);
            
            $pdo->commit();
            
            json_response([
                'success' => false,
                'requires_approval' => true,
                'approval_request_id' => $pdo->lastInsertId(),
                'message' => 'Manager approval required for refund'
            ], 202);
        }
        
        // Validate approval PIN
        $approvedBy = validateApprovalPin($pdo, $approvalPin, 'pos.approve_refund', $tenantId);
        if (!$approvedBy) {
            json_response(['success' => false, 'error' => 'Invalid approval PIN'], 403);
        }
    }
    
    // Get payment information for refund
    $stmt = $pdo->prepare("
        SELECT * FROM order_payments 
        WHERE order_id = :order_id 
        AND payment_type = 'payment'
        AND status = 'completed'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute(['order_id' => $orderId]);
    $originalPayment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Create refund record
    $stmt = $pdo->prepare("
        INSERT INTO order_refunds (
            tenant_id, branch_id, order_id, payment_id,
            refund_type, amount, currency, reason,
            status, approved_by, approved_at,
            processed_by, processed_at, created_at
        ) VALUES (
            :tenant_id, :branch_id, :order_id, :payment_id,
            :refund_type, :amount, :currency, :reason,
            'completed', :approved_by, NOW(),
            :processed_by, NOW(), NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'order_id' => $orderId,
        'payment_id' => $originalPayment ? $originalPayment['id'] : null,
        'refund_type' => $refundType,
        'amount' => $refundAmount,
        'currency' => $originalPayment ? $originalPayment['currency'] : 'EGP',
        'reason' => $reason,
        'approved_by' => $approvedBy ?: $userId,
        'processed_by' => $userId
    ]);
    
    $refundId = $pdo->lastInsertId();
    
    // Create refund payment record
    $stmt = $pdo->prepare("
        INSERT INTO order_payments (
            tenant_id, branch_id, order_id,
            payment_method, payment_type, amount, currency,
            status, processed_at, processed_by, created_at
        ) VALUES (
            :tenant_id, :branch_id, :order_id,
            :payment_method, 'refund', :amount, :currency,
            'completed', NOW(), :user_id, NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'order_id' => $orderId,
        'payment_method' => $originalPayment ? $originalPayment['payment_method'] : 'cash',
        'amount' => $refundAmount,
        'currency' => $originalPayment ? $originalPayment['currency'] : 'EGP',
        'user_id' => $userId
    ]);
    
    // Update order status
    $newPaymentStatus = ($refundAmount >= $availableToRefund) ? 'refunded' : 'partial';
    $newOrderStatus = ($refundType === 'full') ? 'refunded' : $order['status'];
    
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET payment_status = :payment_status,
            status = :status,
            refunded_amount = refunded_amount + :amount,
            refunded_at = COALESCE(refunded_at, NOW()),
            refunded_by = COALESCE(refunded_by, :user_id),
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'payment_status' => $newPaymentStatus,
        'status' => $newOrderStatus,
        'amount' => $refundAmount,
        'user_id' => $userId,
        'order_id' => $orderId
    ]);
    
    // Void refunded items if item refund
    if ($refundType === 'item' && !empty($refundItems)) {
        $voidStmt = $pdo->prepare("
            UPDATE order_items 
            SET is_voided = 1,
                void_reason = :reason,
                void_at = NOW(),
                void_approved_by = :user_id
            WHERE id = :item_id
        ");
        
        foreach ($refundItems as $item) {
            $voidStmt->execute([
                'reason' => 'Refunded',
                'user_id' => $userId,
                'item_id' => $item['id']
            ]);
        }
    }
    
    // Revoke loyalty points if applicable
    if ($order['customer_id']) {
        revokeLoyaltyPoints($pdo, $order['customer_id'], $orderId, $refundAmount, $tenantId);
    }
    
    // Update cash session if cash refund
    if ($originalPayment && $originalPayment['payment_method'] === 'cash') {
        $cashSessionId = (int)($_SESSION['cash_session_id'] ?? 0);
        if ($cashSessionId) {
            $stmt = $pdo->prepare("
                UPDATE cash_sessions 
                SET cash_sales = cash_sales - :amount,
                    refund_count = refund_count + 1,
                    refund_total = refund_total + :refund_amount,
                    updated_at = NOW()
                WHERE id = :session_id
            ");
            $stmt->execute([
                'amount' => $refundAmount,
                'refund_amount' => $refundAmount,
                'session_id' => $cashSessionId
            ]);
        }
    }
    
    // Log refund event
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, event_type, payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, 'refund', :payload, :user_id, NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'payload' => json_encode([
            'refund_id' => $refundId,
            'refund_type' => $refundType,
            'amount' => $refundAmount,
            'reason' => $reason
        ]),
        'user_id' => $userId
    ]);
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'refund' => [
            'id' => $refundId,
            'order_id' => $orderId,
            'type' => $refundType,
            'amount' => round($refundAmount, 2),
            'status' => 'completed',
            'payment_status' => $newPaymentStatus,
            'order_status' => $newOrderStatus
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Refund error: ' . $e->getMessage());
    json_response([
        'success' => false,
        'error' => 'Failed to process refund',
        'details' => $e->getMessage()
    ], 500);
}

/**
 * Validate approval PIN
 */
function validateApprovalPin($pdo, $pin, $capability, $tenantId) {
    if (!$pin) return null;
    
    $stmt = $pdo->prepare("
        SELECT u.id 
        FROM users u
        JOIN pos_role_capabilities rc ON rc.role_key = u.role_key
        WHERE u.tenant_id = :tenant_id
        AND u.pos_pin = :pin
        AND rc.capability_key = :capability
        AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'pin' => hash('sha256', $pin),
        'capability' => $capability
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $result['id'] : null;
}

/**
 * Revoke loyalty points
 */
function revokeLoyaltyPoints($pdo, $customerId, $orderId, $amount, $tenantId) {
    try {
        // Implementation would revoke points based on refund amount
        // Similar to awardLoyaltyPoints but in reverse
    } catch (Exception $e) {
        error_log('Loyalty revoke error: ' . $e->getMessage());
    }
}

/**
 * Check user permission
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
