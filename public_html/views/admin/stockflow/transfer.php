<?php
declare(strict_types=1);
/**
 * /public_html/views/admin/stockflow/transfer.php
 * Create/Edit Stock Transfer (Microsoft 365 Design)
 * - Resilient nav includes (prevents fatal if partial not found)
 * - Add button now same height as filters & search (prettier)
 */

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  @ini_set('display_errors','1');
  @ini_set('display_startup_errors','1');
  error_reporting(E_ALL);
} else {
  @ini_set('display_errors','0');
}

// ---------- Bootstrap config + auth ----------
$bootstrap_ok = false;
$bootstrap_msg = '';
try {
  $configPath = __DIR__ . '/../../../config/db.php';
  if (!is_file($configPath)) {
    throw new RuntimeException('Configuration file not found at /config/db.php');
  }
  require_once $configPath;

  if (function_exists('use_backend_session')) { use_backend_session(); }
  else { if (session_status() !== PHP_SESSION_ACTIVE) session_start(); }

  $authPath = __DIR__ . '/../../../middleware/auth_login.php';
  if (!is_file($authPath)) throw new RuntimeException('Auth middleware not found');
  require_once $authPath;
  auth_require_login();

  if (!function_exists('db')) throw new RuntimeException('db() not available from config.');
  $bootstrap_ok = true;
} catch (Throwable $e) {
  $bootstrap_msg = $e->getMessage();
}

// ---------- Helpers ----------
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Include first existing file from a list (safe include, not fatal). */
function include_first_existing(array $candidates): bool {
  foreach ($candidates as $f) {
    if (is_file($f)) { include $f; return true; }
  }
  return false;
}

// ---------- Current user / tenant ----------
$user = $_SESSION['user'] ?? null;
if (!$user && $bootstrap_ok) {
  header('Location: /views/auth/login.php'); exit;
}
$tenantId = (int)($user['tenant_id'] ?? 0);
$userId   = (int)($user['id'] ?? 0);

// ---------- Transfer ID ----------
$transferId  = (int)($_GET['id'] ?? 0);
$isEditMode  = $transferId > 0;

// ---------- Flash ----------
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ---------- Data ----------
$transfer       = null;
$transferItems  = [];
$branches       = [];
$allProducts    = []; // ajax-loaded
$categories     = [];
$error_msg      = '';

