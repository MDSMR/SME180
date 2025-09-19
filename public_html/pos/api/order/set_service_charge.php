<?php
/**
 * SME 180 POS - Set Service Charge API
 * Path: /public_html/pos/api/order/set_service_charge.php
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
$action = $input['action'] ?? 'set'; // set, remove, adjust
$chargeType = $input['charge_type'] ?? 'percent'; // percent or amount
$chargeValue = (float)($input['charge_value'] ?? 0);

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
    
    // Check if order can be modified
    if ($order['payment_status'] === 'paid') {
        die('{"success":false,"error":"Cannot modify service charge on paid orders"}');
    }
    
    if (in_array($order['status'], ['voided', 'refunded'])) {
        die('{"success":false,"error":"Cannot modify ' . $order['status'] . ' orders"}');
    }
    
    // Handle service charge based on action
    $serviceChargeAmount = 0;
    $serviceChargePercent = 0;
    
    switch ($action) {
        case 'remove':
            $serviceChargeAmount = 0;
            $serviceChargePercent = 0;
            break;
            
        case 'adjust':
        case 'set':
            if ($chargeType === 'percent') {
                $serviceChargePercent = min(100, max(0, $chargeValue));
                $baseAmount = (float)$order['subtotal'] - (float)$order['discount_amount'];
                $serviceChargeAmount = $baseAmount * ($serviceChargePercent / 100);
            } else {
                $serviceChargeAmount = max(0, $chargeValue);
                $baseAmount = (float)$order['subtotal'] - (float)$order['discount_amount'];
                if ($baseAmount > 0) {
                    $serviceChargePercent = ($serviceChargeAmount / $baseAmount) * 100;
                }
            }
            break;
            
        default:
            die('{"success":false,"error":"Invalid action"}');
    }
    
    $oldServiceCharge = (float)$order['service_charge'];
    
    // Recalculate totals
    $taxRate = 14; // Default tax rate
    $taxableAmount = (float)$order['subtotal'] - (float)$order['discount_amount'] + $serviceChargeAmount;
    $taxAmount = $taxableAmount * ($taxRate / 100);
    $newTotal = $taxableAmount + $taxAmount + (float)$order['tip_amount'];
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET service_charge = :service_amount,
            tax_amount = :tax_amount,
            total_amount = :total,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'service_amount' => $serviceChargeAmount,
        'tax_amount' => $taxAmount,
        'total' => $newTotal,
        'order_id' => $orderId
    ]);
    
    // Log service charge event
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'service_charge_updated', :details, NOW())
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'action' => $action,
            'old_amount' => $oldServiceCharge,
            'new_amount' => $serviceChargeAmount,
            'percent' => $serviceChargePercent
        ])
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'service_charge' => [
            'amount' => round($serviceChargeAmount, 2),
            'percent' => round($serviceChargePercent, 2),
            'previous' => round($oldServiceCharge, 2)
        ],
        'tax' => round($taxAmount, 2),
        'total' => round($newTotal, 2)
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Set service charge error: ' . $e->getMessage());
    die('{"success":false,"error":"Failed to set service charge"}');
}
?>