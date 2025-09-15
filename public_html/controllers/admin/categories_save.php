<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// controllers/admin/categories_save.php — Save (create/update) Category, flat structure
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

/* CSRF */
if (empty($_SESSION['csrf_categories']) || ($_POST['csrf'] ?? '') !== $_SESSION['csrf_categories']) {
  $_SESSION['flash'] = 'Invalid request. Please try again.';
  header('Location: /views/admin/categories.php'); exit;
}

/* Helpers */
function hstr($s){ return trim((string)$s); }
function column_exists(PDO $pdo, string $table, string $col): bool {
  try { $q=$pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c");
        $q->execute([':t'=>$table, ':c'=>$col]); return (bool)$q->fetchColumn(); } catch(Throwable $e){ return false; }
}

/* Input */
$id         = (int)($_POST['id'] ?? 0);
$name_en    = hstr($_POST['name_en'] ?? '');
$name_ar    = hstr($_POST['name_ar'] ?? '');
$sort_order = ($_POST['sort_order'] ?? '') !== '' ? (int)$_POST['sort_order'] : 1;
$is_active  = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
$pos_vis    = isset($_POST['pos_visible']) ? (int)$_POST['pos_visible'] : 1;

/* Validation */
if ($name_en === '') {
  $_SESSION['flash'] = 'Name (English) is required.';
  header('Location: ' . ($id>0 ? '/views/admin/category_edit.php?id='.$id : '/views/admin/categories_new.php'));
  exit;
}

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Ensure pos_visible column exists
  if (!column_exists($pdo, 'categories', 'pos_visible')) {
    try { $pdo->exec("ALTER TABLE categories ADD COLUMN pos_visible TINYINT(1) NOT NULL DEFAULT 1 AFTER is_active"); } catch(Throwable $e){}
  }

  if ($id > 0) {
    $stmt = $pdo->prepare("
      UPDATE categories SET
        name_en = :name_en,
        name_ar = :name_ar,
        sort_order = :sort_order,
        is_active = :is_active,
        pos_visible = :pos_visible,
        updated_at = NOW()
      WHERE id = :id
      LIMIT 1
    ");
    $stmt->bindValue(':id', $id, PDO::PARAM_INT);
  } else {
    $stmt = $pdo->prepare("
      INSERT INTO categories
        (name_en, name_ar, sort_order, is_active, pos_visible, created_at, updated_at)
      VALUES
        (:name_en, :name_ar, :sort_order, :is_active, :pos_visible, NOW(), NOW())
    ");
  }

  $stmt->bindValue(':name_en', $name_en);
  $stmt->bindValue(':name_ar', $name_ar);
  $stmt->bindValue(':sort_order', $sort_order, PDO::PARAM_INT);
  $stmt->bindValue(':is_active', $is_active, PDO::PARAM_INT);
  $stmt->bindValue(':pos_visible', $pos_vis, PDO::PARAM_INT);
  $stmt->execute();

  $_SESSION['flash'] = 'Category saved successfully.';
  header('Location: /views/admin/categories.php');
  exit;

} catch (Throwable $e) {
  $_SESSION['flash'] = 'Save error. ' . $e->getMessage();
  header('Location: ' . ($id>0 ? '/views/admin/category_edit.php?id='.$id : '/views/admin/categories_new.php'));
  exit;
}