<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

declare(strict_types=1);
header('Content-Type: application/json');

try{
  /* Bootstrap */
  $cand = __DIR__ . '/../../../../config/db.php';
  if (!is_file($cand)) { throw new RuntimeException('config/db.php not found'); }
  require_once $cand;
  use_backend_session();
  $db = db();
  if (!$db instanceof PDO) { throw new RuntimeException('DB not available'); }

  $user = $_SESSION['user'] ?? null;
  if (!$user) { throw new RuntimeException('Auth required'); }
  $tenantId = (int)($user['tenant_id'] ?? 0);
  if ($tenantId <= 0) { throw new RuntimeException('Invalid tenant'); }

  /* Helpers */
  $h = fn($s)=>trim((string)$s);
  $program = strtolower($h($_POST['program'] ?? ''));
  $type    = strtolower($h($_POST['type'] ?? 'credit')); // credit|debit
  $memberId= (int)($_POST['member_id'] ?? 0);
  $amount  = (float)($_POST['amount'] ?? 0);
  $reason  = $h($_POST['reason'] ?? '');

  if (!in_array($program, ['points','cashback','stamps'], true)) throw new InvalidArgumentException('Invalid program');
  if (!in_array($type, ['credit','debit'], true)) throw new InvalidArgumentException('Invalid type');
  if ($memberId <= 0) throw new InvalidArgumentException('Invalid customer/member id');
  if (!($amount > 0)) throw new InvalidArgumentException('Amount must be > 0');

  /* Schema probes */
  $tableExists = function(PDO $db, string $name): bool {
    $st=$db->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
    $st->execute([':t'=>$name]); return (int)$st->fetchColumn() > 0;
  };
  $colExists = function(PDO $db, string $table, string $col): bool {
    $st=$db->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=:t AND column_name=:c");
    $st->execute([':t'=>$table, ':c'=>$col]); return (int)$st->fetchColumn() > 0;
  };

  // Resolve cashback & stamps table names
  $cashbackTable = null;
  if ($tableExists($db,'loyalty_cashback_ledger')) $cashbackTable = 'loyalty_cashback_ledger';
  elseif ($tableExists($db,'loyalty_ledgers'))     $cashbackTable = 'loyalty_ledgers';

  $stampTable = null;
  if ($tableExists($db,'stamp_ledger'))      $stampTable = 'stamp_ledger';
  elseif ($tableExists($db,'stamps_ledger')) $stampTable = 'stamps_ledger';

  /* Insert */
  if ($program === 'points'){
    $table = 'loyalty_ledger';
    if (!$tableExists($db, $table)) throw new RuntimeException('Points ledger not found');

    $hasCustomer = $colExists($db,$table,'customer_id');
    $hasMember   = $colExists($db,$table,'member_id');
    if (!$hasCustomer && !$hasMember) throw new RuntimeException('No customer/member link column on loyalty_ledger');

    $linkCol = $hasCustomer ? 'customer_id' : 'member_id';
    $hasReason = $colExists($db,$table,'reason') || $colExists($db,$table,'note');
    $reasonCol = $colExists($db,$table,'reason') ? 'reason' : ($colExists($db,$table,'note') ? 'note' : null);
    $hasCreated= $colExists($db,$table,'created_at');

    $delta = ($type === 'credit') ? +$amount : -$amount;

    $sql = "INSERT INTO {$table}
            (tenant_id, {$linkCol}, points_delta".($reasonCol?", {$reasonCol}":"").($hasCreated?", created_at":"").")
            VALUES (:t, :cid, :delta".($reasonCol?", :reason":"").($hasCreated?", NOW()":"").")";
    $st = $db->prepare($sql);
    $st->bindValue(':t',$tenantId,PDO::PARAM_INT);
    $st->bindValue(':cid',$memberId,PDO::PARAM_INT);
    $st->bindValue(':delta',$delta);
    if ($reasonCol) $st->bindValue(':reason',$reason!==''?$reason:null, $reason!==''?PDO::PARAM_STR:PdO::PARAM_NULL);
    $st->execute();
    echo json_encode(['ok'=>true, 'id'=>$db->lastInsertId()]); exit;
  }

  if ($program === 'cashback'){
    if (!$cashbackTable) throw new RuntimeException('Cashback ledger not found');

    $hasCustomer = $colExists($db,$cashbackTable,'customer_id');
    $hasMember   = $colExists($db,$cashbackTable,'member_id');
    $linkCol = $hasCustomer ? 'customer_id' : ($hasMember ? 'member_id' : null);
    if (!$linkCol) throw new RuntimeException('No customer/member link column on cashback ledger');

    $hasNote    = $colExists($db,$cashbackTable,'note');
    $hasCreated = $colExists($db,$cashbackTable,'created_at');
    $hasDir     = $colExists($db,$cashbackTable,'direction');
    $hasAmt     = $colExists($db,$cashbackTable,'amount');

    if (!$hasAmt) throw new RuntimeException('Cashback ledger missing amount');

    // Use direction if available; otherwise encode sign in amount
    if ($hasDir){
      $sql = "INSERT INTO {$cashbackTable}
              (tenant_id, {$linkCol}, direction, amount".($hasNote?", note":"").($hasCreated?", created_at":"").")
              VALUES (:t, :cid, :dir, :amt".($hasNote?", :note":"").($hasCreated?", NOW()":"").")";
      $st = $db->prepare($sql);
      $st->execute([
        ':t'=>$tenantId,
        ':cid'=>$memberId,
        ':dir'=>$type, // credit|debit
        ':amt'=>$amount,
        ...( $hasNote ? [':note'=>($reason!==''?$reason:null)] : [] ),
      ]);
    } else {
      $signed = ($type==='credit') ? +$amount : -$amount;
      $sql = "INSERT INTO {$cashbackTable}
              (tenant_id, {$linkCol}, amount".($hasNote?", note":"").($hasCreated?", created_at":"").")
              VALUES (:t, :cid, :amt".($hasNote?", :note":"").($hasCreated?", NOW()":"").")";
      $st = $db->prepare($sql);
      $st->execute([
        ':t'=>$tenantId, ':cid'=>$memberId, ':amt'=>$signed,
        ...( $hasNote ? [':note'=>($reason!==''?$reason:null)] : [] ),
      ]);
    }
    echo json_encode(['ok'=>true, 'id'=>$db->lastInsertId()]); exit;
  }

  if ($program === 'stamps'){
    if (!$stampTable) throw new RuntimeException('Stamps ledger not found');

    $hasCustomer = $colExists($db,$stampTable,'customer_id');
    $hasMember   = $colExists($db,$stampTable,'member_id');
    $linkCol = $hasCustomer ? 'customer_id' : ($hasMember ? 'member_id' : null);
    if (!$linkCol) throw new RuntimeException('No customer/member link column on stamps ledger');

    $hasNote    = $colExists($db,$stampTable,'note');
    $hasCreated = $colExists($db,$stampTable,'created_at');
    $hasDir     = $colExists($db,$stampTable,'direction');
    $hasQty     = $colExists($db,$stampTable,'qty');

    $qty = $hasQty ? (int)round($amount) : 1; // if no qty col, assume 1 per event

    if ($hasDir){
      $sql = "INSERT INTO {$stampTable}
              (tenant_id, {$linkCol}, direction, ".($hasQty?'qty':'').($hasQty?', ':'').($hasNote?'note':'').($hasNote && $hasCreated?', ':'').($hasCreated?'created_at':'').")
              VALUES (:t, :cid, :dir".($hasQty?', :qty':'').($hasNote?', :note':'').($hasCreated?', NOW()':'').")";
      $st = $db->prepare($sql);
      $params = [':t'=>$tenantId, ':cid'=>$memberId, ':dir'=>$type];
      if ($hasQty)  $params[':qty']  = $qty;
      if ($hasNote) $params[':note'] = ($reason!==''?$reason:null);
      $st->execute($params);
    } else {
      // No direction column -> store positive qty; meaning of add/deduct may be implied elsewhere
      $sql = "INSERT INTO {$stampTable}
              (tenant_id, {$linkCol}".($hasQty?', qty':'').($hasNote?', note':'').($hasCreated?', created_at':'').")
              VALUES (:t, :cid".($hasQty?', :qty':'').($hasNote?', :note':'').($hasCreated?', NOW()':'').")";
      $st = $db->prepare($sql);
      $params = [':t'=>$tenantId, ':cid'=>$memberId];
      if ($hasQty)  $params[':qty']  = ($type==='credit') ? $qty : -$qty;
      if ($hasNote) $params[':note'] = ($reason!==''?$reason:null);
      $st->execute($params);
    }
    echo json_encode(['ok'=>true, 'id'=>$db->lastInsertId()]); exit;
  }

  throw new RuntimeException('Unsupported program');
}catch(Throwable $e){
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}