<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /controllers/admin/rewards/cashback/adjustment_create.php
// Creates a manual credit/debit and returns JSON with the new balance
declare(strict_types=1);

/* Headers */
header('Content-Type: application/json; charset=utf-8');

/* Bootstrap db + session */
$bootstrap_path = __DIR__ . '/../../../../config/db.php';
if (!is_file($bootstrap_path)) {
  $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  if ($docRoot) { $alt = $docRoot . '/config/db.php'; if (is_file($alt)) $bootstrap_path = $alt; }
}
if (!is_file($bootstrap_path)) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'db bootstrap missing']); exit; }

require_once $bootstrap_path; // db(), use_backend_session()
use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Auth required']); exit; }

$tenantId = (int)($user['tenant_id'] ?? 0);
$userId   = (int)($user['id'] ?? 0);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  echo json_encode(['ok'=>false,'error'=>'Invalid method']); exit;
}

function val_str($k){ return trim((string)($_POST[$k] ?? '')); }

try {
  $db = db();

  $programId  = (int)($_POST['program_id'] ?? 0);
  $customerId = (int)($_POST['customer_id'] ?? 0);
  $type       = val_str('adj_type'); // credit | debit
  $amount     = (float)($_POST['amount'] ?? 0);
  $reason     = val_str('reason');

  if ($programId <= 0 || $customerId <= 0) throw new InvalidArgumentException('Missing program or customer.');
  if (!in_array($type, ['credit','debit'], true)) throw new InvalidArgumentException('Type must be credit or debit.');
  if (!($amount > 0)) throw new InvalidArgumentException('Amount must be greater than zero.');
  if ($reason === '') throw new InvalidArgumentException('Reason is required.');

  // Verify program belongs to tenant & is cashback
  $st = $db->prepare("SELECT id FROM loyalty_programs WHERE id=? AND tenant_id=? AND program_type='cashback' LIMIT 1");
  $st->execute([$programId, $tenantId]);
  if (!$st->fetchColumn()) throw new RuntimeException('Program not found.');

  // Insert ledger row
  $direction = ($type === 'credit') ? 'credit' : 'redeem'; // NOTE: 'redeem' will subtract in balance queries
  $ins = $db->prepare("INSERT INTO loyalty_ledgers
    (tenant_id, program_type, program_id, customer_id,
     direction, amount, note, order_id, user_id, created_at)
    VALUES (:t, 'cashback', :pid, :cid,
            :dir, :amt, :note, NULL, :uid, NOW())");
  $ins->execute([
    ':t'   => $tenantId,
    ':pid' => $programId,
    ':cid' => $customerId,
    ':dir' => $direction,
    ':amt' => number_format($amount, 2, '.', ''),
    ':note'=> $reason,
    ':uid' => $userId ?: null,
  ]);

  // Compute new balance (same logic used in page)
  $q = $db->prepare("SELECT COALESCE(SUM(CASE WHEN direction='redeem' THEN -amount ELSE amount END),0)
                     FROM loyalty_ledgers
                     WHERE tenant_id=? AND program_type='cashback' AND program_id=? AND customer_id=?");
  $q->execute([$tenantId, $programId, $customerId]);
  $balance = (float)($q->fetchColumn() ?: 0);

  echo json_encode(['ok'=>true, 'balance'=>number_format($balance, 2, '.', '')]);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
}