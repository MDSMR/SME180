<?php
// /views/admin/dashboard.php — Modern admin dashboard with audit logging
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

$bootstrap_warning = '';
try {
  if (!function_exists('db')) { throw new RuntimeException('db() is missing in /config/db.php'); }
  if (!function_exists('use_backend_session')) { throw new RuntimeException('use_backend_session() is missing in /config/db.php'); }
  use_backend_session();
  
  // Check session timeout
  if (function_exists('check_session_timeout')) {
    check_session_timeout();
  }
} catch (Throwable $e) { $bootstrap_warning = $e->getMessage(); }

$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);
$userName = $user['name'] ?? $user['username'] ?? 'User';

// Calculate initials for user avatar
$initials = '';
$nameParts = explode(' ', trim($userName));
if (count($nameParts) >= 2) {
    $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1], 0, 1));
} else {
    $initials = strtoupper(substr($userName, 0, 2));
}

/* Enhanced KPIs and Stats */
$db_ok = false; $db_msg = ''; 
$stats = [
    'users' => null,
    'open_orders' => null,
    'products' => null,
    'categories' => null,
    'today_sales' => null,
    'weekly_sales' => null,
    'recent_orders' => [],
    'low_stock' => [],
    'top_products' => [],
    'recent_logins' => 0,
    'today_activities' => 0
];

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

  // Basic counts
  $st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE tenant_id=:t AND disabled_at IS NULL");
  $st->execute([':t'=>$tenantId]); 
  $stats['users'] = (int)$st->fetchColumn();

  $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE tenant_id=:t AND DATE(created_at)=CURDATE() AND status IN ('open','held','sent','preparing','ready','served')");
  $st->execute([':t'=>$tenantId]); 
  $stats['open_orders'] = (int)$st->fetchColumn();

  // Try to get product stats (table may not exist yet)
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM products WHERE tenant_id=:t AND deleted_at IS NULL");
    $st->execute([':t'=>$tenantId]); 
    $stats['products'] = (int)$st->fetchColumn();
  } catch (Exception $e) { $stats['products'] = 0; }

  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE tenant_id=:t AND deleted_at IS NULL");
    $st->execute([':t'=>$tenantId]); 
    $stats['categories'] = (int)$st->fetchColumn();
  } catch (Exception $e) { $stats['categories'] = 0; }

  // Sales data (mock for now if orders table doesn't have proper structure)
  try {
    $st = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE tenant_id=:t AND DATE(created_at)=CURDATE() AND status='completed'");
    $st->execute([':t'=>$tenantId]); 
    $stats['today_sales'] = (float)$st->fetchColumn();
  } catch (Exception $e) { $stats['today_sales'] = 0; }

  try {
    $st = $pdo->prepare("SELECT COALESCE(SUM(total), 0) FROM orders WHERE tenant_id=:t AND YEARWEEK(created_at)=YEARWEEK(NOW()) AND status='completed'");
    $st->execute([':t'=>$tenantId]); 
    $stats['weekly_sales'] = (float)$st->fetchColumn();
  } catch (Exception $e) { $stats['weekly_sales'] = 0; }

  // Recent orders (limit 5)
  try {
    $st = $pdo->prepare("SELECT id, customer_name, total, status, created_at FROM orders WHERE tenant_id=:t ORDER BY created_at DESC LIMIT 5");
    $st->execute([':t'=>$tenantId]);
    $stats['recent_orders'] = $st->fetchAll(PDO::FETCH_ASSOC);
  } catch (Exception $e) { $stats['recent_orders'] = []; }
  
  // Get today's login count from audit logs
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE tenant_id=:t AND DATE(created_at)=CURDATE() AND action='login'");
    $st->execute([':t'=>$tenantId]); 
    $stats['recent_logins'] = (int)$st->fetchColumn();
  } catch (Exception $e) { $stats['recent_logins'] = 0; }
  
  // Get today's activity count from audit logs
  try {
    $st = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE tenant_id=:t AND DATE(created_at)=CURDATE()");
    $st->execute([':t'=>$tenantId]); 
    $stats['today_activities'] = (int)$st->fetchColumn();
  } catch (Exception $e) { $stats['today_activities'] = 0; }

  $db_ok = true;
} catch (Throwable $e) { $db_msg = $e->getMessage(); }

// Current time for greeting
$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

