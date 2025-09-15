<?php
/**
 * SME 180 POS - Void Item API
 * Path: /public_html/pos/api/order/void_item.php
 * 
 * Voids individual items with approval workflow
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

if (!isset($input['order_id']) || !isset($input['item_id']) || !isset($input['reason'])) {
    json_response(['success' => false, 'error' => 'Order ID, item ID, and reason are required'], 400);
}

$orderId = (int)$input['order_id'];
$itemId = (int)$input['item_id'];
$reason = $input['reason'];
$approvalPin = $input['approval_pin'] ?? null;

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Check permission
    $hasPermission = check_user_permission($pdo, $userId, 'pos.void_item');
    
    // Fetch item
    $stmt = $pdo->prepare("
        SELECT oi.*, o.status as order_status, o.payment_status
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = :item_id 
        AND oi.order_id = :order_id
        AND o.tenant_id = :tenant_id
        AND oi.is_voided = 0
        FOR UPDATE
    ");
    $stmt->execute([
        'item_id' => $itemId,
        'order_id' => $orderId,
        'tenant_id' => $tenantId
    ]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        json_response(['success' => false, 'error' => 'Item not found or already voided'], 404);
    }
    
    // Check if item can be voided
    if ($item['payment_status'] === 'paid') {
        json_response(['success' => false, 'error' => 'Cannot void items from paid orders'], 400);
    }
    
    // Check if item is fired (requires approval)
    $requiresApproval = false;
    $approvedBy = null;
    
    if ($item['fire_status'] === 'fired' || $item['state'] === 'fired') {
        $requiresApproval = !check_user_permission($pdo, $userId, 'pos.void_fired');
        
        if ($requiresApproval) {
            if (!$approvalPin) {
                // Create approval request
                $stmt = $pdo->prepare("
                    INSERT INTO order_void_requests (
                        tenant_id, branch_id, order_id, order_item_id,
                        void_type, reason, requested_by, requested_at,
                        status, expires_at
                    ) VALUES (
                        :tenant_id, :branch_id, :order_id, :item_id,
                        'item', :reason, :user_id, NOW(),
                        'pending', DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                    )
                ");
                $stmt->execute([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'order_id' => $orderId,
                    'item_id' => $itemId,
                    'reason' => $reason,
                    'user_id' => $userId
                ]);
                
                $pdo->commit();
                
                json_response([
                    'success' => false,
                    'requires_approval' => true,
                    'approval_request_id' => $pdo->lastInsertId(),
                    'message' => 'Manager approval required to void fired item'
                ], 202);
            }
            
            // Validate approval PIN
            $approvedBy = validateApprovalPin($pdo, $approvalPin, 'pos.void_fired', $tenantId);
            if (!$approvedBy) {
                json_response(['success' => false, 'error' => 'Invalid approval PIN'], 403);
            }
        }
    } else if (!$hasPermission) {
        json_response(['success' => false, 'error' => 'No permission to void items'], 403);
    }
    
    // Void the item
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET is_voided = 1,
            void_reason = :reason,
            void_at = NOW(),
            void_approved_by = :approved_by,
            void_approved_at = NOW(),
            updated_at = NOW()
        WHERE id = :item_id
    ");
    $stmt->execute([
        'reason' => $reason,
        'approved_by' => $approvedBy ?: $userId,
        'item_id' => $itemId
    ]);
    
    // Recalculate order totals
    recalculateOrderTotals($pdo, $orderId, $tenantId);
    
    // Log void event
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, order_item_id, event_type,
            payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, :item_id, 'void',
            :payload, :user_id, NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'item_id' => $itemId,
        'payload' => json_encode([
            'reason' => $reason,
            'approved_by' => $approvedBy
        ]),
        'user_id' => $userId
    ]);
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'order_id' => $orderId,
        'item_id' => $itemId,
        'voided' => true,
        'approved_by' => $approvedBy ?: $userId
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Void item error: ' . $e->getMessage());
    json_response([
        'success' => false,
        'error' => 'Failed to void item',
        'details' => $e->getMessage()
    ], 500);
}

/**
 * =============================================================================
 * Void Order API
 * Path: /public_html/pos/api/order/void_order.php
 * =============================================================================
 */

