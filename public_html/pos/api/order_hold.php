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

$in = body_json();
$orderId = i($in['order_id'] ?? 0);
$productIds = $in['product_ids'] ?? null;
$setHeld = isset($in['held']) ? !!$in['held'] : true;

if ($orderId <= 0) json_out(['ok'=>false,'error'=>'invalid_order'], 422);

try {
  $pdo = db(); 
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $st = $pdo->prepare("SELECT id,tenant_id,status,order_notes FROM orders WHERE id=:id AND tenant_id=:t LIMIT 1");
  $st->execute([':id'=>$orderId, ':t'=>$tenantId]);
  $o = $st->fetch(PDO::FETCH_ASSOC);
  if (!$o) json_out(['ok'=>false,'error'=>'not_found'], 404);

  $meta = [];
  if (!empty($o['order_notes'])) { 
      $d = json_decode((string)$o['order_notes'], true); 
      if (is_array($d)) $meta = $d; 
  }
  $held = isset($meta['held_product_ids']) && is_array($meta['held_product_ids']) ? $meta['held_product_ids'] : [];

  if (is_array($productIds)) {
    $set = [];
    foreach ($productIds as $pid) {
        if (is_numeric($pid)) $set[] = (int)$pid;
    }
    if ($setHeld) { 
        $held = array_values(array_unique(array_merge($held, $set))); 
    } else { 
        $held = array_values(array_diff($held, $set)); 
    }
  }

  $meta['held_product_ids'] = $held;

  $up = $pdo->prepare("UPDATE orders SET status='held', order_notes=:notes, updated_at=NOW() WHERE id=:id AND tenant_id=:t");
  $up->execute([':notes'=>json_encode($meta, JSON_UNESCAPED_UNICODE), ':id'=>$orderId, ':t'=>$tenantId]);

  json_out(['ok'=>true,'order_id'=>$orderId,'status'=>'held','held_product_ids'=>$held]);
} catch (Throwable $e) {
  json_out(['ok'=>false,'error'=>'db_error'], 500);
}