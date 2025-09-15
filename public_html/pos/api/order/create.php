<?php
/**
 * SME 180 POS - Order Creation API
 * Path: /public_html/pos/api/order/create.php
 * 
 * Creates new orders with items, variations, and automatic calculations
 * Handles discounts, service charges, and tax calculations
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
$stationId = (int)($_SESSION['station_id'] ?? 0);

if (!$tenantId || !$branchId || !$userId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

// Parse request
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_response(['success' => false, 'error' => 'Invalid request body'], 400);
}

// Validate required fields
$requiredFields = ['order_type', 'items'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field])) {
        json_response(['success' => false, 'error' => "Missing required field: $field"], 400);
    }
}

// Extract order data
$orderType = $input['order_type'] ?? 'dine_in';
$tableId = $input['table_id'] ?? null;
$customerId = $input['customer_id'] ?? null;
$customerName = $input['customer_name'] ?? null;
$guestCount = $input['guest_count'] ?? 1;
$notes = $input['notes'] ?? '';
$items = $input['items'] ?? [];

// Validate order type
$validOrderTypes = ['dine_in', 'takeaway', 'delivery', 'pickup'];
if (!in_array($orderType, $validOrderTypes)) {
    json_response(['success' => false, 'error' => 'Invalid order type'], 400);
}

// Validate items
if (empty($items)) {
    json_response(['success' => false, 'error' => 'Order must have at least one item'], 400);
}

// For dine-in orders, table is required
if ($orderType === 'dine_in' && !$tableId) {
    json_response(['success' => false, 'error' => 'Table is required for dine-in orders'], 400);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Get settings for calculations
    $settings = [];
    $stmt = $pdo->prepare("
        SELECT `key`, `value` 
        FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN (
            'tax_rate', 'tax_type', 'currency_symbol',
            'pos_service_charge_auto', 'pos_service_charge_percent',
            'pos_enable_tips', 'pos_tip_suggestions'
        )
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $taxRate = (float)($settings['tax_rate'] ?? 0);
    $taxType = $settings['tax_type'] ?? 'exclusive';
    $currencySymbol = $settings['currency_symbol'] ?? 'EGP';
    $autoServiceCharge = (bool)($settings['pos_service_charge_auto'] ?? false);
    $serviceChargePercent = (float)($settings['pos_service_charge_percent'] ?? 0);
    
    // Validate table if provided
    if ($tableId) {
        $stmt = $pdo->prepare("
            SELECT id, table_number, zone_id, is_occupied 
            FROM dining_tables 
            WHERE id = :id 
            AND tenant_id = :tenant_id 
            AND branch_id = :branch_id
            AND is_active = 1
        ");
        $stmt->execute([
            'id' => $tableId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId
        ]);
        $table = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$table) {
            json_response(['success' => false, 'error' => 'Invalid table'], 400);
        }
        
        // Check if table is already occupied
        if ($table['is_occupied']) {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) as count 
                FROM orders 
                WHERE table_id = :table_id 
                AND status NOT IN ('closed', 'voided', 'refunded')
                AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                'table_id' => $tableId,
                'tenant_id' => $tenantId
            ]);
            $activeOrders = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($activeOrders['count'] > 0) {
                json_response(['success' => false, 'error' => 'Table already has an active order'], 400);
            }
        }
    }
    
    // Calculate order totals
    $subtotal = 0;
    $totalDiscount = 0;
    $processedItems = [];
    
    foreach ($items as $item) {
        if (!isset($item['product_id']) || !isset($item['quantity'])) {
            json_response(['success' => false, 'error' => 'Invalid item structure'], 400);
        }
        
        $productId = (int)$item['product_id'];
        $quantity = (int)$item['quantity'];
        
        if ($quantity <= 0) {
            json_response(['success' => false, 'error' => 'Invalid quantity'], 400);
        }
        
        // Get product details
        $stmt = $pdo->prepare("
            SELECT id, name, price, category_id, is_active, is_inventory_tracked
            FROM products 
            WHERE id = :id 
            AND tenant_id = :tenant_id 
            AND is_active = 1
        ");
        $stmt->execute([
            'id' => $productId,
            'tenant_id' => $tenantId
        ]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            json_response(['success' => false, 'error' => "Product not found: $productId"], 400);
        }
        
        $unitPrice = (float)$product['price'];
        $variationTotal = 0;
        $variations = [];
        
        // Process variations if provided
        if (!empty($item['variations']) && is_array($item['variations'])) {
            foreach ($item['variations'] as $variation) {
                $groupId = (int)($variation['group_id'] ?? 0);
                $valueId = (int)($variation['value_id'] ?? 0);
                
                // Validate variation
                $stmt = $pdo->prepare("
                    SELECT vg.id as group_id, vg.name as group_name, 
                           vv.id as value_id, vv.value_name, vv.price_delta
                    FROM variation_groups vg
                    JOIN variation_values vv ON vv.variation_group_id = vg.id
                    WHERE vg.id = :group_id 
                    AND vv.id = :value_id
                    AND vg.tenant_id = :tenant_id
                    AND vg.is_active = 1
                ");
                $stmt->execute([
                    'group_id' => $groupId,
                    'value_id' => $valueId,
                    'tenant_id' => $tenantId
                ]);
                $varData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($varData) {
                    $priceDelta = (float)$varData['price_delta'];
                    $variationTotal += $priceDelta;
                    $variations[] = [
                        'group_id' => $groupId,
                        'group_name' => $varData['group_name'],
                        'value_id' => $valueId,
                        'value_name' => $varData['value_name'],
                        'price_delta' => $priceDelta
                    ];
                }
            }
        }
        
        // Calculate line totals
        $lineSubtotal = ($unitPrice + $variationTotal) * $quantity;
        $lineDiscount = 0;
        
        // Apply item-level discount if provided
        if (isset($item['discount_amount']) && $item['discount_amount'] > 0) {
            $lineDiscount = min((float)$item['discount_amount'], $lineSubtotal);
            $totalDiscount += $lineDiscount;
        } elseif (isset($item['discount_percent']) && $item['discount_percent'] > 0) {
            $discountPercent = min((float)$item['discount_percent'], 100);
            $lineDiscount = $lineSubtotal * ($discountPercent / 100);
            $totalDiscount += $lineDiscount;
        }
        
        $lineTotal = $lineSubtotal - $lineDiscount;
        $subtotal += $lineSubtotal;
        
        $processedItems[] = [
            'product_id' => $productId,
            'product_name' => $product['name'],
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'variations' => $variations,
            'variation_total' => $variationTotal,
            'line_subtotal' => $lineSubtotal,
            'discount_amount' => $lineDiscount,
            'line_total' => $lineTotal,
            'notes' => $item['notes'] ?? '',
            'kitchen_notes' => $item['kitchen_notes'] ?? '',
            'fire_status' => 'pending',
            'state' => 'held'
        ];
    }
    
    // Apply order-level discount if provided
    $orderDiscountAmount = 0;
    $orderDiscountType = null;
    $orderDiscountValue = null;
    
    if (isset($input['discount'])) {
        $discount = $input['discount'];
        
        // Check permission for manual discounts
        if ($discount['type'] === 'manual') {
            $hasPermission = check_user_permission($pdo, $userId, 'pos.apply_discount');
            if (!$hasPermission) {
                json_response(['success' => false, 'error' => 'No permission to apply discounts'], 403);
            }
        }
        
        if ($discount['type'] === 'percent') {
            $discountPercent = min((float)$discount['value'], 100);
            $orderDiscountAmount = ($subtotal - $totalDiscount) * ($discountPercent / 100);
            $orderDiscountType = 'percent';
            $orderDiscountValue = $discountPercent;
        } elseif ($discount['type'] === 'fixed') {
            $orderDiscountAmount = min((float)$discount['value'], $subtotal - $totalDiscount);
            $orderDiscountType = 'fixed';
            $orderDiscountValue = $orderDiscountAmount;
        }
        
        $totalDiscount += $orderDiscountAmount;
    }
    
    // Calculate service charge
    $serviceChargeAmount = 0;
    if ($orderType === 'dine_in' && $autoServiceCharge && $serviceChargePercent > 0) {
        $serviceChargeAmount = ($subtotal - $totalDiscount) * ($serviceChargePercent / 100);
    }
    
    // Calculate tax
    $taxableAmount = $subtotal - $totalDiscount + $serviceChargeAmount;
    $taxAmount = 0;
    
    if ($taxRate > 0) {
        if ($taxType === 'inclusive') {
            // Tax is already included in the price
            $taxAmount = $taxableAmount - ($taxableAmount / (1 + $taxRate / 100));
        } else {
            // Tax is exclusive (added on top)
            $taxAmount = $taxableAmount * ($taxRate / 100);
        }
    }
    
    // Calculate final total
    $totalAmount = $subtotal - $totalDiscount + $serviceChargeAmount;
    if ($taxType === 'exclusive') {
        $totalAmount += $taxAmount;
    }
    
    // Generate receipt reference
    $receiptReference = 'ORD-' . date('Ymd') . '-' . str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    // Create the order
    $stmt = $pdo->prepare("
        INSERT INTO orders (
            tenant_id, branch_id, station_id, created_by_user_id,
            receipt_reference, order_type, status, payment_status,
            table_id, customer_id, customer_name, guest_count,
            subtotal_amount, discount_amount, discount_type, discount_value,
            service_charge_amount, service_charge_percent,
            tax_amount, tax_percent, total_amount,
            order_notes, kitchen_status, source_channel,
            created_at, created_date
        ) VALUES (
            :tenant_id, :branch_id, :station_id, :user_id,
            :receipt_reference, :order_type, 'open', 'unpaid',
            :table_id, :customer_id, :customer_name, :guest_count,
            :subtotal, :discount_amount, :discount_type, :discount_value,
            :service_charge_amount, :service_charge_percent,
            :tax_amount, :tax_percent, :total_amount,
            :notes, 'pending', 'pos',
            NOW(), CURDATE()
        )
    ");
    
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'station_id' => $stationId,
        'user_id' => $userId,
        'receipt_reference' => $receiptReference,
        'order_type' => $orderType,
        'table_id' => $tableId,
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'guest_count' => $guestCount,
        'subtotal' => $subtotal,
        'discount_amount' => $totalDiscount,
        'discount_type' => $orderDiscountType,
        'discount_value' => $orderDiscountValue,
        'service_charge_amount' => $serviceChargeAmount,
        'service_charge_percent' => $serviceChargePercent,
        'tax_amount' => $taxAmount,
        'tax_percent' => $taxRate,
        'total_amount' => $totalAmount,
        'notes' => $notes
    ]);
    
    $orderId = (int)$pdo->lastInsertId();
    
    // Insert order items
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (
            order_id, product_id, product_name, unit_price, quantity,
            line_subtotal, discount_amount, line_total,
            notes, kitchen_notes, state, fire_status,
            created_at
        ) VALUES (
            :order_id, :product_id, :product_name, :unit_price, :quantity,
            :line_subtotal, :discount_amount, :line_total,
            :notes, :kitchen_notes, :state, :fire_status,
            NOW()
        )
    ");
    
    $variationStmt = $pdo->prepare("
        INSERT INTO order_item_variations (
            order_item_id, variation_group, variation_value, price_delta
        ) VALUES (
            :item_id, :group_name, :value_name, :price_delta
        )
    ");
    
    foreach ($processedItems as $item) {
        $itemStmt->execute([
            'order_id' => $orderId,
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'unit_price' => $item['unit_price'],
            'quantity' => $item['quantity'],
            'line_subtotal' => $item['line_subtotal'],
            'discount_amount' => $item['discount_amount'],
            'line_total' => $item['line_total'],
            'notes' => $item['notes'],
            'kitchen_notes' => $item['kitchen_notes'],
            'state' => $item['state'],
            'fire_status' => $item['fire_status']
        ]);
        
        $itemId = (int)$pdo->lastInsertId();
        
        // Insert variations
        foreach ($item['variations'] as $variation) {
            $variationStmt->execute([
                'item_id' => $itemId,
                'group_name' => $variation['group_name'],
                'value_name' => $variation['value_name'],
                'price_delta' => $variation['price_delta']
            ]);
        }
    }
    
    // Update table status if dine-in
    if ($orderType === 'dine_in' && $tableId) {
        $stmt = $pdo->prepare("
            UPDATE dining_tables 
            SET is_occupied = 1,
                last_occupied_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute(['id' => $tableId]);
    }
    
    // Log order creation event
    $stmt = $pdo->prepare("
        INSERT INTO order_item_events (
            tenant_id, order_id, event_type, payload, created_by, created_at
        ) VALUES (
            :tenant_id, :order_id, 'add', :payload, :user_id, NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'order_id' => $orderId,
        'payload' => json_encode([
            'items_count' => count($processedItems),
            'total_amount' => $totalAmount
        ]),
        'user_id' => $userId
    ]);
    
    // Record order-level discount if applied
    if ($orderDiscountAmount > 0 && isset($discount)) {
        $stmt = $pdo->prepare("
            INSERT INTO order_discounts_applied (
                tenant_id, branch_id, order_id, 
                discount_type, discount_source, discount_name,
                original_amount, discount_value, discount_amount,
                applied_by, applied_at
            ) VALUES (
                :tenant_id, :branch_id, :order_id,
                :discount_type, :discount_source, :discount_name,
                :original_amount, :discount_value, :discount_amount,
                :applied_by, NOW()
            )
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'order_id' => $orderId,
            'discount_type' => $orderDiscountType,
            'discount_source' => $discount['source'] ?? 'manual',
            'discount_name' => $discount['name'] ?? 'Manual Discount',
            'original_amount' => $subtotal,
            'discount_value' => $orderDiscountValue,
            'discount_amount' => $orderDiscountAmount,
            'applied_by' => $userId
        ]);
    }
    
    $pdo->commit();
    
    // Prepare response
    $response = [
        'success' => true,
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $receiptReference,
            'order_type' => $orderType,
            'status' => 'open',
            'payment_status' => 'unpaid',
            'table_id' => $tableId,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'guest_count' => $guestCount,
            'subtotal' => round($subtotal, 2),
            'discount' => round($totalDiscount, 2),
            'service_charge' => round($serviceChargeAmount, 2),
            'tax' => round($taxAmount, 2),
            'total' => round($totalAmount, 2),
            'currency' => $currencySymbol,
            'items_count' => count($processedItems),
            'created_at' => date('Y-m-d H:i:s')
        ]
    ];
    
    json_response($response);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Order creation error: ' . $e->getMessage());
    json_response([
        'success' => false,
        'error' => 'Failed to create order',
        'details' => $e->getMessage()
    ], 500);
}

/**
 * Check if user has specific permission
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
