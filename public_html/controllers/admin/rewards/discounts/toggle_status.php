<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// public_html/controllers/admin/rewards/discounts/toggle_status.php
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
$entity = (string)($in['entity'] ?? '');
$id     = isset($in['id']) ? (int)$in['id'] : 0;
$is_active = !empty($in['is_active']) ? 1 : 0;

if (!in_array($entity,['rule','scheme'],true) || $id<=0){ http_response_code(422); echo json_encode(['ok'=>false,'error'=>'Bad request']); exit; }

if ($entity==='rule'){
  $st=$pdo->prepare("UPDATE discount_rules SET is_active=:ia, updated_at=NOW() WHERE id=:id AND tenant_id=:t");
  $st->execute([':ia'=>$is_active, ':id'=>$id, ':t'=>$tenantId]);
} else {
  $st=$pdo->prepare("UPDATE discount_schemes SET is_active=:ia, updated_at=NOW() WHERE id=:id AND tenant_id=:t");
  $st->execute([':ia'=>$is_active, ':id'=>$id, ':t'=>$tenantId]);
}

echo json_encode(['ok'=>true]);