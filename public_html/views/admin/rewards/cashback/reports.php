<?php
// /views/admin/rewards/cashback/reports.php
// Cashback Reports & Analytics Dashboard - Updated UI/UX
declare(strict_types=1);

require_once __DIR__ . '/_shared/common.php';

// Updated money function without currency symbol
function money_display($amount) {
    return number_format((float)$amount, 2);
}

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
        $transactionType = $_POST['type'] ?? 'all';
        
        // Load filtered transactions
        $transactions = [];
        if ($pdo instanceof PDO) {
            $whereClause = "WHERE ll.tenant_id = ? AND ll.type LIKE 'cashback%' AND DATE(ll.created_at) BETWEEN ? AND ?";
            $params = [$tenantId, $dateFrom, $dateTo];
            
            if ($selectedBranch !== 'all' && is_numeric($selectedBranch)) {
                $whereClause .= " AND (o.branch_id = ? OR o.branch_id IS NULL)";
                $params[] = (int)$selectedBranch;
            }
            
            if ($transactionType === 'earned') {
                $whereClause .= " AND ll.type = 'cashback_earn'";
            } elseif ($transactionType === 'redeemed') {
                $whereClause .= " AND ll.type = 'cashback_redeem'";
            } elseif ($transactionType === 'expired') {
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
            echo '<tr><td colspan="6" class="empty-state"><h3>No transactions found</h3><p>No transactions match the selected filters.</p></td></tr>';
        } else {
            foreach ($transactions as $tx) {
                $typeLabel = $tx['type'] === 'cashback_earn' ? 'Earned' : ($tx['type'] === 'cashback_redeem' ? 'Redeemed' : 'Expired');
                $badgeClass = $tx['type'] === 'cashback_earn' ? 'earned' : ($tx['type'] === 'cashback_redeem' ? 'redeemed' : 'expired');
                
                echo '<tr>';
                echo '<td>';
                echo '<strong>' . date('M j, Y', strtotime($tx['created_at'])) . '</strong>';
                echo '<div style="font-size: 12px; color: var(--ms-gray-110);">' . date('H:i', strtotime($tx['created_at'])) . '</div>';
                echo '</td>';
                echo '<td>';
                echo '<strong>' . h($tx['customer_name'] ?? 'Unknown') . '</strong>';
                if (!empty($tx['customer_phone'])) {
                    echo '<div style="font-size: 12px; color: var(--ms-gray-110);">' . h($tx['customer_phone']) . '</div>';
                }
                echo '</td>';
                echo '<td><span class="badge ' . $badgeClass . '">' . h($typeLabel) . '</span></td>';
                echo '<td>';
                $amount = abs((float)($tx['cash_delta'] ?? 0));
                $amountClass = $tx['type'] === 'cashback_earn' ? 'amount-positive' : 'amount-negative';
                $amountSign = $tx['type'] === 'cashback_earn' ? '+' : '-';
                echo '<span class="' . $amountClass . '">';
                echo $amountSign . ' ' . money_display($amount);
                echo '</span>';
                echo '</td>';
                echo '<td>' . h($tx['order_id'] ? '#' . $tx['order_id'] : '—') . '</td>';
                echo '<td>' . h($tx['reason'] ?? '—') . '</td>';
                echo '</tr>';
            }
        }
        $transactionsHtml = ob_get_clean();
        
        // Calculate updated statistics for the filtered period
        $filteredStats = [
            'totalIssued' => 0,
            'totalRedeemed' => 0,
            'transactionCount' => count($transactions)
        ];
        
        if ($pdo instanceof PDO) {
            // Get filtered statistics
            $st = $pdo->prepare("SELECT 
                SUM(CASE WHEN type = 'cashback_earn' THEN cash_delta ELSE 0 END) as issued,
                SUM(CASE WHEN type = 'cashback_redeem' THEN ABS(cash_delta) ELSE 0 END) as redeemed
                FROM loyalty_ledger ll
                LEFT JOIN orders o ON ll.order_id = o.id
                WHERE ll.tenant_id = ? AND ll.type LIKE 'cashback%' 
                AND DATE(ll.created_at) BETWEEN ? AND ?
                " . ($selectedBranch !== 'all' ? "AND o.branch_id = ?" : ""));
            
            $execParams = [$tenantId, $dateFrom, $dateTo];
            if ($selectedBranch !== 'all') $execParams[] = $selectedBranch;
            $st->execute($execParams);
            $stats = $st->fetch(PDO::FETCH_ASSOC);
            
            $filteredStats['totalIssued'] = (float)($stats['issued'] ?? 0);
            $filteredStats['totalRedeemed'] = (float)($stats['redeemed'] ?? 0);
        }
        
        echo json_encode([
            'success' => true,
            'transactions_html' => $transactionsHtml,
            'stats' => $filteredStats,
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

/* Load Analytics Data */
$analytics = [
    'totalCashbackIssued' => 0,
    'totalCashbackRedeemed' => 0,
    'cashbackLiability' => 0,
    'redemptionRate' => 0
];

$branches = [];

if ($pdo instanceof PDO) {
    try {
        // Load branches from database
        $st = $pdo->prepare("SELECT id, name, display_name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name");
        $st->execute([$tenantId]);
        $branches = $st->fetchAll(PDO::FETCH_ASSOC);
        
        // Total cashback issued (30 days)
        $st = $pdo->prepare("SELECT SUM(cash_delta) as total FROM loyalty_ledger 
                           WHERE tenant_id = ? AND type = 'cashback_earn' 
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $st->execute([$tenantId]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        $analytics['totalCashbackIssued'] = (float)($res['total'] ?? 0);
        
        // Total cashback redeemed (30 days)
        $st = $pdo->prepare("SELECT SUM(ABS(cash_delta)) as total FROM loyalty_ledger 
                           WHERE tenant_id = ? AND type = 'cashback_redeem' 
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $st->execute([$tenantId]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        $analytics['totalCashbackRedeemed'] = (float)($res['total'] ?? 0);
        
        // Total liability
        $st = $pdo->prepare("
            SELECT SUM(balance) as total_liability
            FROM (
                SELECT customer_id,
                       SUM(CASE WHEN type='cashback_redeem' THEN -cash_delta ELSE cash_delta END) as balance
                FROM loyalty_ledger
                WHERE tenant_id = ?
                GROUP BY customer_id
                HAVING balance > 0
            ) as wallets
        ");
        $st->execute([$tenantId]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        $analytics['cashbackLiability'] = (float)($res['total_liability'] ?? 0);
        
        if ($analytics['totalCashbackIssued'] > 0) {
            $analytics['redemptionRate'] = ($analytics['totalCashbackRedeemed'] / $analytics['totalCashbackIssued']) * 100;
        }
        
    } catch(Throwable $e) {
        error_log('Analytics error: ' . $e->getMessage());
    }
}

/* Get Filter Parameters */
$selectedBranch = $_GET['branch_id'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$transactionType = $_GET['type'] ?? 'all';

/* Load Filtered Transactions */
$transactions = [];
if ($pdo instanceof PDO) {
    try {
        $whereClause = "WHERE ll.tenant_id = ? AND ll.type LIKE 'cashback%' AND DATE(ll.created_at) BETWEEN ? AND ?";
        $params = [$tenantId, $dateFrom, $dateTo];
        
        if ($selectedBranch !== 'all' && is_numeric($selectedBranch)) {
            $whereClause .= " AND (o.branch_id = ? OR o.branch_id IS NULL)";
            $params[] = (int)$selectedBranch;
        }
        
        if ($transactionType === 'earned') {
            $whereClause .= " AND ll.type = 'cashback_earn'";
        } elseif ($transactionType === 'redeemed') {
            $whereClause .= " AND ll.type = 'cashback_redeem'";
        }
        
        $st = $pdo->prepare("SELECT ll.*, c.name as customer_name, c.phone as customer_phone
                            FROM loyalty_ledger ll
                            LEFT JOIN customers c ON ll.customer_id = c.id
                            LEFT JOIN orders o ON ll.order_id = o.id
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
<title>Cashback Reports · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="../points/_shared/styles.css?v=<?= time() ?>">
<style>
/* Discount Reports-style navigation tabs */
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

/* Enhanced Filter Bar matching Discount Reports */
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

.export-buttons {
    margin-left: auto;
    display: flex;
    gap: 8px;
}

/* Metrics matching Discount Reports style */
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

/* Report table matching Discount Reports */
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

/* Badge styles */
.badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge.earned {
    background: var(--ms-green-light);
    color: var(--ms-green-darker);
}

.badge.redeemed {
    background: var(--ms-blue-light);
    color: var(--ms-blue-darker);
}

.badge.expired {
    background: var(--ms-red-light);
    color: var(--ms-red-darker);
}

.amount-positive {
    color: var(--ms-green);
    font-weight: 600;
}

.amount-negative {
    color: var(--ms-red);
    font-weight: 600;
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

/* Loading overlay */
.loading-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.8);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 10;
}

.loading-overlay.show {
    display: flex;
}

.loading-spinner {
    width: 32px;
    height: 32px;
    border: 3px solid var(--ms-gray-40);
    border-top: 3px solid var(--ms-blue);
    border-radius: 50%;
    animation: spin 1s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Report header */
.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 24px;
}

@media (max-width: 768px) {
    .filters-bar {
        flex-direction: column;
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
    .filters-bar, .export-buttons, .points-nav {
        display: none !important;
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
            <h1 class="h1">Cashback Reports</h1>
            <p class="sub">Monitor cashback program performance and transaction activity.</p>
        </div>
    </div>

    <!-- Navigation Tabs - Removed Members -->
    <div class="points-nav">
        <a href="index.php" class="points-nav-tab">Programs</a>
        <a href="create.php" class="points-nav-tab">Create Program</a>
        <a href="reports.php" class="points-nav-tab active">Reports</a>
    </div>

    <!-- Summary Metrics - Simplified without Member Insights -->
    <div class="metrics-row">
        <div class="metric-card">
            <div class="metric-value"><?= money_display($analytics['cashbackLiability']) ?></div>
            <div class="metric-label">Total Liability</div>
        </div>
        
        <div class="metric-card">
            <div class="metric-value"><?= money_display($analytics['totalCashbackIssued']) ?></div>
            <div class="metric-label">Issued (30 Days)</div>
        </div>
        
        <div class="metric-card">
            <div class="metric-value"><?= money_display($analytics['totalCashbackRedeemed']) ?></div>
            <div class="metric-label">Redeemed (30 Days)</div>
        </div>
        
        <div class="metric-card">
            <div class="metric-value"><?= number_format($analytics['redemptionRate'], 1) ?>%</div>
            <div class="metric-label">Redemption Rate</div>
        </div>
    </div>

    <!-- Unified Filters matching Discount Reports -->
    <div class="filters-bar">
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
                <?php foreach ($branches as $branch): ?>
                    <option value="<?= (int)$branch['id'] ?>" <?= $selectedBranch === (string)$branch['id'] ? 'selected' : '' ?>>
                        <?= h($branch['display_name'] ?: $branch['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="filter-group">
            <label>Type</label>
            <select id="type" onchange="applyFilters()">
                <option value="all" <?= $transactionType === 'all' ? 'selected' : '' ?>>All Types</option>
                <option value="earned" <?= $transactionType === 'earned' ? 'selected' : '' ?>>Earned</option>
                <option value="redeemed" <?= $transactionType === 'redeemed' ? 'selected' : '' ?>>Redeemed</option>
                <option value="expired" <?= $transactionType === 'expired' ? 'selected' : '' ?>>Expired</option>
            </select>
        </div>
        
        <div class="export-buttons">
            <button class="btn" onclick="exportTransactions()">Export</button>
        </div>
    </div>

    <!-- Transaction Table matching Discount Reports style -->
    <div style="position: relative;">
        <div class="loading-overlay" id="transactionLoading">
            <div class="loading-spinner"></div>
        </div>
        
        <table class="report-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Customer</th>
                    <th>Type</th>
                    <th>Amount</th>
                    <th>Order</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody id="transactionTableBody">
                <?php if (empty($transactions)): ?>
                    <tr>
                        <td colspan="6" class="empty-state">
                            <h3>No transactions found</h3>
                            <p>No transactions match the selected filters.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($transactions as $tx): ?>
                    <tr>
                        <td>
                            <strong><?= date('M j, Y', strtotime($tx['created_at'])) ?></strong>
                            <div style="font-size: 12px; color: var(--ms-gray-110);"><?= date('H:i', strtotime($tx['created_at'])) ?></div>
                        </td>
                        <td>
                            <strong><?= h($tx['customer_name'] ?? 'Unknown') ?></strong>
                            <?php if (!empty($tx['customer_phone'])): ?>
                                <div style="font-size: 12px; color: var(--ms-gray-110);"><?= h($tx['customer_phone']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $typeLabel = $tx['type'] === 'cashback_earn' ? 'Earned' : ($tx['type'] === 'cashback_redeem' ? 'Redeemed' : 'Adjusted');
                            $badgeClass = $tx['type'] === 'cashback_earn' ? 'earned' : 'redeemed';
                            ?>
                            <span class="badge <?= $badgeClass ?>"><?= h($typeLabel) ?></span>
                        </td>
                        <td>
                            <span class="<?= $tx['type'] === 'cashback_earn' ? 'amount-positive' : 'amount-negative' ?>">
                                <?= $tx['type'] === 'cashback_earn' ? '+' : '-' ?> <?= money_display(abs((float)($tx['cash_delta'] ?? 0))) ?>
                            </span>
                        </td>
                        <td><?= h($tx['order_id'] ? '#' . $tx['order_id'] : '—') ?></td>
                        <td><?= h($tx['reason'] ?? '—') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($transactions)): ?>
            <tfoot>
                <tr>
                    <td colspan="3">Total (<?= count($transactions) ?> transactions)</td>
                    <td colspan="3">—</td>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<script src="_shared/scripts.js"></script>
<script>
// Debounce timer
let filterTimer = null;

function applyFilters() {
    // Clear any existing timer
    if (filterTimer) {
        clearTimeout(filterTimer);
    }
    
    // Set a new timer to apply filters after 800ms of no changes
    filterTimer = setTimeout(function() {
        const params = new URLSearchParams({
            date_from: document.getElementById('date_from').value,
            date_to: document.getElementById('date_to').value,
            branch_id: document.getElementById('branch_id').value,
            type: document.getElementById('type').value
        });
        
        window.location.href = 'reports.php?' + params.toString();
    }, 800); // Wait 800ms after user stops typing/changing
}

function exportTransactions() {
    const params = new URLSearchParams({
        branch_id: document.getElementById('branch_id').value,
        date_from: document.getElementById('date_from').value,
        date_to: document.getElementById('date_to').value,
        type: document.getElementById('type').value,
        export: 'csv'
    });
    
    // Create a temporary link and click it to download
    const link = document.createElement('a');
    link.href = window.location.pathname + '?' + params.toString();
    link.download = 'cashback_transactions_' + new Date().toISOString().split('T')[0] + '.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Dynamic filtering for future enhancement (currently using page reload)
document.addEventListener('DOMContentLoaded', function() {
    const loadingOverlay = document.getElementById('transactionLoading');
    const transactionTableBody = document.getElementById('transactionTableBody');
    
    // Store current request to cancel if new one starts
    let currentRequest = null;
    
    function showLoading() {
        if (loadingOverlay) loadingOverlay.classList.add('show');
    }
    
    function hideLoading() {
        if (loadingOverlay) loadingOverlay.classList.remove('show');
    }
    
    function formatMoney(amount) {
        return parseFloat(amount).toFixed(2);
    }
});
</script>

</body>
</html>