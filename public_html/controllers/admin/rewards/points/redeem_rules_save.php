<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /controllers/admin/rewards/points/redeem_rules_save.php
declare(strict_types=1);

require_once __DIR__ . '/../_bootstrap.php';
require_method('POST');

$payload = read_json();
$redeem = $payload['redeem_rule'] ?? null;
if (!is_array($redeem)) fail('redeem_rule JSON is required');

try {
  db_tx(function(PDO $pdo) use ($TENANT_ID, $redeem) {
    $st = $pdo->prepare("SELECT id, redeem_rule_json FROM loyalty_programs WHERE tenant_id=:t AND program_type='points' LIMIT 1");
    $st->execute([':t'=>$TENANT_ID]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if ($row) {
      $upd = $pdo->prepare("
        UPDATE loyalty_programs
        SET redeem_rule_json=:r, is_active=1, updated_at=NOW()
        WHERE id=:id
      ");
      $upd->execute([':r'=>json_encode($redeem, JSON_UNESCAPED_UNICODE), ':id'=>$row['id']]);
    } else {
      $ins = $pdo->prepare("
        INSERT INTO loyalty_programs
          (tenant_id, program_type, name, earn_rule_json, redeem_rule_json, is_active, created_at, updated_at)
        VALUES
          (:t,'points','Points Program','{}',:r,1,NOW(),NOW())
      ");
      $ins->execute([':t'=>$TENANT_ID, ':r'=>json_encode($redeem, JSON_UNESCAPED_UNICODE)]);
    }
  });

  ok();

} catch (Throwable $e) {
  fail('Save failed: '.$e->getMessage(), 500);
}