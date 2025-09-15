<?php
// /public_html/views/admin/orders/index.php
// Orders list with Microsoft 365-style UI/UX (consistent with Stockflow pages)
declare(strict_types=1);

/* ---------- Bootstrap (robust config + auth) ---------- */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL);
} else {
  @ini_set('display_errors','0');
}

$bootstrap_ok = false;
$bootstrap_msg = '';

try {
  // Try canonical path first
  $configPath = dirname(__DIR__, 3) . '/config/db.php';
  if (!is_file($configPath)) throw new RuntimeException('Configuration file not found: /config/db.php');
  require_once $configPath;

  if (function_exists('use_backend_session')) { use_backend_session(); }
  else { if (session_status() !== PHP_SESSION_ACTIVE) session_start(); }

  $authPath = dirname(__DIR__, 3) . '/middleware/auth_login.php';
  if (!is_file($authPath)) throw new RuntimeException('Auth middleware not found');
  require_once $authPath;
  auth_require_login();

  // Optional helpers (do not fatal if missing)
  $helpersPath = dirname(__DIR__, 3) . '/controllers/admin/orders/_helpers.php';
  if (is_file($helpersPath)) require_once $helpersPath;

  if (!function_exists('db')) throw new RuntimeException('db() not available from config.php');
  $bootstrap_ok = true;
} catch (Throwable $e) {
  $bootstrap_msg = $e->getMessage();
}

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function donly(?string $dt): string{
  if(!$dt) return '-';
  $t = strtotime($dt); if($t<=0) return '-';
  return date('d-m-Y', $t);
}
function typeLabel(string $t): string{
  return $t==='dine_in' ? 'Dine in'
       : ($t==='takeaway' ? 'Takeaway'
       : ($t==='delivery' ? 'Delivery' : ucfirst(str_replace('_',' ',$t))));
}
function payLabel(?string $p): string{
  return $p==='paid' ? 'Paid' : ($p==='voided' ? 'Voided' : 'Unpaid');
}

/** Include first existing file from a list (safe include; non-fatal). */
function include_first_existing(array $candidates): bool {
  foreach ($candidates as $f) { if (is_file($f)) { include $f; return true; } }
  return false;
}

/* ---------- Session / Tenant ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user && $bootstrap_ok) { header('Location: /views/auth/login.php'); exit; }
$tenantId = (int)($user['tenant_id'] ?? 0);

/* ---------- Flash ---------- */
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

/* ---------- Filters ---------- */
$q         = trim((string)($_GET['q'] ?? ''));
$branch    = (int)($_GET['branch'] ?? 0);
$type      = (string)($_GET['type'] ?? 'all');
$payment   = (string)($_GET['payment'] ?? 'all');
$datePreset= (string)($_GET['d'] ?? 'month');
$from      = (string)($_GET['from'] ?? '');
$to        = (string)($_GET['to'] ?? '');

/* ---------- Branches ---------- */
$branches = [];
$db_msg = '';
if ($bootstrap_ok) {
  try {
    $pdo = db();
    $st = $pdo->prepare("SELECT id, COALESCE(display_name, name) AS name FROM branches WHERE tenant_id = :t ORDER BY name ASC");
    $st->execute([':t'=>$tenantId]);
    $branches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { $db_msg = $e->getMessage(); }
}

/* ---------- Date window ---------- */
$start = null; $end = null; $today = new DateTimeImmutable('today');
$firstOfMonth = $today->modify('first day of this month')->format('Y-m-d');
$lastOfMonth  = $today->modify('last day of this month')->format('Y-m-d');

switch ($datePreset) {
  case 'week':
    $ws = $today->modify('monday this week'); $we = $ws->modify('+6 days');
    $start = $ws->format('Y-m-d 00:00:00'); $end = $we->format('Y-m-d 23:59:59'); break;
  case 'month':
    $ms = $today->modify('first day of this month'); $me = $today->modify('last day of this month');
    $start = $ms->format('Y-m-d 00:00:00'); $end = $me->format('Y-m-d 23:59:59'); break;
  case 'year':
    $ys = new DateTimeImmutable(date('Y').'-01-01'); $ye = new DateTimeImmutable(date('Y').'-12-31');
    $start = $ys->format('Y-m-d 00:00:00'); $end = $ye->format('Y-m-d 23:59:59'); break;
  case 'custom':
    $fd = $from !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $from) : DateTimeImmutable::createFromFormat('Y-m-d', $firstOfMonth);
    $td = $to   !== '' ? DateTimeImmutable::createFromFormat('Y-m-d', $to)   : DateTimeImmutable::createFromFormat('Y-m-d', $lastOfMonth);
    if(!$fd) $fd = DateTimeImmutable::createFromFormat('Y-m-d', $firstOfMonth);
    if(!$td) $td = DateTimeImmutable::createFromFormat('Y-m-d', $lastOfMonth);
    if($fd > $td){ [$fd,$td] = [$td,$fd]; }
    $start = $fd->format('Y-m-d 00:00:00'); $end = $td->format('Y-m-d 23:59:59'); break;
  case 'day':
  default:
    $ms = $today->modify('first day of this month'); $me = $today->modify('last day of this month');
    $start = $ms->format('Y-m-d 00:00:00'); $end = $me->format('Y-m-d 23:59:59'); $datePreset = 'month';
}

