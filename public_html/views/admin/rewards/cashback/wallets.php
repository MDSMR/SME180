<?php
// /views/admin/rewards/cashback/wallets.php
// Cashback Wallets Management - Final Version with Searchable Dropdown
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

/* Handle AJAX Requests */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        if ($_POST['action'] === 'search_customers' && $pdo instanceof PDO) {
            $search = trim($_POST['search'] ?? '');
            $customers = [];
            
            if (strlen($search) >= 2) {
                $st = $pdo->prepare("SELECT id, name, phone, email, 
                                     (SELECT SUM(CASE WHEN type='cashback_redeem' THEN -cash_delta ELSE cash_delta END)
                                      FROM loyalty_ledger 
                                      WHERE customer_id = c.id AND tenant_id = ?) as balance
                                     FROM customers c
                                     WHERE c.tenant_id = ? 
                                     AND (c.name LIKE ? OR c.phone LIKE ? OR c.email LIKE ?)
                                     ORDER BY c.name
                                     LIMIT 20");
                $searchTerm = '%' . $search . '%';
                $st->execute([$tenantId, $tenantId, $searchTerm, $searchTerm, $searchTerm]);
                $customers = $st->fetchAll(PDO::FETCH_ASSOC);
            }
            
            echo json_encode(['success' => true, 'customers' => $customers]);
            exit;
        }
        
        if ($_POST['action'] === 'adjust_wallet' && $pdo instanceof PDO) {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            $type = $amount > 0 ? 'cashback_earn' : 'cashback_redeem';
            
            if ($customerId && $amount != 0 && $reason) {
                $st = $pdo->prepare("INSERT INTO loyalty_ledger 
                    (tenant_id, customer_id, type, cash_delta, reason, created_at) 
                    VALUES (?, ?, 'adjust', ?, ?, NOW())");
                $st->execute([$tenantId, $customerId, $amount, $reason]);
                
                echo json_encode(['success' => true, 'message' => 'Wallet adjusted successfully']);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid adjustment data']);
            }
            exit;
        }
        
        if ($_POST['action'] === 'load_customer_ledger') {
            $customerId = (int)($_POST['customer_id'] ?? 0);
            
            if ($customerId && $pdo instanceof PDO) {
                $st = $pdo->prepare("
                    SELECT ll.*, o.receipt_reference
                    FROM loyalty_ledger ll
                    LEFT JOIN orders o ON ll.order_id = o.id
                    WHERE ll.tenant_id = ? AND ll.customer_id = ? 
                    AND ll.type IN ('cashback_earn', 'cashback_redeem', 'adjust', 'expire')
                    ORDER BY ll.created_at DESC
                    LIMIT 50
                ");
                $st->execute([$tenantId, $customerId]);
                $ledger = $st->fetchAll(PDO::FETCH_ASSOC);
                
                ob_start();
                foreach ($ledger as $entry) {
                    $typeLabel = str_replace(['cashback_', '_'], ['', ' '], $entry['type']);
                    $typeLabel = ucfirst($typeLabel);
                    $badgeClass = $entry['cash_delta'] > 0 ? 'live' : 'info';
                    if ($entry['type'] === 'expire') $badgeClass = 'danger';
                    
                    echo '<tr>';
                    echo '<td>' . date('M j, Y H:i', strtotime($entry['created_at'])) . '</td>';
                    echo '<td><span class="badge ' . $badgeClass . '">' . h($typeLabel) . '</span></td>';
                    echo '<td style="font-weight: 600; color: ' . ($entry['cash_delta'] > 0 ? 'var(--ms-green)' : 'var(--ms-red)') . '">';
                    echo ($entry['cash_delta'] > 0 ? '+' : '') . money_display($entry['cash_delta']);
                    echo '</td>';
                    echo '<td>' . h($entry['order_id'] ? '#' . $entry['order_id'] : '—') . '</td>';
                    echo '<td class="helper">' . h($entry['reason'] ?? '—') . '</td>';
                    echo '</tr>';
                }
                if (empty($ledger)) {
                    echo '<tr><td colspan="5" style="text-align: center; padding: 20px;"><span class="helper">No transactions found</span></td></tr>';
                }
                $html = ob_get_clean();
                
                echo json_encode(['success' => true, 'html' => $html]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid customer']);
            }
            exit;
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

/* Get Filter Parameters */
$search = $_GET['search'] ?? '';
$statusFilter = $_GET['status'] ?? 'all';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 25;
$offset = ($page - 1) * $limit;
$selectedTab = $_GET['tab'] ?? 'wallets';

/* Load all customers for dropdown */
$allCustomers = [];
if ($pdo instanceof PDO) {
    try {
        $st = $pdo->prepare("SELECT id, name, phone, email FROM customers WHERE tenant_id = ? ORDER BY name");
        $st->execute([$tenantId]);
        $allCustomers = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        $allCustomers = [];
    }
}

/* Load Wallet Balances */
$wallets = [];
$totalCount = 0;
$activeCount = 0;
$totalBalance = 0;

if ($pdo instanceof PDO) {
    // Get statistics first
    try {
        $statsSql = "SELECT 
                        COUNT(DISTINCT c.id) as total_customers,
                        COUNT(DISTINCT CASE WHEN wallet_balance.balance > 0 THEN wallet_balance.customer_id END) as active_wallets,
                        SUM(COALESCE(wallet_balance.balance, 0)) as total_balance
                    FROM customers c
                    LEFT JOIN (
                        SELECT customer_id, 
                               SUM(CASE WHEN type = 'cashback_redeem' THEN -cash_delta ELSE cash_delta END) as balance
                        FROM loyalty_ledger
                        WHERE tenant_id = :t
                        GROUP BY customer_id
                    ) wallet_balance ON c.id = wallet_balance.customer_id
                    WHERE c.tenant_id = :t";
        
        $st = $pdo->prepare($statsSql);
        $st->execute([':t' => $tenantId]);
        $stats = $st->fetch(PDO::FETCH_ASSOC);
        
        $totalCount = (int)($stats['total_customers'] ?? 0);
        $activeCount = (int)($stats['active_wallets'] ?? 0);
        $totalBalance = (float)($stats['total_balance'] ?? 0);
        
    } catch(Throwable $e) {
        error_log('Stats error: ' . $e->getMessage());
    }
    
    // Load wallets
    $wallets = load_wallet_balances($pdo, $tenantId, $limit, $offset, $search);
}

$totalPages = ceil($totalCount / $limit);

$active = 'rewards';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Cashback Wallets · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="../points/_shared/styles.css?v=<?= time() ?>">
<style>
/* Sub-tabs specific styling */
.sub-tabs {
    display: flex;
    gap: 0;
    margin: 24px 0;
    background: var(--ms-white);
    border-radius: var(--ms-radius-lg);
    box-shadow: var(--ms-shadow-1);
    overflow: hidden;
    border: 1px solid var(--ms-gray-30);
}

.sub-tab {
    flex: 1;
    padding: 12px 20px;
    background: none;
    border: none;
    border-right: 1px solid var(--ms-gray-30);
    color: var(--ms-gray-130);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
    transition: all 0.1s ease;
    text-align: center;
}

.sub-tab:last-child {
    border-right: none;
}

.sub-tab:hover {
    background: var(--ms-gray-10);
    color: var(--ms-gray-160);
}

.sub-tab.active {
    background: var(--ms-blue-lighter);
    color: var(--ms-blue);
    border-bottom: 2px solid var(--ms-blue);
}

/* Modal Styling */
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
    align-items: center;
    justify-content: center;
    z-index: 1000;
    padding: 24px;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: var(--ms-white);
    border-radius: var(--ms-radius-lg);
    box-shadow: var(--ms-shadow-3);
    width: 100%;
    max-width: 900px;
    max-height: 85vh;
    display: flex;
    flex-direction: column;
}

.modal-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--ms-gray-30);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background: linear-gradient(135deg, var(--ms-gray-10) 0%, var(--ms-gray-20) 100%);
}

.modal-header h3 {
    margin: 0;
    font-size: 18px;
    font-weight: 600;
    color: var(--ms-gray-160);
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--ms-gray-110);
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--ms-radius);
    transition: all 0.1s ease;
}

