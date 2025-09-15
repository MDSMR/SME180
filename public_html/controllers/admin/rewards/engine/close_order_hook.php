<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /controllers/admin/rewards/engine/close_order_hook.php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/_apply_on_closed.php';

$orderId = (int)($_REQUEST['order_id'] ?? 0);
if ($orderId <= 0) fail('order_id is required');

try {
  $out = db_tx(function(PDO $pdo) use ($TENANT_ID, $orderId) {
    return rewards_apply_on_order_closed($pdo, $TENANT_ID, $orderId);
  });
  ok($out);
} catch (Throwable $e) {
  fail('Processing error: '.$e->getMessage(), 500);
}