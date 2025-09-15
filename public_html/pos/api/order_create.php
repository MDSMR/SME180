<?php
// /api/order_create.php - With offline support
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/pos_auth.php';
pos_auth_require_login();

declare(strict_types=1);
require_once __DIR__ . '/../../middleware/pos_auth.php';
require_once __DIR__ . '/../../config/db.php';

$user = pos_require_user();
$tenantId = (int)($user['tenant_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);

if (!function_exists('db')) {
    json_out(['ok'=>false,'error'=>'db_missing'], 500);
}

function body_json(): array { 
    $j = json_decode(file_get_contents('php://input') ?: '', true); 
    return is_array($j) ? $j : []; 
}
function num($v): float { return is_numeric($v) ? (float)$v : 0.0; }
function i($v): int { return is_numeric($v) ? (int)$v : 0; }
function s($v): string { return is_string($v) ? trim($v) : ''; }

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Input
$in = body_json();

// Handle offline-created orders
$clientId = s($in['client_id'] ?? '');
$createdOffline = isset($in['created_offline']) ? (bool)$in['created_offline'] : false;
$clientTimestamp = s($in['client_timestamp'] ?? '');

// Check for duplicate submission if offline order
if ($createdOffline && !empty($clientId)) {
    try {
        $dupCheck = $pdo->prepare("SELECT id FROM orders WHERE client_id = :cid AND tenant_id = :t");
        $dupCheck->execute([':cid' => $clientId, ':t' => $tenantId]);
        if ($existingId = $dupCheck->fetchColumn()) {
            // Already synced, return success to clear from offline queue
            json_out([
                'ok' => true,
                'order_id' => (int)$existingId,
                'duplicate' => true,
                'message' => 'Order already synced'
            ]);
        }
    } catch (Throwable $e) {
        // Continue if client_id column doesn't exist
    }
}

// Settings
$decimals = 2;
$currencyCode = 'KD';
$serviceFraction = 0.0;
$enforceCaps = 1;

try {
  $st = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE tenant_id=:t AND `key` IN ('service_percent','currency_decimals','currency_code','discount_caps_enabled')");
  $st->execute([':t'=>$tenantId]);
  $kv = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $kv[$r['key']] = $r['value'];

  if (isset($kv['service_percent']) && is_numeric($kv['service_percent'])) {
      $serviceFraction = max(0.0,(float)$kv['service_percent'])/100.0;
  }
  if (isset($kv['currency_decimals']) && is_numeric($kv['currency_decimals'])) {
      $decimals = max(0,min(4,(int)$kv['currency_decimals']));
  }
  if (!empty($kv['currency_code'])) {
      $currencyCode = strtoupper(substr((string)$kv['currency_code'],0,4));
  }
  if (isset($kv['discount_caps_enabled'])) {
      $enforceCaps = ((int)$kv['discount_caps_enabled'])?1:0;
  }
} catch(Throwable $e){}

// Process order data
$finalize = isset($in['finalize']) ? (bool)$in['finalize'] : true;
$serviceType = s($in['service_type'] ?? 'dine_in');
if (!in_array($serviceType, ['dine_in','takeaway','delivery'], true)) $serviceType = 'dine_in';

$tableId = s($in['table_id'] ?? $in['table_number'] ?? '');
$guestCount = i($in['guest_count'] ?? 0);

$discountMode = s($in['discount_mode'] ?? ($enforceCaps ? 'free' : 'predefined'));
$discountType = s($in['discount_type'] ?? '');
$discountVal = num($in['discount_value'] ?? 0);
$discSchemeId = i($in['discount_scheme_id'] ?? 0);
$promoCode = s($in['promo_code'] ?? '');
$customerPhone = s($in['customer_phone'] ?? '');

$lines = $in['lines'] ?? [];
if (!is_array($lines) || !count($lines)) json_out(['ok'=>false,'error'=>'no_lines'], 422);
if ($serviceType === 'dine_in' && $guestCount <= 0) json_out(['ok'=>false,'error'=>'guests_required'], 422);

$roleKey = (string)($user['role_key'] ?? '');
$isAdmin = ($roleKey === 'admin');
$canDiscount = $isAdmin || has_permission('apply_discount');

// Subtotal & cleaned lines
$subtotal = 0.0;
$metaFired = [];
$metaHeld = [];
$cleanLines = [];
foreach ($lines as $ln) {
  $pid = i($ln['product_id'] ?? 0);
  $pname = s($ln['product_name'] ?? '');
  $unit = num($ln['unit_price'] ?? 0);
  $qty = max(1, i($ln['quantity'] ?? 1));
  $notes = s($ln['notes'] ?? '');
  $hold = !empty($ln['hold']);
  $fired = !empty($ln['fired']);
  if ($pid<=0 || $pname==='' || $unit<0) json_out(['ok'=>false,'error'=>'invalid_line'], 422);
  $lineSub = round($unit * $qty, $decimals);
  $subtotal = round($subtotal + $lineSub, $decimals);
  if ($hold) $metaHeld[] = $pid;
  if ($fired) $metaFired[] = $pid;
  $cleanLines[] = [
    'product_id'=>$pid,'product_name'=>$pname,'unit_price'=>round($unit,$decimals),
    'quantity'=>$qty,'notes'=>$notes,'line_subtotal'=>$lineSub
  ];
}
if ($subtotal <= 0) json_out(['ok'=>false,'error'=>'subtotal_zero'], 422);

// Discount
$discount = 0.0;
if ($discountMode === 'free' && $enforceCaps) {
  if (!$canDiscount) { $discountType=''; $discountVal=0; }
  if ($discountType==='percent') {
    $cap = $isAdmin ? 100.0 : 50.0;
    $pct = max(0.0, min((float)$discountVal, $cap));
    $discount = round($subtotal * ($pct/100.0), $decimals);
  } elseif ($discountType==='fixed') {
    $discount = round(min($discountVal, $subtotal), $decimals);
  }
} else {
  if ($discSchemeId>0) {
    $ds = $pdo->prepare("SELECT type,value FROM discount_schemes WHERE tenant_id=:t AND id=:id AND is_active=1");
    $ds->execute([':t'=>$tenantId, ':id'=>$discSchemeId]);
    if ($row=$ds->fetch(PDO::FETCH_ASSOC)) {
      $typ=$row['type']; $val=(float)$row['value'];
      if ($typ==='percent') $discount = round($subtotal*($val/100.0), $decimals);
      else $discount = round(min($val,$subtotal), $decimals);
    }
  }
}

// Service & tax
$service = ($serviceType==='dine_in') ? round(max(0.0, ($subtotal - $discount))*$serviceFraction, $decimals) : 0.0;
$taxPercent = 0.00; 
$taxAmount = 0.00;

// Totals
$total = round(max(0.0, $subtotal - $discount + $service + $taxAmount), $decimals);

// Branch fallback
$branchId = 0;
try {
  $bq = $pdo->prepare("SELECT id FROM branches WHERE tenant_id=:t AND is_active=1 ORDER BY id ASC LIMIT 1");
  $bq->execute([':t'=>$tenantId]); 
  $branchId = (int)($bq->fetchColumn() ?: 0);
} catch(Throwable $e){}
if ($branchId<=0) json_out(['ok'=>false,'error'=>'no_branch'], 500);

// Status / payment states
if ($finalize) {
  $status = 'closed';
  $paymentStatus = 'paid';
  $paymentMethod = 'cash';
} else {
  $status = 'open';
  $paymentStatus = 'unpaid';
  $paymentMethod = null;
}

// Metadata JSON - Include offline info
$meta = [
  'fired_product_ids'=>array_values(array_unique($metaFired)),
  'held_product_ids' =>array_values(array_unique($metaHeld)),
  'customer_phone'   =>$customerPhone,
  'promo_code'       =>$promoCode,
  'discount_mode'    =>$discountMode,
  'discount_type'    =>$discountType,
  'discount_value'   =>$discountVal,
  'discount_scheme_id'=>$discSchemeId,
  'created_offline'  =>$createdOffline,
  'client_id'        =>$clientId,
  'client_timestamp' =>$clientTimestamp
];
$orderNotes = json_encode($meta, JSON_UNESCAPED_UNICODE);

// Persist
try {
  $pdo->beginTransaction();

  // Check if client_id column exists
  $hasClientId = false;
  try {
      $colCheck = $pdo->query("SHOW COLUMNS FROM orders LIKE 'client_id'");
      $hasClientId = ($colCheck->rowCount() > 0);
  } catch (Throwable $e) {}

  // Build INSERT query based on column availability
  if ($hasClientId && !empty($clientId)) {
      $ins = $pdo->prepare("INSERT INTO orders
        (tenant_id,branch_id,created_by_user_id,receipt_reference,order_notes,source_channel,
         table_id,order_type,status,guest_count,client_id,
         subtotal_amount,tax_percent,tax_amount,service_percent,service_amount,discount_amount,total_amount,
         payment_status,payment_method,created_at)
        VALUES
        (:t,:b,:u,NULL,:notes,'pos',
         :table_id,:otype,:status,:guests,:client_id,
         :subtotal,:taxp,:taxa,:servp,:serva,:disca,:total,
         :pstatus,:pmethod,NOW())");

      $params = [
        ':t'=>$tenantId, ':b'=>$branchId, ':u'=>$userId, ':notes'=>$orderNotes,
        ':table_id'=> ($serviceType==='dine_in' && $tableId!=='') ? $tableId : null,
        ':otype'=>$serviceType, ':status'=>$status, ':guests'=>$guestCount?:null,
        ':client_id'=>$clientId,
        ':subtotal'=>$subtotal, ':taxp'=>round($taxPercent,2), ':taxa'=>$taxAmount,
        ':servp'=>round($serviceFraction*100.0,2), ':serva'=>$service, ':disca'=>$discount, ':total'=>$total,
        ':pstatus'=>$paymentStatus, ':pmethod'=>$paymentMethod
      ];
  } else {
      // Original query without client_id
      $ins = $pdo->prepare("INSERT INTO orders
        (tenant_id,branch_id,created_by_user_id,receipt_reference,order_notes,source_channel,
         table_id,order_type,status,guest_count,
         subtotal_amount,tax_percent,tax_amount,service_percent,service_amount,discount_amount,total_amount,
         payment_status,payment_method,created_at)
        VALUES
        (:t,:b,:u,NULL,:notes,'pos',
         :table_id,:otype,:status,:guests,
         :subtotal,:taxp,:taxa,:servp,:serva,:disca,:total,
         :pstatus,:pmethod,NOW())");

      $params = [
        ':t'=>$tenantId, ':b'=>$branchId, ':u'=>$userId, ':notes'=>$orderNotes,
        ':table_id'=> ($serviceType==='dine_in' && $tableId!=='') ? $tableId : null,
        ':otype'=>$serviceType, ':status'=>$status, ':guests'=>$guestCount?:null,
        ':subtotal'=>$subtotal, ':taxp'=>round($taxPercent,2), ':taxa'=>$taxAmount,
        ':servp'=>round($serviceFraction*100.0,2), ':serva'=>$service, ':disca'=>$discount, ':total'=>$total,
        ':pstatus'=>$paymentStatus, ':pmethod'=>$paymentMethod
      ];
  }

  $ins->execute($params);
  $orderId = (int)$pdo->lastInsertId();

  // Insert order items
  $insLn = $pdo->prepare("INSERT INTO order_items
    (order_id,product_id,product_name,unit_price,quantity,notes,line_subtotal)
    VALUES (:oid,:pid,:pname,:price,:qty,:notes,:ls)");
  foreach ($cleanLines as $ln) {
    $insLn->execute([
      ':oid'=>$orderId, ':pid'=>$ln['product_id'], ':pname'=>$ln['product_name'],
      ':price'=>$ln['unit_price'], ':qty'=>$ln['quantity'], ':notes'=>$ln['notes']?:null, ':ls'=>$ln['line_subtotal']
    ]);
  }

  $pdo->commit();
  
  // Log offline sync if applicable
  if ($createdOffline) {
      error_log("Offline order synced: Order ID {$orderId}, Client ID: {$clientId}");
  }
  
  json_out([
      'ok'=>true,
      'success'=>true,
      'order_id'=>$orderId,
      'total_amount'=>$total,
      'currency'=>$currencyCode,
      'finalized'=>$finalize,
      'was_offline'=>$createdOffline
  ]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log("Order creation error: " . $e->getMessage());
  json_out(['ok'=>false,'error'=>'db_error','message'=>$e->getMessage()], 500);
}