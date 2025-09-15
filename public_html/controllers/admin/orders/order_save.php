<?php
// /public_html/controllers/admin/orders/order_save.php
// Enhanced CREATE/UPDATE endpoint for orders with loyalty integration, line discounts, and modifiers
declare(strict_types=1);

/* Bootstrap and Auth */
require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/middleware/auth_login.php';
auth_require_login();

if (!function_exists('db') || !function_exists('use_backend_session')) {
    http_response_code(500);
    exit('Required functions missing in config/db.php');
}
use_backend_session();

/* Optional Integrations */
$rewards_available = false;
$rewards_path = dirname(__DIR__, 3) . '/includes/rewards.php';
if (is_file($rewards_path)) {
    require_once $rewards_path;
    if (function_exists('rewards_issue_cashback_for_order')) {
        $rewards_available = true;
    }
}

$stockflow_available = false;
$stockflow_path = dirname(__DIR__, 2) . '/stockflow/engine/apply_order_deductions.php';
if (is_file($stockflow_path)) {
    require_once $stockflow_path;
    if (function_exists('stockflow_apply_on_order_close')) {
        $stockflow_available = true;
    }
}

/* Auth Check */
$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /views/auth/login.php');
    exit;
}
$tenantId = (int)$user['tenant_id'];
$userId = (int)$user['id'];

/* CSRF Validation */
if (empty($_SESSION['csrf_orders']) || (($_POST['csrf'] ?? '') !== $_SESSION['csrf_orders'])) {
    $_SESSION['flash'] = 'Invalid request. Please try again.';
    header('Location: /views/admin/orders/index.php');
    exit;
}

/* Helper Functions */
function strval_clean($key): string {
    return trim((string)($_POST[$key] ?? ''));
}

function intval_or_null($key): ?int {
    $val = trim((string)($_POST[$key] ?? ''));
    return ($val === '') ? null : (int)$val;
}

function floatval_clean($key): float {
    $val = preg_replace('/[^\d.\-]/', '', (string)($_POST[$key] ?? ''));
    if ($val === '' || !is_numeric($val)) return 0.0;
    return (float)$val;
}

function ensure_enum(string $val, array $allowed, string $default): string {
    return in_array($val, $allowed, true) ? $val : $default;
}

