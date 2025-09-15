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

$program = strtolower(trim((string)($_POST['program']??'')));
$type    = strtolower(trim((string)($_POST['type']??''))); // credit|debit
$amount  = (float)($_POST['amount']??0);
$reason  = trim((string)($_POST['reason']??''));
$memberId= (int)($_POST['member_id']??0);

if (!in_array($program,['points','cashback'],true)) j(false,'Invalid program');
if (!in_array($type,['credit','debit'],true)) j(false,'Invalid type');
if ($amount<=0) j(false,'Amount must be > 0');
if ($memberId<=0) j(false,'Invalid member');
if ($reason==='') j(false,'Reason required');

if (!$db instanceof PDO) j(false,'DB not available');
$hasTable = (int)$db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='loyalty_ledger'")->fetchColumn()>0;
if (!$hasTable) j(false,'No ledger table');

$hasPts  = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='loyalty_ledger' AND column_name='points_delta'")->fetchColumn()>0;
$hasAmt  = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='loyalty_ledger' AND column_name='amount'")->fetchColumn()>0;
$hasType = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='loyalty_ledger' AND column_name='type'")->fetchColumn()>0;
$hasNote = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='loyalty_ledger' AND column_name='note'")->fetchColumn()>0;
$hasUser = (int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='loyalty_ledger' AND column_name='user_id'")->fetchColumn()>0;

$custCol = ((int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='loyalty_ledger' AND column_name='customer_id'")->fetchColumn()>0)
  ? 'customer_id'
  : ( ((int)$db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='loyalty_ledger' AND column_name='member_id'")->fetchColumn()>0) ? 'member_id' : 'customer_id');

try{
  $db->beginTransaction();

  if ($program==='points'){
    if (!$hasPts) throw new RuntimeException('points_delta column missing');
    $delta = ($type==='credit') ? $amount : -$amount;
    $sql="INSERT INTO loyalty_ledger (tenant_id, {$custCol}, points_delta".($hasNote?', note':'').($hasUser?', user_id':'').", created_at)
          VALUES (:t,:m,:delta".($hasNote?', :note':'').($hasUser?', :uid':'').", NOW())";
    $st=$db->prepare($sql);
    $st->bindValue(':t',$tenantId,PDO::PARAM_INT);
    $st->bindValue(':m',$memberId,PDO::PARAM_INT);
    $st->bindValue(':delta',$delta);
    if ($hasNote) $st->bindValue(':note',$reason);
    if ($hasUser) $st->bindValue(':uid',(int)($user['id']??0),PDO::PARAM_INT);
    $st->execute();
  } else { // cashback
    if (!$hasAmt) throw new RuntimeException('amount column missing');
    $ctype = ($type==='debit') ? 'cashback_redeem' : 'cashback_earn';
    $sql="INSERT INTO loyalty_ledger (tenant_id, {$custCol}, amount".($hasType?', type':'').($hasNote?', note':'').($hasUser?', user_id':'').", created_at)
          VALUES (:t,:m,:amt".($hasType?', :type':'').($hasNote?', :note':'').($hasUser?', :uid':'').", NOW())";
    $st=$db->prepare($sql);
    $st->bindValue(':t',$tenantId,PDO::PARAM_INT);
    $st->bindValue(':m',$memberId,PDO::PARAM_INT);
    $st->bindValue(':amt',abs($amount));
    if ($hasType) $st->bindValue(':type',$ctype);
    if ($hasNote) $st->bindValue(':note',$reason);
    if ($hasUser) $st->bindValue(':uid',(int)($user['id']??0),PDO::PARAM_INT);
    $st->execute();
  }

  $db->commit();
  j(true);
}catch(Throwable $e){
  if ($db->inTransaction()) $db->rollBack();
  j(false, $e->getMessage());
}