/* ---------- Orders & Stats ---------- */
$rows = [];
$stats = ['total_orders' => 0, 'total_revenue' => 0, 'today_orders' => 0];

if ($bootstrap_ok) {
  try {
    $pdo = db();
    $where = ["o.tenant_id = :t", "o.is_deleted = 0"];
    $args = [':t'=>$tenantId];

    if ($q !== '') {
      $where[] = "(o.id = :idq OR o.customer_name LIKE :q OR o.receipt_reference LIKE :q2 OR o.external_order_reference LIKE :q3)";
      $args[':idq'] = ctype_digit($q) ? (int)$q : -1;
      $args[':q']   = "%$q%";
      $args[':q2']  = "%$q%";
      $args[':q3']  = "%$q%";
    }
    if ($branch > 0) { $where[] = "o.branch_id = :b"; $args[':b'] = $branch; }
    if (in_array($type, ['dine_in','takeaway','delivery'], true)) { $where[] = "o.order_type = :ot"; $args[':ot'] = $type; }
    if (in_array($payment, ['paid','unpaid','voided'], true)) { $where[] = "o.payment_status = :ps"; $args[':ps'] = $payment; }
    if ($start && $end) { $where[] = "(o.created_at BETWEEN :ds AND :de)"; $args[':ds'] = $start; $args[':de'] = $end; }

    $whereSql = 'WHERE '.implode(' AND ', $where);
    
    // Get orders
    $sql = "
      SELECT
        o.id, o.created_at, o.order_type, o.status, o.payment_status,
        o.customer_name, o.total_amount,
        b.name AS branch_name
      FROM orders o
      LEFT JOIN branches b ON b.id = o.branch_id
      $whereSql
      ORDER BY o.id DESC
      LIMIT 500
    ";
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Get stats for filtered period
    $statsWhere = ["o.tenant_id = :t", "o.is_deleted = 0"];
    $statsArgs = [':t'=>$tenantId];
    if ($start && $end) { 
      $statsWhere[] = "(o.created_at BETWEEN :ds AND :de)"; 
      $statsArgs[':ds'] = $start; 
      $statsArgs[':de'] = $end; 
    }
    $statsWhereSql = 'WHERE '.implode(' AND ', $statsWhere);
    
    // Total orders (filtered period)
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders o $statsWhereSql");
    $st->execute($statsArgs);
    $stats['total_orders'] = (int)$st->fetchColumn();
    
    // Total revenue (filtered period)
    $st = $pdo->prepare("SELECT COALESCE(SUM(o.total_amount), 0) FROM orders o $statsWhereSql AND o.payment_status = 'paid'");
    $st->execute($statsArgs);
    $stats['total_revenue'] = (float)$st->fetchColumn();
    
    // Today's orders (always today regardless of filters)
    $todayStart = date('Y-m-d 00:00:00');
    $todayEnd = date('Y-m-d 23:59:59');
    $st = $pdo->prepare("SELECT COUNT(*) FROM orders o WHERE o.tenant_id = :t AND o.is_deleted = 0 AND o.created_at BETWEEN :start AND :end");
    $st->execute([':t' => $tenantId, ':start' => $todayStart, ':end' => $todayEnd]);
    $stats['today_orders'] = (int)$st->fetchColumn();
    
  } catch (Throwable $e) { $db_msg = $e->getMessage(); }
}

