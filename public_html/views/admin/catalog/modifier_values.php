<?php
declare(strict_types=1);
/**
 * Modifier Values (list for a group)
 * Path: /public_html/views/admin/catalog/modifier_values.php
 * - Requires group_id
 * - Shows values with edit/delete; link to add
 */

/* Debug */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* Bootstrap */
if (!function_exists('db')) {
  $BOOT_OK = false; $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
  foreach ([__DIR__ . '/../../config/db.php', __DIR__ . '/../../../config/db.php', dirname(__DIR__,3).'/config/db.php', ($docroot?$docroot.'/config/db.php':''), ($docroot?$docroot.'/public_html/config/db.php':'')] as $cand) { if ($cand && is_file($cand)) { require_once $cand; $BOOT_OK = true; break; } }
  if (!$BOOT_OK) { http_response_code(500); echo 'Configuration file not found: /config/db.php'; exit; }
}
if (function_exists('use_backend_session')) use_backend_session(); else if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!function_exists('auth_require_login')) {
  $docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
  foreach ([__DIR__ . '/../../middleware/auth_login.php', __DIR__ . '/../../../middleware/auth_login.php', dirname(__DIR__,3).'/middleware/auth_login.php', ($docroot?$docroot.'/middleware/auth_login.php':''), ($docroot?$docroot.'/public_html/middleware/auth_login.php':'')] as $cand) { if ($cand && is_file($cand)) { require_once $cand; break; } }
}
if (function_exists('auth_require_login')) auth_require_login();

/* Context */
$pdo = db();
$user = $_SESSION['user'] ?? null;
$tenantId = (int)($user['tenant_id'] ?? ($_SESSION['tenant_id'] ?? 0));
if ($tenantId <= 0) { http_response_code(500); echo 'Tenant context missing.'; exit; }

$groupId = (int)($_GET['group_id'] ?? 0);
if ($groupId <= 0) { http_response_code(400); echo 'Missing group_id.'; exit; }

/* Verify group belongs to tenant */
$gs = $pdo->prepare("SELECT id, tenant_id, name FROM variation_groups WHERE id=:id AND tenant_id=:t");
$gs->execute([':id'=>$groupId, ':t'=>$tenantId]);
$group = $gs->fetch(PDO::FETCH_ASSOC);
if (!$group) { http_response_code(404); echo 'Modifier group not found.'; exit; }

/* Fetch values */
$stmt = $pdo->prepare("SELECT id, value_en, value_ar, price_delta, is_active, pos_visible, sort_order
                       FROM variation_values
                       WHERE group_id=:gid
                       ORDER BY sort_order ASC, value_en ASC");
$stmt->execute([':gid'=>$groupId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
$active = 'modifiers';
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Modifier Values · <?= h((string)$group['name']) ?> · Smorll POS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      /* Microsoft 365 Color Palette */
      --bg-primary: #faf9f8;
      --bg-secondary: #f3f2f1;
      --card-bg: #ffffff;
      --text-primary: #323130;
      --text-secondary: #605e5c;
      --text-tertiary: #8a8886;
      --primary: #0078d4;
      --primary-hover: #106ebe;
      --primary-light: #deecf9;
      --primary-lighter: #f3f9fd;
      --border: #edebe9;
      --border-light: #f8f6f4;
      --hover: #f3f2f1;
      --success: #107c10;
      --success-light: #dff6dd;
      --warning: #ff8c00;
      --warning-light: #fff4ce;
      --danger: #d13438;
      --danger-light: #fdf2f2;
      --shadow-sm: 0 1px 2px rgba(0,0,0,.04), 0 1px 1px rgba(0,0,0,.06);
      --shadow-md: 0 4px 8px rgba(0,0,0,.04), 0 1px 3px rgba(0,0,0,.06);
      --shadow-lg: 0 8px 16px rgba(0,0,0,.06), 0 2px 4px rgba(0,0,0,.08);
      --transition: all .1s cubic-bezier(.1,.9,.2,1);
      --radius: 4px;
      --radius-lg: 8px;
    }

    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      background: var(--bg-primary);
      font-family: 'Inter', 'Segoe UI', system-ui, -apple-system, BlinkMacSystemFont, Roboto, 'Helvetica Neue', sans-serif;
      color: var(--text-primary);
      font-size: 14px;
      line-height: 1.4;
    }

    /* Reset default margins */
    h1, h2, h3, h4, h5, h6, p {
      margin: 0;
    }

    /* Page Container */
    .page-container {
      padding: 12px;
    }

    @media (max-width: 768px) {
      .page-container {
        padding: 8px;
      }
    }

    /* Breadcrumb */
    .breadcrumb {
      display: flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 12px;
      font-size: 13px;
    }

    .breadcrumb-link {
      color: var(--text-secondary);
      text-decoration: none;
      transition: var(--transition);
    }

    .breadcrumb-link:hover {
      color: var(--primary);
    }

    .breadcrumb-separator {
      color: var(--text-tertiary);
    }

    .breadcrumb-current {
      color: var(--text-primary);
      font-weight: 500;
    }

    /* Page Header */
    .page-header {
      margin-bottom: 16px;
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    .page-header-content {
      flex: 1;
    }

    .page-title {
      font-size: 20px;
      font-weight: 600;
      color: var(--text-primary);
      margin: 0 0 2px 0;
    }

    .page-subtitle {
      font-size: 13px;
      color: var(--text-secondary);
      margin: 0;
    }

    .page-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    /* Main Card */
    .card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
    }

    .card:hover {
      box-shadow: var(--shadow-md);
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      border: 1px solid transparent;
      border-radius: var(--radius);
      font-size: 13px;
      font-weight: 500;
      text-decoration: none;
      cursor: pointer;
      transition: var(--transition);
      outline: none;
      justify-content: center;
    }

    .btn:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
    }

    .btn:active {
      transform: translateY(0);
    }

    .btn-primary {
      background: var(--primary);
      color: white;
      border-color: var(--primary);
    }

    .btn-primary:hover {
      background: var(--primary-hover);
      border-color: var(--primary-hover);
      color: white;
      text-decoration: none;
    }

    .btn-secondary {
      background: var(--card-bg);
      color: var(--text-primary);
      border-color: var(--border);
    }

    .btn-secondary:hover {
      background: var(--hover);
      color: var(--text-primary);
      text-decoration: none;
    }

    .btn-ghost {
      background: transparent;
      color: var(--text-secondary);
      border-color: transparent;
    }

    .btn-ghost:hover {
      background: var(--hover);
      color: var(--text-primary);
      text-decoration: none;
      box-shadow: none;
    }

    .btn-danger {
      background: var(--card-bg);
      color: var(--danger);
      border-color: var(--border);
    }

    .btn-danger:hover {
      background: var(--danger-light);
      color: var(--danger);
      border-color: #fca5a5;
      text-decoration: none;
    }

    .btn-sm {
      padding: 6px 12px;
      font-size: 12px;
    }

    /* Table */
    .table-container {
      overflow: auto;
      max-height: calc(100vh - 400px);
    }

    .table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
    }

    .table th,
    .table td {
      padding: 12px 16px;
      text-align: left;
      vertical-align: middle;
      border-bottom: 1px solid var(--border-light);
    }

    .table th {
      background: var(--bg-secondary);
      color: var(--text-secondary);
      font-weight: 600;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      position: sticky;
      top: 0;
      z-index: 10;
    }

    .table td {
      font-size: 13px;
      background: var(--card-bg);
    }

    .table tbody tr {
      transition: var(--transition);
    }

    .table tbody tr:hover {
      background: var(--hover);
    }

    .table tbody tr:hover td {
      background: var(--hover);
    }

    .table tbody tr:last-child td {
      border-bottom: none;
    }

    /* ID Column */
    .id-column {
      width: 60px;
      color: var(--text-tertiary);
      font-family: 'SF Mono', Monaco, 'Courier New', monospace;
      font-size: 12px;
    }

    /* Value Names */
    .value-name {
      font-weight: 500;
      color: var(--text-primary);
      margin-bottom: 2px;
    }

    .value-arabic {
      color: var(--text-secondary);
      font-size: 12px;
      direction: rtl;
    }

    /* Price */
    .price {
      font-family: 'SF Mono', Monaco, 'Courier New', monospace;
      font-weight: 500;
      color: var(--success);
    }

    .price.negative {
      color: var(--danger);
    }

    /* Status Badges */
    .badge {
      display: inline-flex;
      align-items: center;
      padding: 3px 8px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 500;
      text-transform: capitalize;
    }

    .badge-success {
      background: var(--success-light);
      color: var(--success);
    }

    .badge-danger {
      background: var(--danger-light);
      color: var(--danger);
    }

    .badge-info {
      background: var(--primary-light);
      color: var(--primary);
    }

    .badge-default {
      background: var(--bg-secondary);
      color: var(--text-secondary);
    }

    /* Action Buttons */
    .action-buttons {
      display: flex;
      gap: 6px;
    }

    .inline-form {
      display: inline;
    }

    /* Empty State */
    .empty-state {
      padding: 48px 20px;
      text-align: center;
      color: var(--text-secondary);
    }

    .empty-icon {
      width: 64px;
      height: 64px;
      margin: 0 auto 16px;
      background: var(--bg-secondary);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 24px;
    }

    .empty-title {
      font-size: 16px;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 6px;
    }

    .empty-text {
      font-size: 13px;
      margin-bottom: 20px;
      max-width: 300px;
      margin-left: auto;
      margin-right: auto;
    }

    /* Back Section */
    .back-section {
      margin-top: 16px;
    }

    /* Responsive */
    @media (max-width: 768px) {
      .page-header {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
      }
      
      .page-actions {
        justify-content: stretch;
      }
      
      .page-actions .btn {
        flex: 1;
      }
      
      .action-buttons {
        flex-direction: column;
        width: 100%;
      }
    }
  </style>
