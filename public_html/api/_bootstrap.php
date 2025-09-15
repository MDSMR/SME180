<?php
declare(strict_types=1);

/**
 * API bootstrap
 * - Loads DB + session from /config/db.php
 * - Loads Redis helper (graceful if missing)
 * - Provides JSON helpers
 * - Provides auth helpers (current_user_or_null/current_user/require_auth)
 * - Optional CSRF verification hook
 * - Simple rate-limit helper
 */

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* --- Resolve /config/db.php robustly --- */
$tried = [];
$paths = [
  __DIR__ . '/../../config/db.php',
  rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/config/db.php',
];
$found = null;
foreach ($paths as $p) { if ($p && is_file($p)) { $found = $p; break; } $tried[] = $p; }
if (!$found) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>['code'=>'BOOTSTRAP','http'=>500,'message'=>'Configuration file not found','details'=>['tried'=>$tried]]], JSON_UNESCAPED_SLASHES);
  exit;
}
require_once $found; // expects: db():PDO, use_backend_session()

if (function_exists('use_backend_session')) { use_backend_session(); }
header('Content-Type: application/json; charset=utf-8');

/* --- JSON helpers --- */
function api_ok(array $data = []): void {
  echo json_encode(['ok'=>true,'data'=>$data], JSON_UNESCAPED_SLASHES);
  exit;
}
function api_error(string $code, int $http, string $message, array $details = []): void {
  http_response_code($http);
  echo json_encode(['ok'=>false,'error'=>[
    'code'=>$code,'http'=>$http,'message'=>$message,'details'=>$details,
    'traceId'=>substr(bin2hex(random_bytes(8)),0,16)
  ]], JSON_UNESCAPED_SLASHES);
  exit;
}

/* --- Safe Redis helper load (graceful) --- */
$redisHelper = __DIR__ . '/helpers/redis_client.php';
if (is_file($redisHelper)) {
  require_once $redisHelper; // defines redis() and redis_optional()
} else {
  // Redis not available; callers can use redis_optional() to degrade gracefully
  function redis_optional() { return null; }
}

/* --- Auth helpers --- */

/** Returns array|null, no side effects */
function current_user_or_null(): ?array {
  $u = $_SESSION['user'] ?? null;
  if (!is_array($u) || empty($u['id']) || empty($u['tenant_id'])) {
    return null;
  }
  return $u;
}

/** Returns array or emits 401 and exits */
function current_user(): array {
  $u = current_user_or_null();
  if ($u === null) api_error('AUTH', 401, 'Unauthorized', []);
  return $u;
}

function require_auth(): void { current_user(); }
function tenant_id(): int { $u = current_user(); return (int)$u['tenant_id']; }

/* --- Small utils --- */
function uuidv4(): string {
  $data = random_bytes(16);
  $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
  $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
  return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/* --- Optional CSRF verification hook --- */
function csrf_verify_if_needed(): void {
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if (!in_array($method, ['GET','HEAD','OPTIONS'], true)) {
    if (function_exists('csrf_verify')) {
      if (!csrf_verify()) api_error('CSRF', 403, 'CSRF token invalid', []);
    }
  }
}
csrf_verify_if_needed();

/* --- Simple rate-limit helper (IP-scoped) --- */
function ratelimit_hit(string $key, int $windowSeconds, int $maxHits): array {
  // Prefer Redis; degrade to in-memory per-request if absent (best-effort)
  $r = function_exists('redis_optional') ? redis_optional() : null;
  $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
  $k  = "rl:" . $key . ":" . $ip;
  if ($r instanceof Redis) {
    $hits = (int)$r->incr($k);
    if ($hits === 1) { $r->expire($k, $windowSeconds); }
    $allowed = $hits <= $maxHits;
    return [$allowed, $hits, $windowSeconds];
  }
  // no Redis → allow (don’t break flows)
  return [true, 1, $windowSeconds];
}

/* --- Capability convenience (uses perm cache helper if present) --- */
if (is_file(__DIR__ . '/helpers/perm_cache.php')) {
  require_once __DIR__ . '/helpers/perm_cache.php';
  function has_capability(string $capability): bool {
    $u = current_user();
    $pdo = db();
    return has_permission($pdo, $u, $capability);
  }
}