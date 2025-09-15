<?php
// /public_html/controllers/admin/customers/customer_update.php
declare(strict_types=1);
@ini_set('display_errors','0');

try {
  $root = dirname(__DIR__, 4);
  $configPath = $root . '/config/db.php';
  if (!is_file($configPath)) throw new RuntimeException('Configuration not found');
  require_once $configPath;

  if (function_exists('use_backend_session')) { use_backend_session(); }
  else { if (session_status() !== PHP_SESSION_ACTIVE) session_start(); }

  $authPath = $root . '/middleware/auth_login.php';
  if (!is_file($authPath)) throw new RuntimeException('Auth middleware not found');
  require_once $authPath;
  auth_require_login();

  if (!function_exists('db')) throw new RuntimeException('db() not available');

  $tenantId = (int)($_SESSION['user']['tenant_id'] ?? 0);
  if ($tenantId <= 0) throw new RuntimeException('Invalid tenant');

  // Inputs
  $id    = (int)($_POST['id'] ?? 0);
  $name  = trim((string)($_POST['name'] ?? ''));
  $phone = trim((string)($_POST['phone'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $classification = (string)($_POST['classification'] ?? 'regular');
  $rewards_enrolled = isset($_POST['rewards_enrolled']) ? 1 : 0;
  $rewards_member_no = trim((string)($_POST['rewards_member_no'] ?? ''));
  $discount_scheme_id = trim((string)($_POST['discount_scheme_id'] ?? ''));

  if ($id <= 0) throw new RuntimeException('Missing customer id');

  // Normalize
  if ($email !== '') $email = strtolower($email);
  if ($phone !== '') $phone = preg_replace('/\s+/', '', $phone);

  $errors = [];
  if ($name === '') $errors[] = 'Name is required.';
  $validClasses = ['regular','vip','corporate','blocked'];
  if (!in_array($classification, $validClasses, true)) $classification = 'regular';

  $pdo = db();

  // Ensure record belongs to tenant
  $stChk = $pdo->prepare("SELECT id FROM customers WHERE id = :id AND tenant_id = :t");
  $stChk->execute([':id'=>$id, ':t'=>$tenantId]);
  if (!$stChk->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('Customer not found or access denied');

  // Duplicate check (exclude self)
  if ($phone !== '' || $email !== '') {
    $sqlDup = "SELECT id FROM customers WHERE tenant_id = :t AND id <> :id AND ( (phone <> '' AND phone = :p) OR (email <> '' AND email = :e) ) LIMIT 1";
    $stDup = $pdo->prepare($sqlDup);
    $stDup->execute([':t'=>$tenantId, ':id'=>$id, ':p'=>$phone, ':e'=>$email]);
    if ($stDup->fetch(PDO::FETCH_ASSOC)) $errors[] = 'Another customer with the same phone or email already exists.';
  }

  if ($errors) {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_old'] = $_POST;
    header('Location: /views/admin/customers/edit.php?id='.$id); exit;
  }

  // Update
  $now = date('Y-m-d H:i:s');
  $sql = "
    UPDATE customers
       SET name = :name,
           phone = :phone,
           email = :email,
           classification = :class,
           rewards_enrolled = :enrolled,
           rewards_member_no = :member,
           discount_scheme_id = :scheme,
           updated_at = :ua
     WHERE id = :id AND tenant_id = :t
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':name'=>$name,
    ':phone'=>$phone,
    ':email'=>$email,
    ':class'=>$classification,
    ':enrolled'=>$rewards_enrolled,
    ':member'=>$rewards_member_no,
    ':scheme'=>($discount_scheme_id !== '' ? (int)$discount_scheme_id : null),
    ':ua'=>$now,
    ':id'=>$id,
    ':t'=>$tenantId,
  ]);

  $_SESSION['flash'] = 'Customer updated successfully.';
  header('Location: /views/admin/customers/index.php'); exit;

} catch (Throwable $e) {
  if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
  $id = (int)($_POST['id'] ?? 0);
  $_SESSION['form_errors'] = ['Failed to update customer: '.$e->getMessage()];
  $_SESSION['form_old'] = $_POST ?? [];
  header('Location: /views/admin/customers/edit.php?id='.$id); exit;
}