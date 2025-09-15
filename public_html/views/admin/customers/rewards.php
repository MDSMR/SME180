<?php
// /public_html/views/admin/customers/rewards.php
declare(strict_types=1);

/* ---------- Bootstrap ---------- */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { 
    @ini_set('display_errors','1'); 
    @ini_set('display_startup_errors','1'); 
    error_reporting(E_ALL); 
} else { 
    @ini_set('display_errors','0'); 
}

$bootstrap_ok = false; 
$bootstrap_msg = '';

try {
    $configPath = dirname(__DIR__, 3) . '/config/db.php';
    if (!is_file($configPath)) throw new RuntimeException('Configuration file not found: /config/db.php');
    require_once $configPath;

    // Enhanced session management - CRITICAL FIX
    if (function_exists('use_backend_session')) { 
        use_backend_session(); 
    } else { 
        if (session_status() === PHP_SESSION_ACTIVE) {
            // If wrong session type is active, regenerate
            if (($_SESSION['session_type'] ?? '') === 'pos') {
                session_write_close();
                session_name('smorll_session');
                session_start();
                session_regenerate_id(true);
                $_SESSION = [];
            }
        } else {
            session_name('smorll_session');
            session_start();
        }
        $_SESSION['session_type'] = 'backend';
    }

    // Clear any corrupted AJAX states
    if (isset($_SESSION['ajax_in_progress'])) {
        unset($_SESSION['ajax_in_progress']);
    }
    if (isset($_SESSION['rewards_page_loaded'])) {
        unset($_SESSION['rewards_page_loaded']);
    }
    $_SESSION['rewards_page_loaded'] = true;
    
    // Ensure clean CSRF token
    if (empty($_SESSION['csrf_customer_rewards'])) {
        $_SESSION['csrf_customer_rewards'] = bin2hex(random_bytes(32));
    }

    $authPath = dirname(__DIR__, 3) . '/middleware/auth_login.php';
    if (!is_file($authPath)) throw new RuntimeException('Auth middleware not found');
    require_once $authPath;
    auth_require_login();

    if (!function_exists('db')) throw new RuntimeException('db() not available from config.php');
    $bootstrap_ok = true;
} catch (Throwable $e) { 
    $bootstrap_msg = $e->getMessage(); 
}

/* ---------- Session / Tenant ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user && $bootstrap_ok) { 
    header('Location: /views/auth/login.php'); 
    exit; 
}
$tenantId = (int)($user['tenant_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);
$userRole = $user['role_key'] ?? '';
$userBranchId = (int)($user['branch_id'] ?? 1);

/* ---------- CSRF Token ---------- */
$csrf = $_SESSION['csrf_customer_rewards'];

/* ---------- Customer ID ---------- */
$customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

