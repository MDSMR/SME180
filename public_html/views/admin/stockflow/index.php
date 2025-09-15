<?php
declare(strict_types=1);
/**
 * /public_html/views/admin/stockflow/index.php
 * Stockflow Transfer Dashboard - Modern UI matching Categories page
 * Main view for all transfers with filters and actions
 */

// Debug mode via ?debug=1
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
    throw new RuntimeException('Auth middleware not found');
  }
  require_once $authPath;
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
$userId = (int)($user['id'] ?? 0);

/* ---------- Flash message handling ---------- */
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

/* ---------- Default date values ---------- */
$defaultDateFrom = date('Y-m-01'); // Beginning of current month
$defaultDateTo = date('Y-m-d'); // Today

/* ---------- Filters ---------- */
$status     = trim((string)($_GET['status'] ?? 'all'));      
$direction  = trim((string)($_GET['dir'] ?? 'all'));         
$branchId   = (int)($_GET['branch'] ?? 0);
$dateFrom   = trim((string)($_GET['date_from'] ?? $defaultDateFrom));
$dateTo     = trim((string)($_GET['date_to'] ?? $defaultDateTo));
$search     = trim((string)($_GET['q'] ?? ''));

$allowedStatus = ['all','pending','shipped','received','cancelled'];
if (!in_array($status, $allowedStatus, true)) { $status = 'all'; }

$allowedDirection = ['all','in','out'];
if (!in_array($direction, $allowedDirection, true)) { $direction = 'all'; }

/* ---------- Data ---------- */
$transfers = [];
$branches = [];
$stats = ['pending_in' => 0, 'pending_out' => 0, 'total_today' => 0, 'total_value' => 0];
$error_msg = '';

