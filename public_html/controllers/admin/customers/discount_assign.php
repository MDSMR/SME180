<?php
// /public_html/controllers/admin/customers/discount_assign.php
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
  if ($tenantId <= 0) throw new RuntimeException('Invalid tenant');

  $id       = (int)($_POST['id'] ?? 0);
  $schemeId = (int)($_POST['scheme_id'] ?? 0);
  if ($id <= 0 || $schemeId <= 0) throw new RuntimeException('Missing parameters');

  $pdo = db();
  $pdo->beginTransaction();

  // Ensure customer belongs to tenant
  $st = $pdo->prepare("SELECT id FROM customers WHERE id = :id AND tenant_id = :t FOR UPDATE");
  $st->execute([':id'=>$id, ':t'=>$tenantId]);
  if (!$st->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('Customer not found or access denied');

  // Ensure scheme belongs to tenant
  try {
    $st2 = $pdo->prepare("SELECT id, active FROM discount_schemes WHERE id = :sid AND tenant_id = :t");
    $st2->execute([':sid'=>$schemeId, ':t'=>$tenantId]);
    if (!$st2->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('Scheme not found or access denied');
  } catch(Throwable $e) {
    throw new RuntimeException('Discount schemes table not available: '.$e->getMessage());
  }

  $now = date('Y-m-d H:i:s');

  // End previous assignment (if history table exists)
  try {
    $end = $pdo->prepare("UPDATE customer_scheme_assignments SET unassigned_at = :ua 
                          WHERE tenant_id = :t AND customer_id = :cid AND unassigned_at IS NULL");
    $end->execute([':ua'=>$now, ':t'=>$tenantId, ':cid'=>$id]);
  } catch(Throwable $e) { /* optional */ }

  // Create new assignment (if history table exists)
  try {
    $ins = $pdo->prepare("INSERT INTO customer_scheme_assignments (tenant_id, customer_id, scheme_id, assigned_at, unassigned_at)
                          VALUES (:t,:cid,:sid,:aa,NULL)");
    $ins->execute([':t'=>$tenantId, ':cid'=>$id, ':sid'=>$schemeId, ':aa'=>$now]);
  } catch(Throwable $e) { /* optional */ }

  // Update current on customer
  $up = $pdo->prepare("UPDATE customers SET discount_scheme_id = :sid, updated_at = :ua WHERE id = :id AND tenant_id = :t");
  $up->execute([':sid'=>$schemeId, ':ua'=>$now, ':id'=>$id, ':t'=>$tenantId]);

  $pdo->commit();

  $_SESSION['flash'] = 'Discount scheme assigned.';
  header('Location: /views/admin/customers/profile.php?id='.$id.'&tab=discounts'); exit;

} catch (Throwable $e) {
  try { if (isset($pdo) && $pdo instanceof PDO) $pdo->rollBack(); } catch(Throwable $e2){}
  if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
  $_SESSION['flash'] = 'Failed to assign scheme: '.$e->getMessage();
  $id = (int)($_POST['id'] ?? 0);
  header('Location: /views/admin/customers/profile.php?id='.$id.'&tab=discounts'); exit;
}