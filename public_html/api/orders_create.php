<?php
/**
 * API: Create Order (POS) — with Variations, Promo Codes & Manual Discounts
 * Path: public_html/api/orders_create.php
 *
 * Request JSON (example):
 * {
 *   "branch_id": 1,
 *   "table_number": "5",
 *   "guest_count": 2,
 *   "aggregator_id": 1,
 *   "items": [
 *     {"id": 12, "qty": 2, "opts": [{"group":"Side 1","value":"Fries"}]}
 *   ],
 *   "discounts": {
 *     "promo_code": "SUMMER15",         // optional
 *     "manual_discount": {              // optional
 *       "type": "percent",              // "percent" | "fixed"
 *       "value": 10
 *     }
 *   }
 * }
 *
 * Behavior:
 * - Prices resolved from DB (branch overrides + variation price_deltas)
 * - Discounts:
 *   1) Promo code (if valid & active & within time window & usage limit)
 *   2) Manual discount (percent or fixed)
 *   The combined discount cannot exceed subtotal.
 * - Saves order, order_items, order_item_variations, and order_discounts rows.
 * - If promo applied, increments promo_codes.used_count atomically.
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/pos_auth.php';

pos_session_start();
$posUser = pos_user();
if (!$posUser) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

$tenantId = (int)$posUser['tenant_id'];
$userId   = (int)$posUser['id'];

// Parse input
$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '[]', true);
if (!is_array($input)) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

$branchId     = (int)($input['branch_id'] ?? 0);
$tableNumber  = trim((string)($input['table_number'] ?? ''));
$guestCount   = (int)($input['guest_count'] ?? 0);
$aggregatorId = isset($input['aggregator_id']) && $input['aggregator_id'] !== '' ? (int)$input['aggregator_id'] : null;
$items        = $input['items'] ?? [];
$discounts    = $input['discounts'] ?? [];

// Validate inputs
if ($branchId <= 0) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'branch_id is required']); exit; }
if (!is_array($items) || count($items) === 0) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'items array is required']); exit; }

// Check branch belongs to tenant
$st = db()->prepare("SELECT id FROM branches WHERE id=:id AND tenant_id=:t LIMIT 1");
$st->execute([':id'=>$branchId, ':t'=>$tenantId]);
if (!$st->fetch()) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Invalid branch for tenant']); exit; }

// Optional table lookup
$tableId = null;
if ($tableNumber !== '') {
  $ts = db()->prepare("SELECT id FROM dining_tables WHERE tenant_id=:t AND branch_id=:b AND table_number=:num LIMIT 1");
  $ts->execute([':t'=>$tenantId, ':b'=>$branchId, ':num'=>$tableNumber]);
  if ($row = $ts->fetch()) $tableId = (int)$row['id'];
}

// Settings
function get_setting($tenantId, $key, $default='0.00'){
  $st = db()->prepare("SELECT `value` FROM settings WHERE tenant_id=:t AND `key`=:k LIMIT 1");
  $st->execute([':t'=>$tenantId, ':k'=>$key]);
  $v = $st->fetchColumn();
  return $v !== false ? (string)$v : $default;
}
$taxPercent     = (float)get_setting($tenantId, 'tax_percent', '0.00');
$servicePercent = (float)get_setting($tenantId, 'service_percent', '0.00');

// Aggregator commission percent
$commissionPercent = 0.00;
if (!empty($aggregatorId)) {
  $ag = db()->prepare("SELECT default_commission_percent FROM aggregators WHERE id=:id AND tenant_id=:t AND is_active=1");
  $ag->execute([':id'=>$aggregatorId, ':t'=>$tenantId]);
  $commissionPercent = (float)($ag->fetchColumn() ?: 0.00);
}

// Build product list
$productIds = [];
foreach ($items as $line) {
  $pid = (int)($line['id'] ?? 0);
  if ($pid > 0) $productIds[$pid] = true;
}
if (!$productIds) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>'No valid product ids']); exit; }

$idPlaceholders = implode(',', array_fill(0, count($productIds), '?'));
$prodStmt = db()->prepare("
  SELECT p.id, p.name_en, p.price AS base_price,
         COALESCE(pba.price_override, p.price) AS eff_price,
         COALESCE(pba.is_available, 1) AS is_available
  FROM products p
  LEFT JOIN product_branch_availability pba
    ON pba.product_id=p.id AND pba.branch_id=? 
  WHERE p.tenant_id=? AND p.id IN ($idPlaceholders) AND p.pos_visible=1 AND p.is_active=1
");
$bind = [$branchId, $tenantId]; foreach (array_keys($productIds) as $pid) $bind[] = $pid;
$prodStmt->execute($bind);
$prodRows = $prodStmt->fetchAll(PDO::FETCH_ASSOC);
$products = [];
foreach ($prodRows as $r) { $products[(int)$r['id']] = $r; }

// Variations index by names
$vgStmt = db()->prepare("
  SELECT vg.id AS group_id, vg.name AS group_name, vv.id AS value_id, vv.value_en AS value_name, vv.price_delta
  FROM variation_groups vg
  JOIN variation_values vv ON vv.group_id = vg.id AND vv.is_active=1
  WHERE vg.tenant_id = :t
");
$vgStmt->execute([':t'=>$tenantId]);
$varIndex = []; // [group_name_lc][value_name_lc] => meta
foreach ($vgStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
  $g = mb_strtolower(trim((string)$row['group_name']), 'UTF-8');
  $v = mb_strtolower(trim((string)$row['value_name']), 'UTF-8');
  $varIndex[$g][$v] = [
    'group_id' => (int)$row['group_id'],
    'value_id' => (int)$row['value_id'],
    'price_delta' => (float)$row['price_delta'],
    'group_name' => (string)$row['group_name'],
    'value_name' => (string)$row['value_name'],
  ];
}

// Calculate lines
$calcLines = [];
$subtotal = 0.000;
foreach ($items as $line) {
  $pid = (int)($line['id'] ?? 0);
  $qty = max(1, (int)($line['qty'] ?? ($line['quantity'] ?? 1)));
  if ($pid <= 0 || !isset($products[$pid])) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>"Product $pid invalid/unavailable"]); exit; }
  $p = $products[$pid];
  if ((int)$p['is_available'] !== 1) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>"Product {$pid} not available in branch"]); exit; }

  $unitBase = (float)$p['eff_price'];
  $opts = is_array($line['opts'] ?? null) ? $line['opts'] : [];
  $chosen = [];
  $deltaSum = 0.000;
  foreach ($opts as $opt) {
    $gName = mb_strtolower(trim((string)($opt['group'] ?? '')), 'UTF-8');
    $vName = mb_strtolower(trim((string)($opt['value'] ?? '')), 'UTF-8');
    if ($gName === '' || $vName === '') continue;
    if (!isset($varIndex[$gName][$vName])) continue; // ignore unknown combo
    $meta = $varIndex[$gName][$vName];
    $deltaSum += (float)$meta['price_delta'];
    $chosen[] = [
      'group' => $meta['group_name'],
      'value' => $meta['value_name'],
      'price_delta' => (float)$meta['price_delta'],
    ];
  }
  $unit = $unitBase + $deltaSum;
  $lineTotal = round($unit * $qty, 3);
  $subtotal += $lineTotal;
  $calcLines[] = [
    'product_id'   => $pid,
    'product_name' => (string)$p['name_en'],
    'unit_price'   => round($unit, 3),
    'qty'          => $qty,
    'line_total'   => $lineTotal,
    'variations'   => $chosen,
  ];
}

// --- Discounts --------------------------------------------------------------
$discountTotal = 0.000;
$discountRows  = []; // for order_discounts inserts

// 1) Promo code (validate against promo_codes + discount_rules)
$promoCodeInput = '';
if (isset($discounts['promo_code']) && is_string($discounts['promo_code'])) {
  $promoCodeInput = trim($discounts['promo_code']);
}
if ($promoCodeInput !== '') {
  $ps = db()->prepare("
    SELECT pc.id AS promo_id, pc.code, pc.max_uses, pc.used_count, pc.is_active,
           dr.id AS rule_id, dr.name, dr.type, dr.amount_type, dr.amount_value,
           dr.start_at, dr.end_at, dr.is_active AS rule_active
    FROM promo_codes pc
    JOIN discount_rules dr ON dr.id = pc.discount_rule_id
    WHERE pc.code = :code AND pc.tenant_id = :t
    LIMIT 1
  ");
  $ps->execute([':code'=>$promoCodeInput, ':t'=>$tenantId]);
  if ($row = $ps->fetch(PDO::FETCH_ASSOC)) {
    $now = new DateTimeImmutable('now');
    $ok = true;
    if (!intval($row['is_active'])) $ok = false;
    if (!intval($row['rule_active'])) $ok = false;
    if ($row['start_at'] && $now < new DateTimeImmutable($row['start_at'])) $ok = false;
    if ($row['end_at'] && $now > new DateTimeImmutable($row['end_at'])) $ok = false;
    if ($row['max_uses'] !== null && (int)$row['used_count'] >= (int)$row['max_uses']) $ok = false;

    if ($ok) {
      $amount = 0.000;
      if ($row['amount_type']==='percent') $amount = $subtotal * ((float)$row['amount_value']/100);
      if ($row['amount_type']==='fixed')   $amount = (float)$row['amount_value'];
      $amount = min($amount, $subtotal);
      if ($amount > 0) {
        $discountTotal += $amount;
        $discountRows[] = [
          'discount_rule_id' => (int)$row['rule_id'],
          'promo_code_id'    => (int)$row['promo_id'],
          'amount_applied'   => round($amount,3),
        ];
      }
      // else 0 discount silently (e.g., value 0)
    } else {
      // invalid promo, but we won't fail the whole order — we just ignore it.
    }
  }
}

// 2) Manual discount
if (isset($discounts['manual_discount']) && is_array($discounts['manual_discount'])) {
  $md = $discounts['manual_discount'];
  $t = $md['type'] ?? '';
  $v = (float)($md['value'] ?? 0);
  if (($t==='percent' || $t==='fixed') && $v > 0) {
    $amt = 0.000;
    if ($t==='percent') $amt = $subtotal * ($v/100);
    if ($t==='fixed')   $amt = $v;
    $discountTotal += $amt;
    // We don’t save manual discount in order_discounts (no rule). It’s captured in orders.discount_amount.
  }
}

// Cap discountTotal not to exceed subtotal
$discountTotal = min($discountTotal, $subtotal);

// Taxes/service on (subtotal - discounts)
$subAfterDisc = $subtotal - $discountTotal;
$taxAmount     = round($subAfterDisc * ($taxPercent / 100), 3);
$serviceAmount = round($subAfterDisc * ($servicePercent / 100), 3);
$totalAmount   = round($subAfterDisc + $taxAmount + $serviceAmount, 3);

// Aggregator commission on total
$commissionAmount = 0.000;
if (!empty($aggregatorId) && $commissionPercent > 0) {
  $commissionAmount = round($totalAmount * ($commissionPercent / 100), 3);
}

// Persist
try {
  db()->beginTransaction();

  // Insert order
  $o = db()->prepare("
    INSERT INTO orders (
      tenant_id, branch_id, user_id, table_id,
      customer_name, order_type, status,
      guest_count, aggregator_id, commission_percent, commission_amount,
      subtotal_amount, tax_percent, tax_amount,
      service_percent, service_amount, discount_amount, total_amount,
      created_at, updated_at
    ) VALUES (
      :t, :b, :u, :table_id,
      :customer_name, :order_type, :status,
      :guest_count, :agg_id, :comm_p, :comm_a,
      :sub, :tax_p, :tax_a,
      :svc_p, :svc_a, :disc_a, :total_a,
      NOW(), NOW()
    )
  ");
  $o->execute([
    ':t' => $tenantId,
    ':b' => $branchId,
    ':u' => $userId,
    ':table_id' => $tableId,
    ':customer_name' => null,
    ':order_type' => $tableId ? 'dine_in' : ($aggregatorId ? 'talabat' : 'takeaway'),
    ':status' => 'pending',
    ':guest_count' => $guestCount ?: null,
    ':agg_id' => $aggregatorId,
    ':comm_p' => $commissionPercent,
    ':comm_a' => $commissionAmount,
    ':sub' => $subtotal,
    ':tax_p' => $taxPercent,
    ':tax_a' => $taxAmount,
    ':svc_p' => $servicePercent,
    ':svc_a' => $serviceAmount,
    ':disc_a' => $discountTotal,
    ':total_a' => $totalAmount,
  ]);
  $orderId = (int)db()->lastInsertId();

  // Items + variations
  $oi = db()->prepare("
    INSERT INTO order_items (
      order_id, product_id, product_name, unit_price, quantity, notes, line_subtotal, created_at, updated_at
    ) VALUES (
      :oid, :pid, :pname, :unit, :qty, :notes, :line, NOW(), NOW()
    )
  ");
  $oiv = db()->prepare("
    INSERT INTO order_item_variations (order_item_id, variation_group, variation_value, price_delta)
    VALUES (:oiid, :g, :v, :d)
  ");

  foreach ($calcLines as $L) {
    $oi->execute([
      ':oid'   => $orderId,
      ':pid'   => $L['product_id'],
      ':pname' => $L['product_name'],
      ':unit'  => $L['unit_price'],
      ':qty'   => $L['qty'],
      ':notes' => null,
      ':line'  => $L['line_total'],
    ]);
    $orderItemId = (int)db()->lastInsertId();

    foreach ($L['variations'] as $V) {
      $oiv->execute([
        ':oiid' => $orderItemId,
        ':g'    => $V['group'],
        ':v'    => $V['value'],
        ':d'    => $V['price_delta'],
      ]);
    }
  }

  // order_discounts rows (promo only)
  if ($discountRows) {
    $od = db()->prepare("
      INSERT INTO order_discounts (order_id, discount_rule_id, promo_code_id, amount_applied, created_at)
      VALUES (:oid, :rid, :pid, :amt, NOW())
    ");
    foreach ($discountRows as $R) {
      $od->execute([
        ':oid' => $orderId,
        ':rid' => $R['discount_rule_id'],
        ':pid' => $R['promo_code_id'],
        ':amt' => $R['amount_applied'],
      ]);
    }
    // Bump promo usage counts
    $upd = db()->prepare("UPDATE promo_codes SET used_count = used_count + 1 WHERE id = :pid");
    foreach ($discountRows as $R) {
      if (!empty($R['promo_code_id'])) $upd->execute([':pid'=>$R['promo_code_id']]);
    }
  }

  db()->commit();
} catch (Throwable $e) {
  if (db()->inTransaction()) db()->rollBack();
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Failed to save order']); exit;
}

echo json_encode([
  'ok' => true,
  'order_id' => $orderId,
  'totals' => [
    'subtotal' => (float)$subtotal,
    'discount' => (float)$discountTotal,
    'tax_percent' => (float)$taxPercent,
    'tax_amount' => (float)$taxAmount,
    'service_percent' => (float)$servicePercent,
    'service_amount' => (float)$serviceAmount,
    'aggregator_commission_percent' => (float)$commissionPercent,
    'aggregator_commission_amount'  => (float)$commissionAmount,
    'total' => (float)$totalAmount,
  ]
], JSON_UNESCAPED_UNICODE);