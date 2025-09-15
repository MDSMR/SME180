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

// controllers/admin/modifiers_delete.php — Delete Modifier Group (tenant-scoped) + its values
declare(strict_types=1);

use_backend_session();

$user=$_SESSION['user']??null; if(!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id'];

$id=(int)($_GET['id']??0);
if($id<=0){ $_SESSION['flash']='Modifier group not specified.'; header('Location:/views/admin/modifiers.php'); exit; }

try{
  $pdo=db(); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
  // verify ownership
  $chk=$pdo->prepare("SELECT id FROM variation_groups WHERE id=:id AND tenant_id=:t LIMIT 1");
  $chk->execute([':id'=>$id, ':t'=>$tenantId]);
  if(!$chk->fetchColumn()){ $_SESSION['flash']='Group not found for this tenant.'; header('Location:/views/admin/modifiers.php'); exit; }

  $pdo->beginTransaction();
  $pdo->prepare("DELETE FROM variation_values WHERE group_id=:g")->execute([':g'=>$id]);
  $pdo->prepare("DELETE FROM variation_groups WHERE id=:g AND tenant_id=:t LIMIT 1")->execute([':g'=>$id, ':t'=>$tenantId]);
  $pdo->commit(); $_SESSION['flash']='Modifier group deleted.';
}catch(Throwable $e){
  if(!empty($pdo) && $pdo->inTransaction()) $pdo->rollBack();
  $_SESSION['flash']='Delete error. '.$e->getMessage();
}
header('Location:/views/admin/modifiers.php'); exit;