.modal-close:hover {
    background: var(--ms-gray-30);
    color: var(--ms-gray-160);
}

.modal-body {
    padding: 24px;
    overflow-y: auto;
    flex: 1;
}

/* Quick stats section */
.wallet-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
    margin-bottom: 24px;
}

.wallet-stat-card {
    padding: 16px;
    background: linear-gradient(135deg, var(--ms-gray-10) 0%, var(--ms-gray-20) 100%);
    border-radius: var(--ms-radius-lg);
    border-left: 4px solid var(--ms-blue);
}

.wallet-stat-value {
    font-size: 24px;
    font-weight: 700;
    color: var(--ms-gray-160);
    margin-bottom: 4px;
}

.wallet-stat-label {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: var(--ms-gray-110);
}

/* Filter bar */
.filter-bar {
    display: flex;
    gap: 12px;
    align-items: center;
    padding: 16px;
    background: var(--ms-gray-10);
    border-radius: var(--ms-radius-lg);
    margin-bottom: 20px;
}

.filter-bar input {
    flex: 1;
    max-width: 400px;
}

/* Searchable dropdown */
.searchable-dropdown {
    position: relative;
    width: 100%;
}

.searchable-dropdown input {
    width: 100%;
}

.searchable-dropdown .dropdown-list {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: var(--ms-white);
    border: 1px solid var(--ms-gray-60);
    border-radius: var(--ms-radius);
    box-shadow: var(--ms-shadow-2);
    max-height: 250px;
    overflow-y: auto;
    z-index: 100;
    display: none;
    margin-top: 4px;
}

