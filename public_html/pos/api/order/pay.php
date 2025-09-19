<?php
/**
 * SME 180 POS - Pay Order API
 * Path: /public_html/pos/api/order/pay.php
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
$sessionId = (int)($_SESSION['cash_session_id'] ?? 0);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    die('{"success":false,"error":"Invalid request body"}');
}

if (!isset($input['order_id'])) {
    die('{"success":false,"error":"Order ID is required"}');
}

if (!isset($input['payments']) || !is_array($input['payments'])) {
    die('{"success":false,"error":"Payments array is required"}');
}

$orderId = (int)$input['order_id'];
$payments = $input['payments'];
$printReceipt = (bool)($input['print_receipt'] ?? true);

try {
    $pdo->beginTransaction();
    
    // Get order with lock
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
    
    // Check if order can be paid
    if ($order['payment_status'] === 'paid') {
        die('{"success":false,"error":"Order is already paid"}');
    }
    
    if (in_array($order['status'], ['voided', 'refunded'])) {
        die('{"success":false,"error":"Cannot pay ' . $order['status'] . ' orders"}');
    }
    
    // Calculate total payment amount
    $totalPayment = 0;
    $paymentMethods = [];
    
    foreach ($payments as $payment) {
        if (!isset($payment['method']) || !isset($payment['amount'])) {
            die('{"success":false,"error":"Invalid payment structure"}');
        }
        
        $amount = (float)$payment['amount'];
        if ($amount <= 0) {
            die('{"success":false,"error":"Payment amount must be positive"}');
        }
        
        $totalPayment += $amount;
        $paymentMethods[] = $payment['method'];
    }
    
    // Check if payment covers the order total
    $orderTotal = (float)$order['total_amount'];
    $amountDue = $orderTotal - (float)$order['paid_amount'];
    
    if ($totalPayment < $amountDue - 0.01) {
        die(json_encode([
            'success' => false,
            'error' => 'Insufficient payment',
            'amount_due' => $amountDue,
            'payment_received' => $totalPayment,
            'shortage' => $amountDue - $totalPayment
        ]));
    }
    
    // Calculate change if overpayment
    $changeAmount = max(0, $totalPayment - $amountDue);
    
    // Process each payment
    $paymentIds = [];
    foreach ($payments as $payment) {
        $method = $payment['method'];
        $amount = (float)$payment['amount'];
        $reference = $payment['reference'] ?? null;
        
        // Adjust last payment for exact amount
        if ($payment === end($payments) && $changeAmount > 0) {
            $amount = $amount - $changeAmount;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO order_payments (
                order_id, tenant_id, branch_id,
                payment_method, amount, reference_number,
                processed_by, status, created_at
            ) VALUES (
                :order_id, :tenant_id, :branch_id,
                :method, :amount, :reference,
                :user_id, 'completed', NOW()
            )
        ");
        
        $stmt->execute([
            'order_id' => $orderId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'method' => $method,
            'amount' => $amount,
            'reference' => $reference,
            'user_id' => $userId
        ]);
        
        $paymentIds[] = (int)$pdo->lastInsertId();
    }
    
    // Update order status
    $newPaidAmount = (float)$order['paid_amount'] + $totalPayment - $changeAmount;
    $isFullyPaid = $newPaidAmount >= $orderTotal - 0.01;
    
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET paid_amount = :paid_amount,
            payment_status = :payment_status,
            status = CASE WHEN :is_paid THEN 'closed' ELSE status END,
            payment_method = :payment_method,
            paid_at = CASE WHEN :is_paid2 THEN NOW() ELSE paid_at END,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    
    $stmt->execute([
        'paid_amount' => $newPaidAmount,
        'payment_status' => $isFullyPaid ? 'paid' : 'partial',
        'is_paid' => $isFullyPaid,
        'is_paid2' => $isFullyPaid,
        'payment_method' => implode(',', array_unique($paymentMethods)),
        'order_id' => $orderId
    ]);
    
    // Free the table if dine-in and fully paid
    if ($isFullyPaid && $order['order_type'] === 'dine_in' && $order['table_id']) {
        try {
            $stmt = $pdo->prepare("
                UPDATE dining_tables 
                SET status = 'available',
                    current_order_id = NULL,
                    updated_at = NOW()
                WHERE id = :table_id
            ");
            $stmt->execute(['table_id' => $order['table_id']]);
        } catch (PDOException $e) {
            // Table might not exist
        }
    }
    
    // Log the payment
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            :action, :details, NOW()
        )
    ");
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'action' => $isFullyPaid ? 'paid_full' : 'paid_partial',
        'details' => json_encode([
            'payments' => $payments,
            'total_payment' => $totalPayment,
            'change' => $changeAmount,
            'payment_status' => $isFullyPaid ? 'paid' : 'partial'
        ])
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => $isFullyPaid ? 'Payment successful' : 'Partial payment received',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'total_amount' => $orderTotal,
            'paid_amount' => $newPaidAmount,
            'payment_status' => $isFullyPaid ? 'paid' : 'partial',
            'status' => $isFullyPaid ? 'closed' : $order['status']
        ],
        'payment' => [
            'total_received' => $totalPayment,
            'change' => $changeAmount,
            'payment_ids' => $paymentIds
        ],
        'print_queued' => $printReceipt && $isFullyPaid
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Pay order error: ' . $e->getMessage());
    die('{"success":false,"error":"Failed to process payment"}');
}
?>