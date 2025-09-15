<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// public_html/controllers/admin/rewards/discounts/schemes_list.php
declare(strict_types=1);

/* Bootstrap */
$ok=false; $cand=[ __DIR__.'/../../../../config/db.php', (rtrim((string)($_SERVER['DOCUMENT_ROOT']??''),'/')).'/config/db.php' ];
foreach ($cand as $f){ if (is_file($f)){ require_once $f; if (function_exists('db')&&function_exists('use_backend_session')){$ok=true; break;} } }
if (!$ok){ http_response_code(500); header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'Missing config']); exit; }
use_backend_session();
$pdo = db();
$user = $_SESSION['user'] ?? null;
if (!$user){ http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Auth']); exit; }
$tenantId=(int)$user['tenant_id']; $roleKey=(string)($user['role_key']??'');

/* Permission */
$st=$pdo->prepare("SELECT is_allowed FROM pos_role_permissions WHERE role_key=:rk AND permission_key='rewards.discounts.view'");
$st->execute([':rk'=>$roleKey]); if (!(bool)$st->fetchColumn()){ http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Forbidden']); exit; }

$st=$pdo->prepare("SELECT id,code,name,type,value,is_stackable,is_active FROM discount_schemes WHERE tenant_id=:t ORDER BY COALESCE(updated_at, created_at) DESC, id DESC");
$st->execute([':t'=>$tenantId]); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['ok'=>true,'items'=>$rows]);