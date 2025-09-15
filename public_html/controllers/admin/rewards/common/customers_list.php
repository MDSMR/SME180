<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/rewards/common/customers_list.php
// Returns a JSON list of customers with a safe "is_active" field.
// Uses COALESCE(c.is_active, 1) so it works even if the column doesn't exist.

declare(strict_types=1);
@ini_set('display_errors','0');

function out($code, $payload){ http_response_code($code); header('Content-Type: application/json'); echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit; }

function find_config(): string {
  $cands = [
    __DIR__.'/../../../../config/db.php',
    (rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/')).'/config/db.php',
  ];
  $cur = __DIR__;
  for ($i=0; $i<6; $i++) { $cur = dirname($cur); $cands[] = $cur.'/config/db.php'; }
  foreach ($cands as $p) if (is_file($p)) return $p;
  throw new RuntimeException('config/db.php not found');
}

try {
  require_once find_config(); // must define db(), use_backend_session()
  use_backend_session();
} catch (Throwable $e) { out(500, ['ok'=>false, 'error'=>'Bootstrap error: '.$e->getMessage()]); }

$user = $_SESSION['user'] ?? null;
if (!$user) out(401, ['ok'=>false, 'error'=>'Auth required']);
$tenantId = (int)($user['tenant_id'] ?? 0);
if ($tenantId <= 0) out(403, ['ok'=>false, 'error'=>'Invalid tenant']);

$q      = trim((string)($_GET['q'] ?? ''));
$limit  = max(1, min(200, (int)($_GET['limit'] ?? 50)));
$offset = max(0, (int)($_GET['offset'] ?? 0));

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Build WHERE with optional free-text query
  $where = "c.tenant_id = :t";
  $params = [':t' => $tenantId];

  if ($q !== '') {
    $where .= " AND (c.name LIKE :q OR c.phone LIKE :q OR c.email LIKE :q OR CAST(c.id AS CHAR) LIKE :q)";
    $params[':q'] = '%'.$q.'%';
  }

  // The “GOOD” query: prefer is_active if it exists, else default to 1
  // (COALESCE works even if is_active exists with NULL values; if column doesn't exist,
  // we still select COALESCE(c.is_active,1) because MySQL treats missing column as error,
  // so we defensively check the schema first and rewrite if needed.)

  // Schema check for customers.is_active
  $hasIsActive = false;
  try {
    $chk = $pdo->query("SHOW COLUMNS FROM customers LIKE 'is_active'");
    $hasIsActive = (bool)$chk->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $hasIsActive = false;
  }

  if ($hasIsActive) {
    $sql = "SELECT c.id, c.name, c.phone, c.email,
                   COALESCE(c.is_active, 1) AS is_active
            FROM customers c
            WHERE $where
            ORDER BY c.id DESC
            LIMIT :o, :l";
  } else {
    // Fallback: no column → hardcode 1 as is_active_alias to keep the interface consistent
    $sql = "SELECT c.id, c.name, c.phone, c.email,
                   1 AS is_active
            FROM customers c
            WHERE $where
            ORDER BY c.id DESC
            LIMIT :o, :l";
  }

  $st = $pdo->prepare($sql);
  foreach ($params as $k=>$v) $st->bindValue($k, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR);
  $st->bindValue(':o', $offset, PDO::PARAM_INT);
  $st->bindValue(':l', $limit,  PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  out(200, ['ok'=>true, 'rows'=>$rows, 'limit'=>$limit, 'offset'=>$offset]);
} catch (Throwable $e) {
  out(500, ['ok'=>false, 'error'=>'DB query warning: '.$e->getMessage()]);
}