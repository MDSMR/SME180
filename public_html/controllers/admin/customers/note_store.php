<?php
// /public_html/controllers/admin/customers/note_store.php
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

  $id   = (int)($_POST['id'] ?? 0);
  $note = trim((string)($_POST['note'] ?? ''));

  if ($id <= 0) throw new RuntimeException('Missing customer id');
  if ($note === '') throw new RuntimeException('Note text is required');

  $pdo = db();

  // Ensure customer belongs to tenant
  $st = $pdo->prepare("SELECT id FROM customers WHERE id = :id AND tenant_id = :t");
  $st->execute([':id'=>$id, ':t'=>$tenantId]);
  if (!$st->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('Customer not found or access denied');

  // Write note (table optional)
  $now = date('Y-m-d H:i:s');
  try {
    $ins = $pdo->prepare("INSERT INTO customer_notes (tenant_id, customer_id, note, created_at, created_by) VALUES (:t,:cid,:note,:ca,:uid)");
    $ins->execute([':t'=>$tenantId, ':cid'=>$id, ':note'=>$note, ':ca'=>$now, ':uid'=>$userId ?: null]);
    $_SESSION['flash'] = 'Note added.';
  } catch (Throwable $e) {
    $_SESSION['flash'] = 'Notes table not available: '.$e->getMessage();
  }

  header('Location: /views/admin/customers/profile.php?id='.$id.'&tab=notes'); exit;

} catch (Throwable $e) {
  if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
  $_SESSION['flash'] = 'Failed to add note: '.$e->getMessage();
  $id = (int)($_POST['id'] ?? 0);
  header('Location: /views/admin/customers/profile.php?id='.$id.'&tab=notes'); exit;
}