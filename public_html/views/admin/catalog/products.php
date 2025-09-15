<?php
declare(strict_types=1);
/**
 * /public_html/views/admin/catalog/products.php
 * Admin → Catalog → Products (Updated Modern Design)
 */

// Debug mode via ?debug=1 (hidden feature)
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  @ini_set('display_errors','1');
  @ini_set('display_startup_errors','1');
  error_reporting(E_ALL);
} else {
  @ini_set('display_errors','0');
}

/* ---------- Bootstrap config + auth ---------- */
$bootstrap_ok = false;
$bootstrap_msg = '';

try {
  $configPath = __DIR__ . '/../../../config/db.php';
  if (!is_file($configPath)) {
    throw new RuntimeException('Configuration file not found at /config/db.php');
  }
  require_once $configPath;

  if (function_exists('use_backend_session')) {
    use_backend_session();
  } else {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  }

  $authPath = __DIR__ . '/../../../middleware/auth_login.php';
  if (!is_file($authPath)) {
    throw new RuntimeException('Auth middleware not found at /middleware/auth_login.php');
  }
  require_once $authPath;
  if (!function_exists('auth_require_login')) {
    throw new RuntimeException('auth_require_login() not found in auth middleware.');
  }
  auth_require_login();

  if (!function_exists('db')) {
    throw new RuntimeException('db() not available from config.');
  }

  $bootstrap_ok = true;
} catch (Throwable $e) {
  $bootstrap_msg = $e->getMessage();
}

/* ---------- Helpers ---------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* ---------- Current user / tenant ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user && $bootstrap_ok) { 
  header('Location: /views/auth/login.php'); 
  exit; 
}
$tenantId = (int)($user['tenant_id'] ?? 0);

/* ---------- Flash message handling ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- Filters ---------- */
$q        = trim((string)($_GET['q']      ?? ''));
$status   = trim((string)($_GET['status'] ?? 'all'));
$vis      = trim((string)($_GET['vis']    ?? 'all'));
$catId    = (int)($_GET['cat'] ?? 0);
$branchId = (int)($_GET['br']  ?? 0);
$dateFrom = $_GET['date_from'] ?? '';
$dateTo   = $_GET['date_to'] ?? '';

$allowedStatus = ['all','active','inactive'];
if (!in_array($status, $allowedStatus, true)) { $status = 'all'; }

$allowedVis = ['all','visible','hidden'];
if (!in_array($vis, $allowedVis, true)) { $vis = 'all'; }

/* ---------- Data ---------- */
$products   = [];
$categories = [];
$branches   = [];
$error_msg  = '';

