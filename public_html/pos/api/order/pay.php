<?php
/**
 * SME 180 POS - Pay Order API
 * Path: /public_html/pos/api/order/pay.php
 * 
 * Processes payment for orders - supports multiple payment methods and split payments
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function for JSON responses
function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Authentication check
    pos_auth_require_login();
    $user = pos_get_current_user();
    
    if (!$user) {
        json_response(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    // Get tenant and branch from session
    $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
    $branchId = (int)($_SESSION['branch_id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $sessionId = (int)($_SESSION['cash_session_id'] ?? 0);
    
    if (!$tenantId || !$branchId || !$userId) {
        json_response(['success' => false, 'error' => 'Invalid session'], 401);
    }
    
    // Parse request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_response(['success' => false, 'error' => 'Invalid request body'], 400);
    }
    
    if (!isset($input['order_id'])) {
        json_response(['success' => false, 'error' => 'Order ID is required'], 400);
    }
    
    if (!isset($input['payments']) || !is_array($input['payments'])) {
        json_response(['success' => false, 'error' => 'Payments array is required'], 400);
    }
    
    $orderId = (int)$input['order_id'];
    $payments = $input['payments'];
    $printReceipt = (bool)($input['print_receipt'] ?? true);
    
    // Get database connection
    $pdo = db();
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
        json_response(['success' => false, 'error' => 'Order not found'], 404);
    }
    
    // Check if order can be paid
    if ($order['payment_status'] === 'paid') {
        json_response(['success' => false, 'error' => 'Order is already paid'], 400);
    }
    
    if (in_array($order['status'], ['voided', 'refunded'])) {
        json_response(['success' => false, 'error' => 'Cannot pay ' . $order['status'] . ' orders'], 400);
    }
    
    // Calculate total payment amount
    $totalPayment = 0;
    $paymentMethods = [];
    
    foreach ($payments as $payment) {
        if (!isset($payment['method']) || !isset($payment['amount'])) {
            json_response(['success' => false, 'error' => 'Invalid payment structure'], 400);
        }
        
        $amount = (float)$payment['amount'];
        if ($amount <= 0) {
            json_response(['success' => false, 'error' => 'Payment amount must be positive'], 400);
        }
        
        $totalPayment += $amount;
        $paymentMethods[] = $payment['method'];
    }
    
    // Check if payment covers the order total
    $orderTotal = (float)$order['total_amount'];
    $amountDue = $orderTotal - (float)$order['paid_amount'];
    
    if ($totalPayment < $amountDue - 0.01) { // Allow for small rounding differences
        json_response([
            'success' => false, 
            'error' => 'Insufficient payment',
            'amount_due' => $amountDue,
            'payment_received' => $totalPayment,
            'shortage' => $amountDue - $totalPayment
        ], 400);
    }
    
    // Calculate change if overpayment
    $changeAmount = max(0, $totalPayment - $amountDue);
    
    // Get currency symbol
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'currency_symbol' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $currencySymbol = $stmt->fetchColumn() ?: '$';
    
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
                processed_by, cash_session_id, created_at
            ) VALUES (
                :order_id, :tenant_id, :branch_id,
                :method, :amount, :reference,
                :user_id, :session_id, NOW()
            )
        ");
        
        $stmt->execute([
            'order_id' => $orderId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'method' => $method,
            'amount' => $amount,
            'reference' => $reference,
            'user_id' => $userId,
            'session_id' => $sessionId ?: null
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
    
    // Update cash session if cash payment
    $cashAmount = 0;
    foreach ($payments as $payment) {
        if ($payment['method'] === 'cash') {
            $cashAmount += (float)$payment['amount'] - ($payment === end($payments) ? $changeAmount : 0);
        }
    }
    
    if ($cashAmount > 0 && $sessionId) {
        $stmt = $pdo->prepare("
            UPDATE pos_cash_sessions 
            SET total_sales = total_sales + :amount,
                cash_sales = cash_sales + :amount,
                transaction_count = transaction_count + 1,
                updated_at = NOW()
            WHERE id = :session_id
        ");
        $stmt->execute([
            'amount' => $cashAmount,
            'session_id' => $sessionId
        ]);
    }
    
    // Free the table if dine-in and fully paid
    if ($isFullyPaid && $order['order_type'] === 'dine_in' && $order['table_id']) {
        $stmt = $pdo->prepare("
            UPDATE dining_tables 
            SET status = 'available',
                current_order_id = NULL,
                updated_at = NOW()
            WHERE id = :table_id
        ");
        $stmt->execute(['table_id' => $order['table_id']]);
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
    
    // Queue receipt printing if requested
    if ($printReceipt && $isFullyPaid) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO print_queue (
                    tenant_id, branch_id, document_type,
                    document_id, station_id, priority,
                    status, created_at
                ) VALUES (
                    :tenant_id, :branch_id, 'receipt',
                    :order_id, :station_id, 1,
                    'pending', NOW()
                )
            ");
            
            $stmt->execute([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'order_id' => $orderId,
                'station_id' => $_SESSION['station_id'] ?? null
            ]);
        } catch (PDOException $e) {
            // Print queue table might not exist, ignore
        }
    }
    
    $pdo->commit();
    
    json_response([
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
            'currency_symbol' => $currencySymbol,
            'payment_ids' => $paymentIds
        ],
        'print_queued' => $printReceipt && $isFullyPaid
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Pay order DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Pay order error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
