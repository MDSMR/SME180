<?php
/**
 * SME 180 - View Subscription Plan
 * Path: /views/superadmin/plans/view.php
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

$pdo = db();

// Get plan ID
$plan_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$plan_id) {
    header('Location: /views/superadmin/plans/index.php');
    exit;
}

// Get plan details from database
$plan = null;
$tenants = [];

try {
    // Check which columns exist
    $columns = $pdo->query("SHOW COLUMNS FROM subscription_plans")->fetchAll(PDO::FETCH_COLUMN);
    $has_features_json = in_array('features_json', $columns);
    $has_features = in_array('features', $columns);
    $features_column = $has_features_json ? 'features_json' : ($has_features ? 'features' : 'NULL as features_json');
    
    // Check if tenants table exists
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $has_tenants = in_array('tenants', $tables);
    
    if ($has_tenants) {
        $stmt = $pdo->prepare("
            SELECT 
                sp.*,
                $features_column,
                (SELECT COUNT(*) FROM tenants WHERE subscription_plan = sp.plan_key) as tenant_count,
                (SELECT COUNT(*) FROM tenants WHERE subscription_plan = sp.plan_key AND subscription_status = 'active') as active_tenants,
                (SELECT COUNT(*) FROM tenants WHERE subscription_plan = sp.plan_key AND subscription_status = 'trial') as trial_tenants
            FROM subscription_plans sp
            WHERE sp.id = ?
        ");
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                sp.*,
                $features_column,
                0 as tenant_count,
                0 as active_tenants,
                0 as trial_tenants
            FROM subscription_plans sp
            WHERE sp.id = ?
        ");
    }
    
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$plan) {
        header('Location: /views/superadmin/plans/index.php');
        exit;
    }
    
    // Convert to proper types
    $plan['monthly_price'] = (float)($plan['monthly_price'] ?? 0);
    $plan['yearly_price'] = (float)($plan['yearly_price'] ?? 0);
    $plan['max_users'] = (int)($plan['max_users'] ?? 5);
    $plan['max_branches'] = (int)($plan['max_branches'] ?? 1);
    $plan['max_products'] = (int)($plan['max_products'] ?? 100);
    $plan['is_active'] = (int)($plan['is_active'] ?? 1);
    
    // Get tenants using this plan (if tenants table exists)
    if ($has_tenants) {
        $stmt = $pdo->prepare("
            SELECT id, name, subscription_status, created_at, billing_email
            FROM tenants
            WHERE subscription_plan = ?
            ORDER BY created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$plan['plan_key']]);
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
} catch (Exception $e) {
    header('Location: /views/superadmin/plans/index.php');
    exit;
}

// Parse features from database
$features = [];
$features_data = $plan['features_json'] ?? $plan['features'] ?? null;
if (!empty($features_data)) {
    $features = is_string($features_data) ? (json_decode($features_data, true) ?: []) : [];
}

// Available features list for display
$available_features = [
    'pos' => ['name' => 'POS System', 'icon' => '💳'],
    'loyalty' => ['name' => 'Loyalty Programs', 'icon' => '🎁'],
    'stockflow' => ['name' => 'Inventory Management', 'icon' => '📦'],
    'table_management' => ['name' => 'Table Management', 'icon' => '🍽️'],
    'api_access' => ['name' => 'API Access', 'icon' => '🔌'],
    'reports_basic' => ['name' => 'Basic Reports', 'icon' => '📊'],
    'reports_advanced' => ['name' => 'Advanced Reports', 'icon' => '📈'],
    'multi_branch' => ['name' => 'Multi-Branch Support', 'icon' => '🏢'],
    'online_ordering' => ['name' => 'Online Ordering', 'icon' => '🛒'],
    'kitchen_display' => ['name' => 'Kitchen Display System', 'icon' => '👨‍🍳'],
    'customer_app' => ['name' => 'Customer Mobile App', 'icon' => '📱'],
    'white_label' => ['name' => 'White Label Options', 'icon' => '🏷️'],
    'custom_reports' => ['name' => 'Custom Reports', 'icon' => '📋'],
    'data_export' => ['name' => 'Data Export', 'icon' => '💾'],
    'integration' => ['name' => '3rd Party Integrations', 'icon' => '🔗']
];

// Get currency from database
try {
    $stmt = $pdo->query("SELECT default_currency_symbol FROM tenants WHERE id = 1 LIMIT 1");
    $currencyResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $currency = $currencyResult['default_currency_symbol'] ?? '$';
} catch (Exception $e) {
    $currency = '$';
}
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
        margin-bottom: 24px;
    }
    
    .page-title {
        font-size: 26px;
        font-weight: 700;
        color: #111827;
    }
    
    .header-actions {
        display: flex;
        gap: 12px;
    }
    
    .btn {
        padding: 10px 20px;
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
    
    .btn-secondary {
        background: white;
        color: #6B7280;
        border: 1px solid #E5E7EB;
    }
    
    .btn-secondary:hover {
        background: #F9FAFB;
    }
    
    .info-grid {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
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
        font-size: 13px;
        font-weight: 600;
        color: #6B7280;
    }
    
    .info-value {
        font-size: 14px;
        color: #111827;
        font-weight: 500;
    }
    
    .status-badge {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 600;
    }
    
    .status-active {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .status-inactive {
        background: #F3F4F6;
        color: #6B7280;
    }
    
    .status-trial {
        background: #DBEAFE;
        color: #1E3A8A;
    }
    
    .features-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 12px;
    }
    
    .feature-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px;
        border-radius: 6px;
        font-size: 13px;
    }
    
    .feature-item.enabled {
        background: #D1FAE5;
        color: #065F46;
    }
    
    .feature-item.disabled {
        background: #F3F4F6;
        color: #9CA3AF;
    }
    
    .stats-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
        margin-bottom: 20px;
    }
    
    .stat-card {
        background: #F9FAFB;
        padding: 12px;
        border-radius: 8px;
        text-align: center;
    }
    
    .stat-value {
        font-size: 24px;
        font-weight: 700;
        color: #111827;
    }
    
    .stat-label {
        font-size: 12px;
        color: #6B7280;
        margin-top: 4px;
    }
    
    .price-display {
        font-size: 18px;
        font-weight: 700;
        color: #7c3aed;
    }
    
    .discount-badge {
        display: inline-block;
        padding: 2px 6px;
        background: #D1FAE5;
        color: #065F46;
        border-radius: 4px;
        font-size: 11px;
        margin-left: 8px;
    }
    
    table {
        width: 100%;
        border-collapse: collapse;
    }
    
    th {
        background: #F9FAFB;
        padding: 10px;
        text-align: left;
        font-size: 12px;
        font-weight: 600;
        color: #6B7280;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid #E5E7EB;
    }
    
    td {
        padding: 12px 10px;
        border-bottom: 1px solid #F3F4F6;
        font-size: 13px;
    }
    
    @media (max-width: 768px) {
        .view-container {
            padding: 16px;
        }
        
        .info-grid {
            grid-template-columns: 1fr;
        }
        
        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 16px;
        }
    }
</style>

<div class="view-container">
    <div class="page-header">
        <h1 class="page-title">View Plan: <?= htmlspecialchars($plan['name']) ?></h1>
        <div class="header-actions">
            <a href="/views/superadmin/plans/index.php" class="btn btn-secondary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Plans
            </a>
            <a href="/views/superadmin/plans/edit.php?id=<?= $plan['id'] ?>" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                </svg>
                Edit Plan
            </a>
        </div>
    </div>
    
    <div class="info-grid">
        <!-- Plan Details -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Plan Details</h3>
            </div>
            <div class="card-body">
                <div class="info-row">
                    <span class="info-label">Plan Name</span>
                    <span class="info-value"><?= htmlspecialchars($plan['name']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Description</span>
                    <span class="info-value"><?= htmlspecialchars($plan['description'] ?? 'No description') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Plan Key</span>
                    <span class="info-value" style="font-family: monospace;"><?= htmlspecialchars($plan['plan_key']) ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Status</span>
                    <span class="info-value">
                        <span class="status-badge status-<?= $plan['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $plan['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Monthly Price</span>
                    <span class="info-value price-display">
                        <?= htmlspecialchars($currency) ?><?= number_format($plan['monthly_price'], 2) ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Yearly Price</span>
                    <span class="info-value price-display">
                        <?= htmlspecialchars($currency) ?><?= number_format($plan['yearly_price'], 2) ?>
                        <?php if ($plan['yearly_price'] > 0 && $plan['monthly_price'] > 0): ?>
                            <?php 
                            $yearly_discount = 100 - (($plan['yearly_price'] / ($plan['monthly_price'] * 12)) * 100);
                            if ($yearly_discount > 0):
                            ?>
                            <span class="discount-badge">
                                <?= number_format($yearly_discount, 0) ?>% discount
                            </span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Side Stats -->
        <div>
            <!-- Usage Statistics -->
            <div class="card" style="margin-bottom: 20px;">
                <div class="card-header">
                    <h3 class="card-title">Usage Statistics</h3>
                </div>
                <div class="card-body">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-value"><?= intval($plan['tenant_count']) ?></div>
                            <div class="stat-label">Total Tenants</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= intval($plan['active_tenants']) ?></div>
                            <div class="stat-label">Active</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-value"><?= intval($plan['trial_tenants']) ?></div>
                            <div class="stat-label">Trial</div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Plan Limits -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Plan Limits</h3>
                </div>
                <div class="card-body">
                    <div class="info-row">
                        <span class="info-label">Max Users</span>
                        <span class="info-value">
                            <?= $plan['max_users'] >= 999 ? 'Unlimited' : $plan['max_users'] ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Max Branches</span>
                        <span class="info-value">
                            <?= $plan['max_branches'] >= 999 ? 'Unlimited' : $plan['max_branches'] ?>
                        </span>
                    </div>
                    <div class="info-row">
                        <span class="info-label">Max Products</span>
                        <span class="info-value">
                            <?= $plan['max_products'] >= 9999 ? 'Unlimited' : number_format($plan['max_products']) ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Features -->
    <div class="card" style="margin-bottom: 20px;">
        <div class="card-header">
            <h3 class="card-title">Plan Features</h3>
        </div>
        <div class="card-body">
            <div class="features-grid">
                <?php foreach ($available_features as $key => $feature): ?>
                <?php $enabled = isset($features[$key]) && $features[$key]; ?>
                <div class="feature-item <?= $enabled ? 'enabled' : 'disabled' ?>">
                    <span><?= $enabled ? '✅' : '❌' ?></span>
                    <span><?= $feature['icon'] ?> <?= htmlspecialchars($feature['name']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- Recent Tenants -->
    <?php if (!empty($tenants)): ?>
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Recent Tenants Using This Plan</h3>
        </div>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tenant Name</th>
                    <th>Email</th>
                    <th>Status</th>
                    <th>Joined</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $tenant): ?>
                <tr>
                    <td>#<?= htmlspecialchars((string)$tenant['id']) ?></td>
                    <td><?= htmlspecialchars($tenant['name']) ?></td>
                    <td><?= htmlspecialchars($tenant['billing_email'] ?? 'N/A') ?></td>
                    <td>
                        <span class="status-badge status-<?= htmlspecialchars($tenant['subscription_status']) ?>">
                            <?= ucfirst(htmlspecialchars($tenant['subscription_status'])) ?>
                        </span>
                    </td>
                    <td><?= date('M d, Y', strtotime($tenant['created_at'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php
// Include the footer (closes the layout)
require_once dirname(__DIR__) . '/includes/footer.php';
?>