<?php
/**
 * SME 180 - View Tenant Details
 * Path: /views/superadmin/tenants/view.php
 */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/db.php';

use_backend_session();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'super_admin') {
    redirect('/views/auth/login.php');
    exit;
}

require_once dirname(__DIR__) . '/includes/sidebar.php';

$pdo = db();
$tenant_id = (int)($_GET['id'] ?? 0);

if (!$tenant_id) {
    redirect('/views/superadmin/tenants/index.php');
    exit;
}

// Get tenant details with statistics - FIXED: Changed final_total to total_amount
$stmt = $pdo->prepare("
    SELECT t.*,
           COALESCE((SELECT COUNT(*) FROM users WHERE tenant_id = t.id AND disabled_at IS NULL), 0) as user_count,
           COALESCE((SELECT COUNT(*) FROM branches WHERE tenant_id = t.id AND is_active = 1), 0) as branch_count,
           COALESCE((SELECT COUNT(*) FROM orders WHERE tenant_id = t.id), 0) as order_count,
           COALESCE((SELECT COUNT(*) FROM products WHERE tenant_id = t.id AND is_active = 1), 0) as product_count,
           COALESCE((SELECT COUNT(*) FROM customers WHERE tenant_id = t.id), 0) as customer_count,
           COALESCE((SELECT SUM(total_amount) FROM orders WHERE tenant_id = t.id AND payment_status IN ('paid', 'partial')), 0) as total_revenue
    FROM tenants t
    WHERE t.id = ?
");
$stmt->execute([$tenant_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    redirect('/views/superadmin/tenants/index.php');
    exit;
}

// Get currency from settings table
$stmt = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = ? AND `key` = 'currency' LIMIT 1");
$stmt->execute([$tenant_id]);
$currencyResult = $stmt->fetch(PDO::FETCH_ASSOC);
$currency = $currencyResult['value'] ?? 'EGP';

// Get users
$stmt = $pdo->prepare("
    SELECT u.*, 
           COALESCE((SELECT COUNT(*) FROM orders WHERE created_by_user_id = u.id), 0) as order_count,
           (SELECT GROUP_CONCAT(b.name SEPARATOR ', ') 
            FROM branches b 
            JOIN user_branches ub ON b.id = ub.branch_id 
            WHERE ub.user_id = u.id) as branches
    FROM users u
    WHERE u.tenant_id = ?
    ORDER BY u.created_at DESC
    LIMIT 10
");
$stmt->execute([$tenant_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get branches
$stmt = $pdo->prepare("
    SELECT b.*,
           COALESCE((SELECT COUNT(*) FROM orders WHERE branch_id = b.id), 0) as order_count,
           COALESCE((SELECT COUNT(*) FROM user_branches WHERE branch_id = b.id), 0) as user_count
    FROM branches b
    WHERE b.tenant_id = ?
    ORDER BY b.created_at DESC
");
$stmt->execute([$tenant_id]);
$branches = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get recent orders - FIXED: Changed final_total to total_amount
$stmt = $pdo->prepare("
    SELECT o.*, b.name as branch_name, u.name as user_name
    FROM orders o
    LEFT JOIN branches b ON o.branch_id = b.id
    LEFT JOIN users u ON o.created_by_user_id = u.id
    WHERE o.tenant_id = ?
    ORDER BY o.created_at DESC
    LIMIT 10
");
$stmt->execute([$tenant_id]);
$recent_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get subscription plan details
$stmt = $pdo->prepare("SELECT * FROM subscription_plans WHERE plan_key = ?");
$stmt->execute([$tenant['subscription_plan'] ?? 'starter']);
$plan_details = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<style>
    .view-container {
        padding: 24px;
        max-width: 1400px;
        margin: 0 auto;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 32px;
    }
    
    .page-title {
        font-size: 26px;
        font-weight: 700;
        color: #111827;
    }
    
    .page-subtitle {
        font-size: 14px;
        color: #6B7280;
        margin-top: 4px;
    }
    
    .header-actions {
        display: flex;
        gap: 12px;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    
    .stat-card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 10px;
        padding: 20px;
        text-align: center;
        transition: all 0.2s;
    }
    
    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }
    
    .stat-value {
        font-size: 28px;
        font-weight: 700;
        color: #7c3aed;
        margin-bottom: 8px;
    }
    
    .stat-label {
        font-size: 13px;
        color: #6B7280;
        font-weight: 500;
    }
    
    .info-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 24px;
        margin-bottom: 24px;
    }
    
    @media (max-width: 1024px) {
        .info-grid {
            grid-template-columns: 1fr;
        }
    }
    
    .card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        overflow: hidden;
    }
    
    .card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #E5E7EB;
        background: linear-gradient(to bottom, #FAFBFC, #F9FAFB);
    }
    
    .card-title {
        font-size: 15px;
        font-weight: 600;
        color: #111827;
    }
    
    .card-body {
        padding: 20px;
    }
    
    .info-row {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid #F3F4F6;
    }
    
    .info-row:last-child {
        border-bottom: none;
    }
    
    .info-label {
        color: #6B7280;
        font-size: 13px;
        font-weight: 500;
    }
    
    .info-value {
        color: #111827;
        font-weight: 600;
        font-size: 13px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th {
        background: #F9FAFB;
        padding: 12px;
        text-align: left;
        font-size: 12px;
        font-weight: 600;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid #E5E7EB;
    }
    
    td {
        padding: 12px;
        border-bottom: 1px solid #F3F4F6;
        font-size: 13px;
        color: #374151;
    }
    
    tbody tr:hover {
        background: #FAFBFC;
    }
    
    .badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .badge-active { background: #D1FAE5; color: #065F46; }
    .badge-inactive { background: #FEE2E2; color: #991B1B; }
    .badge-trial { background: #DBEAFE; color: #1E3A8A; }
    .badge-suspended { background: #FEE2E2; color: #991B1B; }
    .badge-admin { background: #FEF3C7; color: #92400E; }
    .badge-manager { background: #EDE9FE; color: #6D28D9; }
    .badge-user { background: #DBEAFE; color: #1E3A8A; }
    .badge-starter { background: #F3F4F6; color: #6B7280; }
    .badge-professional { background: #EDE9FE; color: #6D28D9; }
    .badge-enterprise { background: #FEF3C7; color: #92400E; }
    .badge-custom { background: #D1FAE5; color: #065F46; }
    
    .btn {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.2s;
    }
    
    .btn-primary { background: #7c3aed; color: white; }
    .btn-primary:hover { background: #6d28d9; transform: translateY(-1px); }
    .btn-secondary { background: white; color: #6B7280; border: 1px solid #D1D5DB; }
    .btn-secondary:hover { background: #F9FAFB; }
    .btn-warning { background: #F59E0B; color: white; }
    .btn-warning:hover { background: #D97706; transform: translateY(-1px); }
    
    .tabs {
        display: flex;
        gap: 16px;
        margin-bottom: 24px;
        border-bottom: 2px solid #E5E7EB;
    }
    
    .tab {
        padding: 12px 16px;
        background: none;
        border: none;
        font-size: 14px;
        font-weight: 500;
        color: #6B7280;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: all 0.2s;
    }
    
    .tab:hover {
        color: #374151;
    }
    
    .tab.active {
        color: #7c3aed;
        border-bottom-color: #7c3aed;
    }
    
    .tab-content {
        display: none;
    }
    
    .tab-content.active {
        display: block;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px;
        color: #6B7280;
    }
</style>

<div class="view-container">
    <div class="page-header">
        <div>
            <h1 class="page-title"><?= htmlspecialchars($tenant['name']) ?></h1>
            <p class="page-subtitle">
                Tenant ID: #<?= $tenant['id'] ?> • 
                Created: <?= date('M d, Y', strtotime($tenant['created_at'])) ?> •
                Status: <span class="badge badge-<?= $tenant['subscription_status'] ?? 'active' ?>">
                    <?= ucfirst($tenant['subscription_status'] ?? 'Active') ?>
                </span>
            </p>
        </div>
        <div class="header-actions">
            <a href="/views/superadmin/tenants/edit.php?id=<?= $tenant_id ?>" class="btn btn-warning">Edit</a>
            <a href="/views/superadmin/tenants/index.php" class="btn btn-secondary">Back to List</a>
        </div>
    </div>
    
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= intval($tenant['user_count']) ?>/<?= intval($tenant['max_users']) ?></div>
            <div class="stat-label">Users</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= intval($tenant['branch_count']) ?>/<?= intval($tenant['max_branches']) ?></div>
            <div class="stat-label">Branches</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= intval($tenant['product_count']) ?>/<?= intval($tenant['max_products']) ?></div>
            <div class="stat-label">Products</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format(intval($tenant['order_count'])) ?></div>
            <div class="stat-label">Total Orders</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format(intval($tenant['customer_count'])) ?></div>
            <div class="stat-label">Customers</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format(floatval($tenant['total_revenue']), 2) ?></div>
            <div class="stat-label">Revenue (<?= htmlspecialchars($currency) ?>)</div>
        </div>
    </div>
    
    <div class="info-grid">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Subscription Details</h3>
            </div>
            <div class="card-body">
                <div class="info-row">
                    <span class="info-label">Plan</span>
                    <span class="info-value">
                        <span class="badge badge-<?= htmlspecialchars($tenant['subscription_plan'] ?? 'starter') ?>">
                            <?= htmlspecialchars(ucfirst($tenant['subscription_plan'] ?? 'starter')) ?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status</span>
                    <span class="info-value">
                        <span class="badge badge-<?= $tenant['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $tenant['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Subscription Status</span>
                    <span class="info-value"><?= ucfirst($tenant['subscription_status'] ?? 'active') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Started</span>
                    <span class="info-value">
                        <?= $tenant['subscription_starts_at'] ? date('M d, Y', strtotime($tenant['subscription_starts_at'])) : 'N/A' ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Expires</span>
                    <span class="info-value">
                        <?= $tenant['subscription_expires_at'] ? date('M d, Y', strtotime($tenant['subscription_expires_at'])) : 'Never' ?>
                    </span>
                </div>
                <?php if ($plan_details): ?>
                <div class="info-row">
                    <span class="info-label">Monthly Price</span>
                    <span class="info-value">
                        $<?= number_format(floatval($plan_details['monthly_price']), 2) ?>
                    </span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Contact Information</h3>
            </div>
            <div class="card-body">
                <div class="info-row">
                    <span class="info-label">Billing Email</span>
                    <span class="info-value"><?= htmlspecialchars($tenant['billing_email'] ?: 'Not set') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Billing Contact</span>
                    <span class="info-value"><?= htmlspecialchars($tenant['billing_contact'] ?: 'Not set') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Currency</span>
                    <span class="info-value"><?= htmlspecialchars($currency) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Payment Method</span>
                    <span class="info-value"><?= ucfirst($tenant['payment_method'] ?? 'manual') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Grace Period</span>
                    <span class="info-value"><?= intval($tenant['grace_period_days'] ?? 7) ?> days</span>
                </div>
            </div>
        </div>
    </div>
    
    <div class="tabs">
        <button class="tab active" onclick="showTab('users')">Users (<?= count($users) ?>)</button>
        <button class="tab" onclick="showTab('branches')">Branches (<?= count($branches) ?>)</button>
        <button class="tab" onclick="showTab('orders')">Recent Orders</button>
    </div>
    
    <div id="users" class="tab-content active">
        <div class="card">
            <?php if (empty($users)): ?>
            <div class="empty-state">No users found for this tenant</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Branches</th>
                        <th>Orders</th>
                        <th>Last Login</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?= htmlspecialchars($user['name']) ?></td>
                        <td><?= htmlspecialchars($user['username']) ?></td>
                        <td><?= htmlspecialchars($user['email'] ?: '-') ?></td>
                        <td>
                            <span class="badge badge-<?= strpos($user['role_key'], 'admin') !== false ? 'admin' : (strpos($user['role_key'], 'manager') !== false ? 'manager' : 'user') ?>">
                                <?= htmlspecialchars(str_replace('_', ' ', $user['role_key'])) ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($user['branches'] ?: 'None') ?></td>
                        <td><?= intval($user['order_count']) ?></td>
                        <td><?= $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : 'Never' ?></td>
                        <td>
                            <span class="badge badge-<?= $user['disabled_at'] ? 'inactive' : 'active' ?>">
                                <?= $user['disabled_at'] ? 'Disabled' : 'Active' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <div id="branches" class="tab-content">
        <div class="card">
            <?php if (empty($branches)): ?>
            <div class="empty-state">No branches found for this tenant</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Branch Name</th>
                        <th>Type</th>
                        <th>Address</th>
                        <th>Phone</th>
                        <th>Users</th>
                        <th>Orders</th>
                        <th>Status</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($branches as $branch): ?>
                    <tr>
                        <td><?= htmlspecialchars($branch['name']) ?></td>
                        <td><?= htmlspecialchars(str_replace('_', ' ', $branch['branch_type'])) ?></td>
                        <td><?= htmlspecialchars($branch['address'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($branch['phone'] ?: '-') ?></td>
                        <td><?= intval($branch['user_count']) ?></td>
                        <td><?= intval($branch['order_count']) ?></td>
                        <td>
                            <span class="badge badge-<?= $branch['is_active'] ? 'active' : 'inactive' ?>">
                                <?= $branch['is_active'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td><?= date('M d, Y', strtotime($branch['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
    
    <div id="orders" class="tab-content">
        <div class="card">
            <?php if (empty($recent_orders)): ?>
            <div class="empty-state">No orders found for this tenant</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Branch</th>
                        <th>User</th>
                        <th>Customer</th>
                        <th>Type</th>
                        <th>Total</th>
                        <th>Payment Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_orders as $order): ?>
                    <tr>
                        <td>#<?= intval($order['id']) ?></td>
                        <td><?= htmlspecialchars($order['branch_name'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($order['user_name'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($order['customer_name'] ?: 'Walk-in') ?></td>
                        <td><?= htmlspecialchars(str_replace('_', ' ', $order['order_type'])) ?></td>
                        <td><?= htmlspecialchars($currency) ?> <?= number_format(floatval($order['total_amount']), 2) ?></td>
                        <td>
                            <span class="badge badge-<?= $order['payment_status'] === 'paid' ? 'active' : 'inactive' ?>">
                                <?= htmlspecialchars($order['payment_status']) ?>
                            </span>
                        </td>
                        <td><?= date('M d, Y H:i', strtotime($order['created_at'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function showTab(tabName) {
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.querySelectorAll('.tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    document.getElementById(tabName).classList.add('active');
    event.target.classList.add('active');
}
</script>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
?>