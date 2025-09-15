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

$primary=(int)($_POST['primary_id']??0);
$other  =(int)($_POST['other_id']??0);
if ($primary<=0 || $other<=0 || $primary===$other) j(false,'Invalid IDs');

try{
  if (!$db instanceof PDO) j(false,'DB not available');

  $hasOrders = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='orders'")->fetchColumn()>0;
  $hasLedger = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='loyalty_ledger'")->fetchColumn()>0;

  $custLedgerCol = ((int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='loyalty_ledger' AND column_name='customer_id'")->fetchColumn()>0)
    ? 'customer_id'
    : ( ((int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='loyalty_ledger' AND column_name='member_id'")->fetchColumn()>0) ? 'member_id' : 'customer_id');

  $hasStatus  = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='status'")->fetchColumn()>0;
  $hasActive  = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='customers' AND column_name='is_active'")->fetchColumn()>0;

  $db->beginTransaction();

  // Ensure both customers exist in this tenant
  $chk=$db->prepare("SELECT COUNT(*) FROM customers WHERE tenant_id=:t AND id IN (:a,:b)");
  $chk->bindValue(':t',$tenantId,PDO::PARAM_INT);
  $chk->bindValue(':a',$primary,PDO::PARAM_INT);
  $chk->bindValue(':b',$other,PDO::PARAM_INT);
  $chk->execute();
  if ((int)$chk->fetchColumn()!==2) throw new RuntimeException('Customer(s) not found');

  if ($hasOrders){
    $u=$db->prepare("UPDATE orders SET customer_id=:p WHERE tenant_id=:t AND customer_id=:o");
    $u->execute([':p'=>$primary,':t'=>$tenantId,':o'=>$other]);
  }
  if ($hasLedger){
    $u=$db->prepare("UPDATE loyalty_ledger SET {$custLedgerCol}=:p WHERE tenant_id=:t AND {$custLedgerCol}=:o");
    $u->execute([':p'=>$primary,':t'=>$tenantId,':o'=>$other]);
  }

  // Deactivate other
  if ($hasStatus){
    $u=$db->prepare("UPDATE customers SET status='inactive' WHERE tenant_id=:t AND id=:o");
    $u->execute([':t'=>$tenantId,':o'=>$other]);
  } elseif ($hasActive){
    $u=$db->prepare("UPDATE customers SET is_active=0 WHERE tenant_id=:t AND id=:o");
    $u->execute([':t'=>$tenantId,':o'=>$other]);
  }

  $db->commit();
  j(true);
}catch(Throwable $e){
  if ($db->inTransaction()) $db->rollBack();
  j(false,$e->getMessage());
}