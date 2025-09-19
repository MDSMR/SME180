<?php
/**
 * SME 180 POS - Apply Discount API
 * Path: /public_html/pos/api/order/apply_discount.php
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

if (!isset($input['order_id'])) {
    die('{"success":false,"error":"Order ID is required"}');
}

$orderId = (int)$input['order_id'];
$discountType = $input['discount_type'] ?? 'percent'; // percent or amount
$discountValue = (float)($input['discount_value'] ?? 0);
$reason = $input['reason'] ?? '';
$managerPin = $input['manager_pin'] ?? '';

if ($discountValue <= 0) {
    die('{"success":false,"error":"Invalid discount value"}');
}

try {
    // Get max discount setting
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'pos_max_discount_percent' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $maxDiscountPercent = (float)($stmt->fetchColumn() ?: 50);
    
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
    
    // Check if order can be modified
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        die('{"success":false,"error":"Cannot modify ' . $order['status'] . ' orders"}');
    }
    
    if ($order['payment_status'] === 'paid') {
        die('{"success":false,"error":"Cannot apply discount to paid orders"}');
    }
    
    // Calculate discount amount
    $subtotal = (float)$order['subtotal'];
    $discountAmount = 0;
    
    if ($discountType === 'percent') {
        if ($discountValue > 100) {
            die('{"success":false,"error":"Discount cannot exceed 100%"}');
        }
        
        // Check if discount exceeds maximum allowed
        if ($discountValue > $maxDiscountPercent) {
            // Require manager approval
            if (!$managerPin) {
                $pdo->commit();
                die(json_encode([
                    'success' => false,
                    'error' => 'Manager approval required for discount over ' . $maxDiscountPercent . '%',
                    'requires_approval' => true,
                    'max_allowed' => $maxDiscountPercent
                ]));
            }
            
            // Validate manager PIN
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
                'pin' => hash('sha256', $managerPin)
            ]);
            
            if (!$stmt->fetchColumn()) {
                die('{"success":false,"error":"Invalid manager PIN"}');
            }
        }
        
        $discountAmount = $subtotal * ($discountValue / 100);
    } else {
        // Fixed amount discount
        $discountAmount = min($discountValue, $subtotal);
        
        // Check percentage equivalent
        $percentEquivalent = ($discountAmount / $subtotal) * 100;
        if ($percentEquivalent > $maxDiscountPercent) {
            if (!$managerPin) {
                $pdo->commit();
                die(json_encode([
                    'success' => false,
                    'error' => 'Manager approval required for discount over ' . $maxDiscountPercent . '%',
                    'requires_approval' => true,
                    'max_allowed_amount' => $subtotal * ($maxDiscountPercent / 100)
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
                'pin' => hash('sha256', $managerPin)
            ]);
            
            if (!$stmt->fetchColumn()) {
                die('{"success":false,"error":"Invalid manager PIN"}');
            }
        }
    }
    
    // Recalculate totals
    $newSubtotal = $subtotal - $discountAmount;
    $taxRate = 14; // Default tax rate
    $newTaxAmount = $newSubtotal * ($taxRate / 100);
    $newTotal = $newSubtotal + $newTaxAmount + (float)$order['tip_amount'] + (float)$order['service_charge'];
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET discount_type = :discount_type,
            discount_value = :discount_value,
            discount_amount = :discount_amount,
            tax_amount = :tax_amount,
            total_amount = :total_amount,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    
    $stmt->execute([
        'discount_type' => $discountType,
        'discount_value' => $discountValue,
        'discount_amount' => $discountAmount,
        'tax_amount' => $newTaxAmount,
        'total_amount' => $newTotal,
        'order_id' => $orderId
    ]);
    
    // Log the discount
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'discount_applied', :details, NOW()
        )
    ");
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'type' => $discountType,
            'value' => $discountValue,
            'amount' => $discountAmount,
            'reason' => $reason
        ])
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Discount applied successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'subtotal' => $subtotal,
            'discount' => [
                'type' => $discountType,
                'value' => $discountValue,
                'amount' => round($discountAmount, 2)
            ],
            'new_subtotal' => round($newSubtotal, 2),
            'tax_amount' => round($newTaxAmount, 2),
            'total_amount' => round($newTotal, 2)
        ]
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Apply discount error: ' . $e->getMessage());
    die('{"success":false,"error":"Database error"}');
}
?>