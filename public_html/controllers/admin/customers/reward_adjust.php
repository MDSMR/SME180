<?php
// /public_html/controllers/admin/customers/reward_adjust.php
declare(strict_types=1);
@ini_set('display_errors','0');

try {
  $root = dirname(__DIR__, 4);
  $configPath = $root . '/config/db.php';
  if (!is_file($configPath)) throw new RuntimeException('Configuration not found');
  require_once $configPath;

  if (function_exists('use_backend_session')) { use_backend_session(); } else { if (session_status() !== PHP_SESSION_ACTIVE) session_start(); }
  $authPath = $root . '/middleware/auth_login.php';
  if (!is_file($authPath)) throw new RuntimeException('Auth middleware not found');
  require_once $authPath;
  auth_require_login();

  if (!function_exists('db')) throw new RuntimeException('db() not available');

  $tenantId = (int)($_SESSION['user']['tenant_id'] ?? 0);
  $userId   = (int)($_SESSION['user']['id'] ?? 0);
  if ($tenantId <= 0) throw new RuntimeException('Invalid tenant');

  $id        = (int)($_POST['id'] ?? 0);
  $direction = (string)($_POST['direction'] ?? 'add'); // add|deduct
  $amount    = (int)($_POST['amount'] ?? 0);
  $reason    = trim((string)($_POST['reason'] ?? ''));

  if ($id <= 0) throw new RuntimeException('Missing customer id');
  if (!in_array($direction, ['add','deduct'], true)) throw new RuntimeException('Invalid direction');
  if ($amount <= 0) throw new RuntimeException('Amount must be a positive integer');
  if ($reason === '') throw new RuntimeException('Reason is required');

  $pdo = db();
  $pdo->beginTransaction();

  // Ensure customer belongs to tenant
  $st = $pdo->prepare("SELECT id FROM customers WHERE id = :id AND tenant_id = :t FOR UPDATE");
  $st->execute([':id'=>$id, ':t'=>$tenantId]);
  if (!$st->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('Customer not found or access denied');

  // Write loyalty_ledger (optional)
  $now = date('Y-m-d H:i:s');
  $delta = ($direction === 'add') ? $amount : -$amount;

  try {
    $ins = $pdo->prepare("INSERT INTO loyalty_ledger (tenant_id, customer_id, program, direction, amount, order_id, user_id, note, created_at)
                          VALUES (:t,:cid,'points',:dir,:amt,NULL,:uid,:note,:ca)");
    $ins->execute([
      ':t'=>$tenantId, ':cid'=>$id, ':dir'=>$direction, ':amt'=>$amount,
      ':uid'=>$userId ?: null, ':note'=>$reason, ':ca'=>$now
    ]);
  } catch (Throwable $e) {
    // If ledger table missing, still try to update accounts; but inform user.
    $_SESSION['flash'] = 'Ledger not available: '.$e->getMessage();
  }

  // Upsert loyalty_accounts (optional)
  try {
    // Lock row if exists
    $stAcc = $pdo->prepare("SELECT current_points, lifetime_points FROM loyalty_accounts WHERE tenant_id = :t AND customer_id = :cid FOR UPDATE");
    $stAcc->execute([':t'=>$tenantId, ':cid'=>$id]);
    $acc = $stAcc->fetch(PDO::FETCH_ASSOC);

    if ($acc) {
      $newCurrent = max(0, (int)$acc['current_points'] + $delta);
      $newLifetime = (int)$acc['lifetime_points'] + ($direction === 'add' ? $amount : 0);
      $up = $pdo->prepare("UPDATE loyalty_accounts SET current_points = :cp, lifetime_points = :lp, updated_at = :ua WHERE tenant_id = :t AND customer_id = :cid");
      $up->execute([':cp'=>$newCurrent, ':lp'=>$newLifetime, ':ua'=>$now, ':t'=>$tenantId, ':cid'=>$id]);
    } else {
      $cur = max(0, $delta);
      $life = ($direction === 'add') ? $amount : 0;
      $insAcc = $pdo->prepare("INSERT INTO loyalty_accounts (tenant_id, customer_id, current_points, lifetime_points, tier, created_at, updated_at)
                               VALUES (:t,:cid,:cp,:lp,NULL,:ca,:ua)");
      $insAcc->execute([':t'=>$tenantId, ':cid'=>$id, ':cp'=>$cur, ':lp'=>$life, ':ca'=>$now, ':ua'=>$now]);
    }
  } catch (Throwable $e) {
    // optional table
    $_SESSION['flash'] = (isset($_SESSION['flash']) ? $_SESSION['flash'].' | ' : '') . 'Accounts not available: '.$e->getMessage();
  }

  $pdo->commit();

  $_SESSION['flash'] = (isset($_SESSION['flash']) ? $_SESSION['flash'].' ' : '') . 'Adjustment applied.';
  header('Location: /views/admin/customers/profile.php?id='.$id.'&tab=rewards'); exit;

} catch (Throwable $e) {
  try { if (isset($pdo) && $pdo instanceof PDO) $pdo->rollBack(); } catch(Throwable $e2){}
  if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
  $_SESSION['flash'] = 'Failed to adjust rewards: '.$e->getMessage();
  $id = (int)($_POST['id'] ?? 0);
  header('Location: /views/admin/customers/profile.php?id='.$id.'&tab=rewards'); exit;
}