// Calculate session remaining time
$session_remaining = null;
if (isset($_SESSION['last_activity']) && defined('SESSION_TIMEOUT')) {
    $elapsed = time() - $_SESSION['last_activity'];
    $remaining = SESSION_TIMEOUT - $elapsed;
    if ($remaining > 0) {
        $session_remaining = [
            'minutes' => floor($remaining / 60),
            'seconds' => $remaining % 60
        ];
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Dashboard · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  /* Microsoft 365 Color Palette - matching sidebar */
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
  line-height: 1.5;
}

/* Reset default margins that might cause spacing issues */
h1, h2, h3, h4, h5, h6, p {
  margin: 0;
}

/* Dashboard Layout - properly positioned inside admin-content */
.dashboard-container {
  padding: 20px;
}

@media (max-width: 768px) {
  .dashboard-container {
    padding: 16px;
  }
}

/* Header Section */
.dashboard-header {
  margin-bottom: 32px;
}

.dashboard-title {
  font-size: 32px;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0 0 8px 0;
}

.dashboard-subtitle {
  font-size: 16px;
  color: var(--text-secondary);
  margin: 0;
}

/* Session Timer */
.session-timer {
  display: inline-block;
  margin-left: 20px;
  padding: 4px 12px;
  background: var(--primary-light);
  color: var(--primary);
  border-radius: 20px;
  font-size: 13px;
  font-weight: 500;
}

.session-timer.warning {
  background: var(--warning-light);
  color: var(--warning);
}

/* Cards */
.card {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-sm);
  transition: var(--transition);
}

.card:hover {
  box-shadow: var(--shadow-md);
}

.card-header {
  padding: 20px 24px 16px;
  border-bottom: 1px solid var(--border);
}

.card-title {
  font-size: 18px;
  font-weight: 600;
  color: var(--text-primary);
  margin: 0;
}

.card-subtitle {
  font-size: 14px;
  color: var(--text-secondary);
  margin: 4px 0 0 0;
}

.card-body {
  padding: 20px 24px;
}

.card-body.no-padding {
  padding: 0;
}

/* KPI Grid - smaller minimum widths for responsive behavior */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 20px;
  margin-bottom: 32px;
}

@media (max-width: 768px) {
  .kpi-grid {
    grid-template-columns: repeat(2, 1fr);
    gap: 12px;
  }
}

@media (max-width: 480px) {
  .kpi-grid {
    grid-template-columns: 1fr;
  }
}

.kpi-card {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 24px;
  box-shadow: var(--shadow-sm);
  transition: var(--transition);
  position: relative;
  overflow: hidden;
}

.kpi-card:hover {
  box-shadow: var(--shadow-md);
  transform: translateY(-1px);
}

.kpi-card.primary {
  border-left: 4px solid var(--primary);
}

.kpi-card.success {
  border-left: 4px solid var(--success);
}

.kpi-card.warning {
  border-left: 4px solid var(--warning);
}

.kpi-card.danger {
  border-left: 4px solid var(--danger);
}

.kpi-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}

.kpi-title {
  font-size: 14px;
  font-weight: 500;
  color: var(--text-secondary);
  margin: 0;
}

.kpi-icon {
  width: 20px;
  height: 20px;
  color: var(--text-tertiary);
}

.kpi-value {
  font-size: 32px;
  font-weight: 700;
  color: var(--text-primary);
  margin: 0 0 8px 0;
  line-height: 1;
}

.kpi-change {
  font-size: 14px;
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 4px;
}

.kpi-change.positive {
  color: var(--success);
}

.kpi-change.negative {
  color: var(--danger);
}

.kpi-change.neutral {
  color: var(--text-secondary);
}

/* Main Dashboard Grid - remove responsive width logic */
.dashboard-grid {
  display: grid;
  grid-template-columns: 2fr 1fr;
  gap: 24px;
  margin-bottom: 32px;
}

@media (max-width: 1024px) {
  .dashboard-grid {
    grid-template-columns: 1fr;
  }
}

/* Quick Actions */
.quick-actions {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  margin-bottom: 32px;
}

.action-card {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 20px;
  text-decoration: none;
  color: var(--text-primary);
  transition: var(--transition);
  display: flex;
  align-items: center;
  gap: 16px;
  box-shadow: var(--shadow-sm);
}

.action-card:hover {
  text-decoration: none;
  color: var(--primary);
  box-shadow: var(--shadow-md);
  transform: translateY(-1px);
  background: var(--primary-lighter);
}

.action-icon {
  width: 24px;
  height: 24px;
  color: var(--primary);
  flex-shrink: 0;
}

.action-text {
  flex: 1;
}

.action-title {
  font-weight: 600;
  font-size: 15px;
  margin: 0 0 4px 0;
}

