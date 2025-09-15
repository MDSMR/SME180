<?php
// /mohamedk10.sg-host.com/public_html/controllers/admin/orders/api/totals.php
// API endpoint for calculating order totals with order type based logic
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

// Get input
$orderType = clean_string($_POST['order_type'] ?? $_GET['order_type'] ?? 'dine_in');
$subtotal = parse_money($_POST['subtotal'] ?? $_GET['subtotal'] ?? '0');
$discount = parse_money($_POST['discount'] ?? $_GET['discount'] ?? '0');
$aggregatorId = (int)($_POST['aggregator_id'] ?? $_GET['aggregator_id'] ?? 0);
$itemsJson = $_POST['items'] ?? $_GET['items'] ?? '[]';

try {
    $pdo = db();
    
    // Parse items for line discount calculation
    $items = [];
    if ($itemsJson !== '[]') {
        $decoded = json_decode($itemsJson, true);
        if (is_array($decoded)) {
            $items = $decoded;
        }
    }
    
    // Calculate items subtotal and line discounts
    $itemsSubtotal = 0;
    $itemsDiscountTotal = 0;
    foreach ($items as $item) {
        $qty = (float)($item['quantity'] ?? 0);
        $price = (float)($item['unit_price'] ?? 0);
        $discountAmt = (float)($item['discount_amount'] ?? 0);
        $discountPct = (float)($item['discount_percent'] ?? 0);
        
        $lineSubtotal = $qty * $price;
        $lineDiscount = $discountAmt + ($lineSubtotal * ($discountPct / 100));
        
        $itemsSubtotal += $lineSubtotal;
        $itemsDiscountTotal += $lineDiscount;
    }
    
    // Use passed subtotal if provided, otherwise use calculated from items
    if ($subtotal == 0 && $itemsSubtotal > 0) {
        $subtotal = $itemsSubtotal;
    }
    
    // Get tax percentage (always applies)
    $taxPercent = (float)get_setting($pdo, $tenantId, 'tax_percent', '0');
    
    // Get service percentage (only for dine-in orders)
    $servicePercent = 0;
    if ($orderType === 'dine_in') {
        $servicePercent = (float)get_setting($pdo, $tenantId, 'service_percent', '0');
    }
    
    // Calculate base amount (after discounts)
    $totalDiscounts = $discount + $itemsDiscountTotal;
    $base = max(0, $subtotal - $totalDiscounts);
    
    // Calculate tax and service
    $taxAmount = round($base * ($taxPercent / 100), 3);
    $serviceAmount = round($base * ($servicePercent / 100), 3);
    
    // Calculate commission for delivery orders with aggregator
    $commissionPercent = 0;
    $commissionAmount = 0;
    
    if ($orderType === 'delivery' && $aggregatorId > 0) {
        $commissionPercent = get_aggregator_commission($pdo, $tenantId, $aggregatorId);
        if ($commissionPercent > 0) {
            $commissionAmount = round(($base + $taxAmount + $serviceAmount) * ($commissionPercent / 100), 3);
        }
    }
    
    // Calculate total
    $total = round($base + $taxAmount + $serviceAmount + $commissionAmount, 3);
    
    success_response('Totals calculated', [
        'order_type' => $orderType,
        'subtotal' => format_money($subtotal),
        'items_discount_total' => format_money($itemsDiscountTotal),
        'order_discount' => format_money($discount),
        'total_discount' => format_money($totalDiscounts),
        'base' => format_money($base),
        'tax_percent' => format_money($taxPercent, 2),
        'tax_amount' => format_money($taxAmount),
        'service_percent' => format_money($servicePercent, 2),
        'service_amount' => format_money($serviceAmount),
        'service_applies' => $orderType === 'dine_in',
        'commission_percent' => format_money($commissionPercent, 2),
        'commission_amount' => format_money($commissionAmount),
        'commission_applies' => $orderType === 'delivery' && $aggregatorId > 0,
        'total' => format_money($total),
        'currency' => get_setting($pdo, $tenantId, 'currency', 'EGP'),
        'breakdown' => [
            'items_subtotal' => format_money($subtotal),
            'minus_line_discounts' => format_money($itemsDiscountTotal),
            'minus_order_discount' => format_money($discount),
            'equals_base' => format_money($base),
            'plus_tax' => format_money($taxAmount),
            'plus_service' => format_money($serviceAmount),
            'plus_commission' => format_money($commissionAmount),
            'equals_total' => format_money($total)
        ]
    ]);
    
} catch (Throwable $e) {
    error_response('Calculation failed: ' . $e->getMessage(), 500);
}