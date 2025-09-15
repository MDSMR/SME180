<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// public_html/controllers/admin/rewards/discounts/rules_list.php
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

/* Filters */
$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$type = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
$active = isset($_GET['active']) && $_GET['active'] !== '' ? (int)$_GET['active'] : null;

$sql = "SELECT id,name,type,amount_type,amount_value,is_stackable,start_at,end_at,is_active
        FROM discount_rules WHERE tenant_id = :t";
$p = [':t'=>$tenantId];
if ($q !== ''){ $sql .= " AND (name LIKE :q)"; $p[':q'] = '%'.$q.'%'; }
if ($type !== ''){ $sql .= " AND type = :ty"; $p[':ty'] = $type; }
if ($active !== null){ $sql .= " AND is_active = :ia"; $p[':ia'] = $active; }
$sql .= " ORDER BY COALESCE(updated_at, created_at) DESC, id DESC";

$st=$pdo->prepare($sql); $st->execute($p); $rows=$st->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode(['ok'=>true, 'items'=>$rows]);