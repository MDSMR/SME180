<?php
/**
 * SME 180 POS - Add Tip API
 * Path: /public_html/pos/api/order/add_tip.php
 * 
 * Adds or updates tip amount for an order
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

// Authentication check
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
    json_response(['success' => false, 'error' => 'Order ID is required'], 400);
}

$orderId = (int)$input['order_id'];
$tipType = $input['tip_type'] ?? 'amount'; // amount or percent
$tipValue = (float)($input['tip_value'] ?? 0);

if ($tipValue < 0) {
    json_response(['success' => false, 'error' => 'Tip value cannot be negative'], 400);
}

try {
    $pdo = db();
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
        json_response(['success' => false, 'error' => 'Order not found'], 404);
    }
    
    // Check if order is already paid (tips can be added during or after payment)
    $canAddTip = true;
    if ($order['status'] === 'voided' || $order['status'] === 'refunded') {
        $canAddTip = false;
    }
    
    if (!$canAddTip) {
        json_response(['success' => false, 'error' => 'Cannot add tip to ' . $order['status'] . ' orders'], 400);
    }
    
    // Get tip settings
    $stmt = $pdo->prepare("
        SELECT `key`, `value` FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('pos_enable_tips', 'pos_tip_calculation_base', 'pos_tip_suggestions')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    if (($settings['pos_enable_tips'] ?? '1') !== '1') {
        json_response(['success' => false, 'error' => 'Tips are not enabled'], 400);
    }
    
    // Calculate tip amount
    $tipAmount = 0;
    $tipPercent = 0;
    
    if ($tipType === 'percent') {
        $tipPercent = min(100, $tipValue); // Cap at 100%
        
        // Determine calculation base
        $calculationBase = $settings['pos_tip_calculation_base'] ?? 'subtotal';
        if ($calculationBase === 'total') {
            $baseAmount = (float)$order['total_amount'] - (float)$order['tip_amount'];
        } else {
            $baseAmount = (float)$order['subtotal_amount'] - (float)$order['discount_amount'];
        }
        
        $tipAmount = $baseAmount * ($tipPercent / 100);
    } else {
        // Direct amount
        $tipAmount = $tipValue;
        
        // Calculate percentage for reference
        $baseAmount = (float)$order['subtotal_amount'] - (float)$order['discount_amount'];
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
            tip_percent = :tip_percent,
            total_amount = total_amount + :tip_difference,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'tip_amount' => $tipAmount,
        'tip_percent' => $tipPercent,
        'tip_difference' => $tipDifference,
        'order_id' => $orderId
    ]);
    
    // If order is already paid and tip is being added, create additional payment record
    if ($order['payment_status'] === 'paid' && $tipDifference > 0) {
        $stmt = $pdo->prepare("
            INSERT INTO order_payments (
                tenant_id, branch_id, order_id,
                payment_method, payment_type, amount,
                currency, status, notes,
                processed_at, processed_by, created_at
            ) VALUES (
                :tenant_id, :branch_id, :order_id,
                :payment_method, 'payment', :amount,
                :currency, 'completed', 'Additional tip',
                NOW(), :user_id, NOW()
            )
        ");
        
        // Use the same payment method as the original payment
        $paymentMethod = $order['payment_method'] ?? 'cash';
        
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'payment_method' => $paymentMethod,
            'amount' => $tipDifference,
            'currency' => 'EGP',
            'user_id' => $userId
        ]);
    }
    
    // Log tip event
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, event_type, payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, 'tip', :payload, :user_id, NOW())
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'payload' => json_encode([
            'old_tip' => $oldTipAmount,
            'new_tip' => $tipAmount,
            'tip_percent' => $tipPercent,
            'type' => $tipType
        ]),
        'user_id' => $userId
    ]);
    
    $pdo->commit();
    
    // Get updated totals
    $stmt = $pdo->prepare("
        SELECT tip_amount, tip_percent, total_amount 
        FROM orders 
        WHERE id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $updatedOrder = $stmt->fetch(PDO::FETCH_ASSOC);
    
    json_response([
        'success' => true,
        'order_id' => $orderId,
        'tip' => [
            'amount' => round((float)$updatedOrder['tip_amount'], 2),
            'percent' => round((float)$updatedOrder['tip_percent'], 2),
            'previous' => round($oldTipAmount, 2)
        ],
        'total' => round((float)$updatedOrder['total_amount'], 2)
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Add tip error: ' . $e->getMessage());
    json_response([
        'success' => false,
        'error' => 'Failed to add tip',
        'details' => $e->getMessage()
    ], 500);
}

/**
 * =============================================================================
 * Set Service Charge API
 * Path: /public_html/pos/api/order/set_service_charge.php
 * =============================================================================
 */

// The code below would be in a separate file: set_service_charge.php

