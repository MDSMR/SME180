<?php
// /views/admin/rewards/points/reports.php
// Streamlined Reports & Analytics Dashboard
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    echo '<h1>Bootstrap Failed</h1><p>' . h($bootstrap_warning) . '</p>';
    exit;
}

/* Handle AJAX Filter Requests */
if (isset($_POST['ajax_filter']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    try {
        $selectedBranch = $_POST['branch_id'] ?? 'all';
        $dateFrom = $_POST['date_from'] ?? date('Y-m-01');
        $dateTo = $_POST['date_to'] ?? date('Y-m-d');
        $reportType = $_POST['report_type'] ?? 'transactions';
        
        // Load filtered transactions
        $transactions = [];
        if ($pdo instanceof PDO) {
            $whereClause = "WHERE ll.tenant_id = ? AND DATE(ll.created_at) BETWEEN ? AND ?";
            $params = [$tenantId, $dateFrom, $dateTo];
            
            if ($selectedBranch !== 'all' && is_numeric($selectedBranch)) {
                $whereClause .= " AND (o.branch_id = ? OR o.branch_id IS NULL)";
                $params[] = (int)$selectedBranch;
            }
            
            if ($reportType === 'earned') {
                $whereClause .= " AND ll.points_delta > 0";
            } elseif ($reportType === 'redeemed') {
                $whereClause .= " AND ll.points_delta < 0";
            } elseif ($reportType === 'expired') {
                $whereClause .= " AND ll.type = 'expire'";
            }
            
            $st = $pdo->prepare("
                SELECT ll.*, 
                       c.name as customer_name, 
                       c.phone as customer_phone,
                       b.display_name as branch_name,
                       o.receipt_reference
                FROM loyalty_ledger ll
                LEFT JOIN customers c ON ll.customer_id = c.id
                LEFT JOIN orders o ON ll.order_id = o.id
                LEFT JOIN branches b ON o.branch_id = b.id
                $whereClause
                ORDER BY ll.created_at DESC
                LIMIT 100
            ");
            $st->execute($params);
            $transactions = $st->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Generate transactions HTML
        ob_start();
        if (empty($transactions)) {
            echo '<tr><td colspan="6" class="empty-state">No transactions found for the selected filters.</td></tr>';
        } else {
            foreach ($transactions as $tx) {
                $typeLabel = ucfirst(str_replace('_', ' ', $tx['type'] ?? 'transaction'));
                $badgeClass = ($tx['points_delta'] ?? 0) > 0 ? 'live' : 'info';
                if (($tx['type'] ?? '') === 'expire') $badgeClass = 'danger';
                
                echo '<tr>';
                echo '<td>' . date('M j, Y', strtotime($tx['created_at'])) . '</td>';
                echo '<td>';
                echo '<strong>' . h($tx['customer_name'] ?? 'Unknown') . '</strong>';
                if (!empty($tx['customer_phone'])) {
                    echo '<div style="font-size: 12px; color: var(--ms-gray-110);">' . h($tx['customer_phone']) . '</div>';
                }
                echo '</td>';
                echo '<td>' . h($tx['branch_name'] ?? 'Main') . '</td>';
                echo '<td><span class="badge ' . $badgeClass . '">' . h($typeLabel) . '</span></td>';
                echo '<td>';
                $pointsColor = ($tx['points_delta'] ?? 0) > 0 ? 'var(--ms-green)' : 'var(--ms-red)';
                $pointsSign = ($tx['points_delta'] ?? 0) > 0 ? '+' : '';
                echo '<span style="color: ' . $pointsColor . '; font-weight: 600;">';
                echo $pointsSign . number_format($tx['points_delta'] ?? 0);
                echo '</span>';
                echo '</td>';
                echo '<td>' . h($tx['reason'] ?? '—') . '</td>';
                echo '</tr>';
            }
        }
        $transactionsHtml = ob_get_clean();
        
        echo json_encode([
            'success' => true,
            'transactions_html' => $transactionsHtml,
            'count' => count($transactions)
        ]);
        exit;
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Filter error: ' . $e->getMessage()
        ]);
        exit;
    }
}

/* Get Filter Parameters */
$reportType = $_GET['report_type'] ?? 'transactions';
$selectedBranch = $_GET['branch_id'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');

/* Simple Analytics with Fallback */
$analytics = [
    'totalPointsEarned' => 0,
    'totalPointsRedeemed' => 0,
    'pointsLiability' => 0,
    'activeMembersCount' => 0
];

// Try to load real data if possible
if ($pdo instanceof PDO) {
    try {
        // Points earned in period
        $st = $pdo->prepare("SELECT COALESCE(SUM(points_delta), 0) as total FROM loyalty_ledger 
                            WHERE tenant_id = ? AND points_delta > 0 
                            AND DATE(created_at) BETWEEN ? AND ?");
        $st->execute([$tenantId, $dateFrom, $dateTo]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        $analytics['totalPointsEarned'] = (int)($res['total'] ?? 0);
        
        // Points redeemed in period
        $st = $pdo->prepare("SELECT COALESCE(ABS(SUM(points_delta)), 0) as total FROM loyalty_ledger 
                            WHERE tenant_id = ? AND points_delta < 0 
                            AND DATE(created_at) BETWEEN ? AND ?");
        $st->execute([$tenantId, $dateFrom, $dateTo]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        $analytics['totalPointsRedeemed'] = (int)($res['total'] ?? 0);
        
        // Current points liability (all customers' current balance)
        $st = $pdo->prepare("SELECT COALESCE(SUM(loyalty_points), 0) as total FROM customers 
                            WHERE tenant_id = ? AND loyalty_points > 0");
        $st->execute([$tenantId]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        $analytics['pointsLiability'] = (int)($res['total'] ?? 0);
        
        // Active members count
        $st = $pdo->prepare("SELECT COUNT(DISTINCT customer_id) as total FROM loyalty_ledger 
                            WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ?");
        $st->execute([$tenantId, $dateFrom, $dateTo]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        $analytics['activeMembersCount'] = (int)($res['total'] ?? 0);
        
    } catch(Throwable $e) {
        // Keep fallback data
    }
}

/* Load Branch Data for Multi-location Support */
$branches = [];
if ($pdo instanceof PDO) {
    try {
        $st = $pdo->prepare("SELECT id, name, display_name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name");
        $st->execute([$tenantId]);
        $branches = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) {
        // Continue with empty branches array
    }
}

/* Load Filtered Transactions */
$transactions = [];
if ($pdo instanceof PDO) {
    try {
        $whereClause = "WHERE ll.tenant_id = ? AND DATE(ll.created_at) BETWEEN ? AND ?";
        $params = [$tenantId, $dateFrom, $dateTo];
        
        // Add branch filter if specified
        if ($selectedBranch !== 'all' && is_numeric($selectedBranch)) {
            $whereClause .= " AND (o.branch_id = ? OR o.branch_id IS NULL)";
            $params[] = (int)$selectedBranch;
        }
        
        if ($reportType === 'earned') {
            $whereClause .= " AND ll.points_delta > 0";
        } elseif ($reportType === 'redeemed') {
            $whereClause .= " AND ll.points_delta < 0";
        } elseif ($reportType === 'expired') {
            $whereClause .= " AND ll.type = 'expire'";
        }
        
        $st = $pdo->prepare("SELECT ll.*, 
                            c.name as customer_name, 
                            c.phone as customer_phone,
                            b.display_name as branch_name
                            FROM loyalty_ledger ll
                            LEFT JOIN customers c ON ll.customer_id = c.id
                            LEFT JOIN orders o ON ll.order_id = o.id
                            LEFT JOIN branches b ON o.branch_id = b.id
                            $whereClause
                            ORDER BY ll.created_at DESC
                            LIMIT 100");
        $st->execute($params);
        $transactions = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch(Throwable $e) {
        // Empty transactions on error
    }
}

$active = 'rewards';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Points Reports · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="_shared/styles.css">
<style>
/* Navigation tabs styling to match discount reports */
.points-nav {
  display: flex;
  background: #ffffff;
  border-radius: 8px;
  box-shadow: 0 1.6px 3.6px 0 rgba(0,0,0,.132), 0 0.3px 0.9px 0 rgba(0,0,0,.108);
  margin: 16px 0 24px 0;
  overflow: hidden;
}

.points-nav-tab {
  flex: 1;
  padding: 12px 20px;
  text-decoration: none;
  color: #605e5c;
  font-weight: 600;
  font-size: 14px;
  text-align: center;
  border-right: 1px solid #edebe9;
  transition: all 0.1s ease;
  position: relative;
  background: transparent;
}

.points-nav-tab:last-child {
  border-right: none;
}

.points-nav-tab:hover {
  background: #f3f2f1;
  color: #323130;
}

.points-nav-tab.active {
  color: #0078d4;
  background: #f3f9fd;
}

.points-nav-tab.active::after {
  content: '';
  position: absolute;
  bottom: 0;
  left: 0;
  right: 0;
  height: 3px;
  background: #0078d4;
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

.badge.live {
  background: var(--ms-green);
  color: white;
}

.badge.info {
  background: var(--ms-blue);
  color: white;
}

.badge.danger {
  background: var(--ms-red);
  color: white;
}

/* Loading spinner */
.loading-spinner {
  display: inline-block;
  width: 12px;
  height: 12px;
  border: 2px solid var(--ms-gray-40);
  border-top: 2px solid var(--ms-blue);
  border-radius: 50%;
  animation: spin 1s linear infinite;
  margin-right: 6px;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
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
$nav_included = include_admin_nav('rewards');
if (!$nav_included) {
    echo '<div class="notice alert-error">Navigation component not found.</div>';
}
?>

<div class="container">
  <div class="report-header">
    <div>
      <h1 class="h1">Points Reports</h1>
      <p class="sub">Monitor loyalty program performance and member activity</p>
    </div>
  </div>

  <!-- Navigation Tabs -->
  <div class="points-nav">
    <a href="index.php" class="points-nav-tab">Programs</a>
    <a href="members.php" class="points-nav-tab">Members</a>
    <a href="reports.php" class="points-nav-tab active">Reports</a>
  </div>

  <!-- Summary Metrics - Moved above filters -->
  <div class="metrics-row">
    <div class="metric-card">
      <div class="metric-value"><?= number_format($analytics['pointsLiability']) ?></div>
      <div class="metric-label">Points Liability</div>
    </div>
    
    <div class="metric-card">
      <div class="metric-value"><?= number_format($analytics['totalPointsEarned']) ?></div>
      <div class="metric-label">Points Earned</div>
    </div>
    
    <div class="metric-card">
      <div class="metric-value"><?= number_format($analytics['totalPointsRedeemed']) ?></div>
      <div class="metric-label">Points Redeemed</div>
    </div>
    
    <div class="metric-card">
      <div class="metric-value"><?= number_format($analytics['activeMembersCount']) ?></div>
      <div class="metric-label">Active Members</div>
    </div>
  </div>

  <!-- Unified Filters with Report Selector -->
  <div class="filters-bar">
    <div class="filter-group report-selector-group">
      <label>Report Type</label>
      <select id="reportType" onchange="changeReport(this.value)">
        <option value="transactions" <?= $reportType === 'transactions' ? 'selected' : '' ?>>All Transactions</option>
        <option value="earned" <?= $reportType === 'earned' ? 'selected' : '' ?>>Points Earned</option>
        <option value="redeemed" <?= $reportType === 'redeemed' ? 'selected' : '' ?>>Points Redeemed</option>
        <option value="expired" <?= $reportType === 'expired' ? 'selected' : '' ?>>Expired Points</option>
      </select>
    </div>
    
    <div class="filter-group">
      <label>From Date</label>
      <input type="date" id="date_from" value="<?= h($dateFrom) ?>" onchange="applyFilters()">
    </div>
    
    <div class="filter-group">
      <label>To Date</label>
      <input type="date" id="date_to" value="<?= h($dateTo) ?>" onchange="applyFilters()">
    </div>
    
    <div class="filter-group">
      <label>Branch</label>
      <select id="branch_id" onchange="applyFilters()">
        <option value="all">All Branches</option>
        <?php foreach($branches as $branch): ?>
        <option value="<?= $branch['id'] ?>" <?= $selectedBranch == $branch['id'] ? 'selected' : '' ?>>
          <?= h($branch['display_name'] ?: $branch['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    
    <div class="export-buttons">
      <button class="btn" onclick="exportReport('csv')">Export</button>
    </div>
  </div>

  <!-- Loading indicator -->
  <div id="filterLoading" style="display: none; margin-bottom: 16px; text-align: center;">
    <span style="font-size: 12px; color: var(--ms-gray-110);">
      <span class="loading-spinner"></span> Updating results...
    </span>
  </div>

  <!-- Transaction Table -->
  <table class="report-table">
    <thead>
      <tr>
        <th>Date</th>
        <th>Customer</th>
        <th>Branch</th>
        <th>Type</th>
        <th>Points</th>
        <th>Reason</th>
      </tr>
    </thead>
    <tbody id="transactionTableBody">
      <?php if (empty($transactions)): ?>
      <tr>
        <td colspan="6" class="empty-state">
          <h3>No transactions found</h3>
          <p>No points activity in the selected period</p>
        </td>
      </tr>
      <?php else: ?>
        <?php foreach ($transactions as $tx): ?>
        <tr>
          <td><?= date('M j, Y', strtotime($tx['created_at'])) ?></td>
          <td>
            <strong><?= h($tx['customer_name'] ?? 'Unknown') ?></strong>
            <?php if (!empty($tx['customer_phone'])): ?>
              <div style="font-size: 12px; color: var(--ms-gray-110);"><?= h($tx['customer_phone']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= h($tx['branch_name'] ?? 'Main') ?></td>
          <td>
            <?php
            $typeLabel = ucfirst(str_replace('_', ' ', $tx['type'] ?? 'transaction'));
            $badgeClass = ($tx['points_delta'] ?? 0) > 0 ? 'live' : 'info';
            if (($tx['type'] ?? '') === 'expire') $badgeClass = 'danger';
            ?>
            <span class="badge <?= $badgeClass ?>"><?= h($typeLabel) ?></span>
          </td>
          <td>
            <span style="color: <?= ($tx['points_delta'] ?? 0) > 0 ? 'var(--ms-green)' : 'var(--ms-red)' ?>; font-weight: 600;">
              <?= ($tx['points_delta'] ?? 0) > 0 ? '+' : '' ?><?= number_format($tx['points_delta'] ?? 0) ?>
            </span>
          </td>
          <td><?= h($tx['reason'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<script>
function changeReport(type) {
  const params = new URLSearchParams(window.location.search);
  params.set('report_type', type);
  window.location.href = 'reports.php?' + params.toString();
}

function applyFilters() {
  const params = new URLSearchParams({
    report_type: document.getElementById('reportType').value,
    date_from: document.getElementById('date_from').value,
    date_to: document.getElementById('date_to').value,
    branch_id: document.getElementById('branch_id').value
  });
  
  window.location.href = 'reports.php?' + params.toString();
}

function exportReport(format) {
  const params = new URLSearchParams(window.location.search);
  params.append('export', format);
  
  // Assuming export controller exists at this path
  window.location.href = '/controllers/admin/rewards/points/export_transactions.php?' + params.toString();
}

// Dynamic filtering with AJAX (optional enhancement)
document.addEventListener('DOMContentLoaded', function() {
  let filterTimeout;
  const filterLoading = document.getElementById('filterLoading');
  const transactionTableBody = document.getElementById('transactionTableBody');
  
  async function applyFiltersAjax() {
    if (filterLoading) filterLoading.style.display = 'block';
    
    try {
      const formData = new FormData();
      formData.append('ajax_filter', '1');
      formData.append('report_type', document.getElementById('reportType').value);
      formData.append('branch_id', document.getElementById('branch_id').value);
      formData.append('date_from', document.getElementById('date_from').value);
      formData.append('date_to', document.getElementById('date_to').value);
      
      const response = await fetch(window.location.pathname, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      
      if (!response.ok) throw new Error('Network response was not ok');
      
      const data = await response.json();
      
      if (data.transactions_html && transactionTableBody) {
        transactionTableBody.innerHTML = data.transactions_html;
      }
      
      // Update URL for bookmarking
      const url = new URL(window.location.href);
      url.searchParams.set('report_type', document.getElementById('reportType').value);
      url.searchParams.set('branch_id', document.getElementById('branch_id').value);
      url.searchParams.set('date_from', document.getElementById('date_from').value);
      url.searchParams.set('date_to', document.getElementById('date_to').value);
      window.history.replaceState({}, '', url.toString());
      
    } catch (error) {
      console.error('Filter error:', error);
      // Fallback to page reload
      applyFilters();
    }
    
    if (filterLoading) filterLoading.style.display = 'none';
  }
  
  // Add change listeners for smooth filtering
  const inputs = document.querySelectorAll('#date_from, #date_to, #branch_id');
  inputs.forEach(input => {
    input.removeAttribute('onchange');
    input.addEventListener('change', () => {
      if (filterTimeout) clearTimeout(filterTimeout);
      filterTimeout = setTimeout(() => applyFiltersAjax(), 500);
    });
  });
  
  document.getElementById('reportType').removeAttribute('onchange');
  document.getElementById('reportType').addEventListener('change', () => {
    if (filterTimeout) clearTimeout(filterTimeout);
    applyFiltersAjax();
  });
});
</script>

</body>
</html>