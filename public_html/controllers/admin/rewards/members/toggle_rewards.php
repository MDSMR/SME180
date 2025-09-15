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
  if ($id<=0 || !in_array($action,['enroll','unenroll'],true)){
    throw new InvalidArgumentException('Invalid request');
  }

  // Ensure columns exist
  $has_enrolled = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='rewards_enrolled'")->fetchColumn() > 0;
  if (!$has_enrolled){ throw new RuntimeException('Column rewards_enrolled is missing on customers'); }
  $has_member_no = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='rewards_member_no'")->fetchColumn() > 0;
  $has_enrolled_at = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='rewards_enrolled_at'")->fetchColumn() > 0;

  if ($action==='enroll'){
    // Generate a deterministic friendly number if missing: M + zero-padded ID (tenant-safe)
    $st = $db->prepare("SELECT rewards_member_no FROM customers WHERE id=:id AND tenant_id=:t LIMIT 1");
    $st->execute([':id'=>$id, ':t'=>$tenantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    $memberNo = $row && !empty($row['rewards_member_no']) ? $row['rewards_member_no'] : ('M'.str_pad((string)$id, 6, '0', STR_PAD_LEFT));

    $sql = "UPDATE customers SET rewards_enrolled=1".
           ($has_member_no ? ", rewards_member_no=:no" : "").
           ($has_enrolled_at ? ", rewards_enrolled_at=NOW()" : "").
           " WHERE id=:id AND tenant_id=:t LIMIT 1";
    $u = $db->prepare($sql);
    $params = [':id'=>$id, ':t'=>$tenantId];
    if ($has_member_no) $params[':no'] = $memberNo;
    $u->execute($params);
  } else {
    $u = $db->prepare("UPDATE customers SET rewards_enrolled=0 WHERE id=:id AND tenant_id=:t LIMIT 1");
    $u->execute([':id'=>$id, ':t'=>$tenantId]);
  }

  echo json_encode(['ok'=>true]);
}catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}