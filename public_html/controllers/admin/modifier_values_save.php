<?php
declare(strict_types=1);
/* ---------- Debug (optional) ---------- */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* ---------- Bootstrap /config/db.php (robust search) ---------- */
if (!function_exists('db')) {
  $__BOOTSTRAP_OK = false;
  $__docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
  $__candidates = [
    __DIR__ . '/../../config/db.php',         // /controllers/admin -> /config
    dirname(__DIR__, 2) . '/config/db.php',   // extra safety
    ($__docroot ? $__docroot . '/config/db.php' : ''),
    ($__docroot ? $__docroot . '/public_html/config/db.php' : ''),
  ];
  foreach ($__candidates as $__cand) {
    if ($__cand && is_file($__cand)) { require_once $__cand; $__BOOTSTRAP_OK = true; break; }
  }
  if (!$__BOOTSTRAP_OK) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Configuration file not found: /config/db.php';
    exit;
  }
}

/* ---------- Start backend session ---------- */
if (function_exists('use_backend_session')) {
  use_backend_session();
} else {
  if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
}

/* ---------- Auth ---------- */
if (!function_exists('auth_require_login')) {
  $__docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
  $__auth_candidates = [
    __DIR__ . '/../../middleware/auth_login.php',     // /controllers/admin -> /middleware
    dirname(__DIR__, 2) . '/middleware/auth_login.php',
    ($__docroot ? $__docroot . '/middleware/auth_login.php' : ''),
    ($__docroot ? $__docroot . '/public_html/middleware/auth_login.php' : ''),
  ];
  foreach ($__auth_candidates as $__a) {
    if ($__a && is_file($__a)) { require_once $__a; break; }
  }
}
if (function_exists('auth_require_login')) { auth_require_login(); }
?>
<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/modifier_values_save.php — Create/Update single Modifier Value (tenant-scoped)
declare(strict_types=1);

use_backend_session();

$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id'];

if (empty($_SESSION['csrf_modval']) || ($_POST['csrf']??'')!==$_SESSION['csrf_modval']){ $_SESSION['flash']='Invalid request.'; header('Location:/views/admin/modifier_values.php'); exit; }

function column_exists(PDO $pdo, string $t, string $c): bool {
  $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
  $q->execute([':t'=>$t,':c'=>$c]); return (bool)$q->fetchColumn();
}

$id  =(int)($_POST['id']??0);
$gid =(int)($_POST['group_id']??0);
$en  =trim((string)($_POST['value_en']??''));
$ar  =trim((string)($_POST['value_ar']??''));
$delta=(string)($_POST['price_delta']??'0');
$sort=(int)($_POST['sort_order']??1);
$act =(int)($_POST['is_active']??1);
$vis =(int)($_POST['pos_visible']??1);

if($gid<=0 || $en===''){ $_SESSION['flash']='Group and English value are required.'; header('Location: '.($id>0?"/views/admin/modifier_value_edit.php?id=$id":"/views/admin/modifier_value_new.php?group=$gid")); exit; }

try{
  $pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  if(!column_exists($pdo,'variation_values','pos_visible')){ try{$pdo->exec("ALTER TABLE variation_values ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active");}catch(Throwable $e){} }

  // verify the group belongs to tenant
  $chk=$pdo->prepare("SELECT id FROM variation_groups WHERE id=:g AND tenant_id=:t LIMIT 1");
  $chk->execute([':g'=>$gid, ':t'=>$tenantId]);
  if(!$chk->fetchColumn()){ $_SESSION['flash']='Group not found for this tenant.'; header("Location:/views/admin/modifier_values.php"); exit; }

  if($id>0){
    // verify value belongs to that group
    $vchk=$pdo->prepare("SELECT id FROM variation_values WHERE id=:id AND group_id=:g LIMIT 1");
    $vchk->execute([':id'=>$id, ':g'=>$gid]);
    if(!$vchk->fetchColumn()){ $_SESSION['flash']='Value not found for this group.'; header("Location:/views/admin/modifier_values.php?group=$gid"); exit; }

    $st=$pdo->prepare("UPDATE variation_values SET group_id=:g, value_en=:en, value_ar=:ar, price_delta=:d, sort_order=:s, is_active=:a, pos_visible=:v, updated_at=NOW() WHERE id=:id LIMIT 1");
    $st->execute([':g'=>$gid, ':en'=>$en, ':ar'=>$ar, ':d'=>$delta, ':s'=>$sort, ':a'=>$act, ':v'=>$vis, ':id'=>$id]);
    $_SESSION['flash']='Modifier value updated.';
  } else {
    $st=$pdo->prepare("INSERT INTO variation_values (group_id,value_en,value_ar,price_delta,sort_order,is_active,pos_visible,created_at,updated_at) VALUES (:g,:en,:ar,:d,:s,:a,:v,NOW(),NOW())");
    $st->execute([':g'=>$gid, ':en'=>$en, ':ar'=>$ar, ':d'=>$delta, ':s'=>$sort, ':a'=>$act, ':v'=>$vis]);
    $_SESSION['flash']='Modifier value created.';
  }
  header('Location:/views/admin/modifier_values.php?group='.$gid); exit;

}catch(Throwable $e){
  $_SESSION['flash']='Save error. '.$e->getMessage();
  header('Location: '.($id>0?"/views/admin/modifier_value_edit.php?id=$id":"/views/admin/modifier_value_new.php?group=$gid")); exit;
}