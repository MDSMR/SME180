<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/pos_auth.php';
pos_auth_require_login();

// /public_html/pos/api/categories.php
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
  $st = $pdo->prepare("SELECT id, name_en, name_ar FROM categories WHERE tenant_id=:t AND is_active=1 AND pos_visible=1 ORDER BY sort_order, name_en");
  $st->execute([':t'=>$tenantId]);
  $cats = $st->fetchAll(PDO::FETCH_ASSOC);
  echo json_encode(['ok'=>true,'categories'=>$cats], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  echo json_encode(['ok'=>false,'error'=>'db_error'], JSON_UNESCAPED_UNICODE);
}