<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

declare(strict_types=1);
header('Content-Type: application/json');

try{
  require_once __DIR__ . '/../../../../config/db.php';
  use_backend_session();
  $db = db();

  $user = $_SESSION['user'] ?? null;
  if (!$user) { throw new RuntimeException('Auth required'); }
  $tenantId = (int)($user['tenant_id'] ?? 0);

  $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
  $schemeIdRaw = $_POST['scheme_id'] ?? '';
  if ($id<=0){ throw new InvalidArgumentException('Invalid member'); }

  // Check column
  $has_disc_fk = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='discount_scheme_id'")->fetchColumn() > 0;
  if (!$has_disc_fk){ throw new RuntimeException('Column discount_scheme_id is missing on customers'); }

  // Allow clearing
  if ($schemeIdRaw==='' || $schemeIdRaw==='0'){
    $st = $db->prepare("UPDATE customers SET discount_scheme_id=NULL WHERE id=:id AND tenant_id=:t LIMIT 1");
    $st->execute([':id'=>$id, ':t'=>$tenantId]);
    echo json_encode(['ok'=>true]); exit;
  }

  $schemeId = (int)$schemeIdRaw;

  // Validate scheme belongs to tenant and active
  $chk = $db->prepare("SELECT COUNT(*) FROM discount_schemes WHERE id=:sid AND tenant_id=:t AND is_active=1");
  $chk->execute([':sid'=>$schemeId, ':t'=>$tenantId]);
  if ((int)$chk->fetchColumn()===0){ throw new RuntimeException('Invalid discount scheme'); }

  $st = $db->prepare("UPDATE customers SET discount_scheme_id=:sid WHERE id=:id AND tenant_id=:t LIMIT 1");
  $st->execute([':sid'=>$schemeId, ':id'=>$id, ':t'=>$tenantId]);

  echo json_encode(['ok'=>true]);
}catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}