if ($bootstrap_ok) {
  try {
    $pdo = db();

    // Branches
    $stmt = $pdo->prepare("
      SELECT b.id, COALESCE(b.display_name, b.name) AS name, b.branch_type, b.is_production_enabled
      FROM branches b
      WHERE b.tenant_id = :t AND b.is_active = 1
      ORDER BY COALESCE(b.display_name, b.name)
    ");
    $stmt->execute([':t' => $tenantId]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Categories
    $stmt = $pdo->prepare("
      SELECT id, name_en AS name
      FROM categories
      WHERE tenant_id = :t AND is_active = 1
      ORDER BY name_en
    ");
    $stmt->execute([':t' => $tenantId]);
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Edit mode: transfer + items
    if ($isEditMode) {
      $stmt = $pdo->prepare("
        SELECT
          t.*,
          COALESCE(fb.display_name, fb.name) AS from_branch_name,
          COALESCE(tb.display_name, tb.name) AS to_branch_name,
          u.name AS created_by_name
        FROM stockflow_transfers t
        LEFT JOIN branches fb ON fb.id = t.from_branch_id AND fb.tenant_id = t.tenant_id
        LEFT JOIN branches tb ON tb.id = t.to_branch_id   AND tb.tenant_id = t.tenant_id
        LEFT JOIN users u     ON u.id = t.created_by_user_id AND u.tenant_id = t.tenant_id
        WHERE t.id = :id AND t.tenant_id = :t
      ");
      $stmt->execute([':id' => $transferId, ':t' => $tenantId]);
      $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$transfer) { header('Location: /views/admin/stockflow/index.php'); exit; }

      $stmt = $pdo->prepare("
        SELECT
          ti.*,
          p.name_en AS product_name,
          p.inventory_unit,
          c.name_en AS category_name,
          sl.current_stock,
          sl.reserved_stock
        FROM stockflow_transfer_items ti
        LEFT JOIN products p ON p.id = ti.product_id AND p.tenant_id = :t
        LEFT JOIN product_categories pc ON pc.product_id = ti.product_id
        LEFT JOIN categories c ON c.id = pc.category_id AND c.tenant_id = :t2
        LEFT JOIN stockflow_stock_levels sl ON sl.product_id = ti.product_id
             AND sl.branch_id = :branch_id AND sl.tenant_id = :t3
        WHERE ti.transfer_id = :transfer_id
        ORDER BY ti.id
      ");
      $stmt->execute([
        ':transfer_id' => $transferId,
        ':t'           => $tenantId,
        ':t2'          => $tenantId,
        ':t3'          => $tenantId,
        ':branch_id'   => $transfer['from_branch_id']
      ]);
      $transferItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

  } catch (Throwable $e) {
    $error_msg = $e->getMessage();
  }
}

$active    = 'stockflow';
$pageTitle = $isEditMode ? 'Edit Transfer' : 'New Transfer';
$canEdit   = !$isEditMode || ($transfer && in_array($transfer['status'], ['pending'], true));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($pageTitle) ?> · Smorll POS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root{
      --bg-primary:#faf9f8; --bg-secondary:#f3f2f1; --card-bg:#fff;
      --text-primary:#323130; --text-secondary:#605e5c; --text-tertiary:#8a8886;
      --primary:#0078d4; --primary-hover:#106ebe; --primary-light:#deecf9; --primary-lighter:#f3f9fd;
      --border:#edebe9; --border-light:#f8f6f4; --hover:#f3f2f1;
      --success:#107c10; --success-light:#dff6dd;
      --warning:#ff8c00; --warning-light:#fff4ce;
      --danger:#d13438; --danger-light:#fdf2f2;
      --info:#0078d4; --info-light:#deecf9;
      --shadow-sm:0 1px 2px rgba(0,0,0,.04),0 1px 1px rgba(0,0,0,.06);
      --shadow-md:0 4px 8px rgba(0,0,0,.04),0 1px 3px rgba(0,0,0,.06);
      --shadow-lg:0 8px 16px rgba(0,0,0,.06),0 2px 4px rgba(0,0,0,.08);
      --transition:all .1s cubic-bezier(.1,.9,.2,1);
      --radius:4px; --radius-lg:8px;

      /* NEW: normalize control heights */
      --control-height: 36px;          /* matches search/filter inputs */
      --control-padding-y: 8px;
      --control-padding-x: 12px;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg-primary);font-family:'Inter','Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,Roboto,'Helvetica Neue',sans-serif;color:var(--text-primary);font-size:14px;line-height:1.5}
    h1,h2,h3,h4,h5,h6,p{margin:0}

    .transfer-container{padding:16px;width:100%;max-width:1400px;margin:0 auto;transition:all .3s ease}
    @media (max-width:768px){.transfer-container{padding:12px;max-width:none}}

    .page-header{margin-bottom:20px;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap}
    .page-title{font-size:24px;font-weight:600;color:var(--text-primary);display:flex;align-items:center;gap:12px}
    .page-subtitle{font-size:14px;color:var(--text-secondary)}
    .status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:16px;font-size:12px;font-weight:500;text-transform:capitalize}
    .status-badge.pending{background:var(--warning-light);color:var(--warning)}
    .status-badge.shipped{background:var(--info-light);color:var(--info)}
    .status-badge.received{background:var(--success-light);color:var(--success)}
    .status-badge.cancelled{background:var(--danger-light);color:var(--danger)}

    .main-grid{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start;overflow:hidden}
    @media (max-width:1024px){.main-grid{grid-template-columns:1fr;overflow:visible}}

    .card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);overflow:hidden;transition:var(--transition);margin-bottom:20px}
    .card:hover{box-shadow:var(--shadow-md)}
    .card-header{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:16px}
    .card-title{font-size:16px;font-weight:600;color:var(--text-primary)}
    .card-body{padding:20px}

    .form-group{margin-bottom:20px}
    .form-label{display:block;font-size:14px;font-weight:500;color:var(--text-primary);margin-bottom:6px}
    .form-label.required::after{content:' *';color:var(--danger)}
    .form-input,.form-select,.form-textarea{
      width:100%;padding:var(--control-padding-y) var(--control-padding-x);
      border:1px solid var(--border);border-radius:var(--radius);
      background:var(--card-bg);font-size:14px;color:var(--text-primary);
      outline:none;transition:var(--transition);font-family:inherit;height:var(--control-height);
    }
    .form-textarea{resize:vertical;min-height:80px;height:auto}
    .form-input:focus,.form-select:focus,.form-textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(0,120,212,0.1)}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    @media (max-width:600px){.form-grid{grid-template-columns:1fr}}

    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border:1px solid transparent;border-radius:var(--radius);font-size:14px;font-weight:500;text-decoration:none;cursor:pointer;transition:var(--transition);outline:none;white-space:nowrap;line-height:1}
    .btn:hover{transform:translateY(-1px);box-shadow:var(--shadow-md);text-decoration:none}
    .btn:active{transform:translateY(0)}
    .btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
    .btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}
    .btn-primary:hover:not(:disabled){background:var(--primary-hover);border-color:var(--primary-hover);color:#fff}
    .btn-secondary{background:var(--card-bg);color:var(--text-secondary);border-color:var(--border)}
    .btn-secondary:hover:not(:disabled){background:var(--hover);color:var(--text-primary)}
    .btn-success{background:var(--success);color:#fff;border-color:var(--success)}
    .btn-warning{background:var(--warning);color:#fff;border-color:var(--warning)}
    .btn-danger{background:var(--card-bg);color:var(--danger);border-color:var(--border)}
    .btn-sm{padding:8px 12px}

    /* NEW: make header controls the same height */
    .search-input,.filter-select{height:var(--control-height);padding:var(--control-padding-y) var(--control-padding-x)}
    .btn-control{height:var(--control-height);padding:var(--control-padding-y) 16px;display:inline-flex;align-items:center;justify-content:center}

    .btn-group{display:flex;gap:12px;flex-wrap:wrap}
    @media (max-width:600px){.btn-group{flex-direction:column}}

    .table-controls{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:16px;flex-wrap:wrap}
    .search-filter-group{display:flex;gap:12px;flex:1;min-width:300px;flex-wrap:wrap;align-items:center}
    .table-actions-group{display:flex;gap:12px;align-items:center;flex-shrink:0}

    @media (max-width:768px){
      .table-controls{flex-direction:column;align-items:stretch;gap:12px}
      .search-filter-group{min-width:auto;flex-direction:column;align-items:stretch}
      .table-actions-group{justify-content:flex-start;width:100%}
    }

    .search-box{position:relative;flex:1;min-width:200px}
    .search-input{width:100%;border:1px solid var(--border);border-radius:var(--radius);background:var(--card-bg);font-size:14px;color:var(--text-primary);outline:none;transition:var(--transition)}
    .search-input:focus{border-color:var(--primary);box-shadow:0 0 0 3px rgba(0,120,212,0.1)}
    .search-input::placeholder{color:var(--text-tertiary)}
    .search-icon{position:absolute;left:10px;top:50%;transform:translateY(-50%);width:16px;height:16px;color:var(--text-tertiary)}
    .search-input{padding-left:36px}

    .filter-select{padding-right:28px;border:1px solid var(--border);border-radius:var(--radius);background:var(--card-bg);font-size:14px;color:var(--text-primary);cursor:pointer;min-width:120px}

    .results-info{font-size:12px;color:var(--text-secondary);display:flex;align-items:center;gap:16px}
    .clear-filters{color:var(--primary);text-decoration:none;font-size:12px;cursor:pointer}
    .clear-filters:hover{text-decoration:underline}

    .products-table{width:100%;border-collapse:collapse;border:1px solid var(--border);border-radius:var(--radius-lg);overflow:hidden}
    .products-table thead{background:var(--bg-secondary)}
    .products-table th{padding:12px 16px;text-align:left;font-weight:600;font-size:12px;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid var(--border)}
    .products-table td{padding:16px;border-bottom:1px solid var(--border-light);vertical-align:middle}
    .products-table tbody tr:hover{background:var(--hover)}
    .products-table tbody tr.selected{background:var(--primary-lighter)}
    @media (max-width:768px){.products-table th,.products-table td{padding:8px;font-size:12px}}

    .product-name{font-weight:500;color:var(--text-primary);margin-bottom:2px}
    .product-details{font-size:12px;color:var(--text-secondary)}
    .category-badge{display:inline-block;padding:2px 6px;background:var(--primary-light);color:var(--primary);border-radius:12px;font-size:10px;font-weight:500;margin-right:6px}

    .quantity-input{width:80px;padding:6px 8px;border:1px solid var(--border);border-radius:var(--radius);text-align:center;font-size:14px}
    .quantity-input:focus{border-color:var(--primary);outline:none}

    .stock-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 8px;border-radius:12px;font-size:12px;font-weight:500}
    .stock-available{background:var(--success-light);color:var(--success)}
    .stock-low{background:var(--warning-light);color:var(--warning)}
    .stock-none{background:var(--danger-light);color:var(--danger)}

    .select-checkbox{width:18px;height:18px;cursor:pointer}

    .transfer-items-summary{max-height:300px;overflow-y:auto;border:1px solid var(--border);border-radius:var(--radius)}
    .transfer-item{display:flex;justify-content:space-between;align-items:center;padding:8px 12px;border-bottom:1px solid var(--border-light);font-size:13px}
    .transfer-item:last-child{border-bottom:none}
    .transfer-item-name{font-weight:500;flex:1}
    .transfer-item-qty{color:var(--text-secondary);margin:0 8px}
    .remove-transfer-item{color:var(--danger);cursor:pointer;padding:2px}
    .remove-transfer-item:hover{background:var(--danger-light);border-radius:50%}

    .empty-state{padding:40px 20px;text-align:center;color:var(--text-secondary);border:2px dashed var(--border);border-radius:var(--radius-lg);margin:16px 0}
    .empty-icon{width:60px;height:60px;margin:0 auto 16px;background:var(--bg-secondary);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:24px}
    .empty-title{font-size:16px;font-weight:600;color:var(--text-primary);margin-bottom:4px}

    .summary-item{display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border-light)}
    .summary-item:last-child{border-bottom:none;font-weight:600;font-size:16px}
    .summary-label{color:var(--text-secondary)}
    .summary-value{font-family:'SF Mono',Monaco,'Courier New',monospace;font-weight:500}

    .alert{padding:16px 20px;border-radius:var(--radius-lg);margin-bottom:16px;font-size:14px;border:1px solid;display:flex;align-items:center;gap:12px}
    .alert.success{background:var(--success-light);border-color:#a7f3d0;color:var(--success)}
    .alert.error{background:var(--danger-light);border-color:#fca5a5;color:var(--danger)}
    .alert.warning{background:var(--warning-light);border-color:#fbbf24;color:var(--warning)}

    .loading{display:inline-block;width:16px;height:16px;border:2px solid var(--border);border-top-color:var(--primary);border-radius:50%;animation:spin .8s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
  </style>
</head>
<body>

<?php
// --- Safe include: top nav ---
$navIncluded = include_first_existing([
  __DIR__ . '/../../partials/admin_nav.php',
  dirname(__DIR__,2) . '/partials/admin_nav.php',
  $_SERVER['DOCUMENT_ROOT'] . '/views/partials/admin_nav.php',
  $_SERVER['DOCUMENT_ROOT'] . '/partials/admin_nav.php'
]);
if (!$navIncluded) {
  echo "<div class='alert warning' style='margin:12px'>Navigation not loaded (partials/admin_nav.php not found).</div>";
}
?>

<div class="transfer-container">
  <?php if (!$bootstrap_ok): ?>
    <div class="alert error">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.314 16.5c-.77.833.192 2.5 1.732 2.5z"/>
      </svg>
      <div><strong>Bootstrap Error</strong><br><?= h($bootstrap_msg) ?></div>
    </div>
  <?php else: ?>

    <?php if ($flash): ?>
      <div class="alert success">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <?= h($flash) ?>
      </div>
    <?php endif; ?>

    <?php if ($DEBUG && $error_msg): ?>
      <div class="alert error"><strong>Debug:</strong> <?= h($error_msg) ?></div>
    <?php endif; ?>

    <div class="page-header">
      <div>
        <h1 class="page-title">
          <?= h($pageTitle) ?>
          <?php if ($isEditMode && $transfer): ?>
            <span class="status-badge <?= h($transfer['status']) ?>"><?= h($transfer['status']) ?></span>
          <?php endif; ?>
        </h1>
        <p class="page-subtitle">
          <?php if ($isEditMode && $transfer): ?>
            Transfer <?= h($transfer['transfer_number']) ?> • Created <?= date('M j, Y', strtotime($transfer['created_at'])) ?>
          <?php else: ?>
            Create a new stock transfer between branches
          <?php endif; ?>
        </p>
      </div>
      <div class="btn-group">
        <a href="/views/admin/stockflow/index.php" class="btn btn-secondary">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M19 12H5m7-7l-7 7 7 7"/>
          </svg>
          Back to Transfers
        </a>
      </div>
    </div>

    <div class="main-grid">
      <div>
        <!-- Transfer Details -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Transfer Details</h3></div>
          <div class="card-body">
            <form id="transferForm">
              <?php if ($isEditMode): ?><input type="hidden" name="transfer_id" value="<?= (int)$transferId ?>"><?php endif; ?>
              <div class="form-grid">
                <div class="form-group">
                  <label class="form-label required">From Branch</label>
                  <select name="from_branch_id" class="form-select" <?= $canEdit ? '' : 'disabled' ?> required>
                    <option value="">Select source branch</option>
                    <?php foreach ($branches as $branch): ?>
                      <option value="<?= (int)$branch['id'] ?>" <?= ($transfer && (int)$transfer['from_branch_id'] === (int)$branch['id']) ? 'selected' : '' ?>>
                        <?= h($branch['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label required">To Branch</label>
                  <select name="to_branch_id" class="form-select" <?= $canEdit ? '' : 'disabled' ?> required>
                    <option value="">Select destination branch</option>
                    <?php foreach ($branches as $branch): ?>
                      <option value="<?= (int)$branch['id'] ?>" <?= ($transfer && (int)$transfer['to_branch_id'] === (int)$branch['id']) ? 'selected' : '' ?>>
                        <?= h($branch['name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
              </div>

              <div class="form-group">
                <label class="form-label">Date</label>
                <input type="date" name="scheduled_date" class="form-input" style="max-width:200px;" <?= $canEdit ? '' : 'disabled' ?>
                       value="<?= $transfer && $transfer['scheduled_date'] ? date('Y-m-d', strtotime($transfer['scheduled_date'])) : date('Y-m-d') ?>"
                       min="<?= date('Y-m-d') ?>">
                <div class="form-help" style="font-size:12px;color:var(--text-secondary);margin-top:4px">Date for this transfer</div>
              </div>

              <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-textarea" <?= $canEdit ? '' : 'disabled' ?> placeholder="Optional notes about this transfer"><?= $transfer ? h($transfer['notes']) : '' ?></textarea>
              </div>
            </form>
          </div>
        </div>

        <!-- Products Browser -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Available Products</h3></div>
          <div class="card-body">
            <div class="table-controls">
              <div class="search-filter-group">
                <div class="search-box">
                  <svg class="search-icon" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                  <input type="text" id="productSearch" class="search-input" placeholder="Search products...">
                </div>
                <select id="stockFilter" class="filter-select">
                  <option value="">All Stock Levels</option>
                  <option value="available">Available</option>
                  <option value="low">Low Stock</option>
                  <option value="none">Out of Stock</option>
                </select>
                <select id="categoryFilter" class="filter-select">
                  <option value="">All Categories</option>
                  <?php foreach ($categories as $cat): ?>
                    <option value="<?= h($cat['name']) ?>"><?= h($cat['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="table-actions-group">
                <div class="results-info">
                  <span id="productCount"><?= count($allProducts) ?> products</span>
                  <a href="#" class="clear-filters">Clear filters</a>
                </div>
                <!-- NEW: Add button same height & nicer look -->
                <button type="button" class="btn btn-primary btn-control" id="addSelectedBtn" disabled>Add</button>
              </div>
            </div>

            <table class="products-table" id="productsTable">
              <thead>
                <tr>
                  <th style="width:40px;"><input type="checkbox" id="selectAll"></th>
                  <th>Product</th>
                  <th style="width:120px;">Available</th>
                  <th style="width:100px;">Quantity</th>
                </tr>
              </thead>
              <tbody id="productsTableBody">
                <tr>
                  <td colspan="4" style="text-align:center; color:var(--text-secondary);">
                    <?= $isEditMode ? 'Products will load based on selected branch' : 'Please select a branch to see available products' ?>
                  </td>
                </tr>
              </tbody>
            </table>

            <div class="empty-state" id="emptyState" style="display:none;">
              <div class="empty-icon">🔍</div>
              <div class="empty-title">No products found</div>
              <div>Try adjusting your search or filter criteria</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div>
        <div class="card">
          <div class="card-header"><h3 class="card-title">Transfer Items</h3></div>
          <div class="card-body">
            <div class="transfer-items-summary" id="transferItemsList">
              <?php if (!$transferItems && !$isEditMode): ?>
                <div class="empty-state">
                  <div class="empty-icon">📦</div>
                  <div class="empty-title">No items selected</div>
                  <div>Select products from the table to add to transfer</div>
                </div>
              <?php else: ?>
                <?php foreach ($transferItems as $item): ?>
                  <div class="transfer-item" data-item-id="<?= (int)$item['id'] ?>">
                    <div class="transfer-item-name"><?= h($item['product_name']) ?></div>
                    <div class="transfer-item-qty"><?= number_format((float)($item['quantity_requested'] ?? $item['quantity'] ?? 0), 1) ?></div>
                    <?php if ($canEdit): ?><div class="remove-transfer-item" onclick="removeTransferItem(<?= (int)$item['id'] ?>)">×</div><?php endif; ?>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h3 class="card-title">Summary</h3></div>
          <div class="card-body">
            <div id="transferSummary">
              <div class="summary-item"><span class="summary-label">Total Items:</span><span class="summary-value" id="totalItems"><?= count($transferItems) ?></span></div>
              <div class="summary-item">
                <span class="summary-label">Total Quantity:</span>
                <span class="summary-value" id="totalQuantity">
                  <?php $totalQty = 0; foreach ($transferItems as $item) { $totalQty += (float)($item['quantity_requested'] ?? $item['quantity'] ?? 0); } echo number_format($totalQty, 1); ?>
                </span>
              </div>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-header"><h3 class="card-title">Actions</h3></div>
          <div class="card-body">
            <div class="btn-group" style="flex-direction:column;">
              <?php if (!$isEditMode || $transfer['status'] === 'pending'): ?>
                <button type="button" class="btn btn-primary" id="saveBtn">
                  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
                    <polyline points="17,21 17,13 7,13 7,21"/>
                    <polyline points="7,3 7,8 15,8"/>
                  </svg>
                  <?= $isEditMode ? 'Update Transfer' : 'Save Transfer' ?>
                </button>
                <?php if ($canEdit && count($transferItems) > 0): ?>
                  <button type="button" class="btn btn-success" id="shipBtn">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M8 17l4 4 4-4m-4-5v9"/><path d="M3 4h18v5a3 3 0 01-3 3H6a3 3 0 01-3-3V4z"/></svg>
                    Ship Transfer
                  </button>
                <?php endif; ?>
              <?php endif; ?>

              <?php if ($isEditMode && $transfer['status'] === 'shipped'): ?>
                <button type="button" class="btn btn-warning" id="receiveBtn">
                  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 17l-4-4-4 4m4-5v9"/><path d="M21 4H3v5a3 3 0 003 3h12a3 3 0 003-3V4z"/></svg>
                  Receive Transfer
                </button>
              <?php endif; ?>

              <?php if ($isEditMode && $transfer['status'] === 'pending'): ?>
                <button type="button" class="btn btn-danger" id="cancelBtn">
                  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
                  Cancel Transfer
                </button>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <?php if ($isEditMode && $transfer): ?>
        <div class="card">
          <div class="card-header"><h3 class="card-title">Transfer Info</h3></div>
          <div class="card-body">
            <div style="font-size:12px;color:var(--text-secondary);line-height:1.6;">
              <div><strong>Created:</strong> <?= date('M j, Y g:i A', strtotime($transfer['created_at'])) ?></div>
              <div><strong>By:</strong> <?= h($transfer['created_by_name'] ?: 'Unknown') ?></div>
              <?php if ($transfer['scheduled_date']): ?><div style="margin-top:8px;"><strong>Scheduled:</strong> <?= date('M j, Y', strtotime($transfer['scheduled_date'])) ?></div><?php endif; ?>
              <?php if ($transfer['shipped_at']): ?><div style="margin-top:8px;"><strong>Shipped:</strong> <?= date('M j, Y g:i A', strtotime($transfer['shipped_at'])) ?></div><?php endif; ?>
              <?php if ($transfer['received_at']): ?><div style="margin-top:8px;"><strong>Received:</strong> <?= date('M j, Y g:i A', strtotime($transfer['received_at'])) ?></div><?php endif; ?>
              <?php if ($transfer['cancelled_at']): ?><div style="margin-top:8px;"><strong>Cancelled:</strong> <?= date('M j, Y g:i A', strtotime($transfer['cancelled_at'])) ?></div><?php endif; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php
// --- Safe include: closing nav ---
$navCloseIncluded = include_first_existing([
  __DIR__ . '/../../partials/admin_nav_close.php',
  dirname(__DIR__,2) . '/partials/admin_nav_close.php',
  $_SERVER['DOCUMENT_ROOT'] . '/views/partials/admin_nav_close.php',
  $_SERVER['DOCUMENT_ROOT'] . '/partials/admin_nav_close.php'
]);
if (!$navCloseIncluded) {
  // graceful fallback; no fatal
  echo "<!-- nav close partial not found -->";
}
?>

<script>
const transferId  = <?= (int)$transferId ?>;
const isEditMode  = <?= $isEditMode ? 'true' : 'false' ?>;
const canEdit     = <?= $canEdit ? 'true' : 'false' ?>;
let transferItems = <?= json_encode($transferItems, JSON_UNESCAPED_UNICODE) ?>;

function escapeHtml(text){ const d=document.createElement('div'); d.textContent=text||''; return d.innerHTML; }
function showAlert(message, type='success'){
  document.querySelectorAll('.alert').forEach(a=>a.remove());
  const el=document.createElement('div'); el.className=`alert ${type}`;
  el.innerHTML = `
    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
      ${ type==='error'
        ? '<path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.314 16.5c-.77.833.192 2.5 1.732 2.5z"/>'
        : '<path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>' }
    </svg>
    ${escapeHtml(message)}
  `;
  const container=document.querySelector('.transfer-container');
  const header=container?.querySelector('.page-header');
  if(container && header){ container.insertBefore(el, header.nextSibling); }
  setTimeout(()=>{ el.remove(); }, 5000);
}

// ---- Checkbox handling ----
function handleCheckboxChange(){ updateAddButton(); }
function attachCheckboxListeners(){
  document.querySelectorAll('.product-checkbox').forEach(cb=>{
    cb.removeEventListener('change', handleCheckboxChange);
    cb.addEventListener('change', handleCheckboxChange);
  });
}
function updateAddButton(){
  const checkedBoxes=document.querySelectorAll('.product-checkbox:checked');
  const addBtn=document.getElementById('addSelectedBtn');
  if(!addBtn) return;
  const count=checkedBoxes.length;
  addBtn.disabled=(count===0);
  addBtn.textContent = count>0 ? `Add (${count})` : 'Add';
  document.querySelectorAll('.product-checkbox').forEach(cb=>{
    const row=cb.closest('tr'); if(row) row.classList.toggle('selected', cb.checked);
  });
}
function toggleSelectAll(){
  const isChecked = document.getElementById('selectAll')?.checked || false;
  document.querySelectorAll('.product-checkbox').forEach(cb=>{
    const row=cb.closest('tr');
    if(row && row.style.display!=='none'){ cb.checked=isChecked; row.classList.toggle('selected', isChecked); }
  });
  updateAddButton();
}

// ---- Filtering ----
function filterProducts(){
  const searchTerm=(document.getElementById('productSearch')?.value||'').toLowerCase();
  const stockFilter=document.getElementById('stockFilter')?.value||'';
  const categoryFilt=document.getElementById('categoryFilter')?.value||'';
  const rows=document.querySelectorAll('#productsTableBody tr'); let visible=0;

  rows.forEach(row=>{
    const productName=(row.dataset.productName||'').toLowerCase();
    const category=row.dataset.category||'';
    const stockLevel=row.dataset.stockLevel||'';
    const okSearch=!searchTerm || productName.includes(searchTerm);
    const okStock =!stockFilter || stockLevel===stockFilter;
    const okCat   =!categoryFilt || category===categoryFilt;
    const show = okSearch && okStock && okCat;
    row.style.display = show ? '' : 'none';
    if (show) visible++; else { const cb=row.querySelector('.product-checkbox'); if (cb) cb.checked=false; }
  });

  const cnt=document.getElementById('productCount'); if(cnt) cnt.textContent = `${visible} products`;
  const empty=document.getElementById('emptyState'), table=document.getElementById('productsTable');
  if(empty && table){ if(visible===0){ table.style.display='none'; empty.style.display='block'; } else { table.style.display='table'; empty.style.display='none'; } }
  updateAddButton();
}
function clearAllFilters(e){
  if(e) e.preventDefault();
  const s=document.getElementById('productSearch'), st=document.getElementById('stockFilter'), c=document.getElementById('categoryFilter');
  if(s) s.value=''; if(st) st.value=''; if(c) c.value='';
  const selectAll=document.getElementById('selectAll'); if(selectAll) selectAll.checked=false;
  document.querySelectorAll('.product-checkbox').forEach(cb=>{ cb.checked=false; cb.closest('tr')?.classList.remove('selected'); });
  filterProducts();
}

// ---- Branch product loading (AJAX) ----
function updateProductsForBranch(){
  const branchSelect=document.querySelector('select[name="from_branch_id"]');
  const branchId=branchSelect?.value||'';
  const tbody=document.getElementById('productsTableBody');
  const cntEl=document.getElementById('productCount');
  const emptyState=document.getElementById('emptyState');
  const productsTable=document.getElementById('productsTable');

  if(!branchId){
    if(tbody) tbody.innerHTML='<tr><td colspan="4" style="text-align:center;color:var(--text-secondary);">Please select a branch to see available products</td></tr>';
    if(cntEl) cntEl.textContent='0 products';
    if(emptyState) emptyState.style.display='none';
    if(productsTable) productsTable.style.display='table';
    updateAddButton(); return;
  }
  if(tbody){
    tbody.innerHTML='<tr><td colspan="4" style="text-align:center;color:var(--text-secondary);"><div class="loading" style="margin-right:8px;"></div>Loading products...</td></tr>';
  }
  if(emptyState) emptyState.style.display='none';
  if(productsTable) productsTable.style.display='table';

  const formData=new FormData();
  formData.append('branch_id', String(branchId));
  formData.append('tenant_id', '<?= (int)$tenantId ?>');

  fetch('/controllers/admin/stockflow/get_branch_products.php', { method:'POST', body:formData })
    .then(r=>{ if(!r.ok) throw new Error(`HTTP ${r.status}: ${r.statusText}`); return r.text(); })
    .then(text=>{
      let data=null; try{ data=JSON.parse(text); }catch(e){ throw new Error('Invalid JSON from server'); }
      if(data.success && Array.isArray(data.products)){
        updateProductsTable(data.products);
        if(cntEl) cntEl.textContent = `${data.products.length} products`;
      } else {
        if(tbody) tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:var(--danger);">Error: ${escapeHtml(data.error || 'Unknown error')}</td></tr>`;
        if(cntEl) cntEl.textContent='0 products';
      }
    })
    .catch(err=>{
      if(tbody) tbody.innerHTML = `<tr><td colspan="4" style="text-align:center;color:var(--danger);">Network error: ${escapeHtml(err.message)}</td></tr>`;
      if(cntEl) cntEl.textContent='0 products';
    });
}

// ---- Update table ----
function updateProductsTable(products){
  const tbody=document.getElementById('productsTableBody');
  const emptyState=document.getElementById('emptyState');
  const productsTable=document.getElementById('productsTable');
  if(!tbody) return;

  if(!products.length){
    tbody.innerHTML='<tr><td colspan="4" style="text-align:center;color:var(--text-secondary);">No products available in this branch</td></tr>';
    if(emptyState) emptyState.style.display='block';
    if(productsTable) productsTable.style.display='none';
    updateAddButton(); return;
  }

  if(emptyState) emptyState.style.display='none';
  if(productsTable) productsTable.style.display='table';

  tbody.innerHTML = products.map(p=>{
    const available = parseFloat(p.available_stock || 0);
    const level = available <= 0 ? 'none' : (available < 10 ? 'low' : 'available');
    const badgeClass = level === 'none' ? 'stock-none' : (level === 'low' ? 'stock-low' : 'stock-available');
    return `
      <tr data-product-id="${parseInt(p.product_id)}"
          data-product-name="${escapeHtml(p.product_name)}"
          data-category="${escapeHtml(p.category_name || '')}"
          data-stock-level="${level}"
          data-available="${available}">
        <td>
          <input type="checkbox" class="select-checkbox product-checkbox" value="${parseInt(p.product_id)}">
        </td>
        <td>
          <div class="product-name">${escapeHtml(p.product_name)}</div>
          <div class="product-details">
            ${p.category_name ? `<span class="category-badge">${escapeHtml(p.category_name)}</span>` : ''}
            ID: ${parseInt(p.product_id)} • Unit: ${escapeHtml(p.inventory_unit || 'piece')}
          </div>
        </td>
        <td><span class="stock-badge ${badgeClass}">${available.toFixed(1)}</span></td>
        <td><input type="number" class="quantity-input" value="1" min="0" step="0.1" max="${available}" data-product-id="${parseInt(p.product_id)}"></td>
      </tr>
    `;
  }).join('');

  attachCheckboxListeners();
  const selectAll=document.getElementById('selectAll'); if(selectAll) selectAll.checked=false;
  filterProducts();
  updateAddButton();
}

// ---- Add selected items ----
function addSelectedToTransfer(){
  const checked=document.querySelectorAll('.product-checkbox:checked');
  if(checked.length===0){ showAlert('Please select at least one product to add','warning'); return; }

  const toAdd=[]; let hasErrors=false;
  checked.forEach(cb=>{
    const row=cb.closest('tr'); if(!row) return;
    const productId=parseInt(cb.value);
    const qInput=row.querySelector('.quantity-input');
    const qty=parseFloat(qInput ? qInput.value : '0') || 0;
    const productName=row.dataset.productName || 'Unknown Product';
    const available=parseFloat(row.dataset.available || '0');

    if(qty<=0){ showAlert(`Please enter a quantity greater than 0 for ${productName}`,'warning'); hasErrors=true; return; }
    if(qty>available){ showAlert(`Quantity for ${productName} (${qty}) exceeds available stock (${available})`,'warning'); hasErrors=true; return; }

    const idx=transferItems.findIndex(it => parseInt(it.product_id || it.id) === productId);
    if(idx >= 0){
      transferItems[idx].quantity_requested = qty;
      transferItems[idx].quantity = qty;
    } else {
      toAdd.push({ product_id: productId, product_name: productName, quantity_requested: qty, quantity: qty });
    }
  });
  if(hasErrors) return;
  if(toAdd.length) transferItems.push(...toAdd);
  updateTransferItemsDisplay();
  updateSummary();
  clearSelections();
  showAlert(`Successfully processed ${checked.length} item(s)`);
}
function clearSelections(){
  document.querySelectorAll('.product-checkbox:checked').forEach(cb=>{ cb.checked=false; cb.closest('tr')?.classList.remove('selected'); });
  const selectAll=document.getElementById('selectAll'); if(selectAll) selectAll.checked=false;
  updateAddButton();
}
function updateTransferItemsDisplay(){
  const c=document.getElementById('transferItemsList'); if(!c) return;
  if(!transferItems.length){
    c.innerHTML = `
      <div class="empty-state">
        <div class="empty-icon">📦</div>
        <div class="empty-title">No items selected</div>
        <div>Select products from the table to add to transfer</div>
      </div>`;
    return;
  }
  c.innerHTML = transferItems.map((it,idx)=>{
    const q = it.quantity_requested ?? it.quantity ?? 0;
    return `
      <div class="transfer-item" data-index="${idx}">
        <div class="transfer-item-name">${escapeHtml(it.product_name || 'Unknown Product')}</div>
        <div class="transfer-item-qty">${parseFloat(q).toFixed(1)}</div>
        ${canEdit ? `<div class="remove-transfer-item" onclick="removeTransferItemByIndex(${idx})">×</div>` : ''}
      </div>
    `;
  }).join('');
}
function removeTransferItemByIndex(i){
  if(i>=0 && i<transferItems.length){
    const name=transferItems[i].product_name || 'item';
    transferItems.splice(i,1);
    updateTransferItemsDisplay(); updateSummary();
    showAlert(`Removed ${name} from transfer`);
  }
}
function removeTransferItem(itemId){
  if(!canEdit) return;
  if(!confirm('Remove this item from the transfer?')) return;
  const fd=new FormData(); fd.append('action','remove_item'); fd.append('item_id', itemId);
  fetch('/controllers/admin/stockflow/manage_transfer.php', { method:'POST', body:fd })
    .then(r=>r.json()).then(d=>{ if(d.ok){ location.reload(); } else { showAlert(d.error || 'Failed to remove item','error'); } })
    .catch(e=>showAlert('Network error: '+e.message,'error'));
}

// ---- Summary ----
function updateSummary(){
  const totalItems=transferItems.length;
  const totalQty=transferItems.reduce((acc,it)=> acc + parseFloat(it.quantity_requested ?? it.quantity ?? 0), 0);
  const ti=document.getElementById('totalItems'); const tq=document.getElementById('totalQuantity');
  if(ti) ti.textContent = String(totalItems);
  if(tq) tq.textContent = totalQty.toFixed(1);
}

// ---- Save / Ship / Receive / Cancel ----
async function saveTransfer(){
  const fromSel=document.querySelector('select[name="from_branch_id"]');
  const toSel=document.querySelector('select[name="to_branch_id"]');
  if(!fromSel?.value){ showAlert('Please select a source branch','error'); return; }
  if(!toSel?.value){ showAlert('Please select a destination branch','error'); return; }
  if(fromSel.value===toSel.value){ showAlert('Source and destination branches cannot be the same','error'); return; }
  if(!transferItems.length){ showAlert('Please add at least one item to the transfer','error'); return; }

  const form=document.getElementById('transferForm'); if(!form){ showAlert('Form not found','error'); return; }
  const fd=new FormData(form);
  fd.append('transfer_items', JSON.stringify(transferItems));
  fd.append('action', isEditMode ? 'update_transfer' : 'create_transfer');

  const btn=document.getElementById('saveBtn'); const original=btn.innerHTML;
  btn.innerHTML='<div class="loading"></div> Saving...'; btn.disabled=true;
  try{
    const resp=await fetch('/controllers/admin/stockflow/manage_transfer.php',{ method:'POST', body:fd });
    if(!resp.ok) throw new Error(`HTTP ${resp.status}`);
    const data=await resp.json();
    if(data.ok || data.success){
      if(!isEditMode && (data.data?.transfer_id || data.transfer_id)){
        const id=data.data?.transfer_id || data.transfer_id;
        showAlert('Transfer created successfully with PENDING status');
        setTimeout(()=>{ window.location.href = `/views/admin/stockflow/transfer.php?id=${id}`; }, 1200);
      } else {
        showAlert(data.message || 'Transfer saved successfully');
        setTimeout(()=>location.reload(), 1200);
      }
    } else {
      showAlert(data.error || data.message || 'Failed to save transfer','error');
    }
  }catch(e){ showAlert('Network error: '+e.message,'error'); }
  finally{ btn.innerHTML=original; btn.disabled=false; }
}
async function shipTransfer(){
  if(!confirm('Ship this transfer? This will deduct stock from the source branch.')) return;
  const fd=new FormData(); fd.append('action','ship_transfer'); fd.append('transfer_id', transferId);
  const d=await (await fetch('/controllers/admin/stockflow/manage_transfer.php',{ method:'POST', body:fd })).json();
  if(d.ok){ showAlert('Transfer shipped successfully'); setTimeout(()=>location.reload(), 900); } else { showAlert(d.error || 'Failed to ship','error'); }
}
async function receiveTransfer(){
  if(!confirm('Receive this transfer? This will add stock to the destination branch.')) return;
  const fd=new FormData(); fd.append('action','receive_transfer'); fd.append('transfer_id', transferId);
  const d=await (await fetch('/controllers/admin/stockflow/manage_transfer.php',{ method:'POST', body:fd })).json();
  if(d.ok){ showAlert('Transfer received successfully'); setTimeout(()=>location.reload(), 900); } else { showAlert(d.error || 'Failed to receive','error'); }
}
async function cancelTransfer(){
  const reason=prompt('Please provide a reason for cancellation:'); if(!reason) return;
  const fd=new FormData(); fd.append('action','cancel_transfer'); fd.append('transfer_id', transferId); fd.append('reason', reason);
  const d=await (await fetch('/controllers/admin/stockflow/manage_transfer.php',{ method:'POST', body:fd })).json();
  if(d.ok){ showAlert('Transfer cancelled'); setTimeout(()=>location.reload(), 900); } else { showAlert(d.error || 'Failed to cancel','error'); }
}

// ---- Init ----
function initializeTransferPage(){
  updateSummary(); updateAddButton();
  attachCheckboxListeners();

  const selectAll=document.getElementById('selectAll');
  if(selectAll) selectAll.addEventListener('change', toggleSelectAll);

  const fromBranchSelect=document.querySelector('select[name="from_branch_id"]');
  if(fromBranchSelect){
    fromBranchSelect.addEventListener('change', updateProductsForBranch);
    if(fromBranchSelect.value){ updateProductsForBranch(); }
  }

  document.getElementById('productSearch')?.addEventListener('input', filterProducts);
  document.getElementById('stockFilter')?.addEventListener('change', filterProducts);
  document.getElementById('categoryFilter')?.addEventListener('change', filterProducts);
  document.querySelector('.clear-filters')?.addEventListener('click', clearAllFilters);

  document.getElementById('saveBtn')?.addEventListener('click', saveTransfer);
  document.getElementById('shipBtn')?.addEventListener('click', shipTransfer);
  document.getElementById('receiveBtn')?.addEventListener('click', receiveTransfer);
  document.getElementById('cancelBtn')?.addEventListener('click', cancelTransfer);
  document.getElementById('addSelectedBtn')?.addEventListener('click', addSelectedToTransfer);
}
document.addEventListener('DOMContentLoaded', initializeTransferPage);
</script>
</body>
</html>