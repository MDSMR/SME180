<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /controllers/admin/rewards/cashback/rules_save.php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_method('POST');

$payload = read_json();
$earn = $payload['earn_rule'] ?? null;
if (!is_array($earn)) fail('earn_rule JSON is required');

try {
  db_tx(function(PDO $pdo) use ($TENANT_ID, $earn) {
    // Ensure a cashback program row exists for this tenant
    $st = $pdo->prepare("SELECT id FROM loyalty_programs WHERE tenant_id=:t AND program_type='cashback' LIMIT 1");
    $st->execute([':t'=>$TENANT_ID]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      $upd = $pdo->prepare("
        UPDATE loyalty_programs
        SET earn_rule_json=:e, is_active=1, updated_at=NOW()
        WHERE id=:id
      ");
      $upd->execute([':e'=>json_encode($earn, JSON_UNESCAPED_UNICODE), ':id'=>$row['id']]);
    } else {
      $ins = $pdo->prepare("
        INSERT INTO loyalty_programs
          (tenant_id, program_type, name, earn_rule_json, redeem_rule_json, is_active, created_at, updated_at)
        VALUES
          (:t,'cashback','Cashback Program',:e,'{}',1,NOW(),NOW())
      ");
      $ins->execute([':t'=>$TENANT_ID, ':e'=>json_encode($earn, JSON_UNESCAPED_UNICODE)]);
    }
  });

  ok();

} catch (Throwable $e) {
  fail('Save failed: '.$e->getMessage(), 500);
}