<?php
/**
 * SME 180 POS - Create Order API
 * Path: /public_html/pos/api/order/create.php
 * 
 * Creates new orders with items, customer info, and initial calculations
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
    if (!function_exists('pos_auth_require_login')) {
        json_response(['success' => false, 'error' => 'Auth function not found'], 500);
    }
    
    pos_auth_require_login();
    $user = pos_get_current_user();
    
    if (!$user) {
        json_response(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
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
    
    // Extract order details
    $orderType = $input['order_type'] ?? 'dine_in';
    $tableId = isset($input['table_id']) ? (int)$input['table_id'] : null;
    $customerName = $input['customer_name'] ?? null;
    $customerPhone = $input['customer_phone'] ?? null;
    $customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
    $notes = $input['notes'] ?? '';
    $items = $input['items'] ?? [];
    
    // Validate required fields
    if (empty($items)) {
        json_response(['success' => false, 'error' => 'Order must have at least one item'], 400);
    }
    
    if ($orderType === 'dine_in' && !$tableId) {
        json_response(['success' => false, 'error' => 'Table is required for dine-in orders'], 400);
    }
    
    // Get database connection
    $pdo = db();
    $pdo->beginTransaction();
    
    // Get currency symbol from settings
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'currency_symbol' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $currencySymbol = $stmt->fetchColumn() ?: '$';
    
    // Get tax and service charge settings
    $stmt = $pdo->prepare("
        SELECT `key`, value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` IN ('pos_tax_rate', 'pos_default_service_charge')
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $taxRate = (float)($settings['pos_tax_rate'] ?? 0);
    $defaultServiceCharge = (float)($settings['pos_default_service_charge'] ?? 0);
    
    // Check if table is available (for dine-in)
    if ($orderType === 'dine_in' && $tableId) {
        $stmt = $pdo->prepare("
            SELECT id FROM orders 
            WHERE tenant_id = :tenant_id 
            AND branch_id = :branch_id 
            AND table_id = :table_id 
            AND status NOT IN ('closed', 'voided', 'refunded')
            AND payment_status != 'paid'
            LIMIT 1
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'table_id' => $tableId
        ]);
        
        if ($stmt->fetchColumn()) {
            json_response(['success' => false, 'error' => 'Table already has an active order'], 400);
        }
    }
    
    // Generate order reference
    $prefix = date('Ymd');
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 FROM orders 
        WHERE tenant_id = :tenant_id 
        AND branch_id = :branch_id 
        AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $orderNumber = $stmt->fetchColumn();
    $receiptReference = sprintf('ORD%s%04d', $prefix, $orderNumber);
    
    // Calculate totals
    $subtotal = 0;
    $totalQuantity = 0;
    
    foreach ($items as $item) {
        $quantity = (float)($item['quantity'] ?? 1);
        $unitPrice = (float)($item['unit_price'] ?? 0);
        $subtotal += ($quantity * $unitPrice);
        $totalQuantity += $quantity;
    }
    
    // Calculate charges
    $serviceChargeAmount = $defaultServiceCharge > 0 ? ($subtotal * $defaultServiceCharge / 100) : 0;
    $taxableAmount = $subtotal + $serviceChargeAmount;
    $taxAmount = $taxRate > 0 ? ($taxableAmount * $taxRate / 100) : 0;
    $totalAmount = $subtotal + $serviceChargeAmount + $taxAmount;
    
    // Create order
    $stmt = $pdo->prepare("
        INSERT INTO orders (
            tenant_id, branch_id, station_id, cashier_id,
            receipt_reference, order_type, table_id,
            customer_id, customer_name, customer_phone,
            subtotal, tax_amount, service_charge, total_amount,
            payment_status, status, notes,
            kitchen_status, parked, created_at, updated_at
        ) VALUES (
            :tenant_id, :branch_id, :station_id, :cashier_id,
            :receipt_reference, :order_type, :table_id,
            :customer_id, :customer_name, :customer_phone,
            :subtotal, :tax_amount, :service_charge, :total_amount,
            'unpaid', 'open', :notes,
            'pending', 0, NOW(), NOW()
        )
    ");
    
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'station_id' => $stationId,
        'cashier_id' => $userId,
        'receipt_reference' => $receiptReference,
        'order_type' => $orderType,
        'table_id' => $tableId,
        'customer_id' => $customerId,
        'customer_name' => $customerName,
        'customer_phone' => $customerPhone,
        'subtotal' => $subtotal,
        'tax_amount' => $taxAmount,
        'service_charge' => $serviceChargeAmount,
        'total_amount' => $totalAmount,
        'notes' => $notes
    ]);
    
    $orderId = (int)$pdo->lastInsertId();
    
    // Insert order items
    $stmt = $pdo->prepare("
        INSERT INTO order_items (
            order_id, tenant_id, branch_id,
            product_id, product_name, quantity, unit_price,
            subtotal, tax_amount, total_amount,
            notes, kitchen_status, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id,
            :product_id, :product_name, :quantity, :unit_price,
            :subtotal, :tax_amount, :total_amount,
            :notes, 'pending', NOW()
        )
    ");
    
    $insertedItems = [];
    foreach ($items as $item) {
        $productId = isset($item['product_id']) ? (int)$item['product_id'] : null;
        $productName = $item['product_name'] ?? 'Unknown Item';
        $quantity = (float)($item['quantity'] ?? 1);
        $unitPrice = (float)($item['unit_price'] ?? 0);
        $itemSubtotal = $quantity * $unitPrice;
        $itemTax = $taxRate > 0 ? ($itemSubtotal * $taxRate / 100) : 0;
        $itemTotal = $itemSubtotal + $itemTax;
        $itemNotes = $item['notes'] ?? '';
        
        $stmt->execute([
            'order_id' => $orderId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'product_id' => $productId,
            'product_name' => $productName,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'subtotal' => $itemSubtotal,
            'tax_amount' => $itemTax,
            'total_amount' => $itemTotal,
            'notes' => $itemNotes
        ]);
        
        $itemId = (int)$pdo->lastInsertId();
        
        // Handle modifiers if present
        if (!empty($item['modifiers'])) {
            $modStmt = $pdo->prepare("
                INSERT INTO order_item_modifiers (
                    order_item_id, modifier_id, modifier_name,
                    quantity, unit_price, total_price
                ) VALUES (
                    :item_id, :modifier_id, :modifier_name,
                    :quantity, :unit_price, :total_price
                )
            ");
            
            foreach ($item['modifiers'] as $modifier) {
                $modStmt->execute([
                    'item_id' => $itemId,
                    'modifier_id' => isset($modifier['id']) ? (int)$modifier['id'] : null,
                    'modifier_name' => $modifier['name'] ?? '',
                    'quantity' => (float)($modifier['quantity'] ?? 1),
                    'unit_price' => (float)($modifier['price'] ?? 0),
                    'total_price' => (float)($modifier['quantity'] ?? 1) * (float)($modifier['price'] ?? 0)
                ]);
            }
        }
        
        $insertedItems[] = [
            'id' => $itemId,
            'product_id' => $productId,
            'product_name' => $productName,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $itemTotal
        ];
    }
    
    // Log order creation
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'created', :details, NOW()
        )
    ");
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'receipt_reference' => $receiptReference,
            'order_type' => $orderType,
            'items_count' => count($items),
            'total' => $totalAmount
        ])
    ]);
    
    // Check if auto-fire is enabled
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'pos_auto_fire_orders' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $autoFire = $stmt->fetchColumn() === 'true';
    
    if ($autoFire) {
        // Auto-fire to kitchen
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET kitchen_status = 'sent', fired_at = NOW() 
            WHERE id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);
        
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET kitchen_status = 'pending', fired_at = NOW() 
            WHERE order_id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);
        
        // Create fire log
        $stmt = $pdo->prepare("
            INSERT INTO order_fire_logs (
                order_id, tenant_id, branch_id, fired_by,
                station_id, items_fired, fired_at
            ) VALUES (
                :order_id, :tenant_id, :branch_id, :fired_by,
                :station_id, :items_fired, NOW()
            )
        ");
        
        $stmt->execute([
            'order_id' => $orderId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'fired_by' => $userId,
            'station_id' => $stationId,
            'items_fired' => json_encode(array_column($insertedItems, 'id'))
        ]);
    }
    
    $pdo->commit();
    
    // Return success response
    json_response([
        'success' => true,
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $receiptReference,
            'order_type' => $orderType,
            'table_id' => $tableId,
            'customer_name' => $customerName,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'service_charge' => $serviceChargeAmount,
            'total_amount' => $totalAmount,
            'currency_symbol' => $currencySymbol,
            'items' => $insertedItems,
            'auto_fired' => $autoFire,
            'status' => 'open',
            'payment_status' => 'unpaid'
        ],
        'message' => $autoFire ? 'Order created and sent to kitchen' : 'Order created successfully'
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order creation DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error: ' . $e->getMessage()], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order creation error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
