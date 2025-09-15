<?php
/**
 * SME 180 POS - Order Payment API
 * Path: /public_html/pos/api/order/pay.php
 * 
 * Processes payments for orders including split payments, tips, and change calculation
 * Supports multiple payment methods and partial payments
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
$cashSessionId = (int)($_SESSION['cash_session_id'] ?? 0);

if (!$tenantId || !$branchId || !$userId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

// Parse request
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_response(['success' => false, 'error' => 'Invalid request body'], 400);
}

// Validate required fields
if (!isset($input['order_id']) || !isset($input['payments'])) {
    json_response(['success' => false, 'error' => 'Order ID and payments are required'], 400);
}

$orderId = (int)$input['order_id'];
$payments = $input['payments'];
$tipAmount = (float)($input['tip_amount'] ?? 0);
$tipPercent = (float)($input['tip_percent'] ?? 0);

// Validate payments array
if (!is_array($payments) || empty($payments)) {
    json_response(['success' => false, 'error' => 'At least one payment method is required'], 400);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Lock and fetch order
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
    
    // Check if all items are ready for payment (optional based on settings)
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_items
        FROM order_items 
        WHERE order_id = :order_id 
        AND is_voided = 0
        AND fire_status = 'pending'
    ");
    $stmt->execute(['order_id' => $orderId]);
    $pendingItems = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value` 
        FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('pos_require_fire_before_payment', 'currency_symbol')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    if (($settings['pos_require_fire_before_payment'] ?? '0') === '1' && $pendingItems['pending_items'] > 0) {
        json_response(['success' => false, 'error' => 'All items must be sent to kitchen before payment'], 400);
    }
    
    $currencySymbol = $settings['currency_symbol'] ?? 'EGP';
    
    // Calculate total amount due including tip
    $totalDue = (float)$order['total_amount'];
    
    // Handle tip
    if ($tipAmount > 0) {
        // Direct tip amount
        $finalTipAmount = $tipAmount;
    } elseif ($tipPercent > 0) {
        // Calculate tip from percentage
        $tipBase = (float)$order['subtotal_amount'] - (float)$order['discount_amount'];
        $finalTipAmount = $tipBase * ($tipPercent / 100);
    } else {
        $finalTipAmount = 0;
    }
    
    $totalDue += $finalTipAmount;
    
    // Validate and process payments
    $totalPaid = 0;
    $paymentMethods = [];
    $processedPayments = [];
    
    foreach ($payments as $payment) {
        if (!isset($payment['method']) || !isset($payment['amount'])) {
            json_response(['success' => false, 'error' => 'Invalid payment structure'], 400);
        }
        
        $method = $payment['method'];
        $amount = (float)$payment['amount'];
        
        // Validate payment method
        $validMethods = ['cash', 'card', 'online', 'wallet', 'voucher', 'loyalty'];
        if (!in_array($method, $validMethods)) {
            json_response(['success' => false, 'error' => 'Invalid payment method: ' . $method], 400);
        }
        
        if ($amount <= 0) {
            json_response(['success' => false, 'error' => 'Payment amount must be positive'], 400);
        }
        
        $totalPaid += $amount;
        $paymentMethods[] = $method;
        
        // Store payment details
        $processedPayments[] = [
            'method' => $method,
            'amount' => $amount,
            'reference' => $payment['reference'] ?? null,
            'card_last_four' => $payment['card_last_four'] ?? null,
            'card_type' => $payment['card_type'] ?? null,
            'gateway_response' => $payment['gateway_response'] ?? null
        ];
    }
    
    // Check if payment is sufficient
    if ($totalPaid < $totalDue) {
        json_response([
            'success' => false,
            'error' => 'Insufficient payment',
            'total_due' => $totalDue,
            'total_paid' => $totalPaid,
            'remaining' => $totalDue - $totalPaid
        ], 400);
    }
    
    // Calculate change
    $changeAmount = $totalPaid - $totalDue;
    
    // Determine payment status and method
    $paymentStatus = 'paid';
    $paymentMethod = count(array_unique($paymentMethods)) > 1 ? 'split' : $paymentMethods[0];
    
    // Insert payment records
    $paymentStmt = $pdo->prepare("
        INSERT INTO order_payments (
            tenant_id, branch_id, order_id, payment_method, payment_type,
            amount, currency, reference_number, card_last_four, card_type,
            gateway_response, status, processed_at, processed_by,
            created_at
        ) VALUES (
            :tenant_id, :branch_id, :order_id, :payment_method, 'payment',
            :amount, :currency, :reference, :card_last_four, :card_type,
            :gateway_response, 'completed', NOW(), :user_id,
            NOW()
        )
    ");
    
    foreach ($processedPayments as $payment) {
        $paymentStmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'payment_method' => $payment['method'],
            'amount' => $payment['amount'],
            'currency' => $currencySymbol,
            'reference' => $payment['reference'],
            'card_last_four' => $payment['card_last_four'],
            'card_type' => $payment['card_type'],
            'gateway_response' => $payment['gateway_response'] ? json_encode($payment['gateway_response']) : null,
            'user_id' => $userId
        ]);
    }
    
    // Update order with payment information
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET payment_status = :payment_status,
            payment_method = :payment_method,
            tip_amount = :tip_amount,
            tip_percent = :tip_percent,
            total_amount = :total_amount,
            status = CASE 
                WHEN status = 'open' THEN 'closed'
                WHEN status = 'ready' THEN 'closed'
                ELSE status
            END,
            closed_at = NOW(),
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'payment_status' => $paymentStatus,
        'payment_method' => $paymentMethod,
        'tip_amount' => $finalTipAmount,
        'tip_percent' => $tipPercent,
        'total_amount' => $totalDue,
        'order_id' => $orderId
    ]);
    
    // Update cash session if cash payment
    if (in_array('cash', $paymentMethods) && $cashSessionId) {
        $cashAmount = 0;
        foreach ($processedPayments as $payment) {
            if ($payment['method'] === 'cash') {
                $cashAmount += $payment['amount'];
            }
        }
        
        $stmt = $pdo->prepare("
            UPDATE cash_sessions 
            SET cash_sales = cash_sales + :amount,
                total_sales = total_sales + :total,
                transaction_count = transaction_count + 1,
                updated_at = NOW()
            WHERE id = :session_id
        ");
        $stmt->execute([
            'amount' => $cashAmount - $changeAmount, // Net cash after change
            'total' => $totalDue,
            'session_id' => $cashSessionId
        ]);
    }
    
    // Update table status if dine-in
    if ($order['order_type'] === 'dine_in' && $order['table_id']) {
        $stmt = $pdo->prepare("
            UPDATE dining_tables 
            SET is_occupied = 0,
                last_cleared_at = NOW()
            WHERE id = :table_id
        ");
        $stmt->execute(['table_id' => $order['table_id']]);
    }
    
    // Award loyalty points if customer exists
    if ($order['customer_id']) {
        awardLoyaltyPoints($pdo, $order['customer_id'], $orderId, $totalDue, $tenantId);
    }
    
    // Log payment event
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, event_type, payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, 'payment', :payload, :user_id, NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'payload' => json_encode([
            'total_paid' => $totalPaid,
            'total_due' => $totalDue,
            'change' => $changeAmount,
            'tip' => $finalTipAmount,
            'payment_methods' => $paymentMethods
        ]),
        'user_id' => $userId
    ]);
    
    $pdo->commit();
    
    // Prepare response
    $response = [
        'success' => true,
        'order_id' => $orderId,
        'payment' => [
            'status' => $paymentStatus,
            'method' => $paymentMethod,
            'total_due' => round($totalDue, 2),
            'total_paid' => round($totalPaid, 2),
            'change' => round($changeAmount, 2),
            'tip' => round($finalTipAmount, 2),
            'currency' => $currencySymbol
        ],
        'receipt' => [
            'reference' => $order['receipt_reference'],
            'print_receipt' => true
        ]
    ];
    
    json_response($response);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Payment processing error: ' . $e->getMessage());
    json_response([
        'success' => false,
        'error' => 'Failed to process payment',
        'details' => $e->getMessage()
    ], 500);
}

/**
 * Award loyalty points to customer
 */
