<?php
// /public_html/controllers/admin/orders/api/loyalty.php
// API endpoint for loyalty program management and rewards calculation
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/config/db.php';
require_once dirname(__DIR__, 4) . '/middleware/auth_login.php';
require_once __DIR__ . '/../_helpers.php';

auth_require_login();
use_backend_session();

header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user) {
    error_response('Unauthorized', 401);
}

$tenantId = (int)$user['tenant_id'];
$userId = (int)$user['id'];

// Determine action
$action = $_POST['action'] ?? $_GET['action'] ?? '';

if (empty($action)) {
    error_response('Action parameter required');
}

try {
    $pdo = db();
    
    switch ($action) {
        case 'customer_loyalty':
            $customerId = (int)($_GET['customer_id'] ?? 0);
            
            if ($customerId <= 0) {
                error_response('Valid customer ID required');
            }
            
            // Get customer loyalty account info
            $stmt = $pdo->prepare("
                SELECT 
                    la.id as account_id,
                    la.points_balance,
                    la.lifetime_points,
                    la.tier_code,
                    la.last_activity_at,
                    c.rewards_enrolled,
                    c.rewards_member_no
                FROM customers c
                LEFT JOIN loyalty_accounts la ON la.customer_id = c.id AND la.tenant_id = c.tenant_id
                WHERE c.id = :id AND c.tenant_id = :t
            ");
            $stmt->execute([':id' => $customerId, ':t' => $tenantId]);
            $loyaltyAccount = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$loyaltyAccount) {
                error_response('Customer not found');
            }
            
            // Get enrolled loyalty programs
            $stmt = $pdo->prepare("
                SELECT 
                    lp.id,
                    lp.name,
                    lp.program_type,
                    lp.earn_rate,
                    lp.redeem_rate,
                    lp.min_redeem_points,
                    lp.max_redeem_percent,
                    lpe.qualifying_visit_count,
                    lpe.last_qualifying_at,
                    lpe.meta_json
                FROM loyalty_programs lp
                JOIN loyalty_program_enrollments lpe ON lpe.program_id = lp.id
                WHERE lp.tenant_id = :t 
                AND lp.status = 'active'
                AND lpe.customer_id = :c
                ORDER BY lp.name
            ");
            $stmt->execute([':t' => $tenantId, ':c' => $customerId]);
            $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process programs
            foreach ($programs as &$program) {
                $program['earn_rate'] = (float)$program['earn_rate'];
                $program['redeem_rate'] = (float)$program['redeem_rate'];
                $program['min_redeem_points'] = (int)($program['min_redeem_points'] ?? 0);
                $program['max_redeem_percent'] = (float)($program['max_redeem_percent'] ?? 0);
                $program['qualifying_visit_count'] = (int)($program['qualifying_visit_count'] ?? 0);
                
                // Parse meta JSON
                $program['meta'] = [];
                if (!empty($program['meta_json'])) {
                    $meta = json_decode($program['meta_json'], true);
                    if (is_array($meta)) {
                        $program['meta'] = $meta;
                    }
                }
                unset($program['meta_json']);
            }
            
            // Get available vouchers
            $stmt = $pdo->prepare("
                SELECT 
                    v.id,
                    v.code,
                    v.type,
                    v.value,
                    v.min_order_amount,
                    v.max_discount_amount,
                    v.uses_remaining,
                    v.expires_at,
                    COALESCE(vr_count.redemption_count, 0) as times_used
                FROM vouchers v
                LEFT JOIN (
                    SELECT voucher_id, COUNT(*) as redemption_count
                    FROM voucher_redemptions
                    WHERE tenant_id = :t
                    GROUP BY voucher_id
                ) vr_count ON vr_count.voucher_id = v.id
                WHERE v.tenant_id = :t 
                AND v.status = 'active'
                AND (v.expires_at IS NULL OR v.expires_at > NOW())
                AND (v.uses_remaining IS NULL OR v.uses_remaining > 0)
                AND (
                    v.restrictions_json IS NULL 
                    OR JSON_SEARCH(v.restrictions_json, 'one', :c, NULL, '$.customer_ids[*]') IS NOT NULL
                    OR JSON_LENGTH(COALESCE(JSON_EXTRACT(v.restrictions_json, '$.customer_ids'), '[]')) = 0
                )
                ORDER BY v.expires_at ASC, v.value DESC
            ");
            $stmt->execute([':t' => $tenantId, ':c' => $customerId]);
            $vouchers = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Process vouchers
            foreach ($vouchers as &$voucher) {
                $voucher['value'] = (float)$voucher['value'];
                $voucher['min_order_amount'] = (float)($voucher['min_order_amount'] ?? 0);
                $voucher['max_discount_amount'] = (float)($voucher['max_discount_amount'] ?? 0);
                $voucher['uses_remaining'] = (int)($voucher['uses_remaining'] ?? 999);
                $voucher['times_used'] = (int)($voucher['times_used'] ?? 0);
            }
            
            success_response('Customer loyalty data retrieved', [
                'customer_id' => $customerId,
                'account_id' => (int)($loyaltyAccount['account_id'] ?? 0),
                'points_balance' => (int)($loyaltyAccount['points_balance'] ?? 0),
                'lifetime_points' => (int)($loyaltyAccount['lifetime_points'] ?? 0),
                'tier_code' => $loyaltyAccount['tier_code'],
                'last_activity_at' => $loyaltyAccount['last_activity_at'],
                'rewards_enrolled' => (bool)($loyaltyAccount['rewards_enrolled'] ?? false),
                'rewards_member_no' => $loyaltyAccount['rewards_member_no'],
                'programs' => $programs,
                'vouchers' => $vouchers
            ]);
            break;
            
        case 'calculate_rewards':
            $programId = (int)($_POST['program_id'] ?? 0);
            $orderTotal = (float)($_POST['order_total'] ?? 0);
            $customerId = (int)($_POST['customer_id'] ?? 0);
            
            if ($programId <= 0 || $orderTotal <= 0) {
                error_response('Valid program ID and order total required');
            }
            
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
                error_response('Program not found or inactive');
            }
            
            $rewards = calculateRewards($program, $orderTotal, $customerId);
            
            success_response('Rewards calculated', ['rewards' => $rewards]);
            break;
            
        case 'redeem_voucher':
            $voucherId = (int)($_POST['voucher_id'] ?? 0);
            $orderTotal = (float)($_POST['order_total'] ?? 0);
            $customerId = (int)($_POST['customer_id'] ?? 0);
            
            if ($voucherId <= 0 || $orderTotal <= 0) {
                error_response('Valid voucher ID and order total required');
            }
            
            // Validate and calculate voucher discount
            $voucherDiscount = validateAndCalculateVoucherDiscount($pdo, $tenantId, $voucherId, $orderTotal, $customerId);
            
            success_response('Voucher validated', [
                'voucher_id' => $voucherId,
                'discount_amount' => $voucherDiscount,
                'applicable' => $voucherDiscount > 0
            ]);
            break;
            
        case 'points_to_voucher':
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $pointsToUse = (int)($_POST['points'] ?? 0);
            $voucherType = validate_enum($_POST['voucher_type'] ?? 'value', ['value', 'percent'], 'value');
            
            if ($customerId <= 0 || $pointsToUse <= 0) {
                error_response('Valid customer ID and points amount required');
            }
            
            // Get customer points balance
            $stmt = $pdo->prepare("
                SELECT points_balance 
                FROM customers 
                WHERE id = :id AND tenant_id = :t
            ");
            $stmt->execute([':id' => $customerId, ':t' => $tenantId]);
            $pointsBalance = (int)$stmt->fetchColumn();
            
            if ($pointsBalance < $pointsToUse) {
                error_response('Insufficient points balance');
            }
            
            // Get conversion rate from settings
            $conversionRate = (float)get_setting($pdo, $tenantId, 'loyalty_points_conversion_rate', '0.01');
            if ($conversionRate <= 0) {
                error_response('Points to voucher conversion not available');
            }
            
            $voucherValue = $pointsToUse * $conversionRate;
            
            $pdo->beginTransaction();
            
            try {
                // Create voucher
                $voucherCode = 'PTS' . str_pad((string)$customerId, 4, '0', STR_PAD_LEFT) . substr(md5(uniqid((string)time())), 0, 6);
                
                $stmt = $pdo->prepare("
                    INSERT INTO vouchers (
                        tenant_id, code, type, value, single_use, uses_remaining,
                        starts_at, expires_at, status, pos_visible,
                        restrictions_json, created_at
                    ) VALUES (
                        :t, :code, :type, :value, 1, 1,
                        NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'active', 1,
                        JSON_OBJECT('customer_ids', JSON_ARRAY(:c)), NOW()
                    )
                ");
                $stmt->execute([
                    ':t' => $tenantId,
                    ':code' => $voucherCode,
                    ':type' => $voucherType,
                    ':value' => $voucherValue,
                    ':c' => $customerId
                ]);
                
                $voucherId = (int)$pdo->lastInsertId();
                
                // Deduct points from customer
                $stmt = $pdo->prepare("
                    UPDATE customers 
                    SET points_balance = points_balance - :points
                    WHERE id = :id AND tenant_id = :t
                ");
                $stmt->execute([':points' => $pointsToUse, ':id' => $customerId, ':t' => $tenantId]);
                
                // Log in loyalty ledger
                $stmt = $pdo->prepare("
                    INSERT INTO loyalty_ledger (
                        tenant_id, customer_id, type, points_delta, 
                        voucher_id, reason, created_at
                    ) VALUES (
                        :t, :c, 'cashback_redeem', :delta, :v, 
                        'Points to voucher conversion', NOW()
                    )
                ");
                $stmt->execute([
                    ':t' => $tenantId,
                    ':c' => $customerId,
                    ':delta' => -$pointsToUse,
                    ':v' => $voucherId
                ]);
                
                $pdo->commit();
                
                success_response('Voucher created from points', [
                    'voucher_id' => $voucherId,
                    'voucher_code' => $voucherCode,
                    'voucher_value' => $voucherValue,
                    'voucher_type' => $voucherType,
                    'points_used' => $pointsToUse,
                    'remaining_points' => $pointsBalance - $pointsToUse
                ]);
                
            } catch (Throwable $e) {
                $pdo->rollBack();
                error_response('Failed to create voucher: ' . $e->getMessage());
            }
            break;
            
        case 'apply_loyalty_order':
            // This will be called when an order is being saved
            $orderId = (int)($_POST['order_id'] ?? 0);
            $programId = (int)($_POST['program_id'] ?? 0);
            $customerId = (int)($_POST['customer_id'] ?? 0);
            
            if ($orderId <= 0 || $customerId <= 0) {
                error_response('Valid order ID and customer ID required');
            }
            
            // Get order details
            $stmt = $pdo->prepare("
                SELECT subtotal_amount, total_amount, status, payment_status
                FROM orders 
                WHERE id = :id AND tenant_id = :t
            ");
            $stmt->execute([':id' => $orderId, ':t' => $tenantId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$order) {
                error_response('Order not found');
            }
            
            $result = ['applied' => false, 'message' => 'No loyalty program selected'];
            
            if ($programId > 0) {
                // Apply loyalty program
                $result = applyLoyaltyToOrder($pdo, $tenantId, $orderId, $programId, $customerId, $order);
            }
            
            success_response($result['message'], $result);
            break;
            
        default:
            error_response('Invalid action');
    }
    
} catch (Throwable $e) {
    error_log('[loyalty_api] Error: ' . $e->getMessage());
    error_response('Loyalty operation failed: ' . $e->getMessage(), 500);
}

/**
 * Calculate rewards for a given program and order total
 */
function calculateRewards(array $program, float $orderTotal, int $customerId): array {
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
                    
                    // Apply tier multiplier if available
                    if (!empty($program['earn_rule_json'])) {
                        $earnRules = json_decode($program['earn_rule_json'], true);
                        if (is_array($earnRules) && isset($earnRules['tier_multiplier'])) {
                            // This would need tier lookup logic
                            $multiplier = 1.0; // Default multiplier
                            $pointsToEarn = (int)floor($pointsToEarn * $multiplier);
                        }
                    }
                    
                    $rewards['points_to_earn'] = $pointsToEarn;
                    $rewards['applicable'] = $pointsToEarn > 0;
                    $rewards['description'] = "Earn {$pointsToEarn} points for this order";
                }
                break;
                
            case 'cashback':
                if (!empty($program['earn_rule_json'])) {
                    $earnRules = json_decode($program['earn_rule_json'], true);
                    if (is_array($earnRules)) {
                        $cashback = calculateCashbackRewards($earnRules, $orderTotal, $customerId);
                        if ($cashback > 0) {
                            $rewards['cashback_to_earn'] = $cashback;
                            $rewards['applicable'] = true;
                            $rewards['description'] = "Earn " . format_money($cashback) . " cashback for this order";
                        }
                    }
                }
                break;
                
            case 'stamp':
                // Stamp card logic would go here
                $rewards['description'] = "Get 1 stamp towards your reward";
                $rewards['applicable'] = true;
                break;
        }
    } catch (Throwable $e) {
        error_log('[loyalty] Rewards calculation error: ' . $e->getMessage());
    }
    
    return $rewards;
}

/**
 * Calculate cashback rewards based on rules
 */
function calculateCashbackRewards(array $earnRules, float $orderTotal, int $customerId): float {
    // Check minimum order amount
    $minOrderAmount = (float)($earnRules['min_order_amount'] ?? 0);
    if ($orderTotal < $minOrderAmount) {
        return 0.0;
    }
    
    // Check if ladder system is used
    if (isset($earnRules['ladder']) && is_array($earnRules['ladder'])) {
        // Get customer's visit count for ladder position
        $visitCount = 1; // This would be calculated based on customer's order history
        
        foreach ($earnRules['ladder'] as $tier) {
            if (isset($tier['visit']) && $visitCount >= $tier['visit']) {
                $percent = (float)($tier['percent'] ?? 0);
                return ($percent / 100) * $orderTotal;
            }
        }
    }
    
    return 0.0;
}

/**
 * Validate voucher and calculate discount amount
 */
function validateAndCalculateVoucherDiscount(PDO $pdo, int $tenantId, int $voucherId, float $orderTotal, int $customerId): float {
    // Get voucher details
    $stmt = $pdo->prepare("
        SELECT 
            v.*,
            COALESCE(vr_count.redemption_count, 0) as times_used
        FROM vouchers v
        LEFT JOIN (
            SELECT voucher_id, COUNT(*) as redemption_count
            FROM voucher_redemptions
            WHERE tenant_id = :t
            GROUP BY voucher_id
        ) vr_count ON vr_count.voucher_id = v.id
        WHERE v.id = :id AND v.tenant_id = :t
    ");
    $stmt->execute([':id' => $voucherId, ':t' => $tenantId]);
    $voucher = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$voucher) {
        return 0.0;
    }
    
    // Check if voucher is active
    if ($voucher['status'] !== 'active') {
        return 0.0;
    }
    
    // Check expiry
    if ($voucher['expires_at'] && strtotime($voucher['expires_at']) < time()) {
        return 0.0;
    }
    
    // Check usage limits
    if ($voucher['uses_remaining'] !== null && $voucher['uses_remaining'] <= 0) {
        return 0.0;
    }
    
    // Check minimum order amount
    $minOrderAmount = (float)($voucher['min_order_amount'] ?? 0);
    if ($orderTotal < $minOrderAmount) {
        return 0.0;
    }
    
    // Check customer restrictions
    if (!empty($voucher['restrictions_json'])) {
        $restrictions = json_decode($voucher['restrictions_json'], true);
        if (is_array($restrictions) && isset($restrictions['customer_ids'])) {
            $allowedCustomers = (array)$restrictions['customer_ids'];
            if (!empty($allowedCustomers) && !in_array($customerId, $allowedCustomers)) {
                return 0.0;
            }
        }
    }
    
    // Calculate discount
    $voucherValue = (float)$voucher['value'];
    
    if ($voucher['type'] === 'percent') {
        $discount = ($voucherValue / 100) * $orderTotal;
    } else {
        $discount = $voucherValue;
    }
    
    // Apply maximum discount limit
    $maxDiscount = (float)($voucher['max_discount_amount'] ?? 0);
    if ($maxDiscount > 0 && $discount > $maxDiscount) {
        $discount = $maxDiscount;
    }
    
    // Don't exceed order total
    return min($discount, $orderTotal);
}

/**
 * Apply loyalty program to completed order
 */
function applyLoyaltyToOrder(PDO $pdo, int $tenantId, int $orderId, int $programId, int $customerId, array $order): array {
    try {
        // Get program details
        $stmt = $pdo->prepare("
            SELECT * FROM loyalty_programs 
            WHERE id = :id AND tenant_id = :t AND status = 'active'
        ");
        $stmt->execute([':id' => $programId, ':t' => $tenantId]);
        $program = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$program) {
            return ['applied' => false, 'message' => 'Program not found'];
        }
        
        $orderTotal = (float)$order['subtotal_amount'];
        $rewards = calculateRewards($program, $orderTotal, $customerId);
        
        if (!$rewards['applicable']) {
            return ['applied' => false, 'message' => 'No rewards applicable for this order'];
        }
        
        $pdo->beginTransaction();
        
        if ($program['program_type'] === 'points' && $rewards['points_to_earn'] > 0) {
            // Award points
            $stmt = $pdo->prepare("
                UPDATE customers 
                SET points_balance = points_balance + :points
                WHERE id = :id AND tenant_id = :t
            ");
            $stmt->execute([':points' => $rewards['points_to_earn'], ':id' => $customerId, ':t' => $tenantId]);
            
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
            
        } elseif ($program['program_type'] === 'cashback' && $rewards['cashback_to_earn'] > 0) {
            // Award cashback as voucher
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
        }
        
        $pdo->commit();
        
        return [
            'applied' => true, 
            'message' => 'Loyalty rewards applied successfully',
            'rewards' => $rewards
        ];
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[loyalty] Apply error: ' . $e->getMessage());
        return ['applied' => false, 'message' => 'Failed to apply loyalty rewards'];
    }
}