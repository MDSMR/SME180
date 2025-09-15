<?php
declare(strict_types=1);
/**
 * Settings API (tenant-scoped)
 * Path: /public_html/controllers/admin/settings/index.php
 *
 * GET  ?action=list
 * POST action=save  (JSON or form)  fields: tax_percent, service_percent, currency_code
 */
header('Content-Type: application/json; charset=utf-8');

function respond(bool $ok, $data=null, ?string $error=null, int $code=200): void {
  http_response_code($code);
  echo json_encode(['ok'=>$ok,'data'=>$data,'error'=>$error], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  require_once dirname(__DIR__, 3) . '/config/db.php';
  require_once dirname(__DIR__, 3) . '/middleware/auth_login.php';

  auth_require_login();
  if (function_exists('use_backend_session')) { use_backend_session(); }
  if (!function_exists('db')) respond(false, null, 'Bootstrap error: db() missing', 500);

  if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
  $user = $_SESSION['user'] ?? null;
  if (!$user) respond(false, null, 'Unauthorized', 401);
  $tenantId = (int)($user['tenant_id'] ?? 0);
  if ($tenantId <= 0) respond(false, null, 'Invalid tenant', 403);

  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  $action = (string)($_GET['action'] ?? ($method === 'POST' ? ($_POST['action'] ?? '') : 'list'));

  if ($method === 'GET' && ($action === '' || $action === 'list')) {
    $st = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE tenant_id=:t");
    $st->execute([':t'=>$tenantId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['key']] = $r['value'];
    respond(true, $out);
  }

  if ($method === 'POST' && $action === 'save') {
    // Accept JSON or form
    $payload = [];
    $raw = file_get_contents('php://input') ?: '';
    if ($raw && ($j = json_decode($raw, true)) && is_array($j)) {
      $payload = $j;
    } else {
      $payload = $_POST;
    }

    // CSRF (optional)
    if (function_exists('ensure_csrf_token')) {
      $csrf = (string)($payload['csrf'] ?? '');
      $known = $_SESSION['csrf_settings'] ?? '';
      if ($known && hash_equals($known, $csrf) === false) respond(false, null, 'Invalid CSRF', 403);
    }

    $updates = [];
    $map = [
      'tax_percent'     => 'tax_percent',
      'service_percent' => 'service_percent',
      'currency_code'   => 'currency_code',
    ];
    foreach ($map as $inKey => $dbKey) {
      if (array_key_exists($inKey, $payload)) $updates[$dbKey] = trim((string)$payload[$inKey]);
    }

    // Normalize currency
    if (isset($updates['currency_code'])) {
      $updates['currency_code'] = strtoupper(preg_replace('/[^A-Za-z]/', '', $updates['currency_code']));
      if ($updates['currency_code'] === '') unset($updates['currency_code']);
    }

    // Persist
    $up = $pdo->prepare("INSERT INTO settings (tenant_id, `key`, `value`)
                         VALUES (:t,:k,:v)
                         ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
    foreach ($updates as $k=>$v) $up->execute([':t'=>$tenantId, ':k'=>$k, ':v'=>$v]);

    // Keep legacy 'currency' aligned for older PHP templates
    if (isset($updates['currency_code'])) {
      $symbol = currency_symbol_from_code($updates['currency_code']);
      $up->execute([':t'=>$tenantId, ':k'=>'currency', ':v'=>$symbol ?: $updates['currency_code']]);
    }

    // Return fresh state
    $st = $pdo->prepare("SELECT `key`,`value` FROM settings WHERE tenant_id=:t");
    $st->execute([':t'=>$tenantId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['key']]=$r['value'];
    respond(true, $out);
  }

  respond(false, null, 'Unknown action', 400);
} catch (Throwable $e) {
  respond(false, null, 'Server error: '.$e->getMessage(), 200);
}

function currency_symbol_from_code(?string $code): ?string {
  $code = strtoupper((string)$code);
  $map = [
    'EGP'=>'EGP', 'KWD'=>'KD', 'USD'=>'$', 'EUR'=>'€', 'GBP'=>'£',
    'SAR'=>'ر.س','AED'=>'د.إ','QAR'=>'ر.ق','OMR'=>'ر.ع','BHD'=>'ب.د'
  ];
  return $map[$code] ?? null;
}