<?php
/**
 * SME 180 POS - Apply Discount API
 * Path: /public_html/pos/api/order/apply_discount.php
 * 
 * Applies discounts to orders with validation and approval workflows
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
    
    $orderId = (int)$input['order_id'];
    $discountType = $input['discount_type'] ?? 'percent'; // percent or amount
    $discountValue = (float)($input['discount_value'] ?? 0);
    $discountSource = $input['discount_source'] ?? 'manual'; // manual, coupon, promotion
    $discountCode = $input['discount_code'] ?? null;
    $reason = $input['reason'] ?? '';
    
    if ($discountValue <= 0) {
        json_response(['success' => false, 'error' => 'Invalid discount value'], 400);
    }
    
    // Get database connection
    $pdo = db();
    
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
        json_response(['success' => false, 'error' => 'Order not found'], 404);
    }
    
    // Check if order can be modified
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        json_response(['success' => false, 'error' => 'Cannot modify ' . $order['status'] . ' orders'], 400);
    }
    
    if ($order['payment_status'] === 'paid') {
        json_response(['success' => false, 'error' => 'Cannot apply discount to paid orders'], 400);
    }
    
    // Calculate discount amount
    $subtotal = (float)$order['subtotal'];
    $discountAmount = 0;
    
    if ($discountType === 'percent') {
        if ($discountValue > 100) {
            json_response(['success' => false, 'error' => 'Discount cannot exceed 100%'], 400);
        }
        
        // Check if discount exceeds maximum allowed
        if ($discountValue > $maxDiscountPercent) {
            // Require manager approval
            $managerPin = $input['manager_pin'] ?? '';
            
            if (!$managerPin) {
                json_response([
                    'success' => false,
                    'error' => 'Manager approval required for discount over ' . $maxDiscountPercent . '%',
                    'requires_approval' => true,
                    'max_allowed' => $maxDiscountPercent
                ], 403);
            }
            
            // Validate manager PIN
            $stmt = $pdo->prepare("
                SELECT id, name FROM users 
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
            
            $manager = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$manager) {
                json_response(['success' => false, 'error' => 'Invalid manager PIN'], 403);
            }
        }
        
        $discountAmount = $subtotal * ($discountValue / 100);
    } else {
        // Fixed amount discount
        $discountAmount = $discountValue;
        
        if ($discountAmount > $subtotal) {
            json_response(['success' => false, 'error' => 'Discount cannot exceed order subtotal'], 400);
        }
        
        // Check percentage equivalent
        $percentEquivalent = ($discountAmount / $subtotal) * 100;
        if ($percentEquivalent > $maxDiscountPercent) {
            $managerPin = $input['manager_pin'] ?? '';
            
            if (!$managerPin) {
                json_response([
                    'success' => false,
                    'error' => 'Manager approval required for discount over ' . $maxDiscountPercent . '%',
                    'requires_approval' => true,
                    'max_allowed_amount' => $subtotal * ($maxDiscountPercent / 100)
                ], 403);
            }
            
            // Validate manager PIN (same as above)
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
                json_response(['success' => false, 'error' => 'Invalid manager PIN'], 403);
            }
        }
    }
    
    // Validate discount code if provided
    if ($discountSource === 'coupon' && $discountCode) {
        $stmt = $pdo->prepare("
            SELECT * FROM discount_codes 
            WHERE code = :code 
            AND tenant_id = :tenant_id
            AND is_active = 1
            AND (valid_from IS NULL OR valid_from <= NOW())
            AND (valid_until IS NULL OR valid_until >= NOW())
            AND (usage_limit IS NULL OR usage_count < usage_limit)
            LIMIT 1
        ");
        
        try {
            $stmt->execute([
                'code' => $discountCode,
                'tenant_id' => $tenantId
            ]);
            
            $coupon = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$coupon) {
                json_response(['success' => false, 'error' => 'Invalid or expired discount code'], 400);
            }
            
            // Update usage count
            $stmt = $pdo->prepare("
                UPDATE discount_codes 
                SET usage_count = usage_count + 1 
                WHERE id = :id
            ");
            $stmt->execute(['id' => $coupon['id']]);
            
        } catch (PDOException $e) {
            // Discount codes table might not exist, continue
        }
    }
    
    // Get tax rate for recalculation
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'pos_tax_rate' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $taxRate = (float)($stmt->fetchColumn() ?: 0);
    
    // Recalculate order totals
    $newSubtotal = $subtotal - $discountAmount;
    $serviceCharge = (float)$order['service_charge'];
    $taxableAmount = $newSubtotal + $serviceCharge;
    $newTaxAmount = $taxRate > 0 ? ($taxableAmount * $taxRate / 100) : 0;
    $newTotal = $newSubtotal + $serviceCharge + $newTaxAmount + (float)$order['tip_amount'];
    
    // Update order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET discount_type = :discount_type,
            discount_value = :discount_value,
            discount_amount = :discount_amount,
            discount_source = :discount_source,
            discount_code = :discount_code,
            tax_amount = :tax_amount,
            total_amount = :total_amount,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    
    $stmt->execute([
        'discount_type' => $discountType,
        'discount_value' => $discountValue,
        'discount_amount' => $discountAmount,
        'discount_source' => $discountSource,
        'discount_code' => $discountCode,
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
            'source' => $discountSource,
            'code' => $discountCode,
            'reason' => $reason
        ])
    ]);
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'message' => 'Discount applied successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'subtotal' => $subtotal,
            'discount' => [
                'type' => $discountType,
                'value' => $discountValue,
                'amount' => $discountAmount,
                'source' => $discountSource
            ],
            'new_subtotal' => $newSubtotal,
            'tax_amount' => $newTaxAmount,
            'total_amount' => $newTotal,
            'savings' => $discountAmount
        ]
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Apply discount DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Apply discount error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