function column_exists(PDO $pdo, string $table, string $col): bool {
    try {
        $q = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c");
        $q->execute([':t' => $table, ':c' => $col]);
        return (bool)$q->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function get_setting(PDO $pdo, int $tenantId, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE tenant_id = :t AND `key` = :k LIMIT 1");
        $stmt->execute([':t' => $tenantId, ':k' => $key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string)$value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

/* Read Input */
$id = (int)($_POST['id'] ?? 0);
$branch_id = max(1, (int)($_POST['branch_id'] ?? 0));
$order_type = ensure_enum(strval_clean('order_type'), 
    ['dine_in', 'takeaway', 'delivery', 'pickup', 'online', 'aggregator', 'talabat', 'room', 'other'], 
    'dine_in'
);
$status = ensure_enum(strval_clean('status'),
    ['open', 'held', 'sent', 'preparing', 'ready', 'served', 'closed', 'voided', 'cancelled', 'refunded'],
    'open'
);
$payment_status = ensure_enum(strval_clean('payment_status'),
    ['unpaid', 'partial', 'paid', 'refunded', 'voided'],
    'unpaid'
);
$payment_method = ensure_enum(strval_clean('payment_method'),
    ['', 'cash', 'card', 'online', 'split', 'wallet'],
    ''
);

$customer_id = intval_or_null('customer_id');
$customer_name = strval_clean('customer_name');
$table_id = intval_or_null('table_id');
$session_id = intval_or_null('session_id');
$guest_count = intval_or_null('guest_count');
$aggregator_id = intval_or_null('aggregator_id');
$external_order_reference = strval_clean('external_order_reference');
$receipt_reference = strval_clean('receipt_reference');
$order_notes = strval_clean('order_notes');
$source_channel = ensure_enum(strval_clean('source_channel'), ['pos', 'online', 'aggregator'], 'pos');

/* Loyalty Integration Fields */
$loyalty_program_id = intval_or_null('loyalty_program_id');
$voucher_redemptions_json = strval_clean('voucher_redemptions');
$voucher_redemptions = [];
if (!empty($voucher_redemptions_json)) {
    $decoded = json_decode($voucher_redemptions_json, true);
    if (is_array($decoded)) {
        $voucher_redemptions = $decoded;
    }
}

/* Clean up delivery-specific fields */
if ($order_type !== 'delivery' && $order_type !== 'aggregator' && $order_type !== 'talabat') {
    $aggregator_id = null;
    $external_order_reference = '';
}

/* Clean up dine-in specific fields */
if ($order_type !== 'dine_in') {
    $table_id = null;
    $guest_count = null;
}

/* Process order items JSON */
$orderItemsJson = strval_clean('order_items_json');
$orderItems = [];
if (!empty($orderItemsJson)) {
    $decoded = json_decode($orderItemsJson, true);
    if (is_array($decoded)) {
        $orderItems = $decoded;
    }
}

/* Financial Amounts - Auto-calculate based on settings and order type */
try {
    $pdo = db();
    
    // Calculate items subtotal
    $itemsSubtotal = 0;
    $itemsDiscountTotal = 0;
    
    foreach ($orderItems as $item) {
        $qty = max(0, (float)($item['quantity'] ?? 0));
        $price = max(0, (float)($item['unit_price'] ?? 0));
        $discountAmt = max(0, (float)($item['discount_amount'] ?? 0));
        $discountPct = max(0, (float)($item['discount_percent'] ?? 0));
        
        $lineSubtotal = $qty * $price;
        $lineDiscount = $discountAmt + ($lineSubtotal * ($discountPct / 100));
        
        $itemsSubtotal += $lineSubtotal;
        $itemsDiscountTotal += $lineDiscount;
    }
    
    // Use provided subtotal or calculated from items
    $subtotal_amount = max(0.0, floatval_clean('subtotal_amount'));
    if ($subtotal_amount == 0 && $itemsSubtotal > 0) {
        $subtotal_amount = $itemsSubtotal;
    }
    
    $order_discount_amount = max(0.0, floatval_clean('discount_amount'));
    
    // Calculate voucher discounts
    $voucher_discount_total = 0.0;
    foreach ($voucher_redemptions as $redemption) {
        $voucher_discount_total += max(0.0, (float)($redemption['amount_applied'] ?? 0));
    }
    
    $total_discount_amount = $itemsDiscountTotal + $order_discount_amount + $voucher_discount_total;
    
    // Get percentages from settings based on order type
    $tax_percent = (float)get_setting($pdo, $tenantId, 'tax_percent', '0');
    $service_percent = ($order_type === 'dine_in') ? (float)get_setting($pdo, $tenantId, 'service_percent', '0') : 0.0;
    
    /* Calculate dependent amounts */
    $base_amount = max($subtotal_amount - $total_discount_amount, 0.0);
    $tax_amount = round($base_amount * ($tax_percent / 100.0), 3);
    $service_amount = round($base_amount * ($service_percent / 100.0), 3);
    
    /* Commission handling */
    $commission_percent = 0.0;
    $commission_amount = 0.0;
    $commission_total_amount = 0.0;
    
    // Auto-calculate commission for aggregator orders
    if ($aggregator_id && ($order_type === 'delivery' || $order_type === 'aggregator')) {
        $st = $pdo->prepare("SELECT default_commission_percent FROM aggregators WHERE id = :id AND tenant_id = :t AND is_active = 1");
        $st->execute([':id' => $aggregator_id, ':t' => $tenantId]);
        $commission_percent = (float)($st->fetchColumn() ?: 0.0);
        $commission_amount = round(($base_amount + $tax_amount + $service_amount) * ($commission_percent / 100.0), 3);
        $commission_total_amount = $commission_amount;
    }
    
    $total_amount = round($base_amount + $tax_amount + $service_amount + $commission_total_amount, 3);

} catch (Throwable $e) {
    $_SESSION['flash'] = 'Error calculating totals: ' . $e->getMessage();
    header('Location: ' . ($id > 0 ? "/views/admin/orders/edit.php?id=$id" : '/views/admin/orders/create.php'));
    exit;
}

/* Validation */
if ($branch_id <= 0) {
    $_SESSION['flash'] = 'Branch is required.';
    header('Location: ' . ($id > 0 ? "/views/admin/orders/edit.php?id=$id" : '/views/admin/orders/create.php'));
    exit;
}

if (empty($orderItems)) {
    $_SESSION['flash'] = 'At least one item is required.';
    header('Location: ' . ($id > 0 ? "/views/admin/orders/edit.php?id=$id" : '/views/admin/orders/create.php'));
    exit;
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    /* For UPDATE: Load previous state for transition detection */
    $status_prev = null;
    $payment_status_prev = null;
    
    if ($id > 0) {
        $chk = $pdo->prepare("SELECT tenant_id, status, payment_status FROM orders WHERE id = :id LIMIT 1");
        $chk->execute([':id' => $id]);
        $row = $chk->fetch();
        
        if (!$row || (int)$row['tenant_id'] !== $tenantId) {
            $_SESSION['flash'] = 'Order not found or access denied.';
            header('Location: /views/admin/orders/index.php');
            exit;
        }
        
        $status_prev = (string)$row['status'];
        $payment_status_prev = (string)$row['payment_status'];
    }
    
    /* Check column existence for optional fields */
    $has_voided_bool = column_exists($pdo, 'orders', 'is_voided');
    $has_closed_at = column_exists($pdo, 'orders', 'closed_at');
    $has_voided_at = column_exists($pdo, 'orders', 'voided_at');
    $has_voided_by = column_exists($pdo, 'orders', 'voided_by_user_id');
    
    /* Determine is_voided flag */
    $is_voided = ($status === 'voided' || $payment_status === 'voided') ? 1 : 0;
    
    $pdo->beginTransaction();
    
    if ($id > 0) {
        /* UPDATE existing order */
        $sets = [
            "branch_id = :branch_id",
            "customer_id = :customer_id",
            "customer_name = :customer_name",
            "table_id = :table_id",
            "session_id = :session_id",
            "guest_count = :guest_count",
            "order_type = :order_type",
            "status = :status",
            "payment_status = :payment_status",
            "payment_method = :payment_method",
            "source_channel = :source_channel",
            "aggregator_id = :aggregator_id",
            "external_order_reference = :external_order_reference",
            "receipt_reference = :receipt_reference",
            "order_notes = :order_notes",
            "subtotal_amount = :subtotal_amount",
            "discount_amount = :discount_amount",
            "tax_percent = :tax_percent",
            "tax_amount = :tax_amount",
            "service_percent = :service_percent",
            "service_amount = :service_amount",
            "commission_percent = :commission_percent",
            "commission_amount = :commission_amount",
            "commission_total_amount = :commission_total_amount",
            "total_amount = :total_amount",
            "updated_at = NOW()"
        ];
        
        if ($has_voided_bool) {
            $sets[] = "is_voided = :is_voided";
        }
        
        $sql = "UPDATE orders SET " . implode(', ', $sets) . " WHERE id = :id AND tenant_id = :tenant_id LIMIT 1";
        
        $params = [
            ':branch_id' => $branch_id,
            ':customer_id' => $customer_id,
            ':customer_name' => $customer_name,
            ':table_id' => $table_id,
            ':session_id' => $session_id,
            ':guest_count' => $guest_count,
            ':order_type' => $order_type,
            ':status' => $status,
            ':payment_status' => $payment_status,
            ':payment_method' => $payment_method,
            ':source_channel' => $source_channel,
            ':aggregator_id' => $aggregator_id,
            ':external_order_reference' => $external_order_reference,
            ':receipt_reference' => $receipt_reference,
            ':order_notes' => $order_notes,
            ':subtotal_amount' => $subtotal_amount,
            ':discount_amount' => $total_discount_amount,
            ':tax_percent' => $tax_percent,
            ':tax_amount' => $tax_amount,
            ':service_percent' => $service_percent,
            ':service_amount' => $service_amount,
            ':commission_percent' => $commission_percent,
            ':commission_amount' => $commission_amount,
            ':commission_total_amount' => $commission_total_amount,
            ':total_amount' => $total_amount,
            ':id' => $id,
            ':tenant_id' => $tenantId
        ];
        
        if ($has_voided_bool) {
            $params[':is_voided'] = $is_voided;
        }
        
        $st = $pdo->prepare($sql);
        $st->execute($params);
        
        // Clear and recreate order items for updates
        $pdo->prepare("DELETE FROM order_item_variations WHERE order_item_id IN (SELECT id FROM order_items WHERE order_id = :id)")->execute([':id' => $id]);
        $pdo->prepare("DELETE FROM order_items WHERE order_id = :id")->execute([':id' => $id]);
        
    } else {
        /* CREATE new order */
        $sql = "
            INSERT INTO orders (
                tenant_id, branch_id, created_by_user_id, customer_id, customer_name,
                table_id, session_id, guest_count, order_type, status, payment_status,
                payment_method, source_channel, aggregator_id, external_order_reference,
                receipt_reference, order_notes, subtotal_amount, discount_amount,
                tax_percent, tax_amount, service_percent, service_amount,
                commission_percent, commission_amount, commission_total_amount,
                total_amount" . ($has_voided_bool ? ", is_voided" : "") . ",
                created_at, updated_at
            ) VALUES (
                :tenant_id, :branch_id, :created_by_user_id, :customer_id, :customer_name,
                :table_id, :session_id, :guest_count, :order_type, :status, :payment_status,
                :payment_method, :source_channel, :aggregator_id, :external_order_reference,
                :receipt_reference, :order_notes, :subtotal_amount, :discount_amount,
                :tax_percent, :tax_amount, :service_percent, :service_amount,
                :commission_percent, :commission_amount, :commission_total_amount,
                :total_amount" . ($has_voided_bool ? ", :is_voided" : "") . ",
                NOW(), NOW()
            )
        ";
        
        $params = [
            ':tenant_id' => $tenantId,
            ':branch_id' => $branch_id,
            ':created_by_user_id' => $userId,
            ':customer_id' => $customer_id,
            ':customer_name' => $customer_name,
            ':table_id' => $table_id,
            ':session_id' => $session_id,
            ':guest_count' => $guest_count,
            ':order_type' => $order_type,
            ':status' => $status,
            ':payment_status' => $payment_status,
            ':payment_method' => $payment_method,
            ':source_channel' => $source_channel,
            ':aggregator_id' => $aggregator_id,
            ':external_order_reference' => $external_order_reference,
            ':receipt_reference' => $receipt_reference,
            ':order_notes' => $order_notes,
            ':subtotal_amount' => $subtotal_amount,
            ':discount_amount' => $total_discount_amount,
            ':tax_percent' => $tax_percent,
            ':tax_amount' => $tax_amount,
            ':service_percent' => $service_percent,
            ':service_amount' => $service_amount,
            ':commission_percent' => $commission_percent,
            ':commission_amount' => $commission_amount,
            ':commission_total_amount' => $commission_total_amount,
            ':total_amount' => $total_amount
        ];
        
        if ($has_voided_bool) {
            $params[':is_voided'] = $is_voided;
        }
        
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $id = (int)$pdo->lastInsertId();
    }
    
    /* Insert order items with line discounts and modifiers */
    foreach ($orderItems as $item) {
        $productId = (int)($item['product_id'] ?? 0);
        $quantity = max(0, (float)($item['quantity'] ?? 0));
        $unitPrice = max(0, (float)($item['unit_price'] ?? 0));
        $discountAmount = max(0, (float)($item['discount_amount'] ?? 0));
        $discountPercent = max(0, (float)($item['discount_percent'] ?? 0));
        $notes = (string)($item['notes'] ?? '');
        $modifiers = $item['modifiers'] ?? [];
        
        if ($productId <= 0 || $quantity <= 0) continue;
        
        // Get product name
        $stmt = $pdo->prepare("SELECT name_en FROM products WHERE id = :id AND tenant_id = :t");
        $stmt->execute([':id' => $productId, ':t' => $tenantId]);
        $productName = $stmt->fetchColumn() ?: "Product #$productId";
        
        $lineSubtotal = $quantity * $unitPrice;
        $lineDiscountTotal = $discountAmount + ($lineSubtotal * ($discountPercent / 100));
        $lineTotal = max(0, $lineSubtotal - $lineDiscountTotal);
        
        $stmt = $pdo->prepare("
            INSERT INTO order_items (
                order_id, product_id, product_name, unit_price, quantity, 
                line_subtotal, discount_amount, discount_percent, line_total,
                notes, state, created_at
            ) VALUES (
                :order_id, :product_id, :product_name, :unit_price, :quantity,
                :line_subtotal, :discount_amount, :discount_percent, :line_total,
                :notes, 'held', NOW()
            )
        ");
        $stmt->execute([
            ':order_id' => $id,
            ':product_id' => $productId,
            ':product_name' => $productName,
            ':unit_price' => $unitPrice,
            ':quantity' => $quantity,
            ':line_subtotal' => $lineSubtotal,
            ':discount_amount' => $discountAmount,
            ':discount_percent' => $discountPercent,
            ':line_total' => $lineTotal,
            ':notes' => $notes
        ]);
        
        $orderItemId = (int)$pdo->lastInsertId();
        
        // Insert modifiers/variations for this order item
        if (!empty($modifiers) && is_array($modifiers)) {
            foreach ($modifiers as $modifier) {
                $groupName = (string)($modifier['group_name'] ?? '');
                $valueName = (string)($modifier['value_name'] ?? '');
                $priceDelta = (float)($modifier['price_delta'] ?? 0);
                
                if (empty($groupName) || empty($valueName)) continue;
                
                $stmt = $pdo->prepare("
                    INSERT INTO order_item_variations (
                        order_item_id, variation_group, variation_value, price_delta
                    ) VALUES (
                        :item_id, :group_name, :value_name, :price_delta
                    )
                ");
                $stmt->execute([
                    ':item_id' => $orderItemId,
                    ':group_name' => $groupName,
                    ':value_name' => $valueName,
                    ':price_delta' => $priceDelta
                ]);
            }
        }
    }
    
    /* Process Voucher Redemptions */
    foreach ($voucher_redemptions as $redemption) {
        $voucherId = (int)($redemption['voucher_id'] ?? 0);
        $amountApplied = max(0.0, (float)($redemption['amount_applied'] ?? 0));
        
        if ($voucherId <= 0 || $amountApplied <= 0) continue;
        
        // Validate voucher is still valid
        $stmt = $pdo->prepare("
            SELECT uses_remaining, single_use 
            FROM vouchers 
            WHERE id = :id AND tenant_id = :t AND status = 'active'
            AND (expires_at IS NULL OR expires_at > NOW())
        ");
        $stmt->execute([':id' => $voucherId, ':t' => $tenantId]);
        $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$voucher) continue;
        
        // Record redemption
        $stmt = $pdo->prepare("
            INSERT INTO voucher_redemptions (
                tenant_id, voucher_id, order_id, amount_applied, 
                user_id, redeemed_at
            ) VALUES (
                :t, :v, :o, :amount, :u, NOW()
            )
        ");
        $stmt->execute([
            ':t' => $tenantId,
            ':v' => $voucherId,
            ':o' => $id,
            ':amount' => $amountApplied,
            ':u' => $userId
        ]);
        
        // Update voucher usage
        if ($voucher['single_use'] || $voucher['uses_remaining'] !== null) {
            $stmt = $pdo->prepare("
                UPDATE vouchers 
                SET uses_remaining = GREATEST(0, uses_remaining - 1)
                WHERE id = :id AND tenant_id = :t
            ");
            $stmt->execute([':id' => $voucherId, ':t' => $tenantId]);
        }
    }
    
    /* Update lifecycle timestamps */
    if ($status === 'closed' && $has_closed_at) {
        $pdo->prepare("UPDATE orders SET closed_at = COALESCE(closed_at, NOW()) WHERE id = :id")
            ->execute([':id' => $id]);
    }
    
    if ($status === 'voided' && $has_voided_at) {
        $sqlV = "UPDATE orders SET voided_at = COALESCE(voided_at, NOW())";
        $params = [':id' => $id];
        
        if ($has_voided_by) {
            $sqlV .= ", voided_by_user_id = COALESCE(voided_by_user_id, :vb)";
            $params[':vb'] = $userId;
        }
        
        $sqlV .= " WHERE id = :id";
        $pdo->prepare($sqlV)->execute($params);
    }
    
    /* Transition Detection */
    $becameClosed = ($status_prev !== 'closed' && $status === 'closed');
    $becamePaid = ($payment_status_prev !== 'paid' && $payment_status === 'paid');
    $isNewOrder = ($status_prev === null);
    
    $flash_messages = [];
    $flash_messages[] = $isNewOrder ? 'Order created successfully.' : 'Order updated successfully.';
    
    /* Loyalty Program Processing - Apply immediately for new orders */
    if ($customer_id && $loyalty_program_id && ($isNewOrder || $becameClosed || $becamePaid)) {
        try {
            // Apply loyalty program
            $loyaltyResult = applyLoyaltyProgram($pdo, $tenantId, $id, $loyalty_program_id, $customer_id, $subtotal_amount, $userId);
            
            if ($loyaltyResult['success']) {
                $flash_messages[] = $loyaltyResult['message'];
            } else {
                error_log('[loyalty] Failed to apply program: ' . $loyaltyResult['message']);
            }
        } catch (Throwable $e) {
            error_log('[loyalty] Error applying program: ' . $e->getMessage());
        }
    }
    
    /* Traditional Rewards Hook (if available) */
    if ($rewards_available && ($becameClosed || $becamePaid)) {
        try {
            $result = rewards_issue_cashback_for_order($tenantId, $id);
            if (!empty($result['issued'])) {
                $flash_messages[] = 'Cashback issued: ' . $result['amount'] . ' (Voucher ' . $result['code'] . ').';
            }
        } catch (Throwable $e) {
            error_log('[rewards] Error for order ' . $id . ': ' . $e->getMessage());
        }
    }
    
    /* Stockflow Integration (if closing) */
    if ($stockflow_available && $becameClosed) {
        try {
            $stockflow_result = stockflow_apply_on_order_close($pdo, $tenantId, $id, $userId);
            if (!empty($stockflow_result['notes'])) {
                $flash_messages[] = 'Stock: ' . implode('; ', $stockflow_result['notes']);
            }
        } catch (Throwable $e) {
            error_log('[stockflow] Error for order ' . $id . ': ' . $e->getMessage());
        }
    }
    
    $pdo->commit();
    $_SESSION['flash'] = implode(' ', $flash_messages);
    header('Location: /views/admin/orders/view.php?id=' . $id);
    exit;
    
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('[order_save] Error: ' . $e->getMessage());
    $_SESSION['flash'] = 'Error saving order: ' . $e->getMessage();
    header('Location: ' . ($id > 0 ? "/views/admin/orders/edit.php?id=$id" : '/views/admin/orders/create.php'));
    exit;
}

/**
 * Apply loyalty program to order
 */
function applyLoyaltyProgram(PDO $pdo, int $tenantId, int $orderId, int $programId, int $customerId, float $orderTotal, int $userId): array {
    try {
        // Get program details
        $stmt = $pdo->prepare("
            SELECT 
                lp.*,
                lpe.qualifying_visit_count,
                lpe.meta_json
            FROM loyalty_programs lp
            LEFT JOIN loyalty_program_enrollments lpe ON lpe.program_id = lp.id AND lpe.customer_id = :c
            WHERE lp.id = :id AND lp.tenant_id = :t AND lp.status = 'active'
        ");
        $stmt->execute([':id' => $programId, ':t' => $tenantId, ':c' => $customerId]);
        $program = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$program) {
            return ['success' => false, 'message' => 'Loyalty program not found or inactive'];
        }
        
        $rewards = calculateLoyaltyRewards($program, $orderTotal, $customerId);
        
        if (!$rewards['applicable']) {
            return ['success' => false, 'message' => 'No rewards applicable for this order'];
        }
        
        // Apply rewards based on program type
        switch ($program['program_type']) {
            case 'points':
                if ($rewards['points_to_earn'] > 0) {
                    // Update customer points balance
                    $stmt = $pdo->prepare("
                        UPDATE customers 
                        SET points_balance = points_balance + :points
                        WHERE id = :id AND tenant_id = :t
                    ");
                    $stmt->execute([':points' => $rewards['points_to_earn'], ':id' => $customerId, ':t' => $tenantId]);
                    
                    // Update loyalty account if exists
                    $stmt = $pdo->prepare("
                        UPDATE loyalty_accounts 
                        SET points_balance = points_balance + :points,
                            lifetime_points = lifetime_points + :points,
                            last_activity_at = NOW()
                        WHERE customer_id = :c AND tenant_id = :t
                    ");
                    $stmt->execute([':points' => $rewards['points_to_earn'], ':c' => $customerId, ':t' => $tenantId]);
                    
                    // Log transaction
                    $stmt = $pdo->prepare("
                        INSERT INTO loyalty_ledger (
                            tenant_id, program_id, customer_id, order_id, 
                            type, points_delta, reason, created_at
                        ) VALUES (
                            :t, :p, :c, :o, 'cashback_earn', :points, 
                            'Points earned from order', NOW()
                        )
                    ");
                    $stmt->execute([
                        ':t' => $tenantId, ':p' => $programId, ':c' => $customerId, 
                        ':o' => $orderId, ':points' => $rewards['points_to_earn']
                    ]);
                    
                    return [
                        'success' => true, 
                        'message' => "Loyalty reward applied: {$rewards['points_to_earn']} points earned.",
                        'rewards' => $rewards
                    ];
                }
                break;
                
            case 'cashback':
                if ($rewards['cashback_to_earn'] > 0) {
                    // Create cashback voucher
                    $voucherCode = 'CB' . str_pad((string)$customerId, 4, '0', STR_PAD_LEFT) . substr(md5(uniqid((string)time())), 0, 6);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO vouchers (
                            tenant_id, code, type, value, single_use, uses_remaining,
                            starts_at, expires_at, status, pos_visible,
                            restrictions_json, created_at
                        ) VALUES (
                            :t, :code, 'value', :value, 1, 1,
                            NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'active', 1,
                            JSON_OBJECT('customer_ids', JSON_ARRAY(:c)), NOW()
                        )
                    ");
                    $stmt->execute([
                        ':t' => $tenantId, ':code' => $voucherCode, 
                        ':value' => $rewards['cashback_to_earn'], ':c' => $customerId
                    ]);
                    
                    $voucherId = (int)$pdo->lastInsertId();
                    
                    // Log transaction
                    $stmt = $pdo->prepare("
                        INSERT INTO loyalty_ledger (
                            tenant_id, program_id, customer_id, order_id, 
                            type, cash_delta, voucher_id, reason, created_at
                        ) VALUES (
                            :t, :p, :c, :o, 'cashback_earn', :cash, :v,
                            'Cashback earned from order', NOW()
                        )
                    ");
                    $stmt->execute([
                        ':t' => $tenantId, ':p' => $programId, ':c' => $customerId, 
                        ':o' => $orderId, ':cash' => $rewards['cashback_to_earn'], ':v' => $voucherId
                    ]);
                    
                    return [
                        'success' => true, 
                        'message' => "Cashback voucher created: {$voucherCode} (Value: {$rewards['cashback_to_earn']}).",
                        'rewards' => $rewards,
                        'voucher_code' => $voucherCode
                    ];
                }
                break;
        }
        
        return ['success' => false, 'message' => 'Unknown program type or no rewards to apply'];
        
    } catch (Throwable $e) {
        error_log('[loyalty] Apply program error: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Failed to apply loyalty program'];
    }
}

/**
 * Calculate loyalty rewards for order
 */
function calculateLoyaltyRewards(array $program, float $orderTotal, int $customerId): array {
    $rewards = [
        'program_id' => $program['id'],
        'program_name' => $program['name'],
        'program_type' => $program['program_type'],
        'points_to_earn' => 0,
        'cashback_to_earn' => 0.0,
        'description' => 'No rewards applicable',
        'applicable' => false
    ];
    
    try {
        switch ($program['program_type']) {
            case 'points':
                if ($program['earn_mode'] === 'per_currency') {
                    $earnRate = (float)$program['earn_rate'];
                    $pointsToEarn = (int)floor($orderTotal * $earnRate);
                    
                    if ($pointsToEarn > 0) {
                        $rewards['points_to_earn'] = $pointsToEarn;
                        $rewards['applicable'] = true;
                        $rewards['description'] = "Earn {$pointsToEarn} points for this order";
                    }
                }
                break;
                
            case 'cashback':
                if (!empty($program['earn_rule_json'])) {
                    $earnRules = json_decode($program['earn_rule_json'], true);
                    if (is_array($earnRules)) {
                        $cashback = calculateCashbackAmount($earnRules, $orderTotal);
                        if ($cashback > 0) {
                            $rewards['cashback_to_earn'] = $cashback;
                            $rewards['applicable'] = true;
                            $rewards['description'] = "Earn " . number_format($cashback, 2) . " cashback for this order";
                        }
                    }
                }
                break;
        }
    } catch (Throwable $e) {
        error_log('[loyalty] Rewards calculation error: ' . $e->getMessage());
    }
    
    return $rewards;
}

/**
 * Calculate cashback amount based on rules
 */
function calculateCashbackAmount(array $earnRules, float $orderTotal): float {
    // Check minimum order amount
    $minOrderAmount = (float)($earnRules['min_order_amount'] ?? 0);
    if ($orderTotal < $minOrderAmount) {
        return 0.0;
    }
    
    // Check if ladder system is used
    if (isset($earnRules['ladder']) && is_array($earnRules['ladder'])) {
        // For now, use the first tier - in a real system, you'd track customer visits
        $firstTier = $earnRules['ladder'][0] ?? null;
        if ($firstTier && isset($firstTier['percent'])) {
            $percent = (float)$firstTier['percent'];
            return ($percent / 100) * $orderTotal;
        }
    }
    
    // Simple percentage if no ladder
    $percent = (float)($earnRules['percent'] ?? 0);
    if ($percent > 0) {
        return ($percent / 100) * $orderTotal;
    }
    
    return 0.0;
}