.action-desc {
  font-size: 13px;
  color: var(--text-secondary);
  margin: 0;
}

/* Recent Activity Table */
.activity-table {
  width: 100%;
  border-collapse: collapse;
}

.activity-table th {
  text-align: left;
  font-weight: 600;
  color: var(--text-secondary);
  font-size: 13px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--border);
  background: var(--bg-secondary);
}

.activity-table td {
  padding: 16px;
  border-bottom: 1px solid var(--border-light);
  font-size: 14px;
}

.activity-table tr:hover {
  background: var(--hover);
}

.activity-table tr:last-child td {
  border-bottom: none;
}

/* Status badges */
.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
  text-transform: capitalize;
}

.status-badge.open {
  background: var(--primary-light);
  color: var(--primary);
}

.status-badge.completed {
  background: var(--success-light);
  color: var(--success);
}

.status-badge.preparing {
  background: var(--warning-light);
  color: var(--warning);
}

.status-badge.cancelled {
  background: var(--danger-light);
  color: var(--danger);
}

/* Empty states */
.empty-state {
  text-align: center;
  padding: 40px 20px;
  color: var(--text-secondary);
}

.empty-state-icon {
  width: 48px;
  height: 48px;
  color: var(--text-tertiary);
  margin: 0 auto 16px;
}

.empty-state-title {
  font-size: 16px;
  font-weight: 600;
  margin: 0 0 8px 0;
}

.empty-state-desc {
  font-size: 14px;
  margin: 0;
}

/* Alerts */
.alert {
  padding: 16px 20px;
  border-radius: var(--radius-lg);
  margin-bottom: 20px;
  font-size: 14px;
  border: 1px solid;
}

.alert.warning {
  background: var(--warning-light);
  border-color: #ffb366;
  color: #8b4000;
}

.alert.danger {
  background: var(--danger-light);
  border-color: #fca5a5;
  color: var(--danger);
}

.alert.info {
  background: var(--primary-lighter);
  border-color: #93c5fd;
  color: #1d4ed8;
}

/* Utilities */
.text-right {
  text-align: right;
}

.text-center {
  text-align: center;
}

.font-mono {
  font-family: ui-monospace, 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', Consolas, 'Liberation Mono', monospace;
}

.currency {
  font-weight: 600;
}

.small {
  font-size: 13px;
  color: var(--text-secondary);
}

.muted {
  color: var(--text-secondary);
}

/* Progress bars */
.progress {
  width: 100%;
  height: 6px;
  background: var(--border-light);
  border-radius: 3px;
  overflow: hidden;
  margin: 8px 0;
}

.progress-bar {
  height: 100%;
  background: var(--primary);
  border-radius: 3px;
  transition: width 0.3s ease;
}

.progress-bar.success {
  background: var(--success);
}

.progress-bar.warning {
  background: var(--warning);
}
</style>
</head>
<body>
<?php
// Include the fixed admin navigation (leaves layout open)
$active = 'dashboard';
try {
    require __DIR__ . '/../partials/admin_nav.php';
} catch (Throwable $e) {
    echo "<div class='alert danger'>Navigation error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
}
?>

