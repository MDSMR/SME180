<?php
// /views/admin/rewards/stamp/reports.php
// Streamlined stamp reports matching discount report design
declare(strict_types=1);

// Clear any stale output buffers
while (ob_get_level()) {
    ob_end_clean();
}

// Force no-cache headers BEFORE any output
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    echo '<h1>Bootstrap Failed</h1><p>' . h($bootstrap_warning) . '</p>';
    exit;
}

/* Get filter parameters - ensure they're properly retrieved */
$reportType = isset($_GET['report_type']) ? $_GET['report_type'] : 'transactions';
$programId = isset($_GET['program_id']) ? (int)$_GET['program_id'] : 0;
$branchId = isset($_GET['branch_id']) ? (int)$_GET['branch_id'] : 0;
$dateFrom = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$dateTo = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

// Validate report type
if (!in_array($reportType, ['transactions', 'programs', 'customers'])) {
    $reportType = 'transactions';
}

/* Load programs and branches for selectors */
$programs = [];
$branches = [];
if ($pdo instanceof PDO) {
    try {
        // Load stamp programs
        $stmt = $pdo->prepare("SELECT id, name FROM loyalty_programs WHERE tenant_id = ? AND program_type = 'stamp' ORDER BY name");
        $stmt->execute([$tenantId]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Load branches
        $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name");
        $stmt->execute([$tenantId]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error loading filters: " . $e->getMessage());
    }
}

/* Initialize summary metrics */
$summaryMetrics = [
    'total_stamps_issued' => 0,
    'total_stamps_redeemed' => 0,
    'active_cards' => 0,
    'completion_rate' => 0
];

/* Initialize report data */
$reportData = [];

if ($pdo instanceof PDO) {
    try {
        // Build base WHERE conditions for loyalty_ledgers table
        $whereConditions = ["ll.tenant_id = ?"];
        $whereParams = [$tenantId];
        
        // Add date range filter
        $whereConditions[] = "DATE(ll.created_at) BETWEEN ? AND ?";
        $whereParams[] = $dateFrom;
        $whereParams[] = $dateTo;
        
        // Check if we need to filter by program type
        $hasProgTypeColumn = false;
        try {
            $checkStmt = $pdo->query("SHOW COLUMNS FROM loyalty_ledgers LIKE 'program_type'");
            if ($checkStmt->rowCount() > 0) {
                $hasProgTypeColumn = true;
                $whereConditions[] = "ll.program_type = 'stamp'";
            }
        } catch (Exception $e) {
            // Column doesn't exist, continue without it
        }
        
        // Add program filter if specified
        if ($programId > 0) {
            $whereConditions[] = "ll.program_id = ?";
            $whereParams[] = $programId;
        }
        
        // Add branch filter if specified
        if ($branchId > 0) {
            $whereConditions[] = "ll.branch_id = ?";
            $whereParams[] = $branchId;
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        // Calculate summary metrics
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN ll.direction = 'earn' THEN COALESCE(ll.amount, 0) ELSE 0 END) as stamps_issued,
                SUM(CASE WHEN ll.direction = 'redeem' THEN COALESCE(ll.amount, 0) ELSE 0 END) as stamps_redeemed,
                COUNT(DISTINCT ll.customer_id) as active_customers,
                COUNT(DISTINCT CONCAT(ll.customer_id, '-', ll.program_id)) as active_cards
            FROM loyalty_ledgers ll 
            WHERE {$whereClause}
        ");
        $stmt->execute($whereParams);
        $metrics = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($metrics) {
            $summaryMetrics['total_stamps_issued'] = (int)($metrics['stamps_issued'] ?? 0);
            $summaryMetrics['total_stamps_redeemed'] = (int)($metrics['stamps_redeemed'] ?? 0);
            $summaryMetrics['active_cards'] = (int)($metrics['active_cards'] ?? 0);
            
            // Calculate completion rate
            if ($summaryMetrics['total_stamps_issued'] > 0) {
                $summaryMetrics['completion_rate'] = round(
                    ($summaryMetrics['total_stamps_redeemed'] / $summaryMetrics['total_stamps_issued']) * 100, 
                    1
                );
            }
        }
        
        // Load data based on report type
        switch($reportType) {
            case 'transactions':
                // Recent transactions
                $sql = "
                    SELECT 
                        ll.created_at,
                        ll.direction,
                        ll.amount,
                        ll.reason,
                        c.name as customer_name,
                        c.phone as customer_phone,
                        lp.name as program_name,
                        b.name as branch_name
                    FROM loyalty_ledgers ll
                    LEFT JOIN customers c ON c.id = ll.customer_id AND c.tenant_id = ll.tenant_id
                    LEFT JOIN loyalty_programs lp ON lp.id = ll.program_id AND lp.tenant_id = ll.tenant_id
                    LEFT JOIN branches b ON b.id = ll.branch_id AND b.tenant_id = ll.tenant_id
                    WHERE {$whereClause}
                    ORDER BY ll.created_at DESC
                    LIMIT 100
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($whereParams);
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            case 'programs':
                // Program performance
                $sql = "
                    SELECT 
                        lp.id,
                        lp.name,
                        lp.stamps_required,
                        lp.status,
                        COUNT(DISTINCT ll.customer_id) as active_customers,
                        COALESCE(SUM(CASE WHEN ll.direction = 'earn' THEN ll.amount ELSE 0 END), 0) as stamps_issued,
                        COALESCE(SUM(CASE WHEN ll.direction = 'redeem' THEN ll.amount ELSE 0 END), 0) as stamps_redeemed
                    FROM loyalty_programs lp
                    LEFT JOIN loyalty_ledgers ll ON ll.program_id = lp.id 
                        AND ll.tenant_id = lp.tenant_id
                        AND DATE(ll.created_at) BETWEEN ? AND ?
                ";
                
                // Add branch filter to JOIN if needed
                if ($branchId > 0) {
                    $sql .= " AND ll.branch_id = ?";
                }
                
                $sql .= " WHERE lp.tenant_id = ? AND lp.program_type = 'stamp'";
                
                if ($programId > 0) {
                    $sql .= " AND lp.id = ?";
                }
                
                $sql .= " GROUP BY lp.id ORDER BY active_customers DESC";
                
                // Build parameters for this query
                $progParams = [$dateFrom, $dateTo];
                if ($branchId > 0) {
                    $progParams[] = $branchId;
                }
                $progParams[] = $tenantId;
                if ($programId > 0) {
                    $progParams[] = $programId;
                }
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($progParams);
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
                
            case 'customers':
                // Customer activity
                $sql = "
                    SELECT 
                        c.id,
                        c.name,
                        c.phone,
                        COUNT(DISTINCT ll.program_id) as programs_participated,
                        COALESCE(SUM(CASE WHEN ll.direction = 'earn' THEN ll.amount ELSE 0 END), 0) as stamps_earned,
                        COALESCE(SUM(CASE WHEN ll.direction = 'redeem' THEN ll.amount ELSE 0 END), 0) as stamps_redeemed,
                        MAX(ll.created_at) as last_activity
                    FROM customers c
                    JOIN loyalty_ledgers ll ON ll.customer_id = c.id AND ll.tenant_id = c.tenant_id
                    WHERE {$whereClause}
                    GROUP BY c.id
                    HAVING stamps_earned > 0 OR stamps_redeemed > 0
                    ORDER BY stamps_earned DESC
                    LIMIT 100
                ";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($whereParams);
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                break;
        }
        
    } catch (Exception $e) {
        error_log('Stamp reports error: ' . $e->getMessage());
        error_log('SQL: ' . ($sql ?? 'N/A'));
    }
}

$active = 'rewards_stamps_view';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Stamp Reports · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
<meta http-equiv="Pragma" content="no-cache">
<meta http-equiv="Expires" content="0">
<!-- FIXED: Absolute path with cache buster -->
<link rel="stylesheet" href="/views/admin/rewards/stamp/_shared/styles.css?v=<?= time() ?>">
<style>
/* Navigation tabs styling */
.points-nav {
  display: flex;
  gap: 0;
  background: white;
  border-radius: var(--ms-radius-lg);
  box-shadow: var(--ms-shadow-2);
  margin-bottom: 24px;
  overflow: hidden;
}

.points-nav-tab {
  flex: 1;
  padding: 16px 24px;
  background: white;
  border: none;
  border-right: 1px solid var(--ms-gray-30);
  font-size: 14px;
  font-weight: 600;
  color: var(--ms-gray-110);
  cursor: pointer;
  transition: all 0.2s ease;
  position: relative;
  text-decoration: none;
  text-align: center;
}

.points-nav-tab:last-child {
  border-right: none;
}

.points-nav-tab:hover {
  background: var(--ms-gray-10);
  color: var(--ms-gray-130);
}

.points-nav-tab.active {
  background: var(--ms-blue-lighter);
  color: var(--ms-blue);
}

.points-nav-tab.active::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: var(--ms-blue);
}

/* Enhanced Filter Bar with Report Selector */
.filters-bar {
  display: flex;
  gap: 16px;
  padding: 20px;
  background: white;
  border-radius: var(--ms-radius-lg);
  box-shadow: var(--ms-shadow-2);
  margin-bottom: 24px;
  align-items: end;
  flex-wrap: nowrap;
  overflow-x: auto;
}

/* Allow wrapping only on smaller screens */
@media (max-width: 1400px) {
  .filters-bar {
    flex-wrap: wrap;
  }
}

.filter-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
  flex-shrink: 0;
}

