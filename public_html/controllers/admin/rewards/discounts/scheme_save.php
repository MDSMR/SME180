<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// public_html/controllers/admin/rewards/discounts/scheme_save.php
declare(strict_types=1);

/* Bootstrap */
$ok=false; $cand=[ __DIR__.'/../../../../config/db.php', (rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/')).'/config/db.php' ];
foreach ($cand as $f){ if (is_file($f)){ require_once $f; if (function_exists('db')&&function_exists('use_backend_session')){$ok=true; break;} } }
header('Content-Type: application/json');
if (!$ok){ http_response_code(500); echo json_encode(['ok'=>false,'error'=>'Missing config']); exit; }
use_backend_session();
$pdo=db();
$user=$_SESSION['user']??null; if(!$user){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Auth']); exit; }
$tenantId=(int)$user['tenant_id']; $roleKey=(string)($user['role_key']??'');

/* Permission */
$st=$pdo->prepare("SELECT is_allowed FROM pos_role_permissions WHERE role_key=:rk AND permission_key='rewards.discounts.edit'");
$st->execute([':rk'=>$roleKey]); if (!(bool)$st->fetchColumn()){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

/* Input */
$in=json_decode(file_get_contents('php://input'), true);
if (!is_array($in)){ http_response_code(400); echo json_encode(['ok'=>false,'error'=>'Invalid JSON']); exit; }

$id = isset($in['id']) && $in['id'] ? (int)$in['id'] : null;
$code = trim((string)($in['code'] ?? ''));
$name = trim((string)($in['name'] ?? ''));
$type = (string)($in['type'] ?? 'percent');
$value = (float)($in['value'] ?? 0);
$is_stackable = !empty($in['is_stackable']) ? 1 : 0;
$is_active = !empty($in['is_active']) ? 1 : 0;

if ($code===''){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Code is required']); exit; }
if ($name===''){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Name is required']); exit; }
if (!in_array($type,['percent','fixed'],true)){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Bad type']); exit; }
if ($value < 0){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Value must be ≥ 0']); exit; }

/* Enforce unique code per tenant */
if ($id){
  $chk=$pdo->prepare("SELECT COUNT(*) FROM discount_schemes WHERE tenant_id=:t AND code=:c AND id<>:id");
  $chk->execute([':t'=>$tenantId, ':c'=>$code, ':id'=>$id]);
} else {
  $chk=$pdo->prepare("SELECT COUNT(*) FROM discount_schemes WHERE tenant_id=:t AND code=:c");
  $chk->execute([':t'=>$tenantId, ':c'=>$code]);
}
if ((int)$chk->fetchColumn() > 0){ http_response_code(409); echo json_encode(['ok'=>false,'error'=>'Code already exists']); exit; }

/* Insert/Update */
if ($id){
  $st=$pdo->prepare("UPDATE discount_schemes SET code=:c,name=:n,type=:ty,value=:v,is_stackable=:st,is_active=:ia,updated_at=NOW() WHERE id=:id AND tenant_id=:t");
  $st->execute([':c'=>$code, ':n'=>$name, ':ty'=>$type, ':v'=>$value, ':st'=>$is_stackable, ':ia'=>$is_active, ':id'=>$id, ':t'=>$tenantId]);
} else {
  $st=$pdo->prepare("INSERT INTO discount_schemes (tenant_id,code,name,type,value,is_stackable,is_active,created_at,updated_at) VALUES (:t,:c,:n,:ty,:v,:st,:ia,NOW(),NOW())");
  $st->execute([':t'=>$tenantId, ':c'=>$code, ':n'=>$name, ':ty'=>$type, ':v'=>$value, ':st'=>$is_stackable, ':ia'=>$is_active]);
  $id = (int)$pdo->lastInsertId();
}

echo json_encode(['ok'=>true,'id'=>$id]);