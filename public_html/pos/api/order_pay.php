<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/pos_auth.php';
pos_auth_require_login();

declare(strict_types=1);
require_once __DIR__ . '/../../middleware/pos_auth.php';
require_once __DIR__ . '/../../config/db.php';

$user = pos_require_user();
$tenantId = (int)($user['tenant_id'] ?? 0);

if (!function_exists('db')) {
    json_out(['ok'=>false,'error'=>'db_missing'], 500);
}

function body_json(): array { 
    $j = json_decode(file_get_contents('php://input') ?: '', true); 
    return is_array($j) ? $j : []; 
}
function i($v): int { return is_numeric($v) ? (int)$v : 0; }
function f($v): float { return is_numeric($v) ? (float)$v : 0.0; }

$in = body_json();
$orderId = i($in['order_id'] ?? 0);
$mode = (string)($in['mode'] ?? 'amount');
$parts = i($in['parts'] ?? 0);
$payments = $in['payments'] ?? [];

if ($orderId <= 0) json_out(['ok'=>false,'error'=>'invalid_order'], 422);

try {
  $pdo = db(); 
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  
  $st = $pdo->prepare("SELECT id,tenant_id,total_amount,payment_status FROM orders WHERE id=:id AND tenant_id=:t LIMIT 1");
  $st->execute([':id'=>$orderId, ':t'=>$tenantId]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  if (!$o) json_out(['ok'=>false,'error'=>'not_found'], 404);
  if ($o['payment_status'] === 'paid') json_out(['ok'=>true,'order_id'=>$orderId,'payment_status'=>'paid']);

  $total = (float)$o['total_amount']; 
  if ($total < 0) $total = 0.0;

  if ($mode === 'preview') {
    if ($parts < 2) json_out(['ok'=>false,'error'=>'parts_invalid'], 422);
    $each = round($total / $parts, 2);
    $first = array_fill(0, $parts - 1, $each);
    $last = round($total - array_sum($first), 2);
    $splits = $first; 
    $splits[] = $last;
    json_out(['ok'=>true,'order_id'=>$orderId,'split_parts'=>$parts,'each'=>$each,'splits'=>$splits]);
  }

  if (!is_array($payments) || !count($payments)) json_out(['ok'=>false,'error'=>'payments_required'], 422);

  $sum = 0.0; 
  $methods = [];
  foreach ($payments as $p) {
    $m = (string)($p['method'] ?? ''); 
    $a = f($p['amount'] ?? 0);
    if (!in_array($m, ['cash','card','online'], true)) json_out(['ok'=>false,'error'=>'invalid_method'], 422);
    if ($a <= 0) json_out(['ok'=>false,'error'=>'invalid_amount'], 422);
    $sum += $a; 
    $methods[$m] = true;
  }

  if ($mode === 'equal' && $parts >= 2) {
    if (count($payments) !== $parts) json_out(['ok'=>false,'error'=>'split_parts_mismatch'], 422);
    if (round($sum, 2) !== round($total, 2)) json_out(['ok'=>false,'error'=>'split_sum_mismatch'], 422);
  } else {
    if ($sum + 0.0001 < $total) json_out(['ok'=>false,'error'=>'insufficient_sum'], 422);
  }

  $method = (count($methods) > 1) ? 'split' : array_keys($methods)[0];

  $up = $pdo->prepare("UPDATE orders SET payment_status='paid', payment_method=:m, status=IF(status IN('open','held','sent'),'closed',status), closed_at=NOW(), updated_at=NOW() WHERE id=:id AND tenant_id=:t");
  $up->execute([':m'=>$method, ':id'=>$orderId, ':t'=>$tenantId]);

  json_out(['ok'=>true,'order_id'=>$orderId,'payment_status'=>'paid','payment_method'=>$method,'total_amount'=>$total,'amount_received'=>round($sum,2)]);
} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>'db_error'], 500);
}