<?php
declare(strict_types=1);

// Current page key for header highlighting
$current = 'categories';

require_once __DIR__ . '/../../../config/db.php';
$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf_input(){ echo '<input type="hidden" name="csrf" value="'.e($_SESSION['csrf']).'">'; }
function csrf_check($t){ return is_string($t ?? null) && hash_equals($_SESSION['csrf'], (string)$t); }

$err = null;

// --- Handle POST before any output so redirects work ---
try {
  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!csrf_check($_POST['csrf'] ?? '')) throw new Exception('Invalid CSRF token');

    $action = $_POST['action'] ?? '';
    if ($action === 'create') {
      $name_en   = trim((string)($_POST['name_en'] ?? ''));
      $name_ar   = trim((string)($_POST['name_ar'] ?? ''));
      if ($name_en === '') throw new Exception('English name is required.');

      $parent_id  = ($_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null);
      $sort_order = (int)($_POST['sort_order'] ?? 0);
      $is_active  = isset($_POST['is_active']) ? 1 : 0;

      // Insert using JSON name_i18n
      $stmt = $pdo->prepare("
        INSERT INTO categories (name_i18n, parent_id, sort_order, is_active)
        VALUES (JSON_OBJECT('en', ?, 'ar', NULLIF(?, '')), ?, ?, ?)
      ");
      $stmt->execute([$name_en, $name_ar, $parent_id, $sort_order, $is_active]);

      header("Location: /views/admin/menu/categories.php?ok=1");
      exit;
    } elseif ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new Exception('Invalid category id.');
      $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
      header("Location: /views/admin/menu/categories.php?ok=1");
      exit;
    }
  }
} catch (Throwable $ex) {
  $err = $ex->getMessage();
}

// --- Fetch categories (use generated columns) ---
$cats = $pdo->query("
  SELECT
    id,
    parent_id,
    is_active,
    sort_order,
    cat_name_en,
    cat_name_ar
  FROM categories
  ORDER BY sort_order ASC, cat_name_en ASC
")->fetchAll(PDO::FETCH_ASSOC);

// After logic and DB work, include header (starts HTML output)
require_once __DIR__ . '/../_header.php';
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Categories</title>

<main style="padding:20px;max-width:1000px;margin:0 auto;font-family:system-ui">
  <h2 style="margin:0 0 12px">Categories</h2>

  <?php if ($err): ?>
    <div style="background:#fee;border:1px solid #fbb;padding:10px;border-radius:10px;color:#900;margin:0 0 12px">
      <?= e($err) ?>
    </div>
  <?php endif; ?>

  <?php if (isset($_GET['ok'])): ?>
    <div style="background:#eefbea;border:1px solid #b2e3b2;padding:10px;border-radius:10px;color:#08660b;margin:0 0 12px">
      Saved.
    </div>
  <?php endif; ?>

  <details style="margin:0 0 16px">
    <summary style="cursor:pointer;font-weight:600">Add new category</summary>
    <form method="post" style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px;background:#fff;border:1px solid #eee;border-radius:12px;padding:12px">
      <?php csrf_input(); ?>
      <input type="hidden" name="action" value="create">

      <label> Name (English)
        <input name="name_en" required>
      </label>

      <label> Name (Arabic)
        <input name="name_ar">
      </label>

      <label> Parent
        <select name="parent_id">
          <option value="">— none —</option>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['cat_name_en'] ?? '') ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label> Sort order
        <input type="number" name="sort_order" value="0">
      </label>

      <label style="display:flex;align-items:center;gap:6px">
        <input type="checkbox" name="is_active" value="1" checked> Active
      </label>

      <div style="grid-column:1/-1">
        <button style="padding:10px 14px;border:0;border-radius:10px;background:#0d6efd;color:#fff;font-weight:700">
          Create
        </button>
      </div>
    </form>
  </details>

  <div style="overflow:auto;background:#fff;border:1px solid #eee;border-radius:12px">
    <table style="width:100%;border-collapse:collapse;min-width:760px">
      <thead>
        <tr style="background:#f7f7fb">
          <th style="text-align:left;padding:10px;border-bottom:1px solid #eee">ID</th>
          <th style="text-align:left;padding:10px;border-bottom:1px solid #eee">Name (EN)</th>
          <th style="text-align:left;padding:10px;border-bottom:1px solid #eee">Name (AR)</th>
          <th style="text-align:left;padding:10px;border-bottom:1px solid #eee">Parent</th>
          <th style="text-align:left;padding:10px;border-bottom:1px solid #eee">Active</th>
          <th style="text-align:left;padding:10px;border-bottom:1px solid #eee">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cats as $c): ?>
          <tr>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= (int)$c['id'] ?></td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= e($c['cat_name_en'] ?? '') ?></td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= e($c['cat_name_ar'] ?? '') ?></td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0">
              <?php
                $parentName = '';
                if (!empty($c['parent_id'])) {
                  foreach ($cats as $cc) {
                    if ((int)$cc['id'] === (int)$c['parent_id']) { $parentName = (string)($cc['cat_name_en'] ?? ''); break; }
                  }
                }
                echo e($parentName);
              ?>
            </td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0"><?= ((int)$c['is_active'] ? 'Yes' : 'No') ?></td>
            <td style="padding:8px;border-bottom:1px solid #f0f0f0">
              <form method="post" style="display:inline" onsubmit="return confirm('Delete category #<?= (int)$c['id'] ?>?')">
                <?php csrf_input(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button style="padding:6px 10px;border:1px solid #eee;border-radius:8px;background:#fff">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</main>