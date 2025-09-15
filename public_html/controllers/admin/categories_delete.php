<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/categories_delete.php — Delete a category (and its product links)
declare(strict_types=1);

/* Bootstrap + session */
$bootstrap_path = __DIR__ . '/../../config/db.php';
if (!is_file($bootstrap_path)) { http_response_code(500); exit('Configuration file not found: /config/db.php'); }
require_once $bootstrap_path;
if (!function_exists('db') || !function_exists('use_backend_session')) { http_response_code(500); exit('Required functions missing in config/db.php (db(), use_backend_session()).'); }
use_backend_session();

/* Auth */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

/* Helpers */
function table_exists(PDO $pdo, string $table): bool {
  try {
    $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t");
    $stmt->execute([':t'=>$table]); return (bool)$stmt->fetchColumn();
  } catch (Throwable $e) { return false; }
}
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Input */
$id   = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$csrf = (string)($_POST['csrf'] ?? $_GET['csrf'] ?? '');

/* Optional CSRF check (passes if token present and matches; if omitted, we proceed to keep compatibility with existing links) */
if ($csrf !== '') {
  if (empty($_SESSION['csrf_categories']) || !hash_equals($_SESSION['csrf_categories'], $csrf)) {
    $_SESSION['flash'] = 'Invalid request (CSRF).';
    header('Location: /views/admin/categories.php'); exit;
  }
}

if ($id <= 0) {
  $_SESSION['flash'] = 'Category not specified.';
  header('Location: /views/admin/categories.php'); exit;
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->beginTransaction();

  // Remove product linkages first (if that table exists) to avoid FK errors
  if (table_exists($pdo, 'product_categories')) {
    $stmt = $pdo->prepare("DELETE FROM product_categories WHERE category_id = :id");
    $stmt->execute([':id' => $id]);
  }

  // Delete the category
  $stmt = $pdo->prepare("DELETE FROM categories WHERE id = :id LIMIT 1");
  $stmt->execute([':id' => $id]);

  $pdo->commit();
  $_SESSION['flash'] = 'Category deleted.';
  header('Location: /views/admin/categories.php');
  exit;

} catch (Throwable $e) {
  if (!empty($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
  $_SESSION['flash'] = 'Delete error. ' . $e->getMessage();
  header('Location: /views/admin/categories.php');
  exit;
}