<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/pos_auth.php';
pos_auth_require_login();

// /public_html/pos/api/tables.php
declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$tenantId = 1;
if (@file_exists(__DIR__ . '/../../middleware/pos_auth.php')) {
  require_once __DIR__ . '/../../middleware/pos_auth.php';
  if (function_exists('pos_user')) { $u=pos_user(); $tenantId=(int)($u['tenant_id']??1); }
}

try {
  $st = $pdo->prepare("SELECT id, table_number FROM dining_tables WHERE tenant_id=:t ORDER BY table_number+0, table_number ASC");
  $st->execute([':t'=>$tenantId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode(['ok'=>true,'tables'=>$rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'db_error'], JSON_UNESCAPED_UNICODE);
}