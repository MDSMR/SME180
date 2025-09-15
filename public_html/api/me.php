<?php
/**
 * API: POS /me
 * Path: public_html/api/me.php
 * Auth: POS session (middleware/pos_auth.php)
 * Returns: { ok, user, permissions, settings }
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';

// --- safe includes (no redeclare issues) -----------------------------------
$cfg = __DIR__ . '/../config/db.php';
$mw  = __DIR__ . '/../middleware/pos_auth.php';
if (!is_file($cfg) || !is_file($mw)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Missing config or middleware']); exit;
}
require_once $cfg;
require_once $mw;

// Provide db() if config exposes $pdo
if (!function_exists('db')) {
  if (isset($pdo) && $pdo instanceof PDO) {
    function db(): PDO { global $pdo; return $pdo; }
  } else {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Database helper not available (db() or $pdo)']); exit;
  }
}

try {
  pos_session_start();
  $u = pos_user();
  if (!$u) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized (POS login required)']); exit;
  }

  $tenantId = (int)$u['tenant_id'];
  $roleKey  = (string)$u['role_key'];

  // Permissions for POS role
  $perms = [];
  try {
    $st = db()->prepare("SELECT permission_key, is_allowed FROM pos_role_permissions WHERE role_key=:rk");
    $st->execute([':rk'=>$roleKey]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $perms[$r['permission_key']] = (bool)$r['is_allowed'];
    }
  } catch (Throwable $e) {
    if ($DEBUG) { $perms['__warn'] = 'perm load: '.$e->getMessage(); }
  }

  // Settings (tax/service)
  $settings = ['tax_percent'=>0.0,'service_percent'=>0.0];
  try {
    $st = db()->prepare("SELECT `key`,`value` FROM settings WHERE tenant_id=:t AND `key` IN ('tax_percent','service_percent')");
    $st->execute([':t'=>$tenantId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      if ($r['key']==='tax_percent')     $settings['tax_percent']     = (float)$r['value'];
      if ($r['key']==='service_percent') $settings['service_percent'] = (float)$r['value'];
    }
  } catch (Throwable $e) {
    if ($DEBUG) { $settings['__warn'] = 'settings load: '.$e->getMessage(); }
  }

  $user = [
    'id'        => (int)$u['id'],
    'tenant_id' => $tenantId,
    'username'  => (string)$u['username'],
    'name'      => (string)$u['name'],
    'role_key'  => $roleKey,
  ];

  echo json_encode(['ok'=>true,'user'=>$user,'permissions'=>$perms,'settings'=>$settings], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  $out = ['ok'=>false,'error'=>'Server error'];
  if ($DEBUG) { $out['message']=$e->getMessage(); $out['trace']=$e->getTraceAsString(); }
  echo json_encode($out);
}