<?php
// /views/admin/rewards/discounts/reports.php
// Consolidated discount reports with dropdown selector
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    die('<h1>Bootstrap Failed</h1><p>' . htmlspecialchars($bootstrap_warning ?? 'Unknown error') . '</p>');
}

// Check if h() function exists, if not define it
if (!function_exists('h')) {
    function h($s): string { 
        return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
    }
}

if (!isset($user) || !isset($tenantId)) {
    header('Location: /views/auth/login.php');
    exit;
}

// Get filter parameters
$reportType = $_GET['report_type'] ?? 'usage';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$branchId = $_GET['branch_id'] ?? 'all';
$programId = $_GET['program_id'] ?? 'all';

// Load filter options
$programs = [];
$branches = [];

if ($pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare("SELECT id, name, code FROM discount_schemes WHERE tenant_id = ? ORDER BY name");
        $stmt->execute([$tenantId]);
        $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $pdo->prepare("SELECT id, name FROM branches WHERE tenant_id = ? ORDER BY name");
        $stmt->execute([$tenantId]);
        $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(Exception $e) {
        error_log("Error loading filters: " . $e->getMessage());
    }
}

// Initialize report data
$reportData = [];
$summaryMetrics = [];

// Load data based on selected report
if ($pdo instanceof PDO) {
    try {
        switch($reportType) {
            case 'usage':
                // Usage Report Query
                $sql = "
                    SELECT 
                        ds.id as program_id,
                        ds.name as program_name,
                        ds.code as program_code,
                        ds.type as discount_type,
                        ds.value as discount_value,
                        ds.is_stackable,
                        b.name as branch_name,
                        o.order_type,
                        DATE(o.created_at) as order_date,
                        COUNT(DISTINCT o.id) as usage_count,
                        COUNT(DISTINCT o.customer_id) as unique_customers,
                        SUM(o.discount_amount) as total_discount,
                        AVG(o.discount_amount) as avg_discount
                    FROM discount_schemes ds
                    LEFT JOIN orders o ON o.tenant_id = ds.tenant_id 
                        AND o.discount_amount > 0
                        AND DATE(o.created_at) BETWEEN :date_from AND :date_to
                        AND o.is_voided = 0
                    LEFT JOIN branches b ON o.branch_id = b.id
                    WHERE ds.tenant_id = :tenant_id
                ";
                
                $params = [
                    ':tenant_id' => $tenantId,
                    ':date_from' => $dateFrom,
                    ':date_to' => $dateTo
                ];
                
                if ($programId !== 'all') {
                    $sql .= " AND ds.id = :program_id";
                    $params[':program_id'] = $programId;
                }
                
                if ($branchId !== 'all') {
                    $sql .= " AND o.branch_id = :branch_id";
                    $params[':branch_id'] = $branchId;
                }
                
                $sql .= " GROUP BY ds.id, b.id, o.order_type, DATE(o.created_at)
                          ORDER BY ds.name, order_date DESC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $rawData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Process usage data
                $programTotals = [];
                foreach ($rawData as $row) {
                    $pid = $row['program_id'];
                    if (!isset($programTotals[$pid])) {
                        $programTotals[$pid] = [
                            'program_name' => $row['program_name'],
                            'program_code' => $row['program_code'],
                            'discount_type' => $row['discount_type'],
                            'discount_value' => $row['discount_value'],
                            'is_stackable' => $row['is_stackable'],
                            'usage_count' => 0,
                            'unique_customers' => 0,
                            'total_discount' => 0,
                            'branches' => []
                        ];
                    }
                    $programTotals[$pid]['usage_count'] += $row['usage_count'];
                    $programTotals[$pid]['total_discount'] += $row['total_discount'];
                    if ($row['branch_name']) {
                        $programTotals[$pid]['branches'][$row['branch_name']] = 
                            ($programTotals[$pid]['branches'][$row['branch_name']] ?? 0) + $row['usage_count'];
                    }
                }
                $reportData = array_values($programTotals);
                
                $summaryMetrics = [
                    'total_programs' => count($programTotals),
                    'total_usage' => array_sum(array_column($programTotals, 'usage_count')),
                    'total_discount' => array_sum(array_column($programTotals, 'total_discount')),
                    'avg_discount' => count($programTotals) > 0 ? 
                        array_sum(array_column($programTotals, 'total_discount')) / max(1, array_sum(array_column($programTotals, 'usage_count')))
                        : 0
                ];
                break;
                
            case 'savings':
                // Customer Savings Report Query
                $sql = "
                    SELECT 
                        c.id as customer_id,
                        c.name as customer_name,
                        c.phone,
                        c.email,
                        c.classification,
                        COUNT(DISTINCT o.id) as total_orders,
                        COUNT(DISTINCT CASE WHEN o.discount_amount > 0 THEN o.id END) as discounted_orders,
                        COALESCE(SUM(o.discount_amount), 0) as total_savings,
                        COALESCE(AVG(CASE WHEN o.discount_amount > 0 THEN o.discount_amount END), 0) as avg_savings,
                        MIN(CASE WHEN o.discount_amount > 0 THEN o.created_at END) as first_discount_date,
                        MAX(CASE WHEN o.discount_amount > 0 THEN o.created_at END) as last_discount_date
                    FROM customers c
                    LEFT JOIN orders o ON c.id = o.customer_id 
                        AND o.tenant_id = :tenant_id
                        AND DATE(o.created_at) BETWEEN :date_from AND :date_to
                        AND o.is_voided = 0
                ";
                
                if ($branchId !== 'all') {
                    $sql .= " AND o.branch_id = :branch_id";
                }
                
                $sql .= " WHERE c.tenant_id = :tenant_id
                          GROUP BY c.id
                          HAVING total_savings > 0
                          ORDER BY total_savings DESC
                          LIMIT 100";
                
                $params = [
                    ':tenant_id' => $tenantId,
                    ':date_from' => $dateFrom,
                    ':date_to' => $dateTo
                ];
                
                if ($branchId !== 'all') {
                    $params[':branch_id'] = $branchId;
                }
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $summaryMetrics = [
                    'total_customers' => count($reportData),
                    'total_savings' => array_sum(array_column($reportData, 'total_savings')),
                    'total_orders' => array_sum(array_column($reportData, 'total_orders')),
                    'discounted_orders' => array_sum(array_column($reportData, 'discounted_orders'))
                ];
                break;
                
            case 'impact':
                // Sales Impact Report Query
                $sql = "
                    SELECT 
                        DATE(o.created_at) as period,
                        COUNT(DISTINCT o.id) as total_orders,
                        COUNT(DISTINCT CASE WHEN o.discount_amount > 0 THEN o.id END) as discounted_orders,
                        SUM(o.subtotal_amount) as total_sales,
                        SUM(CASE WHEN o.discount_amount > 0 THEN o.subtotal_amount ELSE 0 END) as discounted_sales,
                        SUM(o.discount_amount) as total_discount,
                        AVG(o.total_amount) as avg_order_value,
                        AVG(CASE WHEN o.discount_amount > 0 THEN o.total_amount END) as avg_discounted_order,
                        AVG(CASE WHEN o.discount_amount = 0 THEN o.total_amount END) as avg_regular_order
                    FROM orders o
                    WHERE o.tenant_id = :tenant_id
                        AND DATE(o.created_at) BETWEEN :date_from AND :date_to
                        AND o.is_voided = 0
                ";
                
                $params = [
                    ':tenant_id' => $tenantId,
                    ':date_from' => $dateFrom,
                    ':date_to' => $dateTo
                ];
                
                if ($branchId !== 'all') {
                    $sql .= " AND o.branch_id = :branch_id";
                    $params[':branch_id'] = $branchId;
                }
                
                $sql .= " GROUP BY period ORDER BY period DESC";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $summaryMetrics = [
                    'total_orders' => array_sum(array_column($reportData, 'total_orders')),
                    'discounted_orders' => array_sum(array_column($reportData, 'discounted_orders')),
                    'total_sales' => array_sum(array_column($reportData, 'total_sales')),
                    'total_discount' => array_sum(array_column($reportData, 'total_discount'))
                ];
                break;
        }
    } catch(Exception $e) {
        error_log("Report error: " . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Discount Reports · Smorll POS</title>
    <link rel="stylesheet" href="_shared/styles.css">
    <style>
    /* Ensure navigation tabs have proper styling - copied from index.php */
    .discount-nav {
      display: flex;
      background: #ffffff;
      border-radius: 8px;
      box-shadow: 0 1.6px 3.6px 0 rgba(0,0,0,.132), 0 0.3px 0.9px 0 rgba(0,0,0,.108);
      margin: 16px 0 24px 0;
      overflow: hidden;
    }

    .discount-nav-tab {
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

    .discount-nav-tab:last-child {
      border-right: none;
    }

    .discount-nav-tab:hover {
      background: #f3f2f1;
      color: #323130;
    }

    .discount-nav-tab.active {
      color: #0078d4;
      background: #f3f9fd;
    }

    .discount-nav-tab.active::after {
      content: '';
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 3px;
      background: #0078d4;
    }
    
    .report-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
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
        flex-wrap: wrap;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
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
        min-width: 150px;
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
    
    .report-table tfoot {
        background: var(--ms-gray-20);
        font-weight: 600;
    }
    
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .badge.vip {
        background: var(--ms-yellow-light);
        color: var(--ms-yellow-darker);
    }
    
    .badge.corporate {
        background: var(--ms-blue-light);
        color: var(--ms-blue-darker);
    }
    
    .badge.regular {
        background: var(--ms-gray-20);
        color: var(--ms-gray-130);
    }
    
    .amount-positive {
        color: var(--ms-green);
        font-weight: 600;
    }
    
    .amount-negative {
        color: var(--ms-red);
    }
    
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
    
    @media print {
        .filters-bar, .export-buttons, .discount-nav {
            display: none !important;
        }
    }
    </style>
</head>
<body>

<?php 
$active = 'rewards';
include_admin_nav($active);
?>

<div class="container">
    <div class="report-header">
        <div>
            <h1 class="h1">Discount Reports</h1>
            <p class="sub">Comprehensive analytics for your discount programs</p>
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="discount-nav">
        <a href="index.php" class="discount-nav-tab">Programs</a>
        <a href="create_program.php" class="discount-nav-tab">Create Program</a>
        <a href="reports.php" class="discount-nav-tab active">Reports</a>
    </div>

    <!-- Summary Metrics - Moved above filters -->
    <div class="metrics-row">
        <?php if ($reportType === 'usage'): ?>
            <div class="metric-card">
                <div class="metric-value"><?= number_format($summaryMetrics['total_programs'] ?? 0) ?></div>
                <div class="metric-label">Programs Used</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?= number_format($summaryMetrics['total_usage'] ?? 0) ?></div>
                <div class="metric-label">Total Redemptions</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?= number_format($summaryMetrics['total_discount'] ?? 0, 2) ?></div>
                <div class="metric-label">Total Discounts</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?= number_format($summaryMetrics['avg_discount'] ?? 0, 2) ?></div>
                <div class="metric-label">Avg Discount</div>
            </div>
        <?php elseif ($reportType === 'savings'): ?>
            <div class="metric-card">
                <div class="metric-value"><?= number_format($summaryMetrics['total_customers'] ?? 0) ?></div>
                <div class="metric-label">Customers</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?= number_format($summaryMetrics['total_savings'] ?? 0, 2) ?></div>
                <div class="metric-label">Total Savings</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?= number_format($summaryMetrics['discounted_orders'] ?? 0) ?></div>
                <div class="metric-label">Discounted Orders</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">
                    <?= $summaryMetrics['total_orders'] > 0 
                        ? number_format(($summaryMetrics['discounted_orders'] / $summaryMetrics['total_orders']) * 100, 1) 
                        : 0 ?>%
                </div>
                <div class="metric-label">Utilization Rate</div>
            </div>
        <?php elseif ($reportType === 'impact'): ?>
            <div class="metric-card">
                <div class="metric-value"><?= number_format($summaryMetrics['total_sales'] ?? 0, 2) ?></div>
                <div class="metric-label">Total Sales</div>
            </div>
            <div class="metric-card">
                <div class="metric-value"><?= number_format($summaryMetrics['total_orders'] ?? 0) ?></div>
                <div class="metric-label">Total Orders</div>
            </div>
            <div class="metric-card">
                <div class="metric-value" style="color: var(--ms-red);">
                    -<?= number_format($summaryMetrics['total_discount'] ?? 0, 2) ?>
                </div>
                <div class="metric-label">Discounts Given</div>
            </div>
            <div class="metric-card">
                <div class="metric-value">
                    <?= ($summaryMetrics['total_sales'] ?? 0) > 0 
                        ? number_format(($summaryMetrics['total_discount'] / $summaryMetrics['total_sales']) * 100, 1)
                        : 0 ?>%
                </div>
                <div class="metric-label">Discount Rate</div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Unified Filters with Report Selector -->
    <div class="filters-bar">
        <div class="filter-group report-selector-group">
            <label>Report Type</label>
            <select id="reportType" onchange="changeReport(this.value)">
                <option value="usage" <?= $reportType === 'usage' ? 'selected' : '' ?>>Usage Report</option>
                <option value="savings" <?= $reportType === 'savings' ? 'selected' : '' ?>>Customer Savings</option>
                <option value="impact" <?= $reportType === 'impact' ? 'selected' : '' ?>>Sales Impact</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label>From Date</label>
            <input type="date" id="dateFrom" value="<?= h($dateFrom) ?>" onchange="applyFilters()">
        </div>
        
        <div class="filter-group">
            <label>To Date</label>
            <input type="date" id="dateTo" value="<?= h($dateTo) ?>" onchange="applyFilters()">
        </div>
        
        <div class="filter-group">
            <label>Branch</label>
            <select id="branchFilter" onchange="applyFilters()">
                <option value="all">All Branches</option>
                <?php foreach($branches as $branch): ?>
                <option value="<?= $branch['id'] ?>" <?= $branchId == $branch['id'] ? 'selected' : '' ?>>
                    <?= h($branch['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <?php if ($reportType === 'usage'): ?>
        <div class="filter-group">
            <label>Program</label>
            <select id="programFilter" onchange="applyFilters()">
                <option value="all">All Programs</option>
                <?php foreach($programs as $prog): ?>
                <option value="<?= $prog['id'] ?>" <?= $programId == $prog['id'] ? 'selected' : '' ?>>
                    <?= h($prog['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        
        <div class="export-buttons">
            <button class="btn" onclick="exportReport('csv')">Export</button>
        </div>
    </div>

    <!-- Report Table -->
    <table class="report-table">
        <?php if ($reportType === 'usage'): ?>
            <thead>
                <tr>
                    <th>Program Name</th>
                    <th>Code</th>
                    <th>Type</th>
                    <th>Stackable</th>
                    <th>Times Used</th>
                    <th>Total Discount</th>
                    <th>Avg Discount</th>
                    <th>Branch Usage</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reportData)): ?>
                <tr>
                    <td colspan="8" class="empty-state">
                        <h3>No usage data found</h3>
                        <p>No discount usage in the selected period</p>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach($reportData as $row): ?>
                    <tr>
                        <td><strong><?= h($row['program_name']) ?></strong></td>
                        <td><code style="background: var(--ms-gray-20); padding: 2px 6px; border-radius: 3px;"><?= h($row['program_code']) ?></code></td>
                        <td>
                            <?= $row['discount_type'] === 'percent' 
                                ? h($row['discount_value']) . '%' 
                                : h($row['discount_value']) ?>
                        </td>
                        <td><?= $row['is_stackable'] ? 'Yes' : 'No' ?></td>
                        <td><?= number_format($row['usage_count']) ?></td>
                        <td class="amount-positive"><?= number_format($row['total_discount'], 2) ?></td>
                        <td><?= number_format($row['usage_count'] > 0 ? $row['total_discount'] / $row['usage_count'] : 0, 2) ?></td>
                        <td>
                            <?php if (!empty($row['branches'])): ?>
                                <?php foreach($row['branches'] as $branch => $count): ?>
                                    <div style="font-size: 12px;">
                                        <?= h($branch) ?>: <?= number_format($count) ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($reportData)): ?>
            <tfoot>
                <tr>
                    <td colspan="4">Total</td>
                    <td><?= number_format($summaryMetrics['total_usage']) ?></td>
                    <td class="amount-positive"><?= number_format($summaryMetrics['total_discount'], 2) ?></td>
                    <td colspan="2">—</td>
                </tr>
            </tfoot>
            <?php endif; ?>
            
        <?php elseif ($reportType === 'savings'): ?>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Classification</th>
                    <th>Total Orders</th>
                    <th>Discounted Orders</th>
                    <th>Total Savings</th>
                    <th>Avg Savings</th>
                    <th>First Use</th>
                    <th>Last Use</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reportData)): ?>
                <tr>
                    <td colspan="8" class="empty-state">
                        <h3>No customer savings found</h3>
                        <p>No customers have used discounts in the selected period</p>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach($reportData as $row): ?>
                    <tr>
                        <td>
                            <strong><?= h($row['customer_name']) ?></strong>
                            <div style="font-size: 12px; color: var(--ms-gray-110);">
                                <?= h($row['phone'] ?: 'No phone') ?>
                                <?= $row['email'] ? ' • ' . h($row['email']) : '' ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge <?= h($row['classification'] ?: 'regular') ?>">
                                <?= h(ucfirst($row['classification'] ?: 'regular')) ?>
                            </span>
                        </td>
                        <td><?= number_format($row['total_orders']) ?></td>
                        <td>
                            <?= number_format($row['discounted_orders']) ?>
                            <?php if ($row['total_orders'] > 0): ?>
                                <span style="font-size: 12px; color: var(--ms-gray-110);">
                                    (<?= number_format(($row['discounted_orders'] / $row['total_orders']) * 100, 1) ?>%)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="amount-positive">
                            <?= number_format($row['total_savings'], 2) ?>
                        </td>
                        <td><?= number_format($row['avg_savings'], 2) ?></td>
                        <td><?= $row['first_discount_date'] ? date('M d, Y', strtotime($row['first_discount_date'])) : '—' ?></td>
                        <td><?= $row['last_discount_date'] ? date('M d, Y', strtotime($row['last_discount_date'])) : '—' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($reportData)): ?>
            <tfoot>
                <tr>
                    <td>Total (<?= number_format($summaryMetrics['total_customers']) ?> customers)</td>
                    <td>—</td>
                    <td><?= number_format($summaryMetrics['total_orders']) ?></td>
                    <td><?= number_format($summaryMetrics['discounted_orders']) ?></td>
                    <td class="amount-positive"><?= number_format($summaryMetrics['total_savings'], 2) ?></td>
                    <td colspan="3">—</td>
                </tr>
            </tfoot>
            <?php endif; ?>
            
        <?php elseif ($reportType === 'impact'): ?>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Total Orders</th>
                    <th>Discounted Orders</th>
                    <th>Gross Sales</th>
                    <th>Discount Amount</th>
                    <th>Net Sales</th>
                    <th>Avg Order Value</th>
                    <th>Discount %</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reportData)): ?>
                <tr>
                    <td colspan="8" class="empty-state">
                        <h3>No sales data found</h3>
                        <p>No orders found in the selected period</p>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach($reportData as $row): ?>
                    <tr>
                        <td><?= date('M d, Y', strtotime($row['period'])) ?></td>
                        <td><?= number_format($row['total_orders']) ?></td>
                        <td>
                            <?= number_format($row['discounted_orders']) ?>
                            <?php if ($row['total_orders'] > 0): ?>
                                <span style="font-size: 12px; color: var(--ms-gray-110);">
                                    (<?= number_format(($row['discounted_orders'] / $row['total_orders']) * 100, 1) ?>%)
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format($row['total_sales'], 2) ?></td>
                        <td class="amount-negative">-<?= number_format($row['total_discount'], 2) ?></td>
                        <td style="font-weight: 600;">
                            <?= number_format($row['total_sales'] - $row['total_discount'], 2) ?>
                        </td>
                        <td><?= number_format($row['avg_order_value'], 2) ?></td>
                        <td>
                            <?= $row['total_sales'] > 0 
                                ? number_format(($row['total_discount'] / $row['total_sales']) * 100, 1) 
                                : 0 ?>%
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($reportData)): ?>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td><?= number_format($summaryMetrics['total_orders']) ?></td>
                    <td><?= number_format($summaryMetrics['discounted_orders']) ?></td>
                    <td><?= number_format($summaryMetrics['total_sales'], 2) ?></td>
                    <td class="amount-negative">-<?= number_format($summaryMetrics['total_discount'], 2) ?></td>
                    <td style="font-weight: 600;">
                        <?= number_format(($summaryMetrics['total_sales'] ?? 0) - ($summaryMetrics['total_discount'] ?? 0), 2) ?>
                    </td>
                    <td>—</td>
                    <td>
                        <?= ($summaryMetrics['total_sales'] ?? 0) > 0 
                            ? number_format(($summaryMetrics['total_discount'] / $summaryMetrics['total_sales']) * 100, 1) 
                            : 0 ?>%
                    </td>
                </tr>
            </tfoot>
            <?php endif; ?>
        <?php endif; ?>
    </table>