.searchable-dropdown .dropdown-list.open {
    display: block;
}

.dropdown-item {
    padding: 10px 12px;
    cursor: pointer;
    transition: all 0.1s ease;
    border-bottom: 1px solid var(--ms-gray-20);
}

.dropdown-item:last-child {
    border-bottom: none;
}

.dropdown-item:hover {
    background: var(--ms-blue-lighter);
    color: var(--ms-blue);
}

.dropdown-item .item-name {
    font-weight: 600;
    margin-bottom: 2px;
}

.dropdown-item .item-detail {
    font-size: 12px;
    color: var(--ms-gray-110);
}

/* Optimized form layout */
.form-row-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .form-row-3 {
        grid-template-columns: 1fr;
    }
    
    .wallet-stats {
        grid-template-columns: 1fr;
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
    <div class="h1">Cashback Wallets & Management</div>
    <p class="sub">View member balances, transaction history, and make adjustments.</p>

    <?php if ($bootstrap_warning): ?>
        <div class="notice alert-error"><?= h($bootstrap_warning) ?></div>
    <?php endif; ?>

    <!-- Navigation Tabs -->
    <div class="points-nav">
        <a href="index.php" class="points-nav-tab">Programs</a>
        <a href="create.php" class="points-nav-tab">Create Program</a>
        <a href="wallets.php" class="points-nav-tab active">Members</a>
        <a href="reports.php" class="points-nav-tab">Reports</a>
    </div>

    <!-- Quick Stats Overview -->
    <div class="wallet-stats">
        <div class="wallet-stat-card">
            <div class="wallet-stat-value"><?= money_display($totalBalance) ?></div>
            <div class="wallet-stat-label">Total Balance</div>
        </div>
        <div class="wallet-stat-card">
            <div class="wallet-stat-value"><?= number_format($activeCount) ?></div>
            <div class="wallet-stat-label">Active Wallets</div>
        </div>
        <div class="wallet-stat-card">
            <div class="wallet-stat-value"><?= number_format($totalCount) ?></div>
            <div class="wallet-stat-label">Total Members</div>
        </div>
        <div class="wallet-stat-card">
            <div class="wallet-stat-value"><?= $activeCount > 0 ? money_display($totalBalance / $activeCount) : '0.00' ?></div>
            <div class="wallet-stat-label">Avg Balance</div>
        </div>
    </div>

    <!-- Sub Tab Navigation (Without Bulk Actions) -->
    <div class="sub-tabs">
        <button class="sub-tab <?= $selectedTab === 'wallets' ? 'active' : '' ?>" onclick="switchTab('wallets')">
            Wallet Balances
        </button>
        <button class="sub-tab <?= $selectedTab === 'adjustments' ? 'active' : '' ?>" onclick="switchTab('adjustments')">
            Make Adjustment
        </button>
    </div>

    <!-- Wallets Tab -->
    <div id="wallets-tab" class="card" style="<?= $selectedTab !== 'wallets' ? 'display: none;' : '' ?>">
        <div class="filter-bar">
            <input type="text" id="search" placeholder="Search by name, phone, email..." 
                   class="ms-input" value="<?= h($search) ?>">
            <select id="status-filter" class="ms-input" style="width: 200px;">
                <option value="all">All Members</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>With Balance</option>
                <option value="zero" <?= $statusFilter === 'zero' ? 'selected' : '' ?>>Zero Balance</option>
            </select>
            <button class="btn primary" onclick="searchWallets()">Search</button>
            <button class="btn" onclick="resetFilters()">Reset</button>
        </div>
        
        <div class="scroll-body">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width: 25%">Customer</th>
                        <th style="width: 20%">Contact</th>
                        <th style="width: 15%">Balance</th>
                        <th style="width: 20%">Last Activity</th>
                        <th style="width: 20%">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($wallets)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px;">
                                <div class="helper">
                                    <?= $search ? 'No wallets found matching your search.' : 'No active wallets found.' ?>
                                </div>
                                <div style="margin-top: 12px;">
                                    <button class="btn small primary" onclick="resetFilters()">View All Members</button>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($wallets as $wallet): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 600;"><?= h($wallet['name']) ?></div>
                                <div class="helper">ID: #<?= h($wallet['id']) ?></div>
                            </td>
                            <td>
                                <?php if ($wallet['phone']): ?>
                                    <div><?= h($wallet['phone']) ?></div>
                                <?php endif; ?>
                                <?php if ($wallet['email']): ?>
                                    <div class="helper"><?= h($wallet['email']) ?></div>
                                <?php endif; ?>
                                <?php if (!$wallet['phone'] && !$wallet['email']): ?>
                                    <span class="helper">No contact info</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="font-weight: 600; color: <?= $wallet['balance'] > 0 ? 'var(--ms-green)' : 'var(--ms-gray-110)' ?>;">
                                    <?= money_display($wallet['balance']) ?>
                                </span>
                            </td>
                            <td>
                                <?= $wallet['last_activity'] ? date('M j, Y', strtotime($wallet['last_activity'])) : '—' ?>
                            </td>
                            <td>
                                <div style="display: flex; gap: 8px;">
                                    <button class="btn small" onclick="viewLedger(<?= $wallet['id'] ?>, '<?= h(addslashes($wallet['name'])) ?>')">
                                        History
                                    </button>
                                    <button class="btn small primary" onclick="quickAdjust(<?= $wallet['id'] ?>, '<?= h(addslashes($wallet['name'])) ?>', <?= $wallet['balance'] ?>)">
                                        Adjust
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($totalPages > 1): ?>
        <div style="padding: 20px; display: flex; justify-content: center; gap: 8px; background: var(--ms-gray-10); border-top: 1px solid var(--ms-gray-30);">
            <?php if ($page > 1): ?>
                <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>" class="btn small">← Previous</a>
            <?php endif; ?>
            
            <div style="display: flex; gap: 4px;">
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <a href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>" 
                       class="btn small <?= $i == $page ? 'primary' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
            </div>
            
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&status=<?= $statusFilter ?>" class="btn small">Next →</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Adjustments Tab with Searchable Customer Dropdown -->
    <div id="adjustments-tab" class="card" style="<?= $selectedTab !== 'adjustments' ? 'display: none;' : '' ?>; padding: 32px;">
        <h2 style="margin: 0 0 24px 0; font-size: 18px; font-weight: 600;">Make Balance Adjustment</h2>
        
        <form id="adjustment-form">
            <div style="max-width: 100%;">
                <!-- Customer Selection with Amount and Type on same line -->
                <div class="form-row-3">
                    <div>
                        <label for="adj_customer_search">Customer *</label>
                        <div class="searchable-dropdown">
                            <input type="text" id="adj_customer_search" class="ms-input" 
                                   placeholder="Search by name, phone, or email..." required>
                            <input type="hidden" id="adj_customer" name="customer_id">
                            <div class="dropdown-list" id="customer-dropdown"></div>
                        </div>
                        <div class="hint">Start typing to search customers</div>
                    </div>
                    
                    <div>
                        <label for="adj_amount">Adjustment Amount *</label>
                        <input type="number" id="adj_amount" class="ms-input" step="0.01" required
                               placeholder="e.g. 10.00 or -5.00">
                        <div class="hint">Positive adds, negative deducts</div>
                    </div>
                    
                    <div>
                        <label for="adj_type">Adjustment Type</label>
                        <select id="adj_type" class="ms-input">
                            <option value="manual">Manual Adjustment</option>
                            <option value="correction">Balance Correction</option>
                            <option value="promotional">Promotional Credit</option>
                            <option value="compensation">Compensation</option>
                        </select>
                        <div class="hint">Category for reporting</div>
                    </div>
                </div>
                
                <!-- Reason field -->
                <div style="margin-bottom: 24px;">
                    <label for="adj_reason">Reason *</label>
                    <textarea id="adj_reason" class="ms-input" rows="3" required
                              placeholder="Describe the reason for this adjustment..."></textarea>
                    <div class="hint">This will be visible in the transaction history</div>
                </div>
                
                <!-- Preview section -->
                <div id="adjustment-preview" style="display: none; padding: 16px; background: var(--ms-blue-lighter); border-radius: var(--ms-radius-lg); margin-bottom: 24px;">
                    <h4 style="margin: 0 0 8px 0; font-size: 14px; font-weight: 600; color: var(--ms-blue);">Adjustment Preview</h4>
                    <div style="font-size: 13px; color: var(--ms-gray-130);">
                        <div>Customer: <span id="preview-customer" style="font-weight: 600;"></span></div>
                        <div>Current Balance: <span id="preview-current" style="font-weight: 600;"></span></div>
                        <div>Adjustment: <span id="preview-adjustment" style="font-weight: 600;"></span></div>
                        <div>New Balance: <span id="preview-new" style="font-weight: 600;"></span></div>
                    </div>
                </div>
                
                <div class="form-footer" style="margin: 0; padding: 0; border: none;">
                    <button type="button" class="btn" onclick="resetAdjustmentForm()">Reset</button>
                    <button type="submit" class="btn primary">Apply Adjustment</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Ledger Modal -->
<div id="ledgerModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3 id="ledgerTitle">Transaction History</h3>
            <button class="modal-close" onclick="closeLedger()">&times;</button>
        </div>
        <div class="modal-body">
            <table class="table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Amount</th>
                        <th>Order</th>
                        <th>Notes</th>
                    </tr>
                </thead>
                <tbody id="ledgerTableBody">
                    <tr><td colspan="5" style="text-align: center;"><span class="helper">Loading...</span></td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="_shared/scripts.js"></script>
<script>
// Global variables
let selectedCustomer = null;
let customerSearchTimeout = null;

function switchTab(tab) {
    document.querySelectorAll('.sub-tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('[id$="-tab"]').forEach(t => t.style.display = 'none');
    
    document.getElementById(tab + '-tab').style.display = 'block';
    event.target.classList.add('active');
    
    const url = new URL(window.location);
    url.searchParams.set('tab', tab);
    window.history.replaceState({}, '', url);
}

function searchWallets() {
    const search = document.getElementById('search').value;
    const status = document.getElementById('status-filter').value;
    window.location = '?search=' + encodeURIComponent(search) + '&status=' + status;
}

function resetFilters() {
    window.location = '?';
}

function viewLedger(customerId, customerName) {
    document.getElementById('ledgerTitle').textContent = 'Transaction History - ' + customerName;
    document.getElementById('ledgerModal').classList.add('show');
    
    fetch(window.location.pathname, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=load_customer_ledger&customer_id=' + customerId
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('ledgerTableBody').innerHTML = data.html;
        }
    });
}

