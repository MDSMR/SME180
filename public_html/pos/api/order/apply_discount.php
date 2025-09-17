## 5. /public_html/pos/api/order/apply_discount.php
```php
<?php
/**
 * SME 180 POS - Apply Discount API
 * Path: /public_html/pos/api/order/apply_discount.php
 * 
 * Applies discounts to orders or items
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

pos_auth_require_login();
$user = pos_get_current_user();

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!$tenantId || !$branchId || !$userId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['order_id']) || !isset($input['discount_type'])) {
    json_response(['success' => false, 'error' => 'Order ID and discount type are required'], 400);
}

$orderId = (int)$input['order_id'];
$discountType = $input['discount_type']; // fixed, percent, voucher, promo
$discountValue = (float)($input['discount_value'] ?? 0);
$discountSource = $input['discount_source'] ?? 'manual';
$discountName = $input['discount_name'] ?? 'Manual Discount';
$promoCode = $input['promo_code'] ?? null;
$voucherId = $input['voucher_id'] ?? null;
$itemId = $input['item_id'] ?? null; // For item-level discounts
$approvalPin = $input['approval_pin'] ?? null;

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Check permission for manual discounts
    if ($discountSource === 'manual') {
        $hasPermission = check_user_permission($pdo, $userId, 'pos.apply_discount');
        if (!$hasPermission) {
            // Check for approval
            if (!$approvalPin) {
                json_response([
                    'success' => false,
                    'error' => 'Manager approval required',
                    'requires_approval' => true
                ], 403);
            }
            
            $approvedBy = validateApprovalPin($pdo, $approvalPin, 'pos.apply_discount', $tenantId);
            if (!$approvedBy) {
                json_response(['success' => false, 'error' => 'Invalid approval PIN'], 403);
            }
        }
    }
    
    // Get order
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
        json_response(['success' => false, 'error' => 'Cannot apply discount to paid orders'], 400);
    }
    
    // Get settings for discount limits
    $stmt = $pdo->prepare("
        SELECT `key`, `value` FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('pos_discount_max_percent', 'pos_discount_requires_approval')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $maxDiscountPercent = (float)($settings['pos_discount_max_percent'] ?? 100);
    
    // Calculate discount amount
    $discountAmount = 0;
    $baseAmount = (float)$order['subtotal_amount'];
    
    if ($itemId) {
        // Item-level discount
        $stmt = $pdo->prepare("
            SELECT unit_price, quantity 
            FROM order_items 
            WHERE id = :item_id 
            AND order_id = :order_id
        ");
        $stmt->execute([
            'item_id' => $itemId,
            'order_id' => $orderId
        ]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item) {
            json_response(['success' => false, 'error' => 'Item not found'], 404);
        }
        
        $baseAmount = (float)$item['unit_price'] * (float)$item['quantity'];
    }
    
    if ($discountType === 'percent') {
        $discountPercent = min($discountValue, $maxDiscountPercent);
        $discountAmount = $baseAmount * ($discountPercent / 100);
    } elseif ($discountType === 'fixed') {
        $discountAmount = min($discountValue, $baseAmount);
    } elseif ($discountType === 'voucher' && $voucherId) {
        // Validate voucher
        $stmt = $pdo->prepare("
            SELECT discount_value, discount_type 
            FROM vouchers 
            WHERE id = :voucher_id 
            AND tenant_id = :tenant_id
            AND status = 'active'
        ");
        $stmt->execute([
            'voucher_id' => $voucherId,
            'tenant_id' => $tenantId
        ]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$voucher) {
            json_response(['success' => false, 'error' => 'Invalid voucher'], 400);
        }
        
        if ($voucher['discount_type'] === 'percent') {
            $discountAmount = $baseAmount * ((float)$voucher['discount_value'] / 100);
        } else {
            $discountAmount = min((float)$voucher['discount_value'], $baseAmount);
        }
    }
    
    // Apply discount
    if ($itemId) {
        // Update item discount
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET discount_amount = :discount_amount,
                discount_type = :discount_type,
                discount_value = :discount_value,
                updated_at = NOW()
            WHERE id = :item_id
        ");
        $stmt->execute([
            'discount_amount' => $discountAmount,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'item_id' => $itemId
        ]);
    } else {
        // Update order discount
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET discount_amount = :discount_amount,
                discount_type = :discount_type,
                discount_value = :discount_value,
                discount_reference = :discount_reference,
                updated_at = NOW()
            WHERE id = :order_id
        ");
        $stmt->execute([
            'discount_amount' => $discountAmount,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_reference' => $promoCode ?: $voucherId,
            'order_id' => $orderId
        ]);
    }
    
    // Recalculate order totals
    recalculateOrderTotals($pdo, $orderId, $tenantId);
    
    // Log discount event
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, order_item_id, event_type,
            payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, :item_id, 'discount',
            :payload, :user_id, NOW())
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'item_id' => $itemId,
        'payload' => json_encode([
            'type' => $discountType,
            'value' => $discountValue,
            'amount' => $discountAmount,
            'source' => $discountSource,
            'name' => $discountName,
            'approved_by' => $approvedBy ?? null
        ]),