</div>

<script>
function changeReport(type) {
    const params = new URLSearchParams(window.location.search);
    params.set('report_type', type);
    
    // Remove program filter if not usage report
    if (type !== 'usage') {
        params.delete('program_id');
    }
    
    window.location.href = 'reports.php?' + params.toString();
}

function applyFilters() {
    const params = new URLSearchParams({
        report_type: document.getElementById('reportType').value,
        date_from: document.getElementById('dateFrom').value,
        date_to: document.getElementById('dateTo').value,
        branch_id: document.getElementById('branchFilter').value
    });
    
    // Add program filter if on usage report
    const programFilter = document.getElementById('programFilter');
    if (programFilter) {
        params.set('program_id', programFilter.value);
    }
    
    window.location.href = 'reports.php?' + params.toString();
}

function exportReport(format) {
    const params = new URLSearchParams(window.location.search);
    params.append('export', format);
    
    // Determine which export controller to use based on report type
    const reportType = document.getElementById('reportType').value;
    let exportUrl = '/controllers/admin/rewards/discounts/';
    
    switch(reportType) {
        case 'usage':
            exportUrl += 'export_usage.php';
            break;
        case 'savings':
            exportUrl += 'export_savings.php';
            break;
        case 'impact':
            exportUrl += 'export_impact.php';
            break;
    }
    
    window.location.href = exportUrl + '?' + params.toString();
}
</script>

</body>
</html>