/* ---------- Handle AJAX Actions ---------- */
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest') {
    header('Content-Type: application/json; charset=utf-8');
    
    $action = $_POST['action'] ?? $_GET['action'] ?? '';
    
    try {
        if (!$bootstrap_ok) {
            throw new Exception('System not properly initialized');
        }
        
        $pdo = db();
        
        switch($action) {
            case 'validate_session':
                echo json_encode([
                    'valid' => isset($_SESSION['user']) && !empty($_SESSION['user']['id']),
                    'session_type' => $_SESSION['session_type'] ?? 'unknown',
                    'user_id' => $_SESSION['user']['id'] ?? null
                ]);
                break;
                
            case 'list_customers':
                $q = trim($_GET['q'] ?? '');
                $classification = $_GET['classification'] ?? 'all';
                $rewards = $_GET['rewards'] ?? 'all';
                $page = max(1, (int)($_GET['page'] ?? 1));
                $limit = 20;
                $offset = ($page - 1) * $limit;
                
                $where = ["c.tenant_id = :t"];
                $params = [':t' => $tenantId];
                
                if ($q !== '') {
                    $searchNumber = preg_replace('/[^0-9]/', '', $q);
                    $where[] = "(c.id = :idq OR c.name LIKE :q OR c.phone LIKE :q2 OR c.rewards_member_no LIKE :q3 OR c.rewards_member_no = :q4)";
                    $params[':idq'] = ctype_digit($q) ? (int)$q : -1;
                    $like = "%$q%";
                    $params[':q'] = $like;
                    $params[':q2'] = $like;
                    $params[':q3'] = $like;
                    $params[':q4'] = $searchNumber;
                }
                
                if (in_array($classification, ['regular','vip','corporate','blocked'], true)) {
                    $where[] = "c.classification = :cl";
                    $params[':cl'] = $classification;
                }
                
                if ($rewards === 'enrolled') {
                    $where[] = "c.rewards_enrolled = 1";
                } elseif ($rewards === 'not') {
                    $where[] = "(c.rewards_enrolled = 0 OR c.rewards_enrolled IS NULL)";
                }
                
                $whereSql = 'WHERE ' . implode(' AND ', $where);
                
                $countSql = "SELECT COUNT(*) FROM customers c $whereSql";
                $countStmt = $pdo->prepare($countSql);
                $countStmt->execute($params);
                $totalCount = (int)$countStmt->fetchColumn();
                
                $sql = "
                    SELECT 
                        c.id, c.name, c.phone, c.classification, 
                        c.rewards_enrolled, c.rewards_member_no, c.points_balance,
                        c.created_at,
                        (SELECT MAX(ll.created_at) FROM loyalty_ledgers ll 
                         WHERE ll.customer_id = c.id AND ll.tenant_id = c.tenant_id) as last_activity_at
                    FROM customers c
                    $whereSql 
                    ORDER BY c.id DESC 
                    LIMIT :limit OFFSET :offset
                ";
                
                $stmt = $pdo->prepare($sql);
                foreach ($params as $key => $value) {
                    if ($key !== ':q4') {
                        $stmt->bindValue($key, $value);
                    } else if (is_numeric($searchNumber)) {
                        $stmt->bindValue($key, $searchNumber);
                    } else {
                        $stmt->bindValue($key, '');
                    }
                }
                $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                
                $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($customers as &$customer) {
                    if ($customer['rewards_member_no']) {
                        if (strlen($customer['rewards_member_no']) === 16) {
                            $customer['member_display'] = '****' . substr($customer['rewards_member_no'], -4);
                        } else {
                            $customer['member_display'] = $customer['rewards_member_no'];
                        }
                    } else {
                        $customer['member_display'] = '';
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'data' => $customers,
                    'total' => $totalCount,
                    'page' => $page,
                    'pages' => ceil($totalCount / $limit)
                ]);
                break;

            case 'load_customer':
                $cid = (int)($_GET['customer_id'] ?? 0);
                if (!$cid) throw new Exception('Customer ID required');
                
                $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = :id AND tenant_id = :t");
                $stmt->execute([':id' => $cid, ':t' => $tenantId]);
                $customer = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$customer) throw new Exception('Customer not found');
                
                $stmt = $pdo->prepare("
                    SELECT 
                        COALESCE(SUM(CASE WHEN direction = 'earn' THEN amount ELSE 0 END), 0) as lifetime_earned,
                        MAX(created_at) as last_activity_at
                    FROM loyalty_ledgers 
                    WHERE tenant_id = :t AND customer_id = :cid AND program_type = 'points'
                ");
                $stmt->execute([':t' => $tenantId, ':cid' => $cid]);
                $lifetimeData = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $stmt = $pdo->prepare("
                    SELECT 
                        ll.*,
                        lp.name as program_name,
                        u.name as user_name,
                        b.name as branch_name
                    FROM loyalty_ledgers ll
                    LEFT JOIN loyalty_programs lp ON lp.id = ll.program_id AND lp.tenant_id = ll.tenant_id
                    LEFT JOIN users u ON u.id = ll.user_id AND u.tenant_id = ll.tenant_id
                    LEFT JOIN branches b ON b.id = ll.branch_id AND b.tenant_id = ll.tenant_id
                    WHERE ll.tenant_id = :t AND ll.customer_id = :cid
                    ORDER BY ll.created_at DESC 
                    LIMIT 50
                ");
                $stmt->execute([':t' => $tenantId, ':cid' => $cid]);
                $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $stmt = $pdo->prepare("
                    SELECT 
                        ll.program_id,
                        lp.name as program_name,
                        SUM(CASE WHEN ll.direction = 'earn' THEN ll.amount 
                                 WHEN ll.direction = 'redeem' THEN -ll.amount 
                                 ELSE 0 END) as stamp_balance
                    FROM loyalty_ledgers ll
                    JOIN loyalty_programs lp ON lp.id = ll.program_id
                    WHERE ll.tenant_id = :t 
                    AND ll.customer_id = :cid 
                    AND ll.program_type = 'stamp'
                    GROUP BY ll.program_id, lp.name
                    HAVING stamp_balance > 0
                ");
                $stmt->execute([':t' => $tenantId, ':cid' => $cid]);
                $stampBalances = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $stmt = $pdo->prepare("
                    SELECT 
                        SUM(CASE WHEN direction = 'earn' THEN amount 
                                 WHEN direction = 'redeem' THEN -amount 
                                 ELSE 0 END) as cashback_balance
                    FROM loyalty_ledgers
                    WHERE tenant_id = :t 
                    AND customer_id = :cid 
                    AND program_type = 'cashback'
                ");
                $stmt->execute([':t' => $tenantId, ':cid' => $cid]);
                $cashbackBalance = (float)$stmt->fetchColumn();
                
                echo json_encode([
                    'success' => true,
                    'customer' => $customer,
                    'lifetime_earned' => $lifetimeData['lifetime_earned'] ?? 0,
                    'last_activity' => $lifetimeData['last_activity_at'],
                    'transactions' => $transactions,
                    'stampBalances' => $stampBalances,
                    'cashbackBalance' => $cashbackBalance
                ]);
                break;

            case 'adjust':
                if (($_POST['csrf'] ?? '') !== $csrf) {
                    throw new Exception('Invalid CSRF token');
                }
                
                $cid = (int)($_POST['customer_id'] ?? 0);
                $direction = $_POST['direction'] ?? 'earn';
                $amount = (int)($_POST['amount'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');
                $branchId = (int)($_POST['branch_id'] ?? $userBranchId);
                
                if (!$cid) throw new Exception('Customer ID required');
                if ($amount <= 0) throw new Exception('Amount must be greater than 0');
                if ($amount > 10000) throw new Exception('Maximum adjustment is 10,000 points');
                if (empty($reason)) throw new Exception('Reason is required');
                
                $pdo->beginTransaction();
                
                try {
                    $stmt = $pdo->prepare("
                        SELECT points_balance 
                        FROM customers 
                        WHERE id = :id AND tenant_id = :t 
                        FOR UPDATE
                    ");
                    $stmt->execute([':id' => $cid, ':t' => $tenantId]);
                    $currentBalance = (int)$stmt->fetchColumn();
                    
                    if ($direction === 'earn') {
                        $newBalance = $currentBalance + $amount;
                    } else {
                        if ($amount > $currentBalance) {
                            throw new Exception('Cannot deduct more points than available (current balance: ' . number_format($currentBalance) . ')');
                        }
                        $newBalance = $currentBalance - $amount;
                    }
                    
                    $stmt = $pdo->prepare("
                        UPDATE customers 
                        SET points_balance = :balance, updated_at = NOW()
                        WHERE id = :id AND tenant_id = :t
                    ");
                    $stmt->execute([
                        ':balance' => $newBalance,
                        ':id' => $cid,
                        ':t' => $tenantId
                    ]);
                    
                    $stmt = $pdo->prepare("
                        SELECT id 
                        FROM loyalty_programs 
                        WHERE tenant_id = :t 
                        AND program_type = 'points' 
                        AND status = 'active'
                        AND (start_at IS NULL OR start_at <= NOW())
                        AND (end_at IS NULL OR end_at >= NOW())
                        ORDER BY id ASC
                        LIMIT 1
                    ");
                    $stmt->execute([':t' => $tenantId]);
                    $programId = $stmt->fetchColumn();
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO loyalty_ledgers 
                        (tenant_id, program_type, program_id, branch_id,
                         customer_id, order_id, direction, amount, 
                         reason, user_id, created_at)
                        VALUES (:t, 'points', :pid, :bid,
                                :cid, NULL, :dir, :amt,
                                :reason, :uid, NOW())
                    ");
                    $stmt->execute([
                        ':t' => $tenantId,
                        ':pid' => $programId ?: null,
                        ':bid' => $branchId,
                        ':cid' => $cid,
                        ':dir' => 'adjust',
                        ':amt' => $amount,
                        ':reason' => "Manual adjustment: $reason",
                        ':uid' => $userId
                    ]);
                    
                    $pdo->commit();
                    
                    $message = $direction === 'earn'
                        ? "Added " . number_format($amount) . " points successfully (new balance: " . number_format($newBalance) . ")" 
                        : "Deducted " . number_format($amount) . " points successfully (new balance: " . number_format($newBalance) . ")";
                    
                    echo json_encode(['success' => true, 'message' => $message]);
                    
                } catch (Exception $e) {
                    $pdo->rollback();
                    throw $e;
                }
                break;
                
            case 'get_branches':
                $stmt = $pdo->prepare("
                    SELECT id, name 
                    FROM branches 
                    WHERE tenant_id = :t AND is_active = 1
                    ORDER BY name
                ");
                $stmt->execute([':t' => $tenantId]);
                $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'branches' => $branches]);
                break;
                
            default:
                throw new Exception('Invalid action');
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

/* ---------- Helper Functions ---------- */
function h($s): string { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

// Set active nav item
$active = 'customers_rewards';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rewards Management · Smorll POS</title>
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="/assets/css/rewards.css?v=<?= time() ?>" rel="stylesheet">
</head>
<body>
    <?php
    require __DIR__ . '/../../partials/admin_nav.php';
    ?>

    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Rewards Management</h1>
            <p class="page-subtitle">Manage customer loyalty points and rewards programs</p>
        </div>

        <!-- Filters Bar -->
        <div class="filters-bar" id="filtersBar">
            <div class="filter-group search-group">
                <label>Search</label>
                <input type="text" id="tableSearchInput" placeholder="Search by name, phone..." onkeyup="debounceTableSearch()">
            </div>

            <div class="filter-group">
                <label>Classification</label>
                <select id="classificationFilter" onchange="applyTableFilters()">
                    <option value="all">All Types</option>
                    <option value="regular">Regular</option>
                    <option value="vip">VIP</option>
                    <option value="corporate">Corporate</option>
                    <option value="blocked">Blocked</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Rewards Status</label>
                <select id="rewardsFilter" onchange="applyTableFilters()">
                    <option value="all">All Status</option>
                    <option value="enrolled">Enrolled</option>
                    <option value="not">Not Enrolled</option>
                </select>
            </div>

            <div class="filter-actions">
                <button type="button" class="btn" id="clearFiltersBtn" onclick="clearTableFilters()" style="display: none;">Clear</button>
            </div>
        </div>

        <!-- Customer Table -->
        <div class="customer-table-card" id="customerTableCard">
            <div class="table-header">
                <h2 class="table-title">Customer List</h2>
                <span style="font-size: 13px; color: var(--ms-gray-110);">Click on a customer to view rewards details</span>
            </div>
            <div id="customersTable"></div>
        </div>

        <!-- Customer Details Section -->
        <div class="customer-details-section" id="customerDetailsSection">
            
            <!-- Compact Customer Info Card -->
            <div class="customer-info-card">
                <div class="customer-info-header">
                    <div class="customer-info-details">
                        <h2>
                            <span id="customerName">-</span>
                            <span id="customerTypeBadge" class="customer-type-badge regular"></span>
                        </h2>
                        <div class="customer-info-meta">
                            <span>
                                <strong>Phone:</strong> <span id="customerPhone">-</span>
                            </span>
                            <span>·</span>
                            <span>
                                <strong>ID:</strong> #<span id="customerIdDisplay">-</span>
                            </span>
                            <span>·</span>
                            <span>
                                <strong>Status:</strong> <span id="customerStatus">Active</span>
                            </span>
                        </div>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="showAdjustModal()">
                            Adjust Points
                        </button>
                    </div>
                </div>
            </div>

            <!-- Reward Summary Cards -->
            <div class="reward-summary-grid">
                <div class="reward-summary-card points">
                    <div class="reward-summary-label">Points Balance</div>
                    <div class="reward-summary-value" id="summaryPoints">0</div>
                </div>
                <div class="reward-summary-card cashback">
                    <div class="reward-summary-label">Cashback</div>
                    <div class="reward-summary-value" id="summaryCashback">0</div>
                </div>
                <div class="reward-summary-card stamps">
                    <div class="reward-summary-label">Active Stamps</div>
                    <div class="reward-summary-value" id="summaryStamps">0</div>
                </div>
                <div class="reward-summary-card discount">
                    <div class="reward-summary-label">Discount</div>
                    <div class="reward-summary-value" id="summaryDiscount">None</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('points', event)">
                    Points
                    <span class="tab-count" id="countPoints">0</span>
                </button>
                <button class="tab" onclick="switchTab('cashback', event)">
                    Cashback
                    <span class="tab-count" id="countCashback">0</span>
                </button>
                <button class="tab" onclick="switchTab('stamps', event)">
                    Stamps
                    <span class="tab-count" id="countStamps">0</span>
                </button>
                <button class="tab" onclick="switchTab('discounts', event)">
                    Discounts
                    <span class="tab-count" id="countDiscounts">0</span>
                </button>
            </div>

            <!-- Tab Contents -->
            <div id="tabPoints" class="tab-content active">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Points Transactions</h3>
                    </div>
                    <div class="card-body">
                        <div id="pointsTransactions" class="transaction-list"></div>
                    </div>
                </div>
            </div>

            <div id="tabCashback" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Cashback Transactions</h3>
                    </div>
                    <div class="card-body">
                        <div id="cashbackTransactions" class="transaction-list"></div>
                    </div>
                </div>
            </div>

            <div id="tabStamps" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Stamp Collections</h3>
                    </div>
                    <div class="card-body">
                        <div id="stampsTransactions" class="transaction-list"></div>
                    </div>
                </div>
            </div>

            <div id="tabDiscounts" class="tab-content">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Discount History</h3>
                    </div>
                    <div class="card-body">
                        <div class="empty-state">
                            <h3>No discount history</h3>
                            <p>Customer has no discount transactions</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div id="adjustModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Adjust Points Balance</h3>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            <form id="adjustForm" onsubmit="submitAdjustment(event)">
                <div class="modal-body">
                    <input type="hidden" name="csrf" value="<?= h($csrf) ?>">
                    <input type="hidden" name="action" value="adjust">
                    <input type="hidden" id="adjustCustomerId" name="customer_id" value="">
                    
                    <div class="form-group">
                        <label class="form-label">Branch</label>
                        <select id="adjustBranch" name="branch_id" class="form-control">
                            <option value="<?= $userBranchId ?>">Current Branch</option>
                        </select>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">Direction</label>
                            <select name="direction" class="form-control" id="adjustDirection" onchange="updateMaxAmount()">
                                <option value="earn">Add Points</option>
                                <option value="redeem">Deduct Points</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">Amount</label>
                            <input type="number" name="amount" id="adjustAmount" class="form-control" 
                                   min="1" max="10000" required>
                            <div style="font-size: 12px; color: var(--ms-gray-110); margin-top: 4px;">
                                Current balance: <span id="currentBalanceHint">0</span> points
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Reason (Required)</label>
                        <textarea name="reason" class="form-control" rows="3" required 
                                  placeholder="Enter reason for adjustment"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Apply Adjustment</button>
                </div>
            </form>
        </div>
    </div>

    <?php
    require __DIR__ . '/../../partials/admin_nav_close.php';
    ?>

    <script>
        // Pass PHP variables to JavaScript
        window.REWARDS_PAGE = {
            customerId: <?= $customerId ?: 'null' ?>,
            userBranchId: <?= $userBranchId ?>
        };
    </script>
    <script src="/assets/js/rewards.js?v=<?= time() ?>"></script>
</body>
</html>