function awardLoyaltyPoints($pdo, $customerId, $orderId, $amount, $tenantId) {
    try {
        // Get loyalty settings
        $stmt = $pdo->prepare("
            SELECT `key`, `value` 
            FROM settings 
            WHERE tenant_id = :tenant_id 
            AND `key` LIKE 'loyalty_%'
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }
        
        if (($settings['loyalty_enabled'] ?? '0') !== '1') {
            return;
        }
        
        $pointsPerCurrency = (int)($settings['loyalty_points_per_currency'] ?? 1);
        $pointsEarned = floor($amount * $pointsPerCurrency);
        
        if ($pointsEarned <= 0) {
            return;
        }
        
        // Update customer points
        $stmt = $pdo->prepare("
            UPDATE customers 
            SET points_balance = points_balance + :points,
                lifetime_points = lifetime_points + :points,
                updated_at = NOW()
            WHERE id = :customer_id
        ");
        $stmt->execute([
            'points' => $pointsEarned,
            'customer_id' => $customerId
        ]);
        
        // Log points transaction
        $stmt = $pdo->prepare("
            INSERT INTO loyalty_ledger (
                tenant_id, customer_id, order_id, type,
                points_delta, balance_after, description,
                created_at
            ) VALUES (
                :tenant_id, :customer_id, :order_id, 'points_earn',
                :points, 
                (SELECT points_balance FROM customers WHERE id = :cust_id),
                :description,
                NOW()
            )
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'customer_id' => $customerId,
            'order_id' => $orderId,
            'points' => $pointsEarned,
            'cust_id' => $customerId,
            'description' => 'Points earned from order #' . $orderId
        ]);
        
    } catch (Exception $e) {
        // Loyalty system errors should not fail the payment
        error_log('Loyalty points error: ' . $e->getMessage());
    }
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