function closeLedger() {
    document.getElementById('ledgerModal').classList.remove('show');
}

function quickAdjust(customerId, customerName, currentBalance) {
    switchTab('adjustments');
    
    // Set the customer
    selectedCustomer = {
        id: customerId,
        name: customerName,
        balance: currentBalance
    };
    
    document.getElementById('adj_customer_search').value = customerName;
    document.getElementById('adj_customer').value = customerId;
    updateAdjustmentPreview();
}

function resetAdjustmentForm() {
    document.getElementById('adjustment-form').reset();
    document.getElementById('adjustment-preview').style.display = 'none';
    selectedCustomer = null;
    document.getElementById('adj_customer').value = '';
}

function updateAdjustmentPreview() {
    const amount = parseFloat(document.getElementById('adj_amount').value) || 0;
    const preview = document.getElementById('adjustment-preview');
    
    if (selectedCustomer && amount !== 0) {
        const currentBalance = selectedCustomer.balance || 0;
        const newBalance = currentBalance + amount;
        
        document.getElementById('preview-customer').textContent = selectedCustomer.name;
        document.getElementById('preview-current').textContent = currentBalance.toFixed(2);
        document.getElementById('preview-adjustment').textContent = (amount > 0 ? '+' : '') + amount.toFixed(2);
        document.getElementById('preview-adjustment').style.color = amount > 0 ? 'var(--ms-green)' : 'var(--ms-red)';
        document.getElementById('preview-new').textContent = newBalance.toFixed(2);
        document.getElementById('preview-new').style.color = newBalance > 0 ? 'var(--ms-green)' : 'var(--ms-gray-110)';
        
        preview.style.display = 'block';
    } else {
        preview.style.display = 'none';
    }
}

