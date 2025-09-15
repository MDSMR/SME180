<?php
// /public_html/controllers/admin/customers/customer_store.php
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

  // Gather inputs
  $name  = trim((string)($_POST['name'] ?? ''));
  $phone = trim((string)($_POST['phone'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $classification = (string)($_POST['classification'] ?? 'regular');
  $rewards_enrolled = isset($_POST['rewards_enrolled']) ? 1 : 0;
  $rewards_member_no = trim((string)($_POST['rewards_member_no'] ?? ''));
  $discount_scheme_id = trim((string)($_POST['discount_scheme_id'] ?? ''));

  // Normalize
  if ($email !== '') $email = strtolower($email);
  if ($phone !== '') $phone = preg_replace('/\s+/', '', $phone);

  $errors = [];

  if ($name === '') $errors[] = 'Name is required.';
  $validClasses = ['regular','vip','corporate','blocked'];
  if (!in_array($classification, $validClasses, true)) $classification = 'regular';

  if ($phone === '' && $email === '' && $rewards_member_no === '') {
    $errors[] = 'Please provide at least one contact or member number (phone, email, or member no.).';
  }

  $pdo = db();

  // Duplicate check (phone or email) per tenant
  if ($phone !== '' || $email !== '') {
    $sqlDup = "SELECT id FROM customers WHERE tenant_id = :t AND ( (phone <> '' AND phone = :p) OR (email <> '' AND email = :e) ) LIMIT 1";
    $stDup = $pdo->prepare($sqlDup);
    $stDup->execute([':t'=>$tenantId, ':p'=>$phone, ':e'=>$email]);
    if ($stDup->fetch(PDO::FETCH_ASSOC)) $errors[] = 'A customer with the same phone or email already exists.';
  }

  if ($errors) {
    $_SESSION['form_errors'] = $errors;
    $_SESSION['form_old'] = $_POST;
    header('Location: /views/admin/customers/create.php'); exit;
  }

  // Insert
  $now = date('Y-m-d H:i:s');
  $sql = "
    INSERT INTO customers
      (tenant_id, name, phone, email, classification, rewards_enrolled, rewards_member_no, discount_scheme_id, created_at, updated_at)
    VALUES
      (:t, :name, :phone, :email, :class, :enrolled, :member, :scheme, :ca, :ua)
  ";
  $st = $pdo->prepare($sql);
  $st->execute([
    ':t' => $tenantId,
    ':name' => $name,
    ':phone' => $phone,
    ':email' => $email,
    ':class' => $classification,
    ':enrolled' => $rewards_enrolled,
    ':member' => $rewards_member_no,
    ':scheme' => ($discount_scheme_id !== '' ? (int)$discount_scheme_id : null),
    ':ca' => $now,
    ':ua' => $now,
  ]);

  // NEW: redirect to profile of the created customer
  $newId = (int)$pdo->lastInsertId();
  $_SESSION['flash'] = 'Customer created successfully.';
  header('Location: /views/admin/customers/profile.php?id='.$newId); exit;

} catch (Throwable $e) {
  if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
  $_SESSION['form_errors'] = ['Failed to create customer: '.$e->getMessage()];
  $_SESSION['form_old'] = $_POST ?? [];
  header('Location: /views/admin/customers/create.php'); exit;
}