if (basename(__FILE__) === 'set_service_charge.php') {
    
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../middleware/pos_auth.php';
    
    pos_auth_require_login();
    $user = pos_get_current_user();
    
    $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
    $branchId = (int)($_SESSION['branch_id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['order_id'])) {
        json_response(['success' => false, 'error' => 'Order ID is required'], 400);
    }
    
    $orderId = (int)$input['order_id'];
    $action = $input['action'] ?? 'set'; // set, remove, adjust
    $chargeType = $input['charge_type'] ?? 'percent'; // percent or amount
    $chargeValue = (float)($input['charge_value'] ?? 0);
    
    try {
        $pdo = db();
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
            json_response(['success' => false, 'error' => 'Order not found'], 404);
        }
        
        // Check if order can be modified
        if ($order['payment_status'] === 'paid') {
            json_response(['success' => false, 'error' => 'Cannot modify service charge on paid orders'], 400);
        }
        
        if (in_array($order['status'], ['voided', 'refunded'])) {
            json_response(['success' => false, 'error' => 'Cannot modify ' . $order['status'] . ' orders'], 400);
        }
        
        // Get settings
        $stmt = $pdo->prepare("
            SELECT `key`, `value` FROM settings 
            WHERE tenant_id = :tenant_id 
            AND `key` IN (
                'pos_enable_service_charge', 
                'pos_service_charge_percent',
                'pos_service_charge_removable',
                'tax_rate',
                'tax_type'
            )
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }
        
        if (($settings['pos_enable_service_charge'] ?? '1') !== '1') {
            json_response(['success' => false, 'error' => 'Service charges are not enabled'], 400);
        }
        
        // Handle service charge based on action
        $serviceChargeAmount = 0;
        $serviceChargePercent = 0;
        
        switch ($action) {
            case 'remove':
                // Check permission to remove service charge
                if (($settings['pos_service_charge_removable'] ?? '0') !== '1') {
                    $hasPermission = check_user_permission($pdo, $userId, 'pos.remove_service_charge');
                    if (!$hasPermission) {
                        json_response(['success' => false, 'error' => 'No permission to remove service charge'], 403);
                    }
                }
                $serviceChargeAmount = 0;
                $serviceChargePercent = 0;
                break;
                
            case 'adjust':
            case 'set':
                if ($chargeType === 'percent') {
                    $serviceChargePercent = min(100, max(0, $chargeValue));
                    $baseAmount = (float)$order['subtotal_amount'] - (float)$order['discount_amount'];
                    $serviceChargeAmount = $baseAmount * ($serviceChargePercent / 100);
                } else {
                    $serviceChargeAmount = max(0, $chargeValue);
                    $baseAmount = (float)$order['subtotal_amount'] - (float)$order['discount_amount'];
                    if ($baseAmount > 0) {
                        $serviceChargePercent = ($serviceChargeAmount / $baseAmount) * 100;
                    }
                }
                break;
                
            default:
                json_response(['success' => false, 'error' => 'Invalid action'], 400);
        }
        
        // Calculate tax impact
        $taxRate = (float)($settings['tax_rate'] ?? 0);
        $taxType = $settings['tax_type'] ?? 'exclusive';
        
        $oldServiceCharge = (float)$order['service_charge_amount'];
        $serviceDifference = $serviceChargeAmount - $oldServiceCharge;
        
        // Recalculate tax if service charge affects taxable amount
        $taxableAmount = (float)$order['subtotal_amount'] - (float)$order['discount_amount'] + $serviceChargeAmount;
        $taxAmount = 0;
        
        if ($taxRate > 0) {
            if ($taxType === 'inclusive') {
                $taxAmount = $taxableAmount - ($taxableAmount / (1 + $taxRate / 100));
            } else {
                $taxAmount = $taxableAmount * ($taxRate / 100);
            }
        }
        
        // Calculate new total
        $newTotal = (float)$order['subtotal_amount'] - (float)$order['discount_amount'] + 
                   $serviceChargeAmount + (float)$order['tip_amount'];
        
        if ($taxType === 'exclusive') {
            $newTotal += $taxAmount;
        }
        
        // Update order
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET service_charge_amount = :service_amount,
                service_charge_percent = :service_percent,
                tax_amount = :tax_amount,
                total_amount = :total,
                updated_at = NOW()
            WHERE id = :order_id
        ");
        $stmt->execute([
            'service_amount' => $serviceChargeAmount,
            'service_percent' => $serviceChargePercent,
            'tax_amount' => $taxAmount,
            'total' => $newTotal,
            'order_id' => $orderId
        ]);
        
        // Log service charge event
        $stmt = $pdo->prepare("
            INSERT INTO order_item_events (
                tenant_id, order_id, event_type, payload, created_by, created_at
            ) VALUES (
                :tenant_id, :order_id, 'service_charge', :payload, :user_id, NOW()
            )
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'payload' => json_encode([
                'action' => $action,
                'old_amount' => $oldServiceCharge,
                'new_amount' => $serviceChargeAmount,
                'percent' => $serviceChargePercent
            ]),
            'user_id' => $userId
        ]);
        
        $pdo->commit();
        
        // Get updated order
        $stmt = $pdo->prepare("
            SELECT service_charge_amount, service_charge_percent, tax_amount, total_amount 
            FROM orders 
            WHERE id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);
        $updatedOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        json_response([
            'success' => true,
            'order_id' => $orderId,
            'service_charge' => [
                'amount' => round((float)$updatedOrder['service_charge_amount'], 2),
                'percent' => round((float)$updatedOrder['service_charge_percent'], 2),
                'previous' => round($oldServiceCharge, 2)
            ],
            'tax' => round((float)$updatedOrder['tax_amount'], 2),
            'total' => round((float)$updatedOrder['total_amount'], 2)
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        error_log('Set service charge error: ' . $e->getMessage());
        json_response([
            'success' => false,
            'error' => 'Failed to set service charge',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Shared helper functions
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

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>