// Searchable customer dropdown
function initCustomerSearch() {
    const searchInput = document.getElementById('adj_customer_search');
    const dropdown = document.getElementById('customer-dropdown');
    const hiddenInput = document.getElementById('adj_customer');
    
    if (!searchInput || !dropdown) return;
    
    searchInput.addEventListener('input', function() {
        const value = this.value.trim();
        
        // Clear previous timeout
        if (customerSearchTimeout) clearTimeout(customerSearchTimeout);
        
        // Clear selected customer if text changed
        if (selectedCustomer && selectedCustomer.name !== value) {
            selectedCustomer = null;
            hiddenInput.value = '';
            updateAdjustmentPreview();
        }
        
        if (value.length < 2) {
            dropdown.classList.remove('open');
            return;
        }
        
        // Debounce search
        customerSearchTimeout = setTimeout(() => {
            searchCustomers(value);
        }, 300);
    });
    
    searchInput.addEventListener('focus', function() {
        if (this.value.length >= 2 && !selectedCustomer) {
            searchCustomers(this.value);
        }
    });
    
    // Close dropdown on outside click
    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.classList.remove('open');
        }
    });
}

function searchCustomers(searchTerm) {
    const dropdown = document.getElementById('customer-dropdown');
    
    fetch(window.location.pathname, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=search_customers&search=' + encodeURIComponent(searchTerm)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success && data.customers) {
            if (data.customers.length === 0) {
                dropdown.innerHTML = '<div class="dropdown-item" style="text-align: center; color: var(--ms-gray-110);">No customers found</div>';
            } else {
                dropdown.innerHTML = data.customers.map(customer => {
                    const balance = parseFloat(customer.balance || 0);
                    return `
                        <div class="dropdown-item" onclick="selectCustomer(${customer.id}, '${customer.name.replace(/'/g, "\\'")}', ${balance})">
                            <div class="item-name">${customer.name}</div>
                            <div class="item-detail">
                                ${customer.phone || ''} ${customer.email ? '• ' + customer.email : ''}
                                • Balance: ${balance.toFixed(2)}
                            </div>
                        </div>
                    `;
                }).join('');
            }
            dropdown.classList.add('open');
        }
    })
    .catch(err => {
        console.error('Search error:', err);
        dropdown.classList.remove('open');
    });
}