.filter-group label {
  font-size: 12px;
  font-weight: 600;
  color: var(--ms-gray-130);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.filter-group select,
.filter-group input {
  padding: 8px 12px;
  border: 1px solid var(--ms-gray-60);
  border-radius: var(--ms-radius);
  font-size: 14px;
  background: white;
  min-width: 140px;
  transition: all 0.2s ease;
}

.filter-group select:hover,
.filter-group input:hover {
  border-color: var(--ms-gray-110);
}

.filter-group select:focus,
.filter-group input:focus {
  outline: none;
  border-color: var(--ms-blue);
  box-shadow: 0 0 0 2px rgba(0, 120, 212, 0.25);
}

/* Special styling for report type selector */
.filter-group.report-selector-group {
  border-right: 2px solid var(--ms-gray-30);
  padding-right: 20px;
  margin-right: 4px;
}

.filter-group.report-selector-group select {
  min-width: 180px;
  font-weight: 600;
  color: var(--ms-blue);
  border-color: var(--ms-blue);
  background-color: var(--ms-blue-lighter);
}

.filter-group.report-selector-group select:hover {
  background-color: var(--ms-blue-light);
  border-color: var(--ms-blue-hover);
}

.export-buttons {
  margin-left: auto;
  display: flex;
  gap: 8px;
}

.metrics-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.metric-card {
  background: white;
  padding: 20px;
  border-radius: var(--ms-radius-lg);
  box-shadow: var(--ms-shadow-2);
  border-left: 4px solid var(--ms-blue);
  transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.metric-card:hover {
  transform: translateY(-2px);
  box-shadow: var(--ms-shadow-3);
}

.metric-value {
  font-size: 28px;
  font-weight: 700;
  color: var(--ms-gray-160);
  margin-bottom: 4px;
}

.metric-label {
  font-size: 12px;
  color: var(--ms-gray-110);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.report-table {
  width: 100%;
  background: white;
  border-radius: var(--ms-radius-lg);
  overflow: hidden;
  box-shadow: var(--ms-shadow-2);
}

.report-table th {
  background: var(--ms-gray-20);
  padding: 12px 16px;
  text-align: left;
  font-size: 12px;
  font-weight: 600;
  color: var(--ms-gray-130);
  text-transform: uppercase;
  letter-spacing: 0.5px;
  border-bottom: 2px solid var(--ms-gray-30);
}

.report-table td {
  padding: 14px 16px;
  border-bottom: 1px solid var(--ms-gray-20);
  font-size: 14px;
}

.report-table tbody tr:hover {
  background: var(--ms-gray-10);
}

.report-table tbody tr:last-child td {
  border-bottom: none;
}

.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--ms-gray-110);
}

.badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
}

