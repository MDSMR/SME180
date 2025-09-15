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
  $action = $_POST['action'] ?? '';
  if ($id<=0 || !in_array($action,['activate','deactivate'],true)){
    throw new InvalidArgumentException('Invalid request');
  }

  $has_is_active = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='is_active'")->fetchColumn() > 0;
  $has_status    = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='status'")->fetchColumn() > 0;

  if (!$has_is_active && !$has_status){
    throw new RuntimeException('No supported status column on customers');
  }

  if ($has_is_active){
    $sql = "UPDATE customers SET is_active = :v WHERE id=:id AND tenant_id=:t LIMIT 1";
    $v = ($action==='activate') ? 1 : 0;
    $st = $db->prepare($sql);
    $st->execute([':v'=>$v, ':id'=>$id, ':t'=>$tenantId]);
  } else {
    $sql = "UPDATE customers SET status = :v WHERE id=:id AND tenant_id=:t LIMIT 1";
    $v = ($action==='activate') ? 'active' : 'inactive';
    $st = $db->prepare($sql);
    $st->execute([':v'=>$v, ':id'=>$id, ':t'=>$tenantId]);
  }

  echo json_encode(['ok'=>true]);
}catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}