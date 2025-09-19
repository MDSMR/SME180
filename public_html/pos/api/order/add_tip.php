<?php
/**
 * SME 180 POS - Add Tip API
 * Path: /public_html/pos/api/order/add_tip.php
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

if (!isset($input['order_id'])) {
    die('{"success":false,"error":"Order ID is required"}');
}

$orderId = (int)$input['order_id'];
$tipType = $input['tip_type'] ?? 'amount'; // amount or percent
$tipValue = (float)($input['tip_value'] ?? 0);

if ($tipValue < 0) {
    die('{"success":false,"error":"Tip value cannot be negative"}');
}

try {
    $pdo->beginTransaction();
    
    // Fetch order
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
    
    // Check if order can accept tips
    if ($order['status'] === 'voided' || $order['status'] === 'refunded') {
        die('{"success":false,"error":"Cannot add tip to ' . $order['status'] . ' orders"}');
    }
    
    // Calculate tip amount
    $tipAmount = 0;
    $tipPercent = 0;
    
    if ($tipType === 'percent') {
        $tipPercent = min(100, $tipValue); // Cap at 100%
        $baseAmount = (float)$order['subtotal'] - (float)$order['discount_amount'];
        $tipAmount = $baseAmount * ($tipPercent / 100);
    } else {
        // Direct amount
        $tipAmount = $tipValue;
        // Calculate percentage for reference
        $baseAmount = (float)$order['subtotal'] - (float)$order['discount_amount'];
        if ($baseAmount > 0) {
            $tipPercent = ($tipAmount / $baseAmount) * 100;
        }
    }
    
    // Update order with tip
    $oldTipAmount = (float)$order['tip_amount'];
    $tipDifference = $tipAmount - $oldTipAmount;
    
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET tip_amount = :tip_amount,
            total_amount = total_amount + :tip_difference,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'tip_amount' => $tipAmount,
        'tip_difference' => $tipDifference,
        'order_id' => $orderId
    ]);
    
    // Log tip event
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'tip_added', :details, NOW())
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'old_tip' => $oldTipAmount,
            'new_tip' => $tipAmount,
            'tip_percent' => $tipPercent,
            'type' => $tipType
        ])
    ]);
    
    $pdo->commit();
    
    // Get updated totals
    $stmt = $pdo->prepare("
        SELECT tip_amount, total_amount 
        FROM orders 
        WHERE id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $updatedOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'tip' => [
            'amount' => round((float)$updatedOrder['tip_amount'], 2),
            'percent' => round($tipPercent, 2),
            'previous' => round($oldTipAmount, 2)
        ],
        'total' => round((float)$updatedOrder['total_amount'], 2)
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Add tip error: ' . $e->getMessage());
    die('{"success":false,"error":"Failed to add tip"}');
}
?>