.badge.good,
.badge.active {
  background: var(--ms-green);
  color: white;
}

.badge.warn {
  background: var(--ms-yellow);
  color: var(--ms-gray-160);
}

.badge.inactive {
  background: var(--ms-gray-60);
  color: white;
}

/* Loading indicator */
.loading-indicator {
  display: none;
  text-align: center;
  padding: 20px;
  color: var(--ms-gray-110);
}

.loading-indicator.active {
  display: block;
}

@media (max-width: 768px) {
  .filters-bar {
    flex-direction: column;
  }
  
  .filter-group.report-selector-group {
    border-right: none;
    border-bottom: 2px solid var(--ms-gray-30);
    padding-right: 0;
    padding-bottom: 16px;
    margin-right: 0;
    margin-bottom: 16px;
    width: 100%;
  }
  
  .export-buttons {
    margin-left: 0;
    width: 100%;
  }
  
  .export-buttons button {
    flex: 1;
  }
}
</style>
</head>
<body>

<?php 
$nav_included = include_admin_nav('rewards_stamps_view');
if (!$nav_included) {
    echo '<div class="notice alert-error">Navigation component not found.</div>';
}
?>

<div class="container">
  <div class="report-header">
    <div>
      <h1 class="h1">Stamp Reports</h1>
      <p class="sub">Monitor stamp card performance and customer activity</p>
    </div>
  </div>

  <!-- Navigation Tabs - FIXED -->
  <div class="points-nav">
    <a href="index.php" class="points-nav-tab">Programs</a>
    <a href="create.php" class="points-nav-tab">Create Program</a>
    <a href="reports.php" class="points-nav-tab active">Reports</a>
  </div>

  <!-- Summary Metrics - Moved above filters -->
  <div class="metrics-row">
    <div class="metric-card">
      <div class="metric-value"><?= number_format($summaryMetrics['total_stamps_issued']) ?></div>
      <div class="metric-label">Stamps Issued</div>
    </div>
    
    <div class="metric-card">
      <div class="metric-value"><?= number_format($summaryMetrics['total_stamps_redeemed']) ?></div>
      <div class="metric-label">Stamps Redeemed</div>
    </div>
    
    <div class="metric-card">
      <div class="metric-value"><?= number_format($summaryMetrics['active_cards']) ?></div>
      <div class="metric-label">Active Cards</div>
    </div>
    
    <div class="metric-card">
      <div class="metric-value"><?= $summaryMetrics['completion_rate'] ?>%</div>
      <div class="metric-label">Completion Rate</div>
    </div>
  </div>

  <!-- Unified Filters with Report Selector -->
  <form method="GET" action="reports.php" id="filterForm">
    <div class="filters-bar">
      <div class="filter-group report-selector-group">
        <label for="reportType">Report Type</label>
        <select id="reportType" name="report_type" onchange="this.form.submit()

