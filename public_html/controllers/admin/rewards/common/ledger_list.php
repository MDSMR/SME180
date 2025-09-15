<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

declare(strict_types=1);
@ini_set('display_errors','0');
require_once __DIR__ . '/../../../../../config/db.php';
use_backend_session();
$db = db();

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES,'UTF-8'); }
function col(PDO $db,$t,$c){ $q=$db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t AND column_name=:c"); $q->execute([':t'=>$t,':c'=>$c]); return (int)$q->fetchColumn()>0; }

$user = $_SESSION['user'] ?? null; if(!$user){ http_response_code(403); exit('Auth required'); }
$tenantId=(int)$user['tenant_id'];
$memberId=(int)($_GET['member_id'] ?? 0);
$reward = $_GET['reward'] ?? 'all';

if ($memberId<=0){ echo '<div class="notice">Invalid member.</div>'; exit; }
if (!$db instanceof PDO){ echo '<div class="notice">DB not available.</div>'; exit; }
if ($db->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='loyalty_ledger'")->fetchColumn()==0){
  echo '<div class="notice">No ledger table.</div>'; exit;
}

$hasPts  = col($db,'loyalty_ledger','points_delta');
$hasAmt  = col($db,'loyalty_ledger','amount');
$hasType = col($db,'loyalty_ledger','type');
$hasNote = col($db,'loyalty_ledger','note');
$hasUser = col($db,'loyalty_ledger','user_id');
$hasOrd  = col($db,'loyalty_ledger','order_id');
$custCol = col($db,'loyalty_ledger','customer_id') ? 'customer_id' : (col($db,'loyalty_ledger','member_id') ? 'member_id' : 'customer_id');

$parts=[];
if (($reward==='all'||$reward==='points') && $hasPts){
  $parts[]="SELECT 'points' AS program, created_at AS ts,
                   CASE WHEN points_delta>=0 THEN 'credit' ELSE 'debit' END AS direction,
                   ABS(points_delta) AS amount,
                   ".($hasOrd?'order_id':'NULL')." AS order_id,
                   ".($hasUser?'user_id':'NULL')." AS user_id,
                   {$custCol} AS customer_id,
                   ".($hasNote ? "COALESCE(note,'')" : "''")." AS note
            FROM loyalty_ledger
           WHERE tenant_id=:t AND {$custCol}=:m AND points_delta IS NOT NULL AND points_delta<>0";
}
if (($reward==='all'||$reward==='cashback') && $hasAmt){
  $parts[]="SELECT 'cashback' AS program, created_at AS ts,
                   CASE WHEN ".($hasType?"type='cashback_redeem'":"0")." THEN 'debit' ELSE 'credit' END AS direction,
                   ABS(amount) AS amount,
                   ".($hasOrd?'order_id':'NULL')." AS order_id,
                   ".($hasUser?'user_id':'NULL')." AS user_id,
                   {$custCol} AS customer_id,
                   ".($hasNote?"COALESCE(note,'')":"'")." AS note
            FROM loyalty_ledger
           WHERE tenant_id=:t AND {$custCol}=:m AND amount IS NOT NULL AND amount<>0 ".($hasType?" AND type IN ('cashback_earn','cashback_redeem')":"");
}
if (!$parts){ echo '<div class="notice">No compatible ledger columns.</div>'; exit; }

$sql="SELECT * FROM (".implode(" UNION ALL ", $parts).") u ORDER BY u.ts DESC LIMIT 100";
$st=$db->prepare($sql);
$st->execute([':t'=>$tenantId, ':m'=>$memberId]);
$rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<table style="width:100%;border-collapse:separate;border-spacing:0 8px">
  <thead>
    <tr><th>Program</th><th>Date/Time</th><th>Direction</th><th style="text-align:right">Amount</th><th>Order</th><th>User</th><th>Note</th></tr>
  </thead>
  <tbody>
     if(!$rows): ?>
      <tr><td colspan="7" style="padding:10px">No entries.</td></tr>
     else: foreach($rows as $r): ?>
      <tr style="background:#fff;border:1px solid #e5e7eb;border-radius:10px">
        <td><?= h(ucfirst($r['program'])) ?></td>
        <td><?= h($r['ts']) ?></td>
        <td><?= h($r['direction']) ?></td>
        <td style="text-align:right"><?= number_format((float)$r['amount'],2,'.','') ?></td>
        <td><?= !empty($r['order_id']) ? '#'.(int)$r['order_id'] : '—' ?></td>
        <td><?= !empty($r['user_id']) ? '#'.(int)$r['user_id'] : '—' ?></td>
        <td><?= h($r['note'] ?? '') ?></td>
      </tr>
     endforeach; endif; ?>
  </tbody>
</table>