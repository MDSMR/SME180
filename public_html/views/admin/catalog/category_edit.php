<?php
declare(strict_types=1);

// Bootstrap
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';
auth_require_login();

$bootstrap_warning = '';
$bootstrap_ok = false;
$bootstrap_path = __DIR__ . '/../../../config/db.php';

if (!is_file($bootstrap_path)) {
    $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
    try {
        require_once $bootstrap_path;
        if (!function_exists('db') || !function_exists('use_backend_session')) {
            $bootstrap_warning = 'Required functions missing in config/db.php';
        } else {
            $bootstrap_ok = true;
            use_backend_session();
        }
    } catch (Throwable $e) {
        $bootstrap_warning = 'Bootstrap error: ' . $e->getMessage();
    }
}

// Auth
$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /views/auth/login.php');
    exit;
}
$tenantId = (int)($user['tenant_id'] ?? 0);

// CSRF token
if (empty($_SESSION['csrf_categories'])) {
    $_SESSION['csrf_categories'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['csrf_categories'];

// Helper function
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Get category ID
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    $_SESSION['flash'] = 'Category not specified.';
    header('Location: /views/admin/catalog/categories.php');
    exit;
}

// Load category
$cat = null;
$db_msg = '';
if ($bootstrap_ok) {
    try {
        $pdo = db();
        
        $stmt = $pdo->prepare("
            SELECT id, tenant_id, name_en, name_ar, sort_order, is_active, 
                   COALESCE(pos_visible, 1) AS pos_visible
            FROM categories
            WHERE id = :id AND tenant_id = :t
            LIMIT 1
        ");
        $stmt->execute([':id' => $id, ':t' => $tenantId]);
        $cat = $stmt->fetch();
        
        if (!$cat) {
            $_SESSION['flash'] = 'Category not found.';
            header('Location: /views/admin/catalog/categories.php');
            exit;
        }
    } catch (Throwable $e) {
        $db_msg = $e->getMessage();
    }
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Edit Category · Smorll POS</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb}
        *{box-sizing:border-box}
        body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto}
        .container{max-width:800px;margin:20px auto;padding:0 16px}
        .section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
        .h1{font-size:18px;font-weight:800;margin:0 0 12px}
        .grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        @media (max-width:900px){.grid{grid-template-columns:1fr}}
        label{font-size:12px;color:#6b7280;display:block;margin-bottom:6px}
        .input, select{width:100%;border:1px solid var(--border);border-radius:10px;padding:10px 12px}
        .btn{border:1px solid var(--border);border-radius:10px;background:#fff;padding:6px 12px;cursor:pointer;text-decoration:none;display:inline-block;color:#111827;line-height:1.1}
        .btn:hover{filter:brightness(.98)}
        .btn-primary{background:#2563eb;color:#fff;border-color:#2563eb}
        .actions{display:flex;gap:10px}
        .small{color:#6b7280;font-size:12px}
        .notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:10px;margin:10px 0}
        .success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:10px;border-radius:10px;margin:10px 0}
    </style>
</head>
<body>

<?php 
$active = 'catalog';
require __DIR__ . '/../../partials/admin_nav.php';
?>

<div class="container">
    <?php if ($bootstrap_warning): ?>
        <div class="notice"><?= h($bootstrap_warning) ?></div>
    <?php endif; ?>
    
    <?php if ($flash): ?>
        <div class="success"><?= h($flash) ?></div>
    <?php endif; ?>
    
    <?php if ($db_msg): ?>
        <div class="small">DEBUG: <?= h($db_msg) ?></div>
    <?php endif; ?>

    <form method="post" action="/controllers/admin/categories_save.php">
        <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
        <input type="hidden" name="id" value="<?= h((string)$cat['id']) ?>">
        
        <div class="section">
            <div class="h1">Edit Category</div>
            
            <div class="grid">
                <div>
                    <label>Name (English)</label>
                    <input class="input" name="name_en" maxlength="200" required value="<?= h($cat['name_en']) ?>">
                </div>
                <div>
                    <label>Arabic Name</label>
                    <input class="input" name="name_ar" maxlength="200" dir="rtl" value="<?= h($cat['name_ar']) ?>">
                </div>
            </div>
            
            <div class="grid">
                <div>
                    <label>POS Visibility</label>
                    <select class="input" name="pos_visible">
                        <option value="1" <?= ((int)$cat['pos_visible'] === 1 ? 'selected' : '') ?>>Visible</option>
                        <option value="0" <?= ((int)$cat['pos_visible'] === 0 ? 'selected' : '') ?>>Hidden</option>
                    </select>
                    <div class="small">Hidden categories won't appear in the POS.</div>
                </div>
                <div>
                    <label>Status</label>
                    <select class="input" name="is_active">
                        <option value="1" <?= ((int)$cat['is_active'] === 1 ? 'selected' : '') ?>>Active</option>
                        <option value="0" <?= ((int)$cat['is_active'] === 0 ? 'selected' : '') ?>>Inactive</option>
                    </select>
                </div>
            </div>
            
            <div class="actions" style="margin-top:12px">
                <a class="btn" href="/views/admin/catalog/categories.php">Back</a>
                <button class="btn btn-primary" type="submit">Update</button>
            </div>
        </div>
    </form>
</div>
</body>
</html>