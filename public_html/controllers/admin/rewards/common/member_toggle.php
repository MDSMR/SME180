<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

declare(strict_types=1);
header('Content-Type: application/json');
@ini_set('display_errors','0');

require_once __DIR__ . '/../../../../../config/db.php';
use_backend_session();
$db = db();

function j($ok,$err=null){ echo json_encode($ok?['ok'=>true]:['ok'=>false,'error'=>$err??'error'], JSON_UNESCAPED_UNICODE); exit; }

$user = $_SESSION['user'] ?? null; if(!$user) j(false,'Auth required');
$tenantId=(int)$user['tenant_id'];

$memberId=(int)($_POST['member_id']??0);
$action = $_POST['action'] ?? '';
if ($memberId<=0 || !in_array($action,['activate','deactivate'],true)) j(false,'Bad input');

try{
  if (!$db instanceof PDO) j(false,'DB not available');
  $hasStatus = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='status'")->fetchColumn()>0;
  $hasActive = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='is_active'")->fetchColumn()>0;
  if (!$hasStatus && !$hasActive) j(false,'No status column');

  if ($hasStatus){
    $st = ($action==='activate')?'active':'inactive';
    $u=$db->prepare("UPDATE customers SET status=:s WHERE tenant_id=:t AND id=:id");
    $u->execute([':s'=>$st, ':t'=>$tenantId, ':id'=>$memberId]);
  } else {
    $val = ($action==='activate')?1:0;
    $u=$db->prepare("UPDATE customers SET is_active=:v WHERE tenant_id=:t AND id=:id");
    $u->execute([':v'=>$val, ':t'=>$tenantId, ':id'=>$memberId]);
  }
  j(true);
}catch(Throwable $e){ j(false,$e->getMessage()); }