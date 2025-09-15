<?php
// /public_html/controllers/admin/customers/customer_create.php
declare(strict_types=1);

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL);
} else {
  @ini_set('display_errors','0');
}

try {
  $root = dirname(__DIR__, 4); // from controllers/admin/customers/* to project root
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

  // Only POST
  if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Location: /views/admin/customers/create.php'); exit;
  }

  // Basic CSRF (if your app sets $_SESSION['csrf'])
  $csrfOk = true;
  if (!empty($_SESSION['csrf'])) {
    $csrfOk = hash_equals((string)$_SESSION['csrf'], (string)($_POST['csrf'] ?? ''));
  }
  if (!$csrfOk) {
    $_SESSION['form_old'] = $_POST;
    $_SESSION['form_errors'] = ['Security check failed. Please try again.'];
    header('Location: /views/admin/customers/create.php'); exit;
  }

  $tenantId = (int)($_SESSION['user']['tenant_id'] ?? 0);
  if ($tenantId <= 0) throw new RuntimeException('Invalid tenant');

  // Collect and validate
  $name  = trim((string)($_POST['name'] ?? ''));
  $phone = trim((string)($_POST['phone'] ?? ''));
  $email = trim((string)($_POST['email'] ?? ''));
  $classification = (string)($_POST['classification'] ?? 'regular');
  $rewards_member_no = trim((string)($_POST['rewards_member_no'] ?? ''));
  $rewards_enrolled = !empty($_POST['rewards_enrolled']) ? 1 : 0;
  $discount_scheme_id = trim((string)($_POST['discount_scheme_id'] ?? ''));

  $errors = [];
  if (mb_strlen($name) < 2 || mb_strlen($name) > 150) { $errors[] = 'Name must be between 2 and 150 characters.'; }
  if ($phone !== '' && mb_strlen($phone) > 30) { $errors[] = 'Phone must be 30 characters or fewer.'; }
  if ($email !== '' && mb_strlen($email) > 255) { $errors[] = 'Email must be 255 characters or fewer.'; }
  $allowed = ['regular','vip','corporate','blocked'];
  if (!in_array($classification, $allowed, true)) { $classification = 'regular'; }
  if ($rewards_member_no !== '' && mb_strlen($rewards_member_no) > 30) { $errors[] = 'Rewards Member No. must be 30 characters or fewer.'; }

  // Validate discount scheme (if provided)
  $schemeId = null;
  if ($discount_scheme_id !== '') {
    if (!ctype_digit($discount_scheme_id)) {
      $errors[] = 'Invalid discount scheme.'; 
    } else {
      $schemeId = (int)$discount_scheme_id;
      try {
        $pdo = db();
        $st = $pdo->prepare("SELECT id FROM discount_schemes WHERE id = :id AND tenant_id = :t AND is_active = 1");
        $st->execute([':id'=>$schemeId, ':t'=>$tenantId]);
        if (!$st->fetchColumn()) {
          $errors[] = 'Selected discount scheme is not available.';
        }
      } catch (Throwable $e) {
        $errors[] = 'Unable to validate discount scheme.';
      }
    }
  }

  if ($errors) {
    $_SESSION['form_old'] = $_POST;
    $_SESSION['form_errors'] = $errors;
    header('Location: /views/admin/customers/create.php'); exit;
  }

  // Insert customer
  $pdo = db();
  $pdo->beginTransaction();

  $sql = "
    INSERT INTO customers
      (tenant_id, name, phone, email, classification, rewards_enrolled, rewards_member_no, discount_scheme_id, created_at, updated_at)
    VALUES
      (:t, :name, :phone, :email, :cls, :renr, :rmn, :dsid, NOW(), NOW())
  ";
  $stmt = $pdo->prepare($sql);
  $stmt->execute([
    ':t'    => $tenantId,
    ':name' => $name,
    ':phone'=> ($phone !== '' ? $phone : null),
    ':email'=> ($email !== '' ? $email : null),
    ':cls'  => $classification,
    ':renr' => $rewards_enrolled,
    ':rmn'  => ($rewards_member_no !== '' ? $rewards_member_no : null),
    ':dsid' => $schemeId,
  ]);

  $newId = (int)$pdo->lastInsertId();

  // Optionally: if you maintain a loyalty_accounts table, you could create a row here when enrolled.
  // Kept minimal as requested — no extra table writes.

  $pdo->commit();

  $_SESSION['flash'] = 'Customer created successfully.';
  header('Location: /views/admin/customers/index.php'); exit;

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
    $pdo->rollBack();
  }
  if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
  $_SESSION['form_old'] = $_POST ?? [];
  $_SESSION['form_errors'] = ['Unexpected error: '.$e->getMessage()];
  header('Location: /views/admin/customers/create.php'); exit;
}