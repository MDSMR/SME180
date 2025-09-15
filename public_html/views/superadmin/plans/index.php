<?php
/**
 * SME 180 - Super Admin Plans Management
 * Path: /views/superadmin/plans/index.php
 * 
 * Lists all subscription plans with real data from database
 */
declare(strict_types=1);

// Include configuration
require_once dirname(__DIR__, 3) . '/config/db.php';

// Start session and verify super admin access
use_backend_session();

// For development - remove in production
if (!isset($_SESSION['user_type'])) {
    $_SESSION['user_type'] = 'super_admin';
    $_SESSION['super_admin_id'] = 1;
    $_SESSION['super_admin_name'] = 'Admin';
}

if ($_SESSION['user_type'] !== 'super_admin') {
    redirect('/views/auth/login.php');
    exit;
}

// Include the sidebar (which opens the layout)
require_once dirname(__DIR__) . '/includes/sidebar.php';

// Handle actions (delete, toggle status)
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $plan_id = (int)($_POST['plan_id'] ?? 0);
    
    if ($plan_id > 0) {
        try {
            $pdo = db();
            
            switch ($action) {
                case 'toggle_status':
                    $stmt = $pdo->prepare("UPDATE subscription_plans SET is_active = NOT is_active WHERE id = ?");
                    $stmt->execute([$plan_id]);
                    $message = 'Plan status updated successfully.';
                    $message_type = 'success';
                    break;
                    
                case 'delete':
                    // Check if plan is in use
                    $stmt = $pdo->prepare("
                        SELECT COUNT(*) FROM tenants 
                        WHERE subscription_plan = (SELECT plan_key FROM subscription_plans WHERE id = ?)
                    ");
                    $stmt->execute([$plan_id]);
                    $in_use = $stmt->fetchColumn();
                    
                    if ($in_use > 0) {
                        $message = "Cannot delete plan - it is currently in use by $in_use tenant(s)";
                        $message_type = 'error';
                    } else {
                        $stmt = $pdo->prepare("DELETE FROM subscription_plans WHERE id = ?");
                        $stmt->execute([$plan_id]);
                        $message = 'Plan deleted successfully.';
                        $message_type = 'success';
                    }
                    break;
            }
            
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Fetch all plans with statistics
try {
    $pdo = db();
    
    // Check which columns exist
    $columns = $pdo->query("SHOW COLUMNS FROM subscription_plans")->fetchAll(PDO::FETCH_COLUMN);
    $has_features_json = in_array('features_json', $columns);
    $has_features = in_array('features', $columns);
    $features_column = $has_features_json ? 'sp.features_json' : ($has_features ? 'sp.features' : 'NULL as features_json');
    
    // Check if tenants table exists
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $has_tenants = in_array('tenants', $tables);
    
    if ($has_tenants) {
        $sql = "
            SELECT 
                sp.*,
                $features_column,
                (SELECT COUNT(*) FROM tenants WHERE subscription_plan = sp.plan_key) as tenant_count,
                (SELECT COUNT(*) FROM tenants WHERE subscription_plan = sp.plan_key AND subscription_status = 'active') as active_tenants,
                (SELECT COUNT(*) FROM tenants WHERE subscription_plan = sp.plan_key AND subscription_status = 'trial') as trial_tenants
            FROM subscription_plans sp
            ORDER BY sp.monthly_price ASC
        ";
    } else {
        $sql = "
            SELECT 
                sp.*,
                $features_column,
                0 as tenant_count,
                0 as active_tenants,
                0 as trial_tenants
            FROM subscription_plans sp
            ORDER BY sp.monthly_price ASC
        ";
    }
    
    $stmt = $pdo->query($sql);
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $plan_count = count($plans);
    
    // Parse features for each plan
    foreach ($plans as &$plan) {
        $plan['monthly_price'] = (float)($plan['monthly_price'] ?? 0);
        $plan['yearly_price'] = (float)($plan['yearly_price'] ?? 0);
        $plan['max_users'] = (int)($plan['max_users'] ?? 5);
        $plan['max_branches'] = (int)($plan['max_branches'] ?? 1);
        $plan['max_products'] = (int)($plan['max_products'] ?? 100);
        $plan['is_active'] = (int)($plan['is_active'] ?? 1);
        
        $features_data = $plan['features_json'] ?? $plan['features'] ?? null;
        if (!empty($features_data)) {
            $plan['features'] = is_string($features_data) ? (json_decode($features_data, true) ?: []) : [];
        } else {
            $plan['features'] = [];
        }
    }
    
    // Get statistics
    if ($has_tenants) {
        $stmt = $pdo->query("
            SELECT 
                COUNT(DISTINCT subscription_plan) as plans_in_use,
                COUNT(*) as total_tenants,
                SUM(CASE WHEN subscription_status = 'active' THEN 1 ELSE 0 END) as paying_tenants
            FROM tenants
        ");
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stats = ['plans_in_use' => 0, 'total_tenants' => 0, 'paying_tenants' => 0];
    }
    
    // Get currency
    try {
        $stmt = $pdo->query("SELECT default_currency_symbol FROM tenants WHERE id = 1 LIMIT 1");
        $currencyResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $currency = $currencyResult['default_currency_symbol'] ?? '$';
    } catch (Exception $e) {
        $currency = '$';
    }
    
} catch (Exception $e) {
    $plans = [];
    $plan_count = 0;
    $stats = ['plans_in_use' => 0, 'total_tenants' => 0, 'paying_tenants' => 0];
    $currency = '$';
    error_log('Plans page error: ' . $e->getMessage());
}

// Calculate metrics
$active_plans = array_filter($plans, fn($p) => ($p['is_active'] ?? 1) == 1);
$active_count = count($active_plans);
$total_tenants = intval($stats['total_tenants'] ?? 0);
$paying_tenants = intval($stats['paying_tenants'] ?? 0);
?>

<style>
    .plans-container {
        padding: 24px;
        max-width: 1600px;
        margin: 0 auto;
    }
    
    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
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
    
    /* Metrics Grid - Same style as dashboard */
    .metrics-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 12px;
        margin-bottom: 28px;
    }
    
    .metric-card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 10px;
        padding: 14px;
        position: relative;
        overflow: hidden;
        transition: all 0.2s;
        min-width: 0;
    }
    
    .metric-card::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 2px;
    }
    
    .metric-card.total::before { background: linear-gradient(90deg, #667eea, #818cf8); }
    .metric-card.active::before { background: linear-gradient(90deg, #10b981, #34d399); }
    .metric-card.tenants::before { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
    .metric-card.revenue::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
    
    .metric-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
    }
    
    .metric-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 10px;
        gap: 8px;
    }
    
    .metric-icon {
        width: 28px;
        height: 28px;
        border-radius: 6px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        flex-shrink: 0;
    }
    
    .metric-icon.purple { background: rgba(102, 126, 234, 0.1); }
    .metric-icon.green { background: rgba(16, 185, 129, 0.1); }
    .metric-icon.blue { background: rgba(59, 130, 246, 0.1); }
    .metric-icon.orange { background: rgba(245, 158, 11, 0.1); }
    
    .metric-badge {
        font-size: 9px;
        font-weight: 600;
        padding: 2px 5px;
        border-radius: 10px;
        line-height: 1;
        white-space: nowrap;
    }
    
    .metric-badge.success { background: #D1FAE5; color: #065F46; }
    .metric-badge.info { background: #DBEAFE; color: #1E3A8A; }
    .metric-badge.warning { background: #FEF3C7; color: #92400E; }
    
    .metric-value {
        font-size: 20px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 4px;
        line-height: 1;
    }
    
    .metric-label {
        font-size: 11px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 3px;
    }
    
    .metric-sublabel {
        font-size: 10px;
        color: #6B7280;
        line-height: 1.3;
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 14px;
    }
    
    .alert-success {
        background: #D1FAE5;
        color: #065F46;
        border: 1px solid #A7F3D0;
    }
    
    .alert-error {
        background: #FEE2E2;
        color: #991B1B;
        border: 1px solid #FCA5A5;
    }
    
    .table-card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        overflow: hidden;
    }
    
    .table-header {
        padding: 16px 20px;
        border-bottom: 1px solid #E5E7EB;
        background: linear-gradient(to bottom, #FAFBFC, #F9FAFB);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .table-title {
        font-size: 15px;
        font-weight: 600;
        color: #111827;
    }
    
    .filter-input {
        padding: 6px 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
        min-width: 200px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th {
        background: #F9FAFB;
        padding: 12px 16px;
        text-align: left;
        font-size: 12px;
        font-weight: 600;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid #E5E7EB;
    }
    
    td {
        padding: 14px 16px;
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
    .badge-inactive { background: #F3F4F6; color: #6B7280; }
    
    .btn {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }
    
    .btn-primary {
        background: #7c3aed;
        color: white;
    }
    
    .btn-primary:hover {
        background: #6d28d9;
        transform: translateY(-1px);
    }
    
    .btn-sm {
        padding: 6px 10px;
        font-size: 12px;
    }
    
    .btn-ghost {
        background: transparent;
        color: #6B7280;
        border: 1px solid #E5E7EB;
    }
    
    .btn-ghost:hover {
        background: #F9FAFB;
        color: #111827;
    }
    
    .actions {
        display: flex;
        gap: 6px;
    }
    
    .action-form {
        display: inline-block;
    }
    
    .empty-state {
        text-align: center;
        padding: 60px 20px;
    }
    
    .empty-icon {
        font-size: 48px;
        margin-bottom: 16px;
    }
    
    .empty-title {
        font-size: 18px;
        font-weight: 600;
        color: #111827;
        margin-bottom: 8px;
    }
    
    .empty-text {
        font-size: 14px;
        color: #6B7280;
        margin-bottom: 24px;
    }
</style>

<div class="plans-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">Subscription Plans</h1>
            <p class="page-subtitle">Manage subscription plans and pricing</p>
        </div>
        <a href="/views/superadmin/plans/create.php" class="btn btn-primary">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Create Plan
        </a>
    </div>
    
    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    
    <!-- Metrics Cards -->
    <div class="metrics-grid">
        <div class="metric-card total">
            <div class="metric-header">
                <div class="metric-icon purple">📋</div>
                <span class="metric-badge info">TOTAL</span>
            </div>
            <div class="metric-value"><?= number_format($plan_count) ?></div>
            <div class="metric-label">Total Plans</div>
            <div class="metric-sublabel">All subscription plans</div>
        </div>
        
        <div class="metric-card active">
            <div class="metric-header">
                <div class="metric-icon green">✅</div>
                <span class="metric-badge success">ACTIVE</span>
            </div>
            <div class="metric-value"><?= number_format($active_count) ?></div>
            <div class="metric-label">Active Plans</div>
            <div class="metric-sublabel">Available for subscription</div>
        </div>
        
        <div class="metric-card tenants">
            <div class="metric-header">
                <div class="metric-icon blue">🏢</div>
                <span class="metric-badge info">TENANTS</span>
            </div>
            <div class="metric-value"><?= number_format($total_tenants) ?></div>
            <div class="metric-label">Total Subscribers</div>
            <div class="metric-sublabel">Tenants using plans</div>
        </div>
        
        <div class="metric-card revenue">
            <div class="metric-header">
                <div class="metric-icon orange">💰</div>
                <span class="metric-badge warning">PAYING</span>
            </div>
            <div class="metric-value"><?= number_format($paying_tenants) ?></div>
            <div class="metric-label">Paying Tenants</div>
            <div class="metric-sublabel">Active subscriptions</div>
        </div>
    </div>
    
    <div class="table-card">
        <div class="table-header">
            <h3 class="table-title">All Plans</h3>
            <input type="text" class="filter-input" placeholder="Search plans..." id="searchInput" onkeyup="filterTable()">
        </div>
        
        <?php if (empty($plans)): ?>
        <div class="empty-state">
            <div class="empty-icon">📋</div>
            <div class="empty-title">No Plans Yet</div>
            <div class="empty-text">Create your first subscription plan to start onboarding tenants.</div>
            <a href="/views/superadmin/plans/create.php" class="btn btn-primary">Create First Plan</a>
        </div>
        <?php else: ?>
        <table id="plansTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Plan Name</th>
                    <th>Monthly Price</th>
                    <th>Yearly Price</th>
                    <th>Limits</th>
                    <th>Tenants</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($plans as $plan): ?>
                <tr>
                    <td>#<?= htmlspecialchars((string)$plan['id']) ?></td>
                    <td>
                        <div style="font-weight: 600;"><?= htmlspecialchars($plan['name']) ?></div>
                        <div style="font-size: 11px; color: #6B7280; margin-top: 2px;">
                            Key: <?= htmlspecialchars($plan['plan_key']) ?>
                        </div>
                    </td>
                    <td style="font-weight: 600;">
                        <?= htmlspecialchars($currency) ?><?= number_format($plan['monthly_price'], 2) ?>
                    </td>
                    <td>
                        <?php if ($plan['yearly_price'] > 0): ?>
                            <?= htmlspecialchars($currency) ?><?= number_format($plan['yearly_price'], 2) ?>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-size: 12px;">
                            Users: <?= $plan['max_users'] >= 999 ? '∞' : $plan['max_users'] ?><br>
                            Branches: <?= $plan['max_branches'] >= 999 ? '∞' : $plan['max_branches'] ?><br>
                            Products: <?= $plan['max_products'] >= 9999 ? '∞' : number_format($plan['max_products']) ?>
                        </div>
                    </td>
                    <td>
                        <div style="font-weight: 600;"><?= intval($plan['tenant_count']) ?></div>
                        <div style="font-size: 11px; color: #6B7280;">
                            Active: <?= intval($plan['active_tenants']) ?>
                        </div>
                    </td>
                    <td>
                        <span class="badge badge-<?= $plan['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $plan['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <div class="actions">
                            <a href="/views/superadmin/plans/view.php?id=<?= $plan['id'] ?>" class="btn btn-sm btn-ghost" title="View">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                            </a>
                            <a href="/views/superadmin/plans/edit.php?id=<?= $plan['id'] ?>" class="btn btn-sm btn-ghost" title="Edit">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </a>
                            
                            <?php if (intval($plan['tenant_count']) == 0): ?>
                            <form method="POST" class="action-form" onsubmit="return confirm('Delete this plan?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-ghost" title="Delete">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                </button>
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
</div>

<script>
function filterTable() {
    const input = document.getElementById('searchInput');
    const filter = input.value.toLowerCase();
    const table = document.getElementById('plansTable');
    const rows = table.getElementsByTagName('tr');
    
    for (let i = 1; i < rows.length; i++) {
        const row = rows[i];
        const cells = row.getElementsByTagName('td');
        let found = false;
        
        for (let j = 0; j < cells.length; j++) {
            const cell = cells[j];
            if (cell.textContent.toLowerCase().indexOf(filter) > -1) {
                found = true;
                break;
            }
        }
        
        row.style.display = found ? '' : 'none';
    }
}
</script>

<?php
// Include the footer (closes the layout)
require_once dirname(__DIR__) . '/includes/footer.php';
?>