if ($bootstrap_ok) {
  try {
    $pdo = db();

    /* Get user branches for filters */
    $stmt = $pdo->prepare("
      SELECT b.id, b.name
      FROM branches b
      WHERE b.tenant_id = :t AND b.is_active = 1
      ORDER BY b.name
    ");
    $stmt->execute([':t' => $tenantId]);
    $branches = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* Build WHERE clause for transfers */
    $where = ["t.tenant_id = :t"];
    $params = [':t' => $tenantId];

    if ($search !== '') {
      $where[] = "(t.transfer_number LIKE :search OR t.notes LIKE :search)";
      $params[':search'] = "%{$search}%";
    }

    if ($status !== 'all') {
      $where[] = "t.status = :status";
      $params[':status'] = $status;
    }

    if ($direction === 'in') {
      $where[] = "t.to_branch_id IN (SELECT id FROM branches WHERE tenant_id = :t2)";
      $params[':t2'] = $tenantId;
    } elseif ($direction === 'out') {
      $where[] = "t.from_branch_id IN (SELECT id FROM branches WHERE tenant_id = :t3)";
      $params[':t3'] = $tenantId;
    }

    if ($branchId > 0) {
      $where[] = "(t.from_branch_id = :branch OR t.to_branch_id = :branch)";
      $params[':branch'] = $branchId;
    }

    if ($dateFrom) {
      $where[] = "DATE(t.created_at) >= :date_from";
      $params[':date_from'] = $dateFrom;
    }

    if ($dateTo) {
      $where[] = "DATE(t.created_at) <= :date_to";
      $params[':date_to'] = $dateTo;
    }

    $whereSql = implode(' AND ', $where);

    /* Get transfers with branch names */
    $sql = "
      SELECT
        t.id,
        t.transfer_number,
        t.status,
        t.transfer_type,
        t.total_items,
        t.notes,
        t.created_at,
        t.shipped_at,
        t.received_at,
        fb.name as from_branch_name,
        tb.name as to_branch_name,
        cu.name as created_by_name,
        su.name as shipped_by_name,
        ru.name as received_by_name,
        COALESCE(
          (SELECT SUM(quantity_requested * unit_cost) 
           FROM stockflow_transfer_items sti 
           WHERE sti.transfer_id = t.id), 0
        ) as total_value
      FROM stockflow_transfers t
      LEFT JOIN branches fb ON fb.id = t.from_branch_id AND fb.tenant_id = t.tenant_id
      LEFT JOIN branches tb ON tb.id = t.to_branch_id AND tb.tenant_id = t.tenant_id
      LEFT JOIN users cu ON cu.id = t.created_by_user_id AND cu.tenant_id = t.tenant_id
      LEFT JOIN users su ON su.id = t.shipped_by_user_id AND su.tenant_id = t.tenant_id
      LEFT JOIN users ru ON ru.id = t.received_by_user_id AND ru.tenant_id = t.tenant_id
      WHERE $whereSql
      ORDER BY t.created_at DESC
      LIMIT 100
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transfers = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    /* Get dashboard stats */
    // Pending incoming transfers
    $stmt = $pdo->prepare("
      SELECT COUNT(*) 
      FROM stockflow_transfers t
      JOIN branches b ON b.id = t.to_branch_id
      WHERE t.tenant_id = :t AND b.tenant_id = :t2 AND t.status = 'pending'
    ");
    $stmt->execute([':t' => $tenantId, ':t2' => $tenantId]);
    $stats['pending_in'] = (int)$stmt->fetchColumn();

    // Pending outgoing transfers
    $stmt = $pdo->prepare("
      SELECT COUNT(*) 
      FROM stockflow_transfers t
      JOIN branches b ON b.id = t.from_branch_id
      WHERE t.tenant_id = :t AND b.tenant_id = :t2 AND t.status = 'pending'
    ");
    $stmt->execute([':t' => $tenantId, ':t2' => $tenantId]);
    $stats['pending_out'] = (int)$stmt->fetchColumn();

    // Completed today
    $stmt = $pdo->prepare("
      SELECT COUNT(*) 
      FROM stockflow_transfers t
      WHERE t.tenant_id = :t AND t.status = 'received' AND DATE(t.received_at) = CURDATE()
    ");
    $stmt->execute([':t' => $tenantId]);
    $stats['total_today'] = (int)$stmt->fetchColumn();

    // Total pending value
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(
        (SELECT SUM(quantity_requested * unit_cost) 
         FROM stockflow_transfer_items sti 
         WHERE sti.transfer_id = t.id)
      ), 0)
      FROM stockflow_transfers t
      WHERE t.tenant_id = :t AND t.status IN ('pending', 'shipped')
    ");
    $stmt->execute([':t' => $tenantId]);
    $stats['total_value'] = (float)$stmt->fetchColumn();

  } catch (Throwable $e) {
    $error_msg = $e->getMessage();
  }
}

$active = 'stockflow_view';  // This specific value for stockflow view
$hasActiveFilters = ($status !== 'all' || $direction !== 'all' || $branchId > 0 || 
                     $dateFrom !== $defaultDateFrom || $dateTo !== $defaultDateTo || $search !== '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Transfers · Smorll POS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Modern color palette matching categories */
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

        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }

        .stat-card {
            background: white;
            border-radius: var(--ms-radius-lg);
            padding: 20px;
            box-shadow: var(--ms-shadow-2);
            transition: all 0.2s ease;
        }

        .stat-card:hover {
            box-shadow: var(--ms-shadow-3);
            transform: translateY(-2px);
        }

        .stat-value {
            font-size: 32px;
            font-weight: 700;
            line-height: 1;
            margin-bottom: 4px;
        }

        .stat-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--ms-gray-130);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-card.pending-in .stat-value { color: var(--ms-blue); }
        .stat-card.pending-out .stat-value { color: var(--ms-yellow); }
        .stat-card.completed .stat-value { color: var(--ms-green); }

        /* Filters Bar */
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

        /* Fixed width for branch dropdown to prevent expansion */
        .filter-group.branch-group {
            flex: 0 0 260px;
            max-width: 260px;
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
            font-family: inherit;
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
            justify-content: center;
        }

        .btn:hover {
            background: var(--ms-gray-30);
            text-decoration: none;
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

        .btn.success {
            background: var(--ms-green);
            color: white;
            border-color: var(--ms-green);
        }

        .btn.success:hover {
            background: var(--ms-green-darker);
            border-color: var(--ms-green-darker);
        }

        .btn.warning {
            background: var(--ms-yellow);
            color: white;
            border-color: var(--ms-yellow);
        }

        .btn.warning:hover {
            background: #e6790d;
            border-color: #e6790d;
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

        /* Transfer specific styles */
        .transfer-number {
            font-weight: 600;
            color: var(--ms-gray-160);
            font-family: 'SF Mono', Monaco, 'Courier New', monospace;
        }

        .transfer-notes {
            font-size: 12px;
            color: var(--ms-gray-110);
            margin-top: 2px;
        }

        .route-info {
            display: flex;
            flex-direction: column;
            gap: 2px;
        }

        .route-line {
            font-size: 13px;
        }

        .route-label {
            color: var(--ms-gray-110);
        }

        .route-value {
            font-weight: 500;
            color: var(--ms-gray-160);
        }

        /* Status badges */
        .status-badge {
            display: inline-block;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 8px;
            border-radius: 12px;
        }

        .status-badge.pending {
            background: var(--ms-yellow-light);
            color: var(--ms-yellow);
        }

        .status-badge.shipped {
            background: var(--ms-blue-lighter);
            color: var(--ms-blue);
        }

        .status-badge.received {
            background: var(--ms-green-light);
            color: var(--ms-green);
        }

        .status-badge.cancelled {
            background: var(--ms-red-light);
            color: var(--ms-red);
        }

        /* Date cell */
        .date-cell {
            font-size: 13px;
            color: var(--ms-gray-130);
        }

        .date-time {
            color: var(--ms-gray-110);
            font-size: 12px;
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

        .empty-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: var(--ms-gray-20);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
        }

        /* Toast */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
        }

        .toast {
            background: white;
            border-radius: var(--ms-radius-lg);
            padding: 16px 20px;
            margin-bottom: 12px;
            box-shadow: var(--ms-shadow-3);
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 300px;
            animation: slideInRight 0.3s ease;
            border-left: 4px solid;
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .toast.success { border-left-color: var(--ms-green); }
        .toast.error { border-left-color: var(--ms-red); }
        .toast.info { border-left-color: var(--ms-blue); }

        /* Alert */
        .alert {
            padding: 16px 20px;
            border-radius: var(--ms-radius-lg);
            margin-bottom: 16px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            border-left: 4px solid;
        }

        .alert.success {
            background: var(--ms-green-light);
            border-left-color: var(--ms-green);
            color: var(--ms-green-darker);
        }

        .alert.error {
            background: var(--ms-red-light);
            border-left-color: var(--ms-red);
            color: var(--ms-red-darker);
        }

        /* Loading spinner */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid var(--ms-gray-40);
            border-top-color: var(--ms-blue);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin { to { transform: rotate(360deg); } }

        /* Actions */
        .actions {
            display: flex;
            gap: 8px;
            white-space: nowrap;
        }

        /* ID column */
        .id-column {
            color: var(--ms-gray-110);
            font-size: 12px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .filters-bar {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            .filter-group.search-group,
            .filter-group.branch-group {
                flex: 1 1 100%;
                max-width: 100%;
            }
            
            .filter-actions {
                width: 100%;
                margin-left: 0;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .actions {
                flex-direction: column;
                gap: 4px;
            }
            
            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php
    $active = 'stockflow_view';  // This specific value for stockflow view
    try {
        // FIX: go up two levels from /views/admin/stockflow to /views/partials
        require __DIR__ . '/../../partials/admin_nav.php';
    } catch (Throwable $e) {
        echo "<div class='alert error'>Navigation error: " . h($e->getMessage()) . "</div>";
    }
    ?>
    
    <div class="container">
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
            
            <?php if ($flash): ?>
                <div class="alert success">
                    <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <?= h($flash) ?>
                </div>
            <?php endif; ?>
            
            <?php if ($DEBUG && $error_msg): ?>
                <div class="alert error">
                    <strong>Debug:</strong> <?= h($error_msg) ?>
                </div>
            <?php endif; ?>
            
            <!-- Page Header -->
            <div class="h1">Stock Transfers</div>
            <p class="sub">Manage inventory transfers between branches</p>
            
            <!-- Stats Cards -->
            <div class="stats-grid">
                <div class="stat-card pending-in">
                    <div class="stat-value"><?= $stats['pending_in'] ?></div>
                    <div class="stat-label">Pending Incoming</div>
                </div>
                <div class="stat-card pending-out">
                    <div class="stat-value"><?= $stats['pending_out'] ?></div>
                    <div class="stat-label">Pending Outgoing</div>
                </div>
                <div class="stat-card completed">
                    <div class="stat-value"><?= $stats['total_today'] ?></div>
                    <div class="stat-label">Completed Today</div>
                </div>
            </div>
            
            <!-- Filters -->
            <div class="filters-bar">
                <form method="get" action="" id="filterForm" style="display: contents;">
                    <div class="filter-group search-group">
                        <label>Search</label>
                        <input type="text" name="q" id="searchInput" placeholder="Search transfer number or notes..." 
                               value="<?= h($search) ?>" onkeyup="debounceSearch()">
                    </div>
                    
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status" id="statusFilter" onchange="applyFilters()">
                            <option value="all" <?= $status==='all'?'selected':'' ?>>All Status</option>
                            <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
                            <option value="shipped" <?= $status==='shipped'?'selected':'' ?>>Shipped</option>
                            <option value="received" <?= $status==='received'?'selected':'' ?>>Received</option>
                            <option value="cancelled" <?= $status==='cancelled'?'selected':'' ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Direction</label>
                        <select name="dir" id="directionFilter" onchange="applyFilters()">
                            <option value="all" <?= $direction==='all'?'selected':'' ?>>All Transfers</option>
                            <option value="in" <?= $direction==='in'?'selected':'' ?>>Incoming</option>
                            <option value="out" <?= $direction==='out'?'selected':'' ?>>Outgoing</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Date From</label>
                        <input type="date" name="date_from" id="dateFromFilter" value="<?= h($dateFrom) ?>" onchange="applyFilters()">
                    </div>
                    
                    <div class="filter-group">
                        <label>Date To</label>
                        <input type="date" name="date_to" id="dateToFilter" value="<?= h($dateTo) ?>" onchange="applyFilters()">
                    </div>
                    
                    <div class="filter-actions">
                        <?php if ($hasActiveFilters): ?>
                            <button type="button" class="btn" onclick="clearFilters()">Clear Filters</button>
                        <?php endif; ?>
                    </div>
                    
                    <div class="filter-group branch-group">
                        <label>Branch</label>
                        <select name="branch" id="branchFilter" onchange="applyFilters()">
                            <option value="0" <?= $branchId===0?'selected':'' ?>>All Branches</option>
                            <?php foreach ($branches as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $branchId===(int)$b['id']?'selected':'' ?>>
                                    <?= h($b['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </form>
            </div>
            
            <!-- Transfers Table -->
            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">Transfers List</h2>
                    <a class="btn primary" href="/views/admin/stockflow/transfer.php">+ New Transfer</a>
                </div>
                
                <?php if (!$transfers): ?>
                    <div class="empty-state">
                        <div class="empty-icon">📦</div>
                        <h3>No transfers found</h3>
                        <p>
                            <?php if ($hasActiveFilters): ?>
                                No transfers match the selected filters. Try adjusting your filters or clear them to see all transfers.
                            <?php else: ?>
                                Start by creating your first stock transfer between branches.
                            <?php endif; ?>
                        </p>
                        <?php if (!$hasActiveFilters): ?>
                            <br>
                            <a href="/views/admin/stockflow/transfer.php" class="btn primary">+ Create First Transfer</a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <table class="table">
                        <thead>
                            <tr>
                                <th style="width: 60px;">ID</th>
                                <th>Transfer #</th>
                                <th>Route</th>
                                <th style="width: 80px; text-align: center;">Items</th>
                                <th style="width: 100px;">Status</th>
                                <th style="width: 140px;">Created</th>
                                <th style="width: 200px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transfers as $transfer): ?>
                                <tr>
                                    <td class="id-column">#<?= (int)$transfer['id'] ?></td>
                                    <td>
                                        <div class="transfer-number"><?= h($transfer['transfer_number']) ?></div>
                                        <?php if ($transfer['notes']): ?>
                                            <div class="transfer-notes">
                                                <?= h(substr($transfer['notes'], 0, 30)) ?><?= strlen($transfer['notes']) > 30 ? '...' : '' ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="route-info">
                                            <div class="route-line">
                                                <span class="route-label">From:</span>
                                                <span class="route-value"><?= h($transfer['from_branch_name'] ?: '—') ?></span>
                                            </div>
                                            <div class="route-line">
                                                <span class="route-label">To:</span>
                                                <span class="route-value"><?= h($transfer['to_branch_name'] ?: '—') ?></span>
                                            </div>
                                        </div>
                                    </td>
                                    <td style="text-align: center; font-weight: 600;"><?= (int)$transfer['total_items'] ?></td>
                                    <td>
                                        <span class="status-badge <?= h($transfer['status']) ?>">
                                            <?= h($transfer['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="date-cell">
                                            <?= date('M j, Y', strtotime($transfer['created_at'])) ?>
                                            <div class="date-time">
                                                <?= date('g:i A', strtotime($transfer['created_at'])) ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="actions">
                                            <?php if (in_array($transfer['status'], ['received', 'cancelled'])): ?>
                                                <a class="btn small" href="/views/admin/stockflow/view_transfer.php?id=<?= (int)$transfer['id'] ?>">View</a>
                                            <?php else: ?>
                                                <a class="btn small" href="/views/admin/stockflow/transfer.php?id=<?= (int)$transfer['id'] ?>">Edit</a>
                                            <?php endif; ?>
                                            
                                            <?php if ($transfer['status'] === 'pending'): ?>
                                                <form method="post" action="/controllers/admin/stockflow/ship_transfer.php" style="display: inline;">
                                                    <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                                                    <button type="submit" class="btn small success" 
                                                            onclick="return confirm('Ship this transfer?')">Ship</button>
                                                </form>
                                            <?php endif; ?>
                                            
                                            <?php if ($transfer['status'] === 'shipped'): ?>
                                                <form method="post" action="/controllers/admin/stockflow/receive_transfer.php" style="display: inline;">
                                                    <input type="hidden" name="transfer_id" value="<?= (int)$transfer['id'] ?>">
                                                    <button type="submit" class="btn small warning" 
                                                            onclick="return confirm('Receive this transfer?')">Receive</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <?php
    $navClosePath = __DIR__ . '/../../partials/admin_nav_close.php';
    if (file_exists($navClosePath)) {
        require $navClosePath;
    }
    ?>
    
    <script>
        let searchTimer = null;
        let filterTimer = null;
        
        // Debounced search
        function debounceSearch() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 600);
        }
        
        // Debounced filter apply
        function applyFilters() {
            clearTimeout(filterTimer);
            showToast('Applying filters...', 'info');
            filterTimer = setTimeout(() => {
                document.getElementById('filterForm').submit();
            }, 400);
        }
        
        // Clear filters
        function clearFilters() {
            document.getElementById('searchInput').value = '';
            document.getElementById('statusFilter').value = 'all';
            document.getElementById('directionFilter').value = 'all';
            document.getElementById('branchFilter').value = '0';
            document.getElementById('dateFromFilter').value = '<?= h($defaultDateFrom) ?>';
            document.getElementById('dateToFilter').value = '<?= h($defaultDateTo) ?>';
            document.getElementById('filterForm').submit();
        }
        
        // Toast Notifications
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            
            const icon = type === 'success' ? 
                '✓' : type === 'error' ? '✕' : 'ℹ';
            
            toast.innerHTML = `<span style="font-size: 16px;">${icon}</span><span>${escapeHtml(message)}</span>`;
            
            container.appendChild(toast);
            
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(100%)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
        
        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    </script>
</body>
</html>