/* ---------- View helpers ---------- */
$from_val = $from !== '' ? $from : $firstOfMonth;
$to_val   = $to   !== '' ? $to   : $lastOfMonth;
$hasActiveFilters = ($q !== '' || $branch > 0 || $type !== 'all' || $payment !== 'all' || $datePreset !== 'month');

$active = 'orders';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Orders · Smorll POS</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">

  <!-- Inter font for Microsoft 365 aesthetic -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    :root{
      /* Microsoft 365 Color Palette - matching stockflow */
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
    }
    *{box-sizing:border-box}
    body{margin:0;background:var(--bg-primary);font-family:'Inter','Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,Roboto,'Helvetica Neue',sans-serif;color:var(--text-primary);font-size:14px;line-height:1.5}
    h1,h2,h3,h4,h5,h6,p{margin:0}

    .orders-container{
      padding:16px;width:100%;max-width:none;transition:all 0.3s ease;
    }
    @media (max-width:768px){.orders-container{padding:12px}}

    /* Page Header */
    .page-header {
      margin-bottom: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 16px;
      flex-wrap: wrap;
    }

    .page-title {
      font-size: 24px;
      font-weight: 700;
      letter-spacing: -0.01em;
      color: var(--text-primary);
      margin: 0 0 4px 0;
    }

    .page-subtitle {
      font-size: 12px;
      color: var(--text-secondary);
      margin: 0;
    }

    /* Stats Cards */
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin-bottom: 20px;
    }

    .stat-card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      padding: 20px;
      box-shadow: var(--shadow-sm);
      transition: var(--transition);
    }

    .stat-card:hover {
      box-shadow: var(--shadow-md);
    }

    .stat-value {
      font-size: 28px;
      font-weight: 700;
      line-height: 1;
      margin-bottom: 4px;
    }

    .stat-label {
      font-size: 12px;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      font-weight: 500;
    }

    .stat-card.total .stat-value { color: var(--primary); }
    .stat-card.paid .stat-value { color: var(--success); }
    .stat-card.revenue .stat-value { color: var(--text-primary); }
    .stat-card.pending .stat-value { color: var(--warning); }

    /* Main Card */
    .card {
      background: var(--card-bg);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm);
      overflow: hidden;
      transition: var(--transition);
    }

    .card:hover {
      box-shadow: var(--shadow-md);
    }

    .card-header {
      padding: 16px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    .card-title {
      font-size: 18px;
      font-weight: 600;
      color: var(--text-primary);
      margin: 0;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .count-badge {
      background: var(--bg-secondary);
      color: var(--text-secondary);
      padding: 4px 8px;
      border-radius: 12px;
      font-size: 12px;
      font-weight: 500;
    }

    /* Filters */
    .filters-section {
      padding: 16px 20px;
      background: var(--bg-secondary);
      border-bottom: 1px solid var(--border);
    }

    .filters-grid {
      display: grid;
      grid-template-columns: minmax(220px, 1fr) repeat(4, minmax(120px, 160px));
      gap: 12px;
      align-items: end;
      margin-bottom: 12px;
    }

    .custom-dates {
      display: grid;
      grid-template-columns: 160px auto 160px auto auto;
      gap: 12px;
      align-items: center;
      margin-top: 12px;
    }

    @media (max-width: 1200px) {
      .filters-grid {
        grid-template-columns: minmax(200px, 1fr) repeat(2, minmax(120px, 1fr)) minmax(140px, 1fr);
      }
    }

    @media (max-width: 768px) {
      .filters-grid {
        grid-template-columns: 1fr;
        gap: 10px;
      }
      .custom-dates {
        grid-template-columns: 1fr;
        gap: 10px;
      }
    }

    .filter-group {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .filter-label {
      font-size: 12px;
      font-weight: 500;
      color: var(--text-secondary);
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .filter-input, .filter-select {
      width: 100%;
      padding: 10px 12px;
      border: 1px solid var(--border);
      border-radius: var(--radius);
      background: var(--card-bg);
      font-size: 14px;
      color: var(--text-primary);
      outline: none;
      transition: var(--transition);
      font-family: inherit;
    }

    .filter-select {
      appearance: none;
      background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%238a8886' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");
      background-position: right 8px center;
      background-repeat: no-repeat;
      background-size: 16px;
      padding-right: 32px;
      cursor: pointer;
    }

    .filter-input:focus, .filter-select:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(0, 120, 212, 0.1);
    }

    .filter-input:hover, .filter-select:hover {
      border-color: var(--text-secondary);
    }

    .filter-input::placeholder {
      color: var(--text-tertiary);
    }

    .clear-filters {
      color: var(--primary);
      text-decoration: none;
      font-size: 14px;
      font-weight: 500;
      padding: 10px 12px;
      border-radius: var(--radius);
      transition: var(--transition);
      white-space: nowrap;
      text-align: center;
      align-self: end;
    }

    .clear-filters:hover {
      background: var(--primary-lighter);
      text-decoration: none;
    }

    /* Buttons */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 10px 16px;
      border: 1px solid transparent;
      border-radius: var(--radius);
      font-size: 14px;
      font-weight: 500;
      text-decoration: none;
      cursor: pointer;
      transition: var(--transition);
      outline: none;
      white-space: nowrap;
      justify-content: center;
    }

    .btn:hover {
      transform: translateY(-1px);
      box-shadow: var(--shadow-md);
      text-decoration: none;
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
    }

    .btn-secondary {
      background: var(--card-bg);
      color: var(--text-primary);
      border-color: var(--border);
    }

    .btn-secondary:hover {
      background: var(--hover);
      color: var(--text-primary);
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
    }

    .btn-sm {
      padding: 6px 12px;
      font-size: 13px;
    }

    .btn-group {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    /* Table */
    .table-container {
      overflow-x: auto;
      overflow-y: auto;
      max-height: calc(100vh - 400px);
    }

    .table {
      width: 100%;
      min-width: 1100px;
      border-collapse: separate;
      border-spacing: 0;
    }

    .table th,
    .table td {
      padding: 14px 16px;
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
      letter-spacing: .6px;
      position: sticky;
      top: 0;
      z-index: 10;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .table td {
      font-size: 14px;
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

    .col-id{min-width:72px;width:72px}
    .col-created{min-width:120px;width:120px}
    .col-branch{min-width:180px}
    .col-type{min-width:110px;width:110px}
    .col-status{min-width:120px;width:120px}
    .col-payment{min-width:120px;width:120px}
    .col-customer{min-width:200px}
    .col-total{min-width:110px;width:110px;text-align:right}
    .col-actions{min-width:230px;width:230px;text-align:right}

    /* Badges */
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 600;
      text-transform: capitalize;
      line-height: 1.2;
    }

    .badge.ok {
      background: var(--success-light);
      color: var(--success);
    }

    .badge.warn {
      background: var(--warning-light);
      color: var(--warning);
    }

    .badge.off {
      background: var(--danger-light);
      color: var(--danger);
    }

    /* Actions */
    .actions {
      display: inline-flex;
      gap: 8px;
      flex-wrap: nowrap;
    }

    .ellipsis {
      display: inline-block;
      max-width: 260px;
      overflow: hidden;
      text-overflow: ellipsis;
      vertical-align: bottom;
    }

    /* Empty State */
    .empty-state {
      padding: 60px 40px;
      text-align: center;
      color: var(--text-secondary);
    }

    .empty-icon {
      width: 80px;
      height: 80px;
      margin: 0 auto 20px;
      background: var(--bg-secondary);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 32px;
    }

    .empty-title {
      font-size: 20px;
      font-weight: 600;
      color: var(--text-primary);
      margin-bottom: 8px;
    }

    .empty-description {
      font-size: 14px;
      color: var(--text-secondary);
      margin-bottom: 24px;
      max-width: 400px;
      margin-left: auto;
      margin-right: auto;
    }

    /* Flash Messages */
    .alert {
      padding: 16px 20px;
      border-radius: var(--radius-lg);
      margin-bottom: 16px;
      font-size: 14px;
      border: 1px solid;
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .alert.success {
      background: var(--success-light);
      border-color: #a7f3d0;
      color: var(--success);
    }

    .alert.error {
      background: var(--danger-light);
      border-color: #fca5a5;
      color: var(--danger);
    }

    .alert.warning {
      background: var(--warning-light);
      border-color: #fbbf24;
      color: var(--warning);
    }

    /* Toast Notifications */
    .toast-container {
      position: fixed;
      top: 20px;
      right: 20px;
      z-index: 9999;
    }

    .toast {
      background: var(--card-bg);
      border-radius: var(--radius-lg);
      padding: 16px 20px;
      margin-bottom: 12px;
      box-shadow: var(--shadow-lg);
      display: flex;
      align-items: center;
      gap: 12px;
      min-width: 300px;
      animation: slideInRight 0.3s ease;
      border: 1px solid var(--border);
    }

    @keyframes slideInRight {
      from { transform: translateX(100%); opacity: 0; }
      to { transform: translateX(0); opacity: 1; }
    }

    .toast.success { border-left: 4px solid var(--success); }
    .toast.error { border-left: 4px solid var(--danger); }
    .toast.info { border-left: 4px solid var(--primary); }

    .toast-icon {
      font-size: 16px;
      width: 20px;
      height: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .toast.success .toast-icon { color: var(--success); }
    .toast.error .toast-icon { color: var(--danger); }
    .toast.info .toast-icon { color: var(--primary); }

    .toast-message {
      flex: 1;
      font-size: 14px;
      color: var(--text-primary);
    }

    /* ID Column */
    .id-column {
      width: 80px;
      color: var(--text-tertiary);
      font-family: 'SF Mono', Monaco, 'Courier New', monospace;
      font-size: 13px;
    }

    /* Print */
    @media print{
      .filters-section, .page-actions, .btn, .alert, .toast-container {display:none!important}
      .orders-container{padding:0}
      .card{box-shadow:none;border-color:#ddd}
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

<div class="orders-container">
  <div class="toast-container" id="toastContainer"></div>
  
  <?php if (!$bootstrap_ok): ?>
    <div class="alert error">
      <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
        <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.314 16.5c-.77.833.192 2.5 1.732 2.5z"/>
      </svg>
      <div>
        <strong>Bootstrap Error</strong><br>
        <?= h($bootstrap_msg) ?>
      </div>
    </div>
  <?php else: ?>

    <?php if($flash): ?>
      <div class="alert success">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <?= h($flash) ?>
      </div>
    <?php endif; ?>

    <?php if(!empty($db_msg) && $DEBUG): ?>
      <div class="alert warning">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.314 16.5c-.77.833.192 2.5 1.732 2.5z"/>
        </svg>
        <div>
          <strong>DEBUG:</strong><br>
          <?= h($db_msg) ?>
        </div>
      </div>
    <?php endif; ?>

    <!-- Page Header -->
    <div class="page-header">
      <div>
        <h1 class="page-title">Orders</h1>
        <p class="page-subtitle">Up to the latest 500 orders based on your filters</p>
      </div>
      <div class="btn-group">
        <a class="btn btn-secondary" href="/controllers/admin/orders/order_export.php?<?= h(http_build_query($_GET)) ?>">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4m4-5l5 5 5-5m-5 5V3"/>
          </svg>
          Export
        </a>
        <a class="btn btn-primary" href="/views/admin/orders/create.php">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M12 5v14m7-7H5"/>
          </svg>
          Add Order
        </a>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
      <div class="stat-card total">
        <div class="stat-value"><?= $stats['total_orders'] ?></div>
        <div class="stat-label">Total Orders</div>
      </div>
      <div class="stat-card paid">
        <div class="stat-value"><?= $stats['paid_orders'] ?></div>
        <div class="stat-label">Paid Orders</div>
      </div>
      <div class="stat-card revenue">
        <div class="stat-value"><?= number_format($stats['total_revenue'], 0) ?></div>
        <div class="stat-label">Revenue (EGP)</div>
      </div>
      <div class="stat-card pending">
        <div class="stat-value"><?= $stats['pending_orders'] ?></div>
        <div class="stat-label">Pending Orders</div>
      </div>
    </div>

    <!-- Main Card -->
    <div class="card">
      <div class="card-header">
        <div class="card-title">
          All Orders
          <span class="count-badge"><?= count($rows) ?></span>
        </div>
      </div>

      <!-- Filters -->
      <div class="filters-section">
        <form id="filterForm" method="get" action="">
          <div class="filters-grid">
            <div class="filter-group">
              <label class="filter-label">Search</label>
              <input class="filter-input" type="text" name="q" id="q" placeholder="ID, customer, receipt, external ref" value="<?= h($q) ?>" autocomplete="off">
            </div>
            <div class="filter-group">
              <label class="filter-label">Branch</label>
              <select class="filter-select" name="branch" id="branch">
                <option value="0" <?= $branch===0?'selected':'' ?>>All branches</option>
                <?php foreach($branches as $b): ?>
                  <option value="<?= (int)$b['id'] ?>" <?= $branch===(int)$b['id']?'selected':'' ?>><?= h($b['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="filter-group">
              <label class="filter-label">Type</label>
              <select class="filter-select" name="type" id="type">
                <option value="all" <?= $type==='all'?'selected':'' ?>>All types</option>
                <option value="dine_in" <?= $type==='dine_in'?'selected':'' ?>>Dine in</option>
                <option value="takeaway" <?= $type==='takeaway'?'selected':'' ?>>Takeaway</option>
                <option value="delivery" <?= $type==='delivery'?'selected':'' ?>>Delivery</option>
              </select>
            </div>
            <div class="filter-group">
              <label class="filter-label">Payment</label>
              <select class="filter-select" name="payment" id="payment">
                <option value="all" <?= $payment==='all'?'selected':'' ?>>All payments</option>
                <option value="paid" <?= $payment==='paid'?'selected':'' ?>>Paid</option>
                <option value="unpaid" <?= $payment==='unpaid'?'selected':'' ?>>Unpaid</option>
                <option value="voided" <?= $payment==='voided'?'selected':'' ?>>Voided</option>
              </select>
            </div>
            <div class="filter-group">
              <label class="filter-label">Date Period</label>
              <select class="filter-select" name="d" id="d">
                <option value="day"   <?= $datePreset==='day'?'selected':'' ?>>Today</option>
                <option value="week"  <?= $datePreset==='week'?'selected':'' ?>>This Week</option>
                <option value="month" <?= $datePreset==='month'?'selected':'' ?>>Current Month</option>
                <option value="year"  <?= $datePreset==='year'?'selected':'' ?>>Current Year</option>
                <option value="custom"<?= $datePreset==='custom'?'selected':'' ?>>Custom</option>
              </select>
            </div>
          </div>

          <div class="custom-dates" id="customRow" style="<?= $datePreset==='custom' ? '' : 'display:none' ?>">
            <div class="filter-group">
              <label class="filter-label">From</label>
              <input class="filter-input" type="date" name="from" value="<?= h($from_val) ?>" id="from">
            </div>
            <span style="color:var(--text-secondary);font-size:12px;align-self:end;padding:10px 0;">to</span>
            <div class="filter-group">
              <label class="filter-label">To</label>
              <input class="filter-input" type="date" name="to" value="<?= h($to_val) ?>" id="to">
            </div>
            <button class="btn btn-primary btn-sm" type="submit" id="applyBtn">Apply</button>
            <?php if ($hasActiveFilters): ?>
              <a class="clear-filters" href="<?= strtok($_SERVER['REQUEST_URI'],'?') ?>">Clear all filters</a>
            <?php endif; ?>
          </div>

          <?php if ($hasActiveFilters && $datePreset !== 'custom'): ?>
            <div style="margin-top: 12px;">
              <a class="clear-filters" href="<?= strtok($_SERVER['REQUEST_URI'],'?') ?>">Clear all filters</a>
            </div>
          <?php endif; ?>
        </form>
      </div>

      <!-- Table -->
      <div class="table-container">
        <?php if(!$rows): ?>
          <div class="empty-state">
            <div class="empty-icon">📋</div>
            <div class="empty-title">No orders found</div>
            <div class="empty-description">
              <?php if ($hasActiveFilters): ?>
                Try adjusting your filters or clear them to see all orders. You can modify the search terms, date range, branch, type, or payment status above.
              <?php else: ?>
                Get started by creating your first order. Orders will appear here once they are created in the system.
              <?php endif; ?>
            </div>
            <?php if (!$hasActiveFilters): ?>
              <a href="/views/admin/orders/create.php" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path d="M12 5v14m7-7H5"/>
                </svg>
                Create First Order
              </a>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <table class="table" id="ordersTable">
            <thead>
              <tr>
                <th class="col-id">ID</th>
                <th class="col-created">Created</th>
                <th class="col-branch">Branch</th>
                <th class="col-type">Type</th>
                <th class="col-status">Status</th>
                <th class="col-payment">Payment</th>
                <th class="col-customer">Customer</th>
                <th class="col-total">Total</th>
                <th class="col-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach($rows as $r): ?>
              <tr>
                <td class="col-id id-column">#<?= (int)$r['id'] ?></td>
                <td class="col-created"><?= h(donly($r['created_at'])) ?></td>
                <td class="col-branch" title="<?= h($r['branch_name'] ?: '-') ?>"><span class="ellipsis"><?= h($r['branch_name'] ?: '-') ?></span></td>
                <td class="col-type"><?= h(typeLabel((string)$r['order_type'])) ?></td>
                <td class="col-status">
                  <?php
                    $st = (string)($r['status'] ?? 'open');
                    $cls = $st==='closed' ? 'ok' : ($st==='cancelled' ? 'off' : 'warn');
                    echo '<span class="badge '.$cls.'">'.h(ucfirst($st ?: 'open')).'</span>';
                  ?>
                </td>
                <td class="col-payment">
                  <?php
                    $ps = (string)($r['payment_status'] ?? 'unpaid');
                    $cls = $ps==='paid' ? 'ok' : ($ps==='voided' ? 'off' : 'warn');
                    echo '<span class="badge '.$cls.'">'.h(payLabel($ps)).'</span>';
                  ?>
                </td>
                <td class="col-customer" title="<?= h($r['customer_name'] ?: '-') ?>"><span class="ellipsis"><?= h($r['customer_name'] ?: '-') ?></span></td>
                <td class="col-total"><strong><?= number_format((float)$r['total_amount'],2) ?></strong></td>
                <td class="col-actions">
                  <div class="actions">
                    <a class="btn btn-sm btn-secondary" href="/views/admin/orders/view.php?id=<?= (int)$r['id'] ?>">View</a>
                    <a class="btn btn-sm btn-secondary" href="/views/admin/orders/edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
                    <a class="btn btn-sm btn-secondary" href="/controllers/admin/orders/order_export.php?id=<?= (int)$r['id'] ?>">Export</a>
                    <a class="btn btn-sm btn-danger" 
                       href="/controllers/admin/orders/order_delete.php?id=<?= (int)$r['id'] ?>&return=<?= urlencode($_SERVER['REQUEST_URI']) ?>"
                       onclick="return confirm('Delete order #<?= (int)$r['id'] ?>? This action cannot be undone.')">Delete</a>
                  </div>
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
document.addEventListener('DOMContentLoaded', function() {
  // Autosubmit behaviour mirrors your original logic, now with refined UI
  const form    = document.getElementById('filterForm');
  const q       = document.getElementById('q');
  const branch  = document.getElementById('branch');
  const typeSel = document.getElementById('type');
  const paySel  = document.getElementById('payment');
  const dsel    = document.getElementById('d');
  const customRow = document.getElementById('customRow');

  // Auto-submit on filter changes with loading indication
  function autosubmit() { 
    if (dsel.value === 'custom') return; 
    showToast('Updating filters...', 'info');
    form.submit(); 
  }

  let searchTimer = null;
  if (q) {
    q.addEventListener('input', () => { 
      if (searchTimer) clearTimeout(searchTimer); 
      searchTimer = setTimeout(autosubmit, 350); 
    });
  }
  
  [branch, typeSel, paySel].forEach(el => {
    if (el) {
      el.addEventListener('change', () => {
        el.style.opacity = '0.6';
        el.disabled = true;
        autosubmit();
      });
    }
  });

  if (dsel) {
    dsel.addEventListener('change', () => {
      if (dsel.value === 'custom') {
        customRow.style.display = '';
      } else {
        customRow.style.display = 'none';
        dsel.style.opacity = '0.6';
        dsel.disabled = true;
        autosubmit();
      }
    });
    
    if (dsel.value === 'custom') { 
      customRow.style.display = ''; 
    } else { 
      customRow.style.display = 'none'; 
    }
  }

  // Initialize toast container if not exists
  if (!document.getElementById('toastContainer')) {
    const container = document.createElement('div');
    container.id = 'toastContainer';
    container.className = 'toast-container';
    document.body.appendChild(container);
  }
});

// Toast notification function
function showToast(message, type = 'success') {
  const container = document.getElementById('toastContainer');
  if (!container) return;
  
  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  
  const icon = type === 'success' ? 
    '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>' : 
    type === 'error' ?
    '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.314 16.5c-.77.833.192 2.5 1.732 2.5z"/></svg>' :
    '<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>';
  
  toast.innerHTML = `
    <span class="toast-icon">${icon}</span>
    <span class="toast-message">${escapeHtml(message)}</span>
  `;
  
  container.appendChild(toast);
  
  setTimeout(() => {
    toast.style.opacity = '0';
    toast.style.transform = 'translateX(100%)';
    setTimeout(() => toast.remove(), 300);
  }, 3000);
}

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
</script>
</body>
</html>