<?php
declare(strict_types=1);
/**
 * /public_html/views/admin/stockflow/view_transfer.php
 * View Stock Transfer — Read-Only (Microsoft 365 Design)
 * - Clean, consistent UI/UX matching transfer.php sample
 * - Sticky table header, compact info blocks, improved timeline
 * - Client-side Print & Export CSV (no backend changes)
 */

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
  if (!is_file($configPath)) { throw new RuntimeException('Configuration file not found at /config/db.php'); }
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

/* ---------- Helpers ---------- */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** Include first existing file from a list (safe include; non-fatal). */
function include_first_existing(array $candidates): bool {
  foreach ($candidates as $f) {
    if (is_file($f)) { include $f; return true; }
  }
  return false;
}

/* ---------- Current user / tenant ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user && $bootstrap_ok) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);

/* ---------- Transfer ID ---------- */
$transferId = (int)($_GET['id'] ?? 0);
if (!$transferId) { header('Location: /views/admin/stockflow/index.php'); exit; }

/* ---------- Flash ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- Data ---------- */
$transfer = null;
$transferItems = [];
$error_msg = '';

if ($bootstrap_ok) {
  try {
    $pdo = db();

    // Transfer + related info
    $stmt = $pdo->prepare("
      SELECT
        t.*,
        COALESCE(fb.display_name, fb.name) AS from_branch_name,
        COALESCE(tb.display_name, tb.name) AS to_branch_name,
        cu.name AS created_by_name,
        su.name AS shipped_by_name,
        ru.name AS received_by_name
      FROM stockflow_transfers t
      LEFT JOIN branches fb ON fb.id = t.from_branch_id AND fb.tenant_id = t.tenant_id
      LEFT JOIN branches tb ON tb.id = t.to_branch_id   AND tb.tenant_id = t.tenant_id
      LEFT JOIN users cu    ON cu.id = t.created_by_user_id AND cu.tenant_id = t.tenant_id
      LEFT JOIN users su    ON su.id = t.shipped_by_user_id AND su.tenant_id = t.tenant_id
      LEFT JOIN users ru    ON ru.id = t.received_by_user_id AND ru.tenant_id = t.tenant_id
      WHERE t.id = :id AND t.tenant_id = :t
    ");
    $stmt->execute([':id' => $transferId, ':t' => $tenantId]);
    $transfer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$transfer) { header('Location: /views/admin/stockflow/index.php'); exit; }

    // Items (read-only view)
    $stmt = $pdo->prepare("
      SELECT
        ti.*,
        p.name_en AS product_name,
        p.inventory_unit,
        c.name_en AS category_name,
        -- Useful computed value
        (COALESCE(ti.quantity_requested,0) * COALESCE(ti.unit_cost,0)) AS item_total_value
      FROM stockflow_transfer_items ti
      LEFT JOIN products p ON p.id = ti.product_id AND p.tenant_id = :t
      LEFT JOIN product_categories pc ON pc.product_id = ti.product_id
      LEFT JOIN categories c ON c.id = pc.category_id AND c.tenant_id = :t2
      WHERE ti.transfer_id = :transfer_id
      ORDER BY p.name_en, ti.id
    ");
    $stmt->execute([
      ':transfer_id' => $transferId,
      ':t'           => $tenantId,
      ':t2'          => $tenantId,
    ]);
    $transferItems = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  } catch (Throwable $e) {
    $error_msg = $e->getMessage();
  }
}

/* ---------- Page meta ---------- */
$active    = 'stockflow';
$pageTitle = 'View Transfer';

/* ---------- Totals ---------- */
$totalItems    = count($transferItems);
$totalRequested= 0.0;
$totalShipped  = 0.0;
$totalReceived = 0.0;
$totalValue    = 0.0;

foreach ($transferItems as $it) {
  $totalRequested += (float)($it['quantity_requested'] ?? 0);
  $totalShipped   += (float)($it['quantity_shipped']   ?? 0);
  $totalReceived  += (float)($it['quantity_received']  ?? 0);
  $totalValue     += (float)($it['item_total_value']   ?? 0);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title><?= h($pageTitle) ?> · Smorll POS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Font -->
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
      --transition:all .12s cubic-bezier(.1,.9,.2,1);
      --radius:6px; --radius-lg:12px;
      --chip-bg:#eef6ff;
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg-primary);font-family:'Inter','Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,Roboto,'Helvetica Neue',sans-serif;color:var(--text-primary);font-size:14px;line-height:1.5}
    h1,h2,h3,h4,h5,h6,p{margin:0}

    .transfer-container{padding:16px;width:100%;max-width:1400px;margin:0 auto}
    @media (max-width:768px){.transfer-container{padding:12px}}

    .page-header{margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;gap:16px;flex-wrap:wrap}
    .page-title{font-size:24px;font-weight:700;letter-spacing:-0.01em;color:var(--text-primary);display:flex;align-items:center;gap:12px}
    .page-subtitle{font-size:13px;color:var(--text-secondary);margin-top:4px}

    .status-badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:16px;font-size:12px;font-weight:600;text-transform:capitalize}
    .status-badge.pending{background:var(--warning-light);color:var(--warning)}
    .status-badge.shipped{background:var(--info-light);color:var(--info)}
    .status-badge.received{background:var(--success-light);color:var(--success)}
    .status-badge.cancelled{background:var(--danger-light);color:var(--danger)}

    .read-only{background:var(--chip-bg);color:var(--primary);padding:6px 10px;border-radius:14px;font-size:11px;font-weight:700;letter-spacing:.3px;text-transform:uppercase}

    .btn{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border:1px solid var(--border);border-radius:var(--radius);font-size:14px;font-weight:500;color:var(--text-secondary);background:var(--card-bg);text-decoration:none;cursor:pointer;transition:var(--transition)}
    .btn:hover{background:var(--hover);color:var(--text-primary);transform:translateY(-1px);box-shadow:var(--shadow-sm)}
    .btn:active{transform:none}
    .btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}
    .btn-primary:hover{background:var(--primary-hover);border-color:var(--primary-hover);color:#fff}
    .btn-icon{width:16px;height:16px}

    .btn-group{display:flex;gap:10px;flex-wrap:wrap}

    .main-grid{display:grid;grid-template-columns:1fr 320px;gap:20px;align-items:start}
    @media (max-width:1024px){.main-grid{grid-template-columns:1fr}}

    .card{background:var(--card-bg);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:var(--shadow-sm);overflow:hidden}
    .card-header{padding:14px 18px;border-bottom:1px solid var(--border);background:var(--bg-secondary);display:flex;align-items:center;justify-content:space-between}
    .card-title{font-size:16px;font-weight:700;letter-spacing:-0.01em}
    .card-body{padding:18px}

    .info-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}
    @media (max-width:640px){.info-grid{grid-template-columns:1fr}}

    .info-item{background:#fff;border:1px solid var(--border);border-radius:10px;padding:12px 14px}
    .info-label{display:block;font-size:11px;font-weight:600;color:var(--text-tertiary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px}
    .info-value{font-size:15px;color:var(--text-primary);font-weight:700}
    .mono{font-family:'SF Mono',Monaco,'Courier New',monospace}

    .items-table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--border);border-radius:10px;overflow:hidden;background:#fff}
    .items-table thead{background:var(--bg-secondary);position:sticky;top:0;z-index:5}
    .items-table th{padding:12px 14px;text-align:left;font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.6px;border-bottom:1px solid var(--border)}
    .items-table td{padding:14px;border-bottom:1px solid var(--border-light);vertical-align:middle}
    .items-table tbody tr:hover{background:var(--hover)}
    .id-col{width:90px;color:var(--text-tertiary);font-size:12px}
    .qty{text-align:right;font-weight:600}
    .value{text-align:right;font-weight:600}
    .badge{display:inline-block;padding:2px 6px;border-radius:999px;font-size:10px;font-weight:600;background:var(--primary-light);color:var(--primary)}

    .summary-item{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border-light)}
    .summary-item:last-child{border-bottom:none}
    .summary-label{color:var(--text-secondary);font-weight:600}
    .summary-value{font-weight:800}

    .timeline{display:flex;flex-direction:column;gap:14px}
    .tl{display:flex;gap:10px;align-items:flex-start}
    .dot{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid var(--border)}
    .dot.ok{background:var(--success-light);color:var(--success);border-color:var(--success)}
    .dot.warn{background:var(--warning-light);color:var(--warning);border-color:var(--warning)}
    .dot.cancel{background:var(--danger-light);color:var(--danger);border-color:var(--danger)}
    .tl-body{flex:1}
    .tl-title{font-weight:700;margin-bottom:2px}
    .tl-meta{color:var(--text-secondary);font-size:13px;line-height:1.5}

    .alert{padding:12px 14px;border-radius:10px;margin-bottom:12px;font-size:14px;border:1px solid;display:flex;align-items:center;gap:10px}
    .alert.success{background:var(--success-light);border-color:#a7f3d0;color:var(--success)}
    .alert.error{background:var(--danger-light);border-color:#fca5a5;color:var(--danger)}
    .alert.warning{background:var(--warning-light);border-color:#fbbf24;color:var(--warning)}

    /* Print */
    @media print{
      .btn-group, .alert, .page-actions, .read-only {display:none!important}
      .transfer-container{padding:0}
      .card{box-shadow:none;border-color:#ddd}
      .card-header{background:#fff}
      body{background:#fff}
    }
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
      <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 9v4m0 4h.01M3.1 19h17.8L12 3 3.1 19z"/></svg>
      <div><strong>Bootstrap Error</strong><br><?= h($bootstrap_msg) ?></div>
    </div>
  <?php else: ?>

    <?php if ($flash): ?>
      <div class="alert success">
        <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 6L9 17l-5-5"/></svg>
        <?= h($flash) ?>
      </div>
    <?php endif; ?>

    <?php if ($DEBUG && $error_msg): ?>
      <div class="alert error"><strong>Debug:</strong> <?= h($error_msg) ?></div>
    <?php endif; ?>

    <!-- Header -->
    <div class="page-header">
      <div>
        <div class="page-title">
          <?= h($pageTitle) ?>
          <span class="status-badge <?= h($transfer['status']) ?>"><?= h($transfer['status']) ?></span>
          <span class="read-only">Read-Only</span>
        </div>
        <div class="page-subtitle">
          Transfer <?= h($transfer['transfer_number']) ?> • Created <?= date('M j, Y', strtotime($transfer['created_at'])) ?>
        </div>
      </div>
      <div class="btn-group page-actions">
        <?php if (in_array($transfer['status'], ['pending','shipped'], true)): ?>
          <a class="btn" href="/views/admin/stockflow/transfer.php?id=<?= (int)$transferId ?>">
            <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.1 2.1 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
            Edit Transfer
          </a>
        <?php endif; ?>
        <a class="btn" href="/views/admin/stockflow/index.php">
          <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 12H5m7-7-7 7 7 7"/></svg>
          Back
        </a>
        <button class="btn" id="printBtn" type="button">
          <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 9V2h12v7"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><path d="M6 14h12v8H6z"/></svg>
          Print
        </button>
        <button class="btn" id="csvBtn" type="button">
          <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16"/><path d="M14 2v6h6"/><path d="M16 13H8"/><path d="M16 17H8"/></svg>
          Export CSV
        </button>
      </div>
    </div>

    <div class="main-grid">
      <div>
        <!-- Transfer Details -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Transfer Details</h3>
          </div>
          <div class="card-body">
            <div class="info-grid">
              <div class="info-item">
                <span class="info-label">From Branch</span>
                <div class="info-value"><?= h($transfer['from_branch_name'] ?: 'Unknown') ?></div>
              </div>
              <div class="info-item">
                <span class="info-label">To Branch</span>
                <div class="info-value"><?= h($transfer['to_branch_name'] ?: 'Unknown') ?></div>
              </div>
              <div class="info-item">
                <span class="info-label">Transfer Number</span>
                <div class="info-value mono"><?= h($transfer['transfer_number']) ?></div>
              </div>
              <div class="info-item">
                <span class="info-label">Status</span>
                <div class="info-value">
                  <span class="status-badge <?= h($transfer['status']) ?>"><?= ucfirst(h($transfer['status'])) ?></span>
                </div>
              </div>
              <?php if ($transfer['scheduled_date']): ?>
              <div class="info-item">
                <span class="info-label">Scheduled Date</span>
                <div class="info-value"><?= date('M j, Y', strtotime($transfer['scheduled_date'])) ?></div>
              </div>
              <?php endif; ?>
              <div class="info-item">
                <span class="info-label">Priority</span>
                <div class="info-value"><?= h($transfer['priority'] ?: 'Normal') ?></div>
              </div>
            </div>

            <?php if (!empty($transfer['notes'])): ?>
              <div class="info-item" style="margin-top:10px">
                <span class="info-label">Notes</span>
                <div class="info-value" style="font-weight:600"><?= nl2br(h($transfer['notes'])) ?></div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <!-- Transfer Items -->
        <div class="card">
          <div class="card-header">
            <h3 class="card-title">Transfer Items</h3>
          </div>
          <div class="card-body">
            <?php if (!$transferItems): ?>
              <div class="alert warning">
                <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 8v4m0 4h.01"/></svg>
                No items in this transfer.
              </div>
            <?php else: ?>
              <div style="max-height:520px;overflow:auto;border-radius:10px">
                <table class="items-table" id="itemsTable">
                  <thead>
                    <tr>
                      <th class="id-col">Product ID</th>
                      <th>Product</th>
                      <th style="width:120px;text-align:right;">Requested</th>
                      <?php if (in_array($transfer['status'], ['shipped','received'], true)): ?>
                        <th style="width:120px;text-align:right;">Shipped</th>
                      <?php endif; ?>
                      <?php if ($transfer['status'] === 'received'): ?>
                        <th style="width:120px;text-align:right;">Received</th>
                      <?php endif; ?>
                      <th style="width:140px;text-align:right;">Value</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($transferItems as $item): ?>
                      <tr>
                        <td class="id-col mono">#<?= (int)$item['product_id'] ?></td>
                        <td>
                          <div style="font-weight:700"><?= h($item['product_name'] ?: 'Unknown Product') ?></div>
                          <div style="color:var(--text-secondary);font-size:12px;margin-top:2px">
                            <?php if (!empty($item['category_name'])): ?>
                              <span class="badge"><?= h($item['category_name']) ?></span>
                            <?php endif; ?>
                            <span style="margin-left:6px">Unit: <?= h($item['inventory_unit'] ?: 'piece') ?></span>
                          </div>
                        </td>
                        <td class="qty"><?= number_format((float)($item['quantity_requested'] ?? 0), 1) ?></td>
                        <?php if (in_array($transfer['status'], ['shipped','received'], true)): ?>
                          <td class="qty"><?= number_format((float)($item['quantity_shipped'] ?? $item['quantity_requested'] ?? 0), 1) ?></td>
                        <?php endif; ?>
                        <?php if ($transfer['status'] === 'received'): ?>
                          <td class="qty"><?= number_format((float)($item['quantity_received'] ?? $item['quantity_requested'] ?? 0), 1) ?></td>
                        <?php endif; ?>
                        <td class="value mono"><?= number_format((float)($item['item_total_value'] ?? 0), 2) ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- Sidebar -->
      <div>
        <!-- Summary -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Summary</h3></div>
          <div class="card-body">
            <div class="summary-item">
              <span class="summary-label">Total Items</span>
              <span class="summary-value mono" id="sumItems"><?= (int)$totalItems ?></span>
            </div>
            <div class="summary-item">
              <span class="summary-label">Total Requested</span>
              <span class="summary-value mono" id="sumRequested"><?= number_format($totalRequested, 1) ?></span>
            </div>
            <?php if (in_array($transfer['status'], ['shipped','received'], true)): ?>
            <div class="summary-item">
              <span class="summary-label">Total Shipped</span>
              <span class="summary-value mono" id="sumShipped"><?= number_format($totalShipped, 1) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($transfer['status'] === 'received'): ?>
            <div class="summary-item">
              <span class="summary-label">Total Received</span>
              <span class="summary-value mono" id="sumReceived"><?= number_format($totalReceived, 1) ?></span>
            </div>
            <?php endif; ?>
            <div class="summary-item">
              <span class="summary-label">Total Value</span>
              <span class="summary-value mono" id="sumValue"><?= number_format($totalValue, 2) ?></span>
            </div>
          </div>
        </div>

        <!-- Timeline -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Transfer Timeline</h3></div>
          <div class="card-body">
            <div class="timeline">
              <!-- Created -->
              <div class="tl">
                <div class="dot ok">
                  <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
                </div>
                <div class="tl-body">
                  <div class="tl-title">Transfer Created</div>
                  <div class="tl-meta">
                    <?= date('M j, Y g:i A', strtotime($transfer['created_at'])) ?>
                    <?php if (!empty($transfer['created_by_name'])): ?>
                      • by <?= h($transfer['created_by_name']) ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>

              <!-- Shipped -->
              <?php if (!empty($transfer['shipped_at'])): ?>
              <div class="tl">
                <div class="dot ok">
                  <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 7l-8-4-8 4m16 0l-8 4-8-4m16 0v10l-8 4-8-4V7"/></svg>
                </div>
                <div class="tl-body">
                  <div class="tl-title">Transfer Shipped</div>
                  <div class="tl-meta">
                    <?= date('M j, Y g:i A', strtotime($transfer['shipped_at'])) ?>
                    <?php if (!empty($transfer['shipped_by_name'])): ?>
                      • by <?= h($transfer['shipped_by_name']) ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php elseif ($transfer['status'] === 'shipped'): ?>
              <div class="tl">
                <div class="dot warn">
                  <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="3"/></svg>
                </div>
                <div class="tl-body">
                  <div class="tl-title">In Transit</div>
                  <div class="tl-meta">Waiting to be received</div>
                </div>
              </div>
              <?php endif; ?>

              <!-- Received -->
              <?php if (!empty($transfer['received_at'])): ?>
              <div class="tl">
                <div class="dot ok">
                  <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M20 6L9 17l-5-5"/></svg>
                </div>
                <div class="tl-body">
                  <div class="tl-title">Transfer Received</div>
                  <div class="tl-meta">
                    <?= date('M j, Y g:i A', strtotime($transfer['received_at'])) ?>
                    <?php if (!empty($transfer['received_by_name'])): ?>
                      • by <?= h($transfer['received_by_name']) ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <!-- Cancelled -->
              <?php if (!empty($transfer['cancelled_at'])): ?>
              <div class="tl">
                <div class="dot cancel">
                  <svg class="btn-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </div>
                <div class="tl-body">
                  <div class="tl-title">Transfer Cancelled</div>
                  <div class="tl-meta">
                    <?= date('M j, Y g:i A', strtotime($transfer['cancelled_at'])) ?>
                    <?php if (!empty($transfer['cancellation_reason'])): ?>
                      • Reason: <?= h($transfer['cancellation_reason']) ?>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- Meta -->
        <div class="card">
          <div class="card-header"><h3 class="card-title">Transfer Info</h3></div>
          <div class="card-body">
            <div style="font-size:13px;color:var(--text-secondary);line-height:1.8;background:var(--bg-secondary);padding:14px;border-radius:10px">
              <div><strong style="color:var(--text-primary)">ID:</strong> <span class="mono" style="background:#fff;padding:2px 6px;border:1px solid var(--border);border-radius:6px"><?= (int)$transfer['id'] ?></span></div>
              <div><strong style="color:var(--text-primary)">Created:</strong> <?= date('M j, Y g:i A', strtotime($transfer['created_at'])) ?></div>
              <?php if (!empty($transfer['updated_at']) && $transfer['updated_at'] !== $transfer['created_at']): ?>
                <div><strong style="color:var(--text-primary)">Last Updated:</strong> <?= date('M j, Y g:i A', strtotime($transfer['updated_at'])) ?></div>
              <?php endif; ?>
              <?php if (!empty($transfer['scheduled_date'])): ?>
                <div><strong style="color:var(--text-primary)">Scheduled:</strong> <?= date('M j, Y', strtotime($transfer['scheduled_date'])) ?></div>
              <?php endif; ?>
            </div>
          </div>
        </div>

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
if (!$navCloseIncluded) { echo "<!-- nav close partial not found -->"; }
?>

<script>
(function(){
  const printBtn = document.getElementById('printBtn');
  if (printBtn) printBtn.addEventListener('click', ()=>window.print());

  const csvBtn = document.getElementById('csvBtn');
  if (csvBtn) csvBtn.addEventListener('click', exportCSV);

  function exportCSV(){
    const table = document.getElementById('itemsTable');
    if (!table) { alert('No items to export.'); return; }

    const rows = [];
    // Header
    const headers = Array.from(table.querySelectorAll('thead th')).map(th => th.textContent.trim());
    rows.push(headers);

    // Body
    table.querySelectorAll('tbody tr').forEach(tr=>{
      const cols = Array.from(tr.querySelectorAll('td')).map(td => td.innerText.replace(/\s+/g,' ').trim());
      rows.push(cols);
    });

    const csv = rows.map(r => r.map(escapeCSV).join(',')).join('\n');
    const blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'transfer_<?= (int)$transferId ?>.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }

  function escapeCSV(val){
    if (val == null) return '';
    const s = String(val);
    return /[",\n]/.test(s) ? '"' + s.replace(/"/g,'""') + '"' : s;
  }
})();
</script>
</body>
</html>