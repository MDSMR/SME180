<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// public_html/controllers/admin/rewards/discounts/rule_save.php
declare(strict_types=1);

/* Bootstrap */
$ok=false; $cand=[ __DIR__.'/../../../../config/db.php', (rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/')).'/config/db.php' ];
foreach ($cand as $f){ if (is_file($f)){ require_once $f; if (function_exists('db')&&function_exists('use_backend_session')){$ok=true; break;} } }
header('Content-Type: application/json');
if (!$ok){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Missing config']); exit; }
use_backend_session();
$pdo = db();
$user = $_SESSION['user'] ?? null;
if (!$user){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Auth']); exit; }
$tenantId=(int)$user['tenant_id']; $roleKey=(string)($user['role_key']??'');

/* Permission */
$st=$pdo->prepare("SELECT is_allowed FROM pos_role_permissions WHERE role_key=:rk AND permission_key='rewards.discounts.edit'");
$st->execute([':rk'=>$roleKey]); if (!(bool)$st->fetchColumn()){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

/* Parse JSON */
$in = json_decode(file_get_contents('php://input'), true);
if (!is_array($in)){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

$id = isset($in['id']) && $in['id'] ? (int)$in['id'] : null;
$name = trim((string)($in['name'] ?? ''));
$type = (string)($in['type'] ?? 'manual');
$amount_type = (string)($in['amount_type'] ?? 'percent');
$amount_value = (float)($in['amount_value'] ?? 0);
$is_stackable = !empty($in['is_stackable']) ? 1 : 0;
$start_at = isset($in['start_at']) && $in['start_at'] ? date('Y-m-d H:i:s', strtotime((string)$in['start_at'])) : null;
$end_at   = isset($in['end_at']) && $in['end_at'] ? date('Y-m-d H:i:s', strtotime((string)$in['end_at'])) : null;
$is_active = !empty($in['is_active']) ? 1 : 0;

if ($name === ''){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Name is required']); exit; }
if (!in_array($type, ['manual','promo_code','time_based'], true)){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Bad type']); exit; }
if (!in_array($amount_type, ['percent','fixed'], true)){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Bad amount_type']); exit; }
if ($amount_type==='percent' && ($amount_value <= 0 || $amount_value > 100)){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Percent must be 0–100']); exit; }
if ($amount_type==='fixed' && $amount_value <= 0){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Fixed must be > 0']); exit; }

/* Insert/Update */
if ($id){
  $st=$pdo->prepare("UPDATE discount_rules
    SET name=:n,type=:t,amount_type=:at,amount_value=:av,is_stackable=:stk,
        start_at=:sa,end_at=:ea,is_active=:ia, updated_at=NOW()
    WHERE id=:id AND tenant_id=:tenant");
  $st->execute([
    ':n'=>$name, ':t'=>$type, ':at'=>$amount_type, ':av'=>$amount_value, ':stk'=>$is_stackable,
    ':sa'=>$start_at, ':ea'=>$end_at, ':ia'=>$is_active, ':id'=>$id, ':tenant'=>$tenantId
  ]);
} else {
  $st=$pdo->prepare("INSERT INTO discount_rules
    (tenant_id,name,type,amount_type,amount_value,is_stackable,start_at,end_at,is_active,created_at,updated_at)
    VALUES (:tenant,:n,:t,:at,:av,:stk,:sa,:ea,:ia,NOW(),NOW())");
  $st->execute([
    ':tenant'=>$tenantId, ':n'=>$name, ':t'=>$type, ':at'=>$amount_type, ':av'=>$amount_value, ':stk'=>$is_stackable,
    ':sa'=>$start_at, ':ea'=>$end_at, ':ia'=>$is_active
  ]);
  $id = (int)$pdo->lastInsertId();
}

/* Promo codes if applicable */
if ($type === 'promo_code' && isset($in['codes']) && is_array($in['codes'])){
  foreach ($in['codes'] as $c){
    $cid = isset($c['id']) && $c['id'] ? (int)$c['id'] : null;
    $code = trim((string)($c['code'] ?? '')); if ($code==='') continue;
    $max_uses = isset($c['max_uses']) && $c['max_uses'] !== null ? (int)$c['max_uses'] : null;
    $active = !empty($c['is_active']) ? 1 : 0;
    if ($cid){
      $st=$pdo->prepare("UPDATE promo_codes SET code=:c,max_uses=:m,is_active=:ia,updated_at=NOW()
        WHERE id=:id AND tenant_id=:t AND discount_rule_id=:r");
      $st->execute([':c'=>$code, ':m'=>$max_uses, ':ia'=>$active, ':id'=>$cid, ':t'=>$tenantId, ':r'=>$id]);
    } else {
      $st=$pdo->prepare("INSERT INTO promo_codes (tenant_id,discount_rule_id,code,max_uses,used_count,is_active,created_at,updated_at)
        VALUES (:t,:r,:c,:m,0,:ia,NOW(),NOW())");
      $st->execute([':t'=>$tenantId, ':r'=>$id, ':c'=>$code, ':m'=>$max_uses, ':ia'=>$active]);
    }
  }
}

echo json_encode(['ok'=>true, 'id'=>$id]);