<option value="transactions" <?= $reportType === 'transactions' ? 'selected' : '' ?>>Transactions</option>
          <option value="programs" <?= $reportType === 'programs' ? 'selected' : '' ?>>Program Performance</option>
          <option value="customers" <?= $reportType === 'customers' ? 'selected' : '' ?>>Customer Activity</option>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="date_from">From Date</label>
        <input type="date" id="date_from" name="date_from" value="<?= h($dateFrom) ?>" onchange="this.form.submit()">
      </div>
      
      <div class="filter-group">
        <label for="date_to">To Date</label>
        <input type="date" id="date_to" name="date_to" value="<?= h($dateTo) ?>" onchange="this.form.submit()">
      </div>
      
      <div class="filter-group">
        <label for="program_id">Program</label>
        <select id="program_id" name="program_id" onchange="this.form.submit()">
          <option value="0">All Programs</option>
          <?php foreach($programs as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $programId === (int)$p['id'] ? 'selected' : '' ?>>
            <?= h($p['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="filter-group">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id" onchange="this.form.submit()">
          <option value="0">All Branches</option>
          <?php foreach($branches as $b): ?>
          <option value="<?= (int)$b['id'] ?>" <?= $branchId === (int)$b['id'] ? 'selected' : '' ?>>
            <?= h($b['name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      
      <div class="export-buttons">
        <button type="button" class="btn" onclick="exportReport('csv')">Export</button>
      </div>
    </div>
  </form>

  <!-- Loading indicator -->
  <div id="loadingIndicator" class="loading-indicator">
    <p>Loading report data...</p>
  </div>

  <!-- Report Table -->
  <table class="report-table" id="reportTable">
    <?php if ($reportType === 'transactions'): ?>
      <thead>
        <tr>
          <th>Date</th>
          <th>Customer</th>
          <th>Program</th>
          <th>Branch</th>
          <th>Action</th>
          <th>Stamps</th>
          <th>Reason</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reportData)): ?>
        <tr>
          <td colspan="7" class="empty-state">
            <h3>No transactions found</h3>
            <p>No stamp activity in the selected period</p>
          </td>
        </tr>
        <?php else: ?>
          <?php foreach($reportData as $row): ?>
          <tr>
            <td><?= date('M j, Y', strtotime($row['created_at'])) ?></td>
            <td>
              <strong><?= h($row['customer_name'] ?? 'Unknown') ?></strong>
              <?php if (!empty($row['customer_phone'])): ?>
                <div style="font-size: 12px; color: var(--ms-gray-110);"><?= h($row['customer_phone']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= h($row['program_name'] ?? '—') ?></td>
            <td><?= h($row['branch_name'] ?? 'Main') ?></td>
            <td>
              <?= $row['direction'] === 'redeem' 
                  ? '<span class="badge warn">Redeem</span>' 
                  : '<span class="badge good">Earn</span>' ?>
            </td>
            <td style="font-weight: 600;">
              <?php 
              $color = $row['direction'] === 'redeem' ? 'var(--ms-red)' : 'var(--ms-green)';
              $sign = $row['direction'] === 'redeem' ? '-' : '+';
              ?>
              <span style="color: <?= $color ?>;"><?= $sign . number_format((int)$row['amount']) ?></span>
            </td>
            <td><?= h($row['reason'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      
    <?php elseif ($reportType === 'programs'): ?>
      <thead>
        <tr>
          <th>Program Name</th>
          <th>Status</th>
          <th>Stamps Required</th>
          <th>Active Customers</th>
          <th>Stamps Issued</th>
          <th>Stamps Redeemed</th>
          <th>Completion Rate</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reportData)): ?>
        <tr>
          <td colspan="7" class="empty-state">
            <h3>No program data found</h3>
            <p>No stamp programs in the selected period</p>
          </td>
        </tr>
        <?php else: ?>
          <?php foreach($reportData as $row): ?>
          <tr>
            <td><strong><?= h($row['name']) ?></strong></td>
            <td>
              <?php 
              $status = $row['status'] ?? 'inactive';
              $statusClass = $status === 'active' ? 'active' : 'inactive';
              ?>
              <span class="badge <?= $statusClass ?>"><?= h($status) ?></span>
            </td>
            <td><?= number_format((int)$row['stamps_required']) ?></td>
            <td><?= number_format((int)$row['active_customers']) ?></td>
            <td style="color: var(--ms-green); font-weight: 600;"><?= number_format((int)$row['stamps_issued']) ?></td>
            <td style="color: var(--ms-red); font-weight: 600;"><?= number_format((int)$row['stamps_redeemed']) ?></td>
            <td>
              <?php 
              $issued = (int)$row['stamps_issued'];
              $redeemed = (int)$row['stamps_redeemed'];
              $rate = $issued > 0 ? round(($redeemed / $issued) * 100, 1) : 0;
              ?>
              <?= $rate ?>%
            </td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      
    <?php elseif ($reportType === 'customers'): ?>
      <thead>
        <tr>
          <th>Customer</th>
          <th>Programs</th>
          <th>Stamps Earned</th>
          <th>Stamps Redeemed</th>
          <th>Net Balance</th>
          <th>Last Activity</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($reportData)): ?>
        <tr>
          <td colspan="6" class="empty-state">
            <h3>No customer data found</h3>
            <p>No customer activity in the selected period</p>
          </td>
        </tr>
        <?php else: ?>
          <?php foreach($reportData as $row): ?>
          <tr>
            <td>
              <strong><?= h($row['name'] ?? 'Customer #' . $row['id']) ?></strong>
              <?php if (!empty($row['phone'])): ?>
                <div style="font-size: 12px; color: var(--ms-gray-110);"><?= h($row['phone']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= number_format((int)$row['programs_participated']) ?></td>
            <td style="color: var(--ms-green); font-weight: 600;">+<?= number_format((int)$row['stamps_earned']) ?></td>
            <td style="color: var(--ms-red); font-weight: 600;">-<?= number_format((int)$row['stamps_redeemed']) ?></td>
            <td style="font-weight: 600;">
              <?php 
              $balance = (int)$row['stamps_earned'] - (int)$row['stamps_redeemed'];
              $balanceColor = $balance > 0 ? 'var(--ms-blue)' : 'var(--ms-gray-110)';
              ?>
              <span style="color: <?= $balanceColor ?>;"><?= number_format($balance) ?></span>
            </td>
            <td><?= $row['last_activity'] ? date('M j, Y', strtotime($row['last_activity'])) : '—' ?></td>
          </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    <?php endif; ?>
  </table>

  <!-- Debug info (remove in production) -->
  <?php if (isset($_GET['debug'])): ?>
  <div style="margin-top: 20px; padding: 10px; background: #f0f0f0; border-radius: 5px;">
    <h4>Debug Info:</h4>
    <pre><?= print_r([
      'report_type' => $reportType,
      'program_id' => $programId,
      'branch_id' => $branchId,
      'date_from' => $dateFrom,
      'date_to' => $dateTo,
      'data_count' => count($reportData)
    ], true) ?></pre>
  </div>
  <?php endif; ?>
</div>

<script>
// Show loading indicator when form is being submitted
document.getElementById('filterForm').addEventListener('submit', function() {
    document.getElementById('loadingIndicator').classList.add('active');
});

// Export function
function exportReport(format) {
    const params = new URLSearchParams(window.location.search);
    params.append('export', format);
    
    // Create a temporary link and click it
    const link = document.createElement('a');
    link.href = '/controllers/admin/rewards/stamp/export_report.php?' + params.toString();
    link.download = 'stamp-report-' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Add a timestamp to prevent caching issues
window.addEventListener('pageshow', function(event) {
    if (event.persisted) {
        window.location.reload();
    }
});
</script>

</body>
</html>