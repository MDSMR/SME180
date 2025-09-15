<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /controllers/admin/rewards/_bootstrap.php
declare(strict_types=1);

/**
 * Shared bootstrap for Rewards controllers:
 * - loads config/db.php
 * - starts backend session
 * - guards authenticated user + tenant
 * - provides helpers: j(), require_method(), read_json(), ok(), fail(), db_tx()
 */

$__BOOT_OK = false;
$__BOOT_WARN = '';

$path = dirname(__DIR__, 2) . '/config/db.php'; // /controllers/admin/rewards -> up 2 -> /controllers/admin -> /controllers -> /config/db.php
if (!is_file($path)) {
  $__BOOT_WARN = 'Configuration file not found: /config/db.php';
} else {
  $prev = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try {
    require_once $path; // expects functions db(), use_backend_session()
    if (!function_exists('db') || !function_exists('use_backend_session')) {
      $__BOOT_WARN = 'Required functions missing in config/db.php (db(), use_backend_session()).';
    } else {
      $__BOOT_OK = true;
    }
  } catch (Throwable $e) {
    $__BOOT_WARN = 'Bootstrap error: '.$e->getMessage();
  } finally {
    if ($prev) set_error_handler($prev);
  }
}

if ($__BOOT_OK) {
  try { use_backend_session(); }
  catch (Throwable $e) { $__BOOT_WARN = $__BOOT_WARN ?: ('Session bootstrap error: '.$e->getMessage()); }
}

// ---- Auth / Tenant guard
$user = $_SESSION['user'] ?? null;
if (!$user) {
  http_response_code(401);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'Unauthenticated']);
  exit;
}
$TENANT_ID = (int)($user['tenant_id'] ?? 0);
if ($TENANT_ID <= 0) {
  http_response_code(400);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'Tenant not set']);
  exit;
}

// ---- Helpers
function j($x): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($x, JSON_UNESCAPED_UNICODE);
}

function require_method(string $m): void {
  if (strcasecmp($_SERVER['REQUEST_METHOD'] ?? '', $m) !== 0) {
    http_response_code(405);
    j(['ok'=>false,'error'=>'Method not allowed']);
    exit;
  }
}

function read_json(): array {
  $raw = file_get_contents('php://input') ?: '';
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function ok(array $x = []): void {
  j(['ok'=>true] + $x);
}

function fail(string $msg, int $code = 400, array $extra = []): void {
  http_response_code($code);
  j(['ok'=>false,'error'=>$msg] + $extra);
  exit;
}

/**
 * Run closure inside a DB transaction and return its result.
 * Rolls back on exception and rethrows.
 */
function db_tx(Closure $fn) {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->beginTransaction();
  try {
    $res = $fn($pdo);
    $pdo->commit();
    return $res;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}