// The code below would be in a separate file: void_order.php

if (basename(__FILE__) === 'void_order.php') {
    
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
    
    if (!isset($input['order_id']) || !isset($input['reason'])) {
        json_response(['success' => false, 'error' => 'Order ID and reason are required'], 400);
    }
    
    $orderId = (int)$input['order_id'];
    $reason = $input['reason'];
    $approvalPin = $input['approval_pin'] ?? null;
    
    try {
        $pdo = db();
        $pdo->beginTransaction();
        
        // Check permission
        $hasPermission = check_user_permission($pdo, $userId, 'pos.void_order');
        $requiresApproval = !check_user_permission($pdo, $userId, 'pos.approve_void');
        
        if (!$hasPermission && !$approvalPin) {
            json_response(['success' => false, 'error' => 'No permission to void orders'], 403);
        }
        
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
        
        // Check if order can be voided
        if ($order['payment_status'] === 'paid') {
            json_response(['success' => false, 'error' => 'Cannot void paid orders. Use refund instead.'], 400);
        }
        
        if (in_array($order['status'], ['voided', 'refunded'])) {
            json_response(['success' => false, 'error' => 'Order is already ' . $order['status']], 400);
        }
        
        // Handle approval if required
        $approvedBy = null;
        if ($requiresApproval) {
            if (!$approvalPin) {
                // Create approval request
                $stmt = $pdo->prepare("
                    INSERT INTO order_void_requests (
                        tenant_id, branch_id, order_id, void_type,
                        reason, requested_by, requested_at, status,
                        expires_at
                    ) VALUES (
                        :tenant_id, :branch_id, :order_id, 'order',
                        :reason, :user_id, NOW(), 'pending',
                        DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                    )
                ");
                $stmt->execute([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'order_id' => $orderId,
                    'reason' => $reason,
                    'user_id' => $userId
                ]);
                
                $pdo->commit();
                
                json_response([
                    'success' => false,
                    'requires_approval' => true,
                    'approval_request_id' => $pdo->lastInsertId(),
                    'message' => 'Manager approval required to void order'
                ], 202);
            }
            
            $approvedBy = validateApprovalPin($pdo, $approvalPin, 'pos.approve_void', $tenantId);
            if (!$approvedBy) {
                json_response(['success' => false, 'error' => 'Invalid approval PIN'], 403);
            }
        }
        
        // Void the order
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET status = 'voided',
                payment_status = 'voided',
                is_voided = 1,
                void_reason = :reason,
                voided_at = NOW(),
                voided_by_user_id = :user_id,
                void_approved_by = :approved_by,
                updated_at = NOW()
            WHERE id = :order_id
        ");
        $stmt->execute([
            'reason' => $reason,
            'user_id' => $userId,
            'approved_by' => $approvedBy ?: $userId,
            'order_id' => $orderId
        ]);
        
        // Void all items
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET is_voided = 1,
                void_reason = :reason,
                void_at = NOW(),
                void_approved_by = :user_id
            WHERE order_id = :order_id
            AND is_voided = 0
        ");
        $stmt->execute([
            'reason' => 'Order voided: ' . $reason,
            'user_id' => $approvedBy ?: $userId,
            'order_id' => $orderId
        ]);
        
        // Update table status if dine-in
        if ($order['order_type'] === 'dine_in' && $order['table_id']) {
            $stmt = $pdo->prepare("
                UPDATE dining_tables 
                SET is_occupied = 0 
                WHERE id = :table_id
            ");
            $stmt->execute(['table_id' => $order['table_id']]);
        }
        
        // Log void event
        $stmt = $pdo->prepare("
            INSERT INTO order_item_events (
                tenant_id, order_id, event_type, payload, created_by, created_at
            ) VALUES (
                :tenant_id, :order_id, 'void', :payload, :user_id, NOW()
            )
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'payload' => json_encode([
                'reason' => $reason,
                'approved_by' => $approvedBy,
                'type' => 'full_order'
            ]),
            'user_id' => $userId
        ]);
        
        $pdo->commit();
        
        json_response([
            'success' => true,
            'order_id' => $orderId,
            'status' => 'voided',
            'approved_by' => $approvedBy ?: $userId
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        error_log('Void order error: ' . $e->getMessage());
        json_response([
            'success' => false,
            'error' => 'Failed to void order',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * =============================================================================
 * Apply Discount API
 * Path: /public_html/pos/api/order/apply_discount.php
 * =============================================================================
 */

// The code below would be in a separate file: apply_discount.php

if (basename(__FILE__) === 'apply_discount.php') {
    
    require_once __DIR__ . '/../../../config/db.php';
    require_once __DIR__ . '/../../../middleware/pos_auth.php';
    
    pos_auth_require_login();
    $user = pos_get_current_user();
    
    $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
    $branchId = (int)($_SESSION['branch_id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    
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
    
    try {
        $pdo = db();
        $pdo->beginTransaction();
        
        // Check permission for manual discounts
        if ($discountSource === 'manual') {
            $hasPermission = check_user_permission($pdo, $userId, 'pos.apply_discount');
            if (!$hasPermission) {
                json_response(['success' => false, 'error' => 'No permission to apply discounts'], 403);
            }
            
            // Check max discount limit
            $stmt = $pdo->prepare("
                SELECT `value` FROM settings 
                WHERE tenant_id = :tenant_id 
                AND `key` = 'pos_discount_max_percent'
            ");
            $stmt->execute(['tenant_id' => $tenantId]);
            $maxDiscount = (float)($stmt->fetch(PDO::FETCH_ASSOC)['value'] ?? 100);
            
            if ($discountType === 'percent' && $discountValue > $maxDiscount) {
                json_response([
                    'success' => false,
                    'error' => 'Discount exceeds maximum allowed',
                    'max_allowed' => $maxDiscount
                ], 400);
            }
        }
        
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
            json_response(['success' => false, 'error' => 'Cannot modify paid orders'], 400);
        }
        
        // Validate promo code if provided
        if ($promoCode) {
            $stmt = $pdo->prepare("
                SELECT * FROM promo_codes 
                WHERE code = :code 
                AND tenant_id = :tenant_id
                AND is_active = 1
                AND (valid_from IS NULL OR valid_from <= NOW())
                AND (valid_to IS NULL OR valid_to >= NOW())
                AND (usage_limit IS NULL OR usage_count < usage_limit)
            ");
            $stmt->execute([
                'code' => $promoCode,
                'tenant_id' => $tenantId
            ]);
            $promo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$promo) {
                json_response(['success' => false, 'error' => 'Invalid or expired promo code'], 400);
            }
            
            $discountType = $promo['discount_type'];
            $discountValue = (float)$promo['discount_value'];
            $discountName = $promo['name'];
            $discountSource = 'promo';
            
            // Update promo usage
            $stmt = $pdo->prepare("
                UPDATE promo_codes 
                SET usage_count = usage_count + 1 
                WHERE id = :id
            ");
            $stmt->execute(['id' => $promo['id']]);
        }
        
        // Calculate discount amount
        $discountAmount = 0;
        $originalAmount = 0;
        
        if ($itemId) {
            // Item-level discount
            $stmt = $pdo->prepare("
                SELECT line_subtotal FROM order_items 
                WHERE id = :item_id 
                AND order_id = :order_id
                AND is_voided = 0
            ");
            $stmt->execute([
                'item_id' => $itemId,
                'order_id' => $orderId
            ]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$item) {
                json_response(['success' => false, 'error' => 'Item not found'], 404);
            }
            
            $originalAmount = (float)$item['line_subtotal'];
            
            if ($discountType === 'percent') {
                $discountAmount = $originalAmount * ($discountValue / 100);
            } else {
                $discountAmount = min($discountValue, $originalAmount);
            }
            
            // Update item discount
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET discount_amount = :discount,
                    discount_percent = :percent,
                    discount_type = :type,
                    line_total = line_subtotal - :discount_amount,
                    updated_at = NOW()
                WHERE id = :item_id
            ");
            $stmt->execute([
                'discount' => $discountAmount,
                'percent' => $discountType === 'percent' ? $discountValue : null,
                'type' => $discountType,
                'discount_amount' => $discountAmount,
                'item_id' => $itemId
            ]);
            
        } else {
            // Order-level discount
            $originalAmount = (float)$order['subtotal_amount'];
            
            if ($discountType === 'percent') {
                $discountAmount = $originalAmount * ($discountValue / 100);
            } else {
                $discountAmount = min($discountValue, $originalAmount);
            }
            
            // Update order discount
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET discount_amount = :discount,
                    discount_type = :type,
                    discount_value = :value,
                    discount_reference = :reference,
                    updated_at = NOW()
                WHERE id = :order_id
            ");
            $stmt->execute([
                'discount' => $discountAmount,
                'type' => $discountType,
                'value' => $discountValue,
                'reference' => $promoCode ?: $voucherId,
                'order_id' => $orderId
            ]);
        }
        
        // Record discount application
        $stmt = $pdo->prepare("
            INSERT INTO order_discounts_applied (
                tenant_id, branch_id, order_id, order_item_id,
                discount_type, discount_source, discount_name,
                original_amount, discount_value, discount_amount,
                applied_by, applied_at
            ) VALUES (
                :tenant_id, :branch_id, :order_id, :item_id,
                :type, :source, :name,
                :original, :value, :amount,
                :user_id, NOW()
            )
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'item_id' => $itemId,
            'type' => $discountType,
            'source' => $discountSource,
            'name' => $discountName,
            'original' => $originalAmount,
            'value' => $discountValue,
            'amount' => $discountAmount,
            'user_id' => $userId
        ]);
        
        // Recalculate order totals
        recalculateOrderTotals($pdo, $orderId, $tenantId);
        
        // Log discount event
        $stmt = $pdo->prepare("
            INSERT INTO order_item_events (
                tenant_id, order_id, order_item_id, event_type,
                payload, created_by, created_at
            ) VALUES (
                :tenant_id, :order_id, :item_id, 'discount',
                :payload, :user_id, NOW()
            )
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'order_id' => $orderId,
            'item_id' => $itemId,
            'payload' => json_encode([
                'type' => $discountType,
                'value' => $discountValue,
                'amount' => $discountAmount,
                'source' => $discountSource
            ]),
            'user_id' => $userId
        ]);
        
        $pdo->commit();
        
        // Get updated totals
        $stmt = $pdo->prepare("
            SELECT subtotal_amount, discount_amount, total_amount 
            FROM orders 
            WHERE id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);
        $updatedOrder = $stmt->fetch(PDO::FETCH_ASSOC);
        
        json_response([
            'success' => true,
            'order_id' => $orderId,
            'discount' => [
                'type' => $discountType,
                'value' => $discountValue,
                'amount' => round($discountAmount, 2),
                'source' => $discountSource
            ],
            'totals' => [
                'subtotal' => round((float)$updatedOrder['subtotal_amount'], 2),
                'discount' => round((float)$updatedOrder['discount_amount'], 2),
                'total' => round((float)$updatedOrder['total_amount'], 2)
            ]
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        error_log('Apply discount error: ' . $e->getMessage());
        json_response([
            'success' => false,
            'error' => 'Failed to apply discount',
            'details' => $e->getMessage()
        ], 500);
    }
}

/**
 * Shared helper functions
 */

function validateApprovalPin($pdo, $pin, $capability, $tenantId) {
    if (!$pin) return null;
    
    $stmt = $pdo->prepare("
        SELECT u.id 
        FROM users u
        JOIN pos_role_capabilities rc ON rc.role_key = u.role_key
        WHERE u.tenant_id = :tenant_id
        AND u.pos_pin = :pin
        AND rc.capability_key = :capability
        AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'pin' => hash('sha256', $pin),
        'capability' => $capability
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result ? $result['id'] : null;
}

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

function recalculateOrderTotals($pdo, $orderId, $tenantId) {
    // Implementation same as in update.php
    // Recalculates subtotal, discount, tax, service charge, and total
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>