function selectCustomer(id, name, balance) {
    selectedCustomer = { id, name, balance };
    document.getElementById('adj_customer_search').value = name;
    document.getElementById('adj_customer').value = id;
    document.getElementById('customer-dropdown').classList.remove('open');
    updateAdjustmentPreview();
}

// Event listeners
document.addEventListener('DOMContentLoaded', function() {
    // Initialize customer search
    initCustomerSearch();
    
    // Adjustment amount change
    document.getElementById('adj_amount')?.addEventListener('input', updateAdjustmentPreview);
    
    // Adjustment form submission
    document.getElementById('adjustment-form')?.addEventListener('submit', function(e) {
        e.preventDefault();
        
        if (!selectedCustomer) {
            alert('Please select a customer');
            return;
        }
        
        const amount = document.getElementById('adj_amount').value;
        const reason = document.getElementById('adj_reason').value;
        const type = document.getElementById('adj_type').value;
        
        if (!amount || !reason) {
            alert('Please fill all required fields');
            return;
        }
        
        const fullReason = '[' + type.toUpperCase() + '] ' + reason;
        
        if (confirm('Apply adjustment of ' + amount + ' to ' + selectedCustomer.name + '?')) {
            fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: 'action=adjust_wallet&customer_id=' + selectedCustomer.id + 
                      '&amount=' + amount + '&reason=' + encodeURIComponent(fullReason)
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Adjustment applied successfully');
                    location.reload();
                } else {
                    alert(data.error || 'Failed to apply adjustment');
                }
            });
        }
    });
    
    // Search on Enter
    document.getElementById('search')?.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') searchWallets();
    });
    
    // Close modal on escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeLedger();
        }
    });
    
    // Close modal on outside click
    document.getElementById('ledgerModal')?.addEventListener('click', function(e) {
        if (e.target === this) {
            closeLedger();
        }
    });
});
</script>

</body>
</html>