if ($bootstrap_ok) {
  try {
    $pdo = db();

    /* Filters: dropdown data */
    $stmt = $pdo->prepare("
      SELECT c.id, COALESCE(NULLIF(c.name_en,''), c.name_ar, CONCAT('Category #', c.id)) AS name
      FROM categories c
      WHERE c.tenant_id = :t
      ORDER BY name
    ");
    $stmt->execute([':t' => $tenantId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stmt = $pdo->prepare("
      SELECT b.id, COALESCE(NULLIF(b.name,''), CONCAT('Branch #', b.id)) AS name
      FROM branches b
      WHERE b.tenant_id = :t
      ORDER BY name
    ");
    $stmt->execute([':t' => $tenantId]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* WHERE builder */
    $where  = ["p.tenant_id = :t"];
    $params = [':t' => $tenantId];

    if ($q !== '') {
      $where[] = "(p.name_en LIKE :q OR p.name_ar LIKE :q)";
      $params[':q'] = "%{$q}%";
    }

    if ($status === 'active')   { $where[] = "p.is_active = 1"; }
    if ($status === 'inactive') { $where[] = "p.is_active = 0"; }

    if     ($vis === 'visible') { $where[] = "p.pos_visible = 1"; }
    elseif ($vis === 'hidden')  { $where[] = "p.pos_visible = 0"; }

    if ($catId > 0) {
      $where[] = "EXISTS (SELECT 1 FROM product_categories pc WHERE pc.product_id = p.id AND pc.category_id = :cat)";
      $params[':cat'] = $catId;
    }

    if ($branchId > 0) {
      $where[] = "EXISTS (SELECT 1 FROM product_branches pb WHERE pb.product_id = p.id AND pb.branch_id = :br)";
      $params[':br'] = $branchId;
    }

    // Date filters if your products table has created_at/updated_at
    if ($dateFrom !== '') {
      $where[] = "DATE(p.created_at) >= :date_from";
      $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
      $where[] = "DATE(p.created_at) <= :date_to";
      $params[':date_to'] = $dateTo;
    }

    $whereSql = implode(' AND ', $where);

    $sql = "
      SELECT
        p.id,
        p.name_en,
        p.name_ar,
        p.price,
        p.is_active,
        p.pos_visible,
        GROUP_CONCAT(DISTINCT COALESCE(NULLIF(c.name_en,''), c.name_ar) ORDER BY COALESCE(NULLIF(c.name_en,''), c.name_ar) SEPARATOR ', ') AS category_names,
        GROUP_CONCAT(DISTINCT b.name ORDER BY b.name SEPARATOR ', ') AS branch_names
      FROM products p
      LEFT JOIN product_categories pc ON pc.product_id = p.id
      LEFT JOIN categories c ON c.id = pc.category_id AND c.tenant_id = p.tenant_id
      LEFT JOIN product_branches pb ON pb.product_id = p.id
      LEFT JOIN branches b ON b.id = pb.branch_id AND b.tenant_id = p.tenant_id
      WHERE $whereSql
      GROUP BY p.id
      ORDER BY p.name_en ASC, p.name_ar ASC
      LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    $error_msg = $e->getMessage();
  }
}

$active = 'products';
$hasActiveFilters = ($q !== '' || $status !== 'all' || $vis !== 'all' || $catId > 0 || $branchId > 0 || $dateFrom !== '' || $dateTo !== '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Products · Smorll POS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      /* Modern color palette matching rewards system */
      --ms-white: #ffffff;
      --ms-gray-10: #faf9f8;
      --ms-gray-20: #f3f2f1;
      --ms-gray-30: #edebe9;
      --ms-gray-40: #e1dfdd;
      --ms-gray-60: #c8c6c4;
      --ms-gray-110: #8a8886;
      --ms-gray-130: #605e5c;
      --ms-gray-160: #323130;
      
      --ms-blue: #0078d4;
      --ms-blue-hover: #106ebe;
      --ms-blue-light: #c7e0f4;
      --ms-blue-lighter: #deecf9;
      
      --ms-green: #107c10;
      --ms-green-light: #dff6dd;
      --ms-green-darker: #0e5e0e;
      
      --ms-red: #d13438;
      --ms-red-light: #fdf2f2;
      --ms-red-darker: #a80000;
      
      --ms-yellow: #ffb900;
      --ms-yellow-light: #fff4ce;
      
      --ms-purple: #5c2d91;
      --ms-purple-light: #e9dfef;
      
      --ms-shadow-1: 0 1px 2px rgba(0,0,0,0.05);
      --ms-shadow-2: 0 1.6px 3.6px 0 rgba(0,0,0,.132), 0 0.3px 0.9px 0 rgba(0,0,0,.108);
      --ms-shadow-3: 0 2px 8px rgba(0,0,0,0.092);
      
      --ms-radius: 4px;
      --ms-radius-lg: 8px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    body {
      font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
      font-size: 14px;
      line-height: 1.5;
      color: var(--ms-gray-160);
      background: var(--ms-gray-10);
    }

    /* Container */
    .container {
      padding: 24px;
      max-width: 1400px;
      margin: 0 auto;
    }

    @media (max-width: 768px) {
      .container {
        padding: 16px;
      }
    }

    /* Page Header */
    .h1 {
      font-size: 28px;
      font-weight: 600;
      color: var(--ms-gray-160);
      margin-bottom: 4px;
    }

    .sub {
      font-size: 14px;
      color: var(--ms-gray-110);
      margin-bottom: 24px;
    }

    /* Alert Messages */
    .alert {
      padding: 16px 20px;
      border-radius: var(--ms-radius-lg);
      margin-bottom: 20px;
      display: flex;
      align-items: flex-start;
      gap: 12px;
      font-size: 14px;
    }

    .alert-success {
      background: var(--ms-green-light);
      color: var(--ms-green-darker);
      border: 1px solid #a7f3d0;
    }

    .alert-error {
      background: var(--ms-red-light);
      color: var(--ms-red-darker);
      border: 1px solid #fca5a5;
    }

    /* Filters Bar - Matching Rewards style */
    .filters-bar {
      display: flex;
      gap: 16px;
      padding: 20px;
      background: white;
      border-radius: var(--ms-radius-lg);
      box-shadow: var(--ms-shadow-2);
      margin-bottom: 24px;
      align-items: end;
      flex-wrap: wrap;
    }

    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
      flex: 1;
      min-width: 140px;
    }

    .filter-group.search-group {
      flex: 2;
      min-width: 200px;
    }

    .filter-group label {
      font-size: 12px;
      font-weight: 600;
      color: var(--ms-gray-130);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .filter-group input,
    .filter-group select {
      padding: 8px 12px;
      border: 1px solid var(--ms-gray-60);
      border-radius: var(--ms-radius);
      font-size: 14px;
      background: white;
      transition: all 0.2s ease;
      width: 100%;
    }

    .filter-group input:hover,
    .filter-group select:hover {
      border-color: var(--ms-gray-110);
    }

    .filter-group input:focus,
    .filter-group select:focus {
      outline: none;
      border-color: var(--ms-blue);
      box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.25);
    }

    .filter-actions {
      margin-left: auto;
      display: flex;
      gap: 8px;
      align-items: flex-end;
    }

    /* Card */
    .card {
      background: white;
      border-radius: var(--ms-radius-lg);
      box-shadow: var(--ms-shadow-2);
      overflow: hidden;
    }

    .card-header {
      padding: 20px 24px;
      border-bottom: 1px solid var(--ms-gray-30);
      display: flex;
      justify-content: space-between;
      align-items: center;
      flex-wrap: wrap;
      gap: 16px;
    }

    .card-title {
      font-size: 16px;
      font-weight: 600;
      color: var(--ms-gray-160);
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      border-radius: var(--ms-radius);
      font-size: 14px;
      font-weight: 500;
      text-decoration: none;
      cursor: pointer;
      transition: all 0.1s ease;
      border: 1px solid transparent;
      background: var(--ms-gray-20);
      color: var(--ms-gray-160);
    }

    .btn:hover {
      background: var(--ms-gray-30);
    }

    .btn.primary {
      background: var(--ms-blue);
      color: white;
      border-color: var(--ms-blue);
    }

    .btn.primary:hover {
      background: var(--ms-blue-hover);
      border-color: var(--ms-blue-hover);
    }

    .btn.small {
      padding: 6px 12px;
      font-size: 13px;
    }

    .btn.danger {
      background: white;
      color: var(--ms-red);
      border: 1px solid var(--ms-gray-30);
    }

    .btn.danger:hover {
      background: var(--ms-red-light);
      border-color: var(--ms-red);
    }

    /* Table */
    .table {
      width: 100%;
      border-collapse: collapse;
    }

    .table th {
      background: var(--ms-gray-20);
      padding: 12px 16px;
      text-align: left;
      font-size: 12px;
      font-weight: 600;
      color: var(--ms-gray-130);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      border-bottom: 1px solid var(--ms-gray-30);
    }

    .table td {
      padding: 14px 16px;
      border-bottom: 1px solid var(--ms-gray-20);
      font-size: 14px;
    }

    .table tbody tr:hover {
      background: var(--ms-gray-10);
    }

    .table tbody tr:last-child td {
      border-bottom: none;
    }

    /* Product name styling */
    .product-name {
      font-weight: 600;
      color: var(--ms-gray-160);
      margin-bottom: 2px;
    }

    .product-name-ar {
      font-size: 12px;
      color: var(--ms-gray-110);
      direction: rtl;
    }

    /* Status badges */
    .text-badge {
      display: inline-block;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .text-badge.active {
      color: var(--ms-green);
    }

    .text-badge.inactive {
      color: var(--ms-gray-110);
    }

    .text-badge.visible {
      color: var(--ms-blue);
    }

    .text-badge.hidden {
      color: var(--ms-yellow);
    }

    /* Empty state */
    .empty-state {
      text-align: center;
      padding: 60px 20px;
      color: var(--ms-gray-110);
    }

    .empty-state h3 {
      font-size: 18px;
      margin-bottom: 8px;
      color: var(--ms-gray-130);
    }

    /* Responsive */
    @media (max-width: 1200px) {
      .filters-bar {
        flex-wrap: wrap;
      }
      
      .filter-group {
        min-width: calc(50% - 8px);
      }
      
      .filter-group.search-group {
        min-width: 100%;
      }
    }

    @media (max-width: 768px) {
      .filters-bar {
        flex-direction: column;
      }
      
      .filter-group {
        width: 100%;
      }
      
      .filter-actions {
        width: 100%;
        margin-left: 0;
      }
      
      .filter-actions .btn {
        flex: 1;
      }
      
      .table {
        font-size: 13px;
      }
      
      .table th,
      .table td {
        padding: 10px 12px;
      }
      
      .card-header {
        flex-direction: column;
        align-items: stretch;
      }
    }

    /* Table container for scroll */
    .scroll-body {
      overflow-x: auto;
    }

    /* Loading state */
    .loading {
      display: inline-block;
      width: 16px;
      height: 16px;
      border: 2px solid var(--ms-gray-40);
      border-top-color: var(--ms-blue);
      border-radius: 50%;
      animation: spin 0.8s linear infinite;
      margin-left: 8px;
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  </style>
</head>
<body>

<?php
// Include admin navigation
$active = 'products';
try {
    require __DIR__ . '/../../partials/admin_nav.php';
} catch (Throwable $e) {
    echo "<div class='alert alert-error'>Navigation error: " . h($e->getMessage()) . "</div>";
}
?>

<div class="container">
  <?php if (!$bootstrap_ok): ?>
    <div class="alert alert-error">
      <strong>Bootstrap Error:</strong> <?= h($bootstrap_msg) ?>
      <br><small>Add <code>?debug=1</code> to the URL for more details.</small>
    </div>
  <?php else: ?>

    <?php if ($flash): ?>
      <div class="alert alert-success"><?= h($flash) ?></div>
    <?php endif; ?>

    <?php if ($DEBUG && $error_msg): ?>
      <div class="alert alert-error">
        <strong>Debug:</strong> <?= h($error_msg) ?>
      </div>
    <?php endif; ?>

    <div class="h1">Products</div>
    <p class="sub">Manage your product catalog and inventory</p>

    <!-- Filters -->
    <form method="get" action="" id="filterForm">
      <div class="filters-bar">
        <div class="filter-group search-group">
          <label>Search</label>
          <input type="text" id="q" name="q" value="<?= h($q) ?>" placeholder="Search products...">
        </div>
        
        <div class="filter-group">
          <label>Status</label>
          <select id="status" name="status">
            <option value="all" <?= $status==='all'?'selected':'' ?>>All Status</option>
            <option value="active" <?= $status==='active'?'selected':'' ?>>Active</option>
            <option value="inactive" <?= $status==='inactive'?'selected':'' ?>>Inactive</option>
          </select>
        </div>
        
        <div class="filter-group">
          <label>Visibility</label>
          <select id="vis" name="vis">
            <option value="all" <?= $vis==='all'?'selected':'' ?>>All Visibility</option>
            <option value="visible" <?= $vis==='visible'?'selected':'' ?>>Visible</option>
            <option value="hidden" <?= $vis==='hidden'?'selected':'' ?>>Hidden</option>
          </select>
        </div>
        
        <div class="filter-group">
          <label>Category</label>
          <select id="cat" name="cat">
            <option value="0">All Categories</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int)$c['id'] ?>" <?= $catId===(int)$c['id']?'selected':'' ?>>
                <?= h((string)$c['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="filter-group">
          <label>Branch</label>
          <select id="br" name="br">
            <option value="0">All Branches</option>
            <?php foreach ($branches as $b): ?>
              <option value="<?= (int)$b['id'] ?>" <?= $branchId===(int)$b['id']?'selected':'' ?>>
                <?= h((string)$b['name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        
        <div class="filter-actions">
          <?php if ($hasActiveFilters): ?>
            <button type="button" class="btn" onclick="clearFilters()">Clear Filters</button>
          <?php endif; ?>
        </div>
      </div>
    </form>

    <!-- Products Table -->
    <div class="card">
      <div class="card-header">
        <h2 class="card-title">Products List</h2>
        <a href="/views/admin/catalog/products_new.php" class="btn primary">+ Add Product</a>
      </div>
      
      <div class="scroll-body">
        <?php if (empty($products)): ?>
          <div class="empty-state">
            <h3>No products found</h3>
            <p>
              <?php if ($hasActiveFilters): ?>
                No products match the selected filters.
              <?php else: ?>
                Start by adding your first product.
              <?php endif; ?>
            </p>
            <?php if (!$hasActiveFilters): ?>
              <br>
              <a href="/views/admin/catalog/products_new.php" class="btn primary">+ Add Your First Product</a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th style="width: 60px;">ID</th>
                <th>Name</th>
                <th>Category</th>
                <th>Branch</th>
                <th style="width: 100px;">Price</th>
                <th style="width: 80px;">Status</th>
                <th style="width: 80px;">Visibility</th>
                <th style="width: 140px;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($products as $row): ?>
                <tr>
                  <td style="color: var(--ms-gray-110); font-size: 12px;">#<?= (int)$row['id'] ?></td>
                  <td>
                    <div class="product-name"><?= h($row['name_en'] ?: 'Untitled') ?></div>
                    <?php if (!empty($row['name_ar'])): ?>
                      <div class="product-name-ar"><?= h($row['name_ar']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="font-size: 13px; color: var(--ms-gray-110);">
                    <?= h($row['category_names'] ?: '—') ?>
                  </td>
                  <td style="font-size: 13px; color: var(--ms-gray-110);">
                    <?= h($row['branch_names'] ?: '—') ?>
                  </td>
                  <td style="font-weight: 600;">
                    <?= number_format((float)($row['price'] ?? 0), 2) ?>
                  </td>
                  <td>
                    <?php if ((int)$row['is_active'] === 1): ?>
                      <span class="text-badge active">ACTIVE</span>
                    <?php else: ?>
                      <span class="text-badge inactive">INACTIVE</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ((int)$row['pos_visible'] === 1): ?>
                      <span class="text-badge visible">VISIBLE</span>
                    <?php else: ?>
                      <span class="text-badge hidden">HIDDEN</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <a href="/views/admin/catalog/product_edit.php?id=<?= (int)$row['id'] ?>" class="btn small">Edit</a>
                    <button class="btn small danger" onclick="deleteProduct(<?= (int)$row['id'] ?>, '<?= h(addslashes($row['name_en'] ?: 'Product #'.$row['id'])) ?>')">Delete</button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

  <?php endif; ?>
</div>

<?php
// Close admin layout
require __DIR__ . '/../../partials/admin_nav_close.php';
?>

<script>
// Debounced filter handling
let filterTimer = null;

function applyFilters() {
  if (filterTimer) clearTimeout(filterTimer);
  
  filterTimer = setTimeout(() => {
    document.getElementById('filterForm').submit();
  }, 600);
}

function clearFilters() {
  window.location.href = '?';
}

function deleteProduct(id, name) {
  if (confirm(`Delete product "${name}"?\n\nThis action cannot be undone.`)) {
    window.location.href = `/controllers/admin/products_delete.php?id=${id}`;
  }
}

// Auto-submit on filter changes
document.addEventListener('DOMContentLoaded', function() {
  const form = document.getElementById('filterForm');
  const searchInput = document.getElementById('q');
  const selects = document.querySelectorAll('select');
  
  // Search with debounce
  if (searchInput) {
    searchInput.addEventListener('input', applyFilters);
    searchInput.addEventListener('keypress', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        clearTimeout(filterTimer);
        form.submit();
      }
    });
  }
  
  // Select changes with debounce
  selects.forEach(select => {
    select.addEventListener('change', applyFilters);
  });
});
</script>

</body>
</html>