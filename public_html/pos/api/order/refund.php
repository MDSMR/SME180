<?php
/**
 * SME 180 POS - Order Refund API
 * Path: /public_html/pos/api/order/refund.php
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    die('{"success":true}');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    require_once __DIR__ . '/../../../config/db.php';
    $pdo = db();
} catch (Exception $e) {
    die('{"success":false,"error":"Database connection failed"}');
}

$tenantId = (int)($_SESSION['tenant_id'] ?? 1);
$branchId = (int)($_SESSION['branch_id'] ?? 1);
$userId = (int)($_SESSION['user_id'] ?? 1);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    die('{"success":false,"error":"Invalid request body"}');
}

if (!isset($input['order_id']) || !isset($input['refund_type']) || !isset($input['reason'])) {
    die('{"success":false,"error":"Order ID, refund type, and reason are required"}');
}

$orderId = (int)$input['order_id'];
$refundType = $input['refund_type']; // full, partial, item
$reason = $input['reason'];
$amount = (float)($input['amount'] ?? 0);
$itemIds = $input['item_ids'] ?? [];
$approvalPin = $input['approval_pin'] ?? null;

try {
    $pdo->beginTransaction();
    
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
        die('{"success":false,"error":"Order not found"}');
    }
    
    // Validate order status
    if ($order['payment_status'] !== 'paid' && $order['payment_status'] !== 'partial') {
        die('{"success":false,"error":"Can only refund paid orders"}');
    }
    
    if ($order['status'] === 'refunded') {
        die('{"success":false,"error":"Order is already refunded"}');
    }
    
    $totalPaid = (float)($order['paid_amount'] ?? $order['total_amount']);
    $totalRefunded = (float)($order['refunded_amount'] ?? 0);
    $availableToRefund = $totalPaid - $totalRefunded;
    
    // Calculate refund amount based on type
    $refundAmount = 0;
    
    switch ($refundType) {
        case 'full':
            $refundAmount = $availableToRefund;
            break;
            
        case 'partial':
            if ($amount <= 0 || $amount > $availableToRefund) {
                die(json_encode([
                    'success' => false,
                    'error' => 'Invalid refund amount',
                    'available' => $availableToRefund
                ]));
            }
            $refundAmount = $amount;
            break;
            
        case 'item':
            if (empty($itemIds)) {
                die('{"success":false,"error":"No items selected for refund"}');
            }
            
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT SUM(line_total) as refund_amount 
                FROM order_items 
                WHERE order_id = ? 
                AND id IN ($placeholders)
                AND is_voided = 0
            ");
            $params = array_merge([$orderId], $itemIds);
            $stmt->execute($params);
            $refundAmount = (float)$stmt->fetchColumn();
            break;
            
        default:
            die('{"success":false,"error":"Invalid refund type"}');
    }
    
    if ($refundAmount <= 0) {
        die('{"success":false,"error":"Invalid refund amount"}');
    }
    
    // Check for manager approval if needed
    $userRole = $_SESSION['role'] ?? 'cashier';
    $approvedBy = $userId;
    
    if (!in_array($userRole, ['admin', 'manager'])) {
        if (!$approvalPin) {
            $pdo->commit();
            die(json_encode([
                'success' => false,
                'requires_approval' => true,
                'message' => 'Manager approval required for refund'
            ]));
        }
        
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
            'pin' => hash('sha256', $approvalPin)
        ]);
        
        $managerId = $stmt->fetchColumn();
        if (!$managerId) {
            die('{"success":false,"error":"Invalid approval PIN"}');
        }
        $approvedBy = $managerId;
    }
    
    // Create refund record (check if table exists)
    try {
        $stmt = $pdo->prepare("
            INSERT INTO order_refunds (
                tenant_id, branch_id, order_id,
                refund_type, amount, currency, reason,
                status, approved_by, approved_at,
                processed_by, processed_at, created_at
            ) VALUES (
                :tenant_id, :branch_id, :order_id,
                :refund_type, :amount, 'EGP', :reason,
                'completed', :approved_by, NOW(),
                :processed_by, NOW(), NOW()
            )
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'refund_type' => $refundType,
            'amount' => $refundAmount,
            'reason' => $reason,
            'approved_by' => $approvedBy,
            'processed_by' => $userId
        ]);
        $refundId = $pdo->lastInsertId();
    } catch (PDOException $e) {
        // Table might not exist, continue without it
        $refundId = 0;
    }
    
    // Update order status
    $newPaymentStatus = ($refundAmount >= $availableToRefund) ? 'refunded' : 'partial_refund';
    $newOrderStatus = ($refundType === 'full') ? 'refunded' : $order['status'];
    
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET payment_status = :payment_status,
            status = :status,
            refunded_amount = COALESCE(refunded_amount, 0) + :amount,
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
    if ($refundType === 'item' && !empty($itemIds)) {
        $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET is_voided = 1,
                void_reason = 'Refunded',
                voided_at = NOW(),
                voided_by = ?
            WHERE id IN ($placeholders)
        ");
        $params = array_merge([$userId], $itemIds);
        $stmt->execute($params);
    }
    
    // Log refund event
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'refunded', :details, NOW()
        )
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'refund_id' => $refundId,
            'refund_type' => $refundType,
            'amount' => $refundAmount,
            'reason' => $reason
        ])
    ]);
    
    $pdo->commit();
    
    echo json_encode([
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
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Refund error: ' . $e->getMessage());
    die('{"success":false,"error":"Failed to process refund"}');
}
?>