<!-- Dashboard Content - Now properly inside admin-content -->
<div class="dashboard-container">
    <?php if ($bootstrap_warning): ?>
        <div class="alert warning">
            <strong>Configuration Warning:</strong> <?= htmlspecialchars($bootstrap_warning, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($DEBUG): ?>
                <div class="small" style="margin-top:8px">Enable debug mode to see detailed PHP errors.</div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$db_ok): ?>
        <div class="alert danger">
            <strong>Database Connection Issue:</strong> Unable to load dashboard data.
            <?php if ($DEBUG && $db_msg): ?>
                <div class="small font-mono" style="margin-top:8px;white-space:pre-wrap">DEBUG: <?= htmlspecialchars($db_msg, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Dashboard Header -->
    <div class="dashboard-header">
        <h1 class="dashboard-title">
            <?= $greeting ?>, <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?>
            <?php if ($session_remaining && $session_remaining['minutes'] < 5): ?>
                <span class="session-timer warning">
                    Session expires in <?= $session_remaining['minutes'] ?>:<?= str_pad((string)$session_remaining['seconds'], 2, '0', STR_PAD_LEFT) ?>
                </span>
            <?php endif; ?>
        </h1>
        <p class="dashboard-subtitle">Here's what's happening with your business today</p>
    </div>

    <!-- KPI Cards -->
    <div class="kpi-grid">
        <div class="kpi-card primary">
            <div class="kpi-header">
                <h3 class="kpi-title">Today's Sales</h3>
                <svg class="kpi-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/>
                </svg>
            </div>
            <div class="kpi-value">$<?= number_format($stats['today_sales'] ?? 0, 2) ?></div>
            <div class="kpi-change positive">
                <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M7 14l5-5 5 5H7z"/>
                </svg>
                +12.5% from yesterday
            </div>
        </div>

        <div class="kpi-card success">
            <div class="kpi-header">
                <h3 class="kpi-title">Open Orders</h3>
                <svg class="kpi-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
            </div>
            <div class="kpi-value"><?= $stats['open_orders'] ?? 0 ?></div>
            <div class="kpi-change neutral">
                Active orders to process
            </div>
        </div>

        <div class="kpi-card warning">
            <div class="kpi-header">
                <h3 class="kpi-title">Products</h3>
                <svg class="kpi-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                </svg>
            </div>
            <div class="kpi-value"><?= $stats['products'] ?? 0 ?></div>
            <div class="kpi-change neutral">
                In <?= $stats['categories'] ?? 0 ?> categories
            </div>
        </div>

        <div class="kpi-card primary">
            <div class="kpi-header">
                <h3 class="kpi-title">Team Members</h3>
                <svg class="kpi-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13.5 7a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"/>
                </svg>
            </div>
            <div class="kpi-value"><?= $stats['users'] ?? 0 ?></div>
            <div class="kpi-change positive">
                <?= $stats['recent_logins'] ?> logged in today
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <a href="/views/admin/orders/create.php" class="action-card">
            <svg class="action-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/>
            </svg>
            <div class="action-text">
                <div class="action-title">New Order</div>
                <div class="action-desc">Create a new customer order</div>
            </div>
        </a>

        <a href="/views/admin/catalog/products.php" class="action-card">
            <svg class="action-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <div class="action-text">
                <div class="action-title">Add Product</div>
                <div class="action-desc">Manage your inventory</div>
            </div>
        </a>

        <a href="/views/admin/rewards/points/overview.php" class="action-card">
            <svg class="action-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
            </svg>
            <div class="action-text">
                <div class="action-title">Rewards</div>
                <div class="action-desc">View customer rewards</div>
            </div>
        </a>

        <a href="/pos/login.php" class="action-card">
            <svg class="action-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2v10a2 2 0 002 2z"/>
            </svg>
            <div class="action-text">
                <div class="action-title">Open POS</div>
                <div class="action-desc">Go to point of sale</div>
            </div>
        </a>
        
        <?php if (user_has_any_role(['admin', 'manager'])): ?>
        <a href="/views/admin/audit_logs.php" class="action-card">
            <svg class="action-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
            </svg>
            <div class="action-text">
                <div class="action-title">Audit Logs</div>
                <div class="action-desc">View system activity logs</div>
            </div>
        </a>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['available_branches']) && count($_SESSION['available_branches']) > 1): ?>
        <a href="#" onclick="showBranchSelector(); return false;" class="action-card">
            <svg class="action-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            <div class="action-text">
                <div class="action-title">Switch Branch</div>
                <div class="action-desc">Current: <?= htmlspecialchars($_SESSION['branch_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        </a>
        <?php endif; ?>
    </div>

    <!-- Main Dashboard Grid -->
    <div class="dashboard-grid">
        <!-- Recent Orders -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">Recent Orders</h2>
                <p class="card-subtitle">Latest customer orders</p>
            </div>
            <div class="card-body no-padding">
                <?php if (!empty($stats['recent_orders'])): ?>
                    <table class="activity-table">
                        <thead>
                            <tr>
                                <th>Order ID</th>
                                <th>Customer</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['recent_orders'] as $order): ?>
                                <tr>
                                    <td class="font-mono">#<?= str_pad((string)$order['id'], 4, '0', STR_PAD_LEFT) ?></td>
                                    <td><?= htmlspecialchars($order['customer_name'] ?: 'Walk-in', ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="currency">$<?= number_format($order['total'] ?? 0, 2) ?></td>
                                    <td>
                                        <span class="status-badge <?= strtolower($order['status'] ?? 'open') ?>">
                                            <?= htmlspecialchars($order['status'] ?? 'Open', ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                    <td class="small muted">
                                        <?= date('M j, g:i A', strtotime($order['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">
                        <svg class="empty-state-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                        </svg>
                        <h3 class="empty-state-title">No Recent Orders</h3>
                        <p class="empty-state-desc">Orders will appear here when customers place them</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- System Status & Quick Stats -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">System Status</h2>
                <p class="card-subtitle">Current system health</p>
            </div>
            <div class="card-body">
                <div style="margin-bottom: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span>Database</span>
                        <span class="status-badge <?= $db_ok ? 'completed' : 'cancelled' ?>">
                            <?= $db_ok ? 'Connected' : 'Error' ?>
                        </span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar <?= $db_ok ? 'success' : 'danger' ?>" style="width: <?= $db_ok ? '100' : '0' ?>%"></div>
                    </div>
                </div>

                <div style="margin-bottom: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span>Active Session</span>
                        <span class="status-badge completed">Active</span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar success" style="width: 100%"></div>
                    </div>
                </div>
                
                <div style="margin-bottom: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span>Today's Activities</span>
                        <span class="small muted"><?= $stats['today_activities'] ?> actions</span>
                    </div>
                </div>

                <div style="margin-bottom: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <span>User Role</span>
                        <span class="small muted"><?= htmlspecialchars($user['role_key'] ?? 'admin', ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>

                <hr style="border: none; border-top: 1px solid var(--border); margin: 20px 0;">

                <div style="text-align: center;">
                    <div style="font-size: 24px; font-weight: 700; color: var(--success); margin-bottom: 4px;">
                        $<?= number_format($stats['weekly_sales'] ?? 0, 2) ?>
                    </div>
                    <div class="small muted">This Week's Sales</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Debug Info (if enabled) -->
    <?php if ($DEBUG): ?>
        <div class="card" style="margin-top: 32px;">
            <div class="card-header">
                <h2 class="card-title">Debug Information</h2>
                <p class="card-subtitle">System debugging details</p>
            </div>
            <div class="card-body">
                <div style="background: var(--bg-secondary); padding: 16px; border-radius: var(--radius); font-family: monospace; font-size: 12px; overflow-x: auto;">
                    <strong>Session Data:</strong><br>
                    <?= htmlspecialchars(print_r($_SESSION, true), ENT_QUOTES, 'UTF-8') ?>
                    <br><br>
                    <strong>Stats Array:</strong><br>
                    <?= htmlspecialchars(print_r($stats, true), ENT_QUOTES, 'UTF-8') ?>
                    <br><br>
                    <strong>Session Timeout:</strong> <?= defined('SESSION_TIMEOUT') ? SESSION_TIMEOUT . ' seconds' : 'Not defined' ?>
                </div>
                <div style="margin-top: 16px; text-align: center;">
                    <a href="?" style="color: var(--primary); text-decoration: none; font-size: 13px;">Disable Debug Mode</a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div><!-- /.dashboard-container -->

<!-- Branch Selector Modal (if user has multiple branches) -->
<?php if (isset($_SESSION['available_branches']) && count($_SESSION['available_branches']) > 1): ?>
<script>
function showBranchSelector() {
    const branches = <?= json_encode($_SESSION['available_branches']) ?>;
    const currentBranch = <?= $_SESSION['branch_id'] ?>;
    
    let options = '';
    for (const [id, name] of Object.entries(branches)) {
        const selected = id == currentBranch ? 'selected' : '';
        options += `<option value="${id}" ${selected}>${name}</option>`;
    }
    
    const modal = document.createElement('div');
    modal.innerHTML = `
        <div style="position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 9999;">
            <div style="background: white; padding: 30px; border-radius: 8px; max-width: 400px; width: 90%;">
                <h3 style="margin: 0 0 20px 0;">Switch Branch</h3>
                <select id="branchSelect" style="width: 100%; padding: 10px; margin-bottom: 20px; border: 1px solid #ddd; border-radius: 4px;">
                    ${options}
                </select>
                <div style="display: flex; gap: 10px;">
                    <button onclick="switchBranch()" style="flex: 1; padding: 10px; background: #0078d4; color: white; border: none; border-radius: 4px; cursor: pointer;">Switch</button>
                    <button onclick="this.closest('[style*=fixed]').remove()" style="flex: 1; padding: 10px; background: #e0e0e0; border: none; border-radius: 4px; cursor: pointer;">Cancel</button>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
}

function switchBranch() {
    const branchId = document.getElementById('branchSelect').value;
    
    fetch('/api/switch_branch.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'branch_id=' + branchId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            location.reload();
        } else {
            alert('Failed to switch branch: ' + data.message);
        }
    })
    .catch(error => {
        alert('Error switching branch: ' + error);
    });
}
</script>
<?php endif; ?>

<?php
// Close the admin layout properly
require __DIR__ . '/../partials/admin_nav_close.php';
?>
</body>
</html>