</head>
<body>

<?php
$__docroot = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim((string)$_SERVER['DOCUMENT_ROOT'], '/') : '';
foreach ([ __DIR__ . '/../partials/admin_nav.php', __DIR__ . '/../../partials/admin_nav.php', ($__docroot?$__docroot.'/views/partials/admin_nav.php':''), ($__docroot?$__docroot.'/public_html/views/partials/admin_nav.php':'') ] as $__nav) { if ($__nav && is_file($__nav)) { include $__nav; break; } }
?>

<div class="page-container">
  <!-- Breadcrumb -->
  <div class="breadcrumb">
    <a href="/views/admin/catalog/modifiers.php" class="breadcrumb-link">Modifiers</a>
    <span class="breadcrumb-separator">/</span>
    <span class="breadcrumb-current"><?= h((string)$group['name']) ?> Values</span>
  </div>

  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-content">
      <h1 class="page-title">Modifier Values</h1>
      <p class="page-subtitle">Manage values for <?= h((string)$group['name']) ?> modifier</p>
    </div>
    <div class="page-actions">
      <a href="/views/admin/catalog/modifier_edit.php?id=<?= (int)$group['id'] ?>" class="btn btn-secondary">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
          <path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
        </svg>
        Edit Group
      </a>
      <a href="/views/admin/catalog/modifier_value_new.php?group_id=<?= (int)$group['id'] ?>" class="btn btn-primary">
        <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M12 5v14m7-7H5"/>
        </svg>
        Add Value
      </a>
    </div>
  </div>

  <!-- Main Card -->
  <div class="card">
    <div class="table-container">
      <?php if (!$rows): ?>
        <div class="empty-state">
          <div class="empty-icon">📝</div>
          <div class="empty-title">No values yet</div>
          <div class="empty-text">Add values that customers can choose from for this modifier</div>
          <a href="/views/admin/catalog/modifier_value_new.php?group_id=<?= (int)$group['id'] ?>" class="btn btn-primary">
            <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M12 5v14m7-7H5"/>
            </svg>
            Add First Value
          </a>
        </div>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th class="id-column">ID</th>
              <th style="width: 25%">Value</th>
              <th style="width: 15%">Price Delta</th>
              <th style="width: 12%">Status</th>
              <th style="width: 12%">Visibility</th>
              <th style="width: 10%">Sort</th>
              <th style="width: 140px">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="id-column">#<?= (int)$r['id'] ?></td>
                <td>
                  <div class="value-name"><?= h((string)$r['value_en']) ?></div>
                  <?php if (!empty($r['value_ar'])): ?>
                    <div class="value-arabic"><?= h((string)$r['value_ar']) ?></div>
                  <?php endif; ?>
                </td>
                <td>
                  <?php 
                    $delta = (float)$r['price_delta'];
                    if ($delta == 0): ?>
                      <span class="price">+0.00</span>
                    <?php elseif ($delta > 0): ?>
                      <span class="price">+<?= number_format($delta, 2) ?></span>
                    <?php else: ?>
                      <span class="price negative"><?= number_format($delta, 2) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                  <?php if ((int)$r['is_active'] === 1): ?>
                    <span class="badge badge-success">Active</span>
                  <?php else: ?>
                    <span class="badge badge-danger">Inactive</span>
                  <?php endif; ?>
                </td>
                <td>
                  <?php if ((int)$r['pos_visible'] === 1): ?>
                    <span class="badge badge-info">Visible</span>
                  <?php else: ?>
                    <span class="badge badge-default">Hidden</span>
                  <?php endif; ?>
                </td>
                <td><?= (int)$r['sort_order'] ?></td>
                <td>
                  <div class="action-buttons">
                    <a href="/views/admin/catalog/modifier_value_edit.php?group_id=<?= (int)$group['id'] ?>&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-secondary">
                      Edit
                    </a>
                    <form action="/controllers/admin/modifier_values_delete.php" method="post" class="inline-form" onsubmit="return confirm('Delete this value? This action cannot be undone.');">
                      <input type="hidden" name="group_id" value="<?= (int)$group['id'] ?>">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- Back Section -->
  <div class="back-section">
    <a href="/views/admin/catalog/modifiers.php" class="btn btn-ghost">
      <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M19 12H5m7-7l-7 7 7 7"/>
      </svg>
      Back to Modifiers
    </a>
  </div>
</div>
</body>
</html>