<?php
/**
 * SME 180 - Super Admin Tenant Management
 * Path: /views/superadmin/tenants/index.php
 * 
 * Lists all tenants with real data from database
 */
declare(strict_types=1);

// Include configuration
require_once dirname(__DIR__, 3) . '/config/db.php';

// Start session and verify super admin access
use_backend_session();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'super_admin') {
    redirect('/views/auth/login.php');
    exit;
}

// Include the sidebar (which opens the layout)
require_once dirname(__DIR__) . '/includes/sidebar.php';

// Handle actions (suspend, activate, delete)
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $tenant_id = (int)($_POST['tenant_id'] ?? 0);
    
    if ($tenant_id > 0) {
        try {
            $pdo = db();
            
            switch ($action) {
                case 'suspend':
                    $stmt = $pdo->prepare("UPDATE tenants SET subscription_status = 'suspended', is_active = 0 WHERE id = ?");
                    $stmt->execute([$tenant_id]);
                    $message = 'Tenant suspended successfully.';
                    $message_type = 'success';
                    break;
                    
                case 'activate':
                    $stmt = $pdo->prepare("UPDATE tenants SET subscription_status = 'active', is_active = 1 WHERE id = ?");
                    $stmt->execute([$tenant_id]);
                    $message = 'Tenant activated successfully.';
                    $message_type = 'success';
                    break;
                    
                case 'delete':
                    // Soft delete - just mark as inactive
                    $stmt = $pdo->prepare("UPDATE tenants SET is_active = 0, subscription_status = 'cancelled' WHERE id = ?");
                    $stmt->execute([$tenant_id]);
                    $message = 'Tenant deleted successfully.';
                    $message_type = 'success';
                    break;
            }
            
            // Log action in super_admin_logs
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO super_admin_logs (admin_id, action, tenant_id, details, ip_address, user_agent, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, NOW())
                ");
                $stmt->execute([
                    $_SESSION['super_admin_id'],
                    $action . '_tenant',
                    $tenant_id,
                    json_encode(['tenant_id' => $tenant_id, 'action' => $action]),
                    $_SERVER['REMOTE_ADDR'] ?? null,
                    $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (Exception $e) {
                // Logging table might not exist, continue
            }
            
        } catch (Exception $e) {
            $message = 'Error: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// Fetch all tenants with statistics
try {
    $pdo = db();
    
    // Get tenants with additional statistics - FIXED: Changed final_total to total_amount
    $sql = "
        SELECT 
            t.*,
            COALESCE((SELECT COUNT(*) FROM users WHERE tenant_id = t.id AND disabled_at IS NULL), 0) as user_count,
            COALESCE((SELECT COUNT(*) FROM branches WHERE tenant_id = t.id AND is_active = 1), 0) as branch_count,
            COALESCE((SELECT COUNT(*) FROM products WHERE tenant_id = t.id AND is_active = 1), 0) as product_count,
            COALESCE((SELECT SUM(total_amount) FROM orders WHERE tenant_id = t.id AND payment_status IN ('paid', 'partial')), 0) as total_revenue
        FROM tenants t
        ORDER BY t.created_at DESC
    ";
    
    $stmt = $pdo->query($sql);
    $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tenant_count = count($tenants);
    
    // Get plan distribution for statistics
    $stmt = $pdo->query("
        SELECT 
            subscription_plan,
            COUNT(*) as count 
        FROM tenants 
        WHERE is_active = 1 
        GROUP BY subscription_plan
    ");
    $plan_stats_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $plan_stats = [];
    foreach ($plan_stats_raw as $row) {
        $plan_stats[$row['subscription_plan'] ?? 'none'] = $row['count'];
    }
    
    // Get status distribution
    $stmt = $pdo->query("
        SELECT 
            subscription_status,
            COUNT(*) as count 
        FROM tenants 
        GROUP BY subscription_status
    ");
    $status_stats_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $status_stats = [];
    foreach ($status_stats_raw as $row) {
        $status_stats[$row['subscription_status'] ?? 'unknown'] = $row['count'];
    }
    
    // Calculate total revenue
    $stmt = $pdo->query("
        SELECT COALESCE(SUM(total_amount), 0) as total_revenue
        FROM orders 
        WHERE payment_status IN ('paid', 'partial')
    ");
    $revenue_data = $stmt->fetch(PDO::FETCH_ASSOC);
    $total_revenue = floatval($revenue_data['total_revenue'] ?? 0);
    
    // Get currency from settings table
    $stmt = $pdo->query("
        SELECT value 
        FROM settings 
        WHERE `key` = 'currency' AND tenant_id = 1
        LIMIT 1
    ");
    $currencyResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $currency = $currencyResult['value'] ?? 'EGP';
    
} catch (Exception $e) {
    $tenants = [];
    $tenant_count = 0;
    $plan_stats = [];
    $status_stats = [];
    $total_revenue = 0;
    $currency = 'EGP';
    error_log('Tenants page error: ' . $e->getMessage());
}

// Calculate metrics for cards
$active_count = intval($status_stats['active'] ?? 0);
$trial_count = intval($status_stats['trial'] ?? 0);
$suspended_count = intval($status_stats['suspended'] ?? 0);
?>

<style>
    .tenants-container {
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
    .metric-card.trial::before { background: linear-gradient(90deg, #3b82f6, #60a5fa); }
    .metric-card.suspended::before { background: linear-gradient(90deg, #f59e0b, #fbbf24); }
    
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
        text-transform: capitalize;
    }
    
    .badge-active { background: #D1FAE5; color: #065F46; }
    .badge-trial { background: #DBEAFE; color: #1E3A8A; }
    .badge-suspended { background: #FEE2E2; color: #991B1B; }
    .badge-cancelled { background: #F3F4F6; color: #6B7280; }
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
    
    /* Responsive */
    @media (max-width: 1200px) {
        .metrics-grid {
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
        }
        
        .metric-card {
            padding: 12px;
        }
        
        .metric-value {
            font-size: 18px;
        }
    }
    
    @media (max-width: 992px) {
        .metrics-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 768px) {
        .tenants-container {
            padding: 16px;
        }
        
        .page-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 16px;
        }
    }
    
    @media (max-width: 576px) {
        .metrics-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="tenants-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">Tenant Management</h1>
            <p class="page-subtitle">Manage all tenants and their subscriptions</p>
        </div>
        <a href="/views/superadmin/tenants/create.php" class="btn btn-primary">
            <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            Create Tenant
        </a>
    </div>
    
    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    
    <!-- Metrics Cards - Same style as dashboard -->
    <div class="metrics-grid">
        <!-- Total Tenants Card -->
        <div class="metric-card total">
            <div class="metric-header">
                <div class="metric-icon purple">🏢</div>
                <span class="metric-badge info">TOTAL</span>
            </div>
            <div class="metric-value"><?= number_format($tenant_count) ?></div>
            <div class="metric-label">Total Tenants</div>
            <div class="metric-sublabel">
                All registered tenants in system
            </div>
        </div>
        
        <!-- Active Tenants Card -->
        <div class="metric-card active">
            <div class="metric-header">
                <div class="metric-icon green">✅</div>
                <span class="metric-badge success">ACTIVE</span>
            </div>
            <div class="metric-value"><?= number_format($active_count) ?></div>
            <div class="metric-label">Active Tenants</div>
            <div class="metric-sublabel">
                Currently operating tenants
            </div>
        </div>
        
        <!-- Trial Tenants Card -->
        <div class="metric-card trial">
            <div class="metric-header">
                <div class="metric-icon blue">⏰</div>
                <span class="metric-badge info">TRIAL</span>
            </div>
            <div class="metric-value"><?= number_format($trial_count) ?></div>
            <div class="metric-label">Trial Tenants</div>
            <div class="metric-sublabel">
                Evaluating the platform
            </div>
        </div>
        
        <!-- Suspended Card -->
        <div class="metric-card suspended">
            <div class="metric-header">
                <div class="metric-icon orange">⚠️</div>
                <span class="metric-badge warning">SUSPENDED</span>
            </div>
            <div class="metric-value"><?= number_format($suspended_count) ?></div>
            <div class="metric-label">Suspended</div>
            <div class="metric-sublabel">
                Temporarily inactive
            </div>
        </div>
    </div>
    
    <div class="table-card">
        <div class="table-header">
            <h3 class="table-title">All Tenants</h3>
            <input type="text" class="filter-input" placeholder="Search tenants..." id="searchInput" onkeyup="filterTable()">
        </div>
        
        <?php if (empty($tenants)): ?>
        <div class="empty-state">
            <div class="empty-icon">🏢</div>
            <div class="empty-title">No Tenants Yet</div>
            <div class="empty-text">Create your first tenant to get started with SME 180.</div>
            <a href="/views/superadmin/tenants/create.php" class="btn btn-primary">Create First Tenant</a>
        </div>
        <?php else: ?>
        <table id="tenantsTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tenant Name</th>
                    <th>Plan</th>
                    <th>Status</th>
                    <th>Users</th>
                    <th>Branches</th>
                    <th>Revenue</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tenants as $tenant): ?>
                <tr>
                    <td>#<?= htmlspecialchars((string)$tenant['id']) ?></td>
                    <td>
                        <div style="font-weight: 600;"><?= htmlspecialchars($tenant['name']) ?></div>
                        <?php if (!empty($tenant['billing_email'])): ?>
                        <div style="font-size: 11px; color: #6B7280; margin-top: 2px;">
                            <?= htmlspecialchars($tenant['billing_email']) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($tenant['subscription_plan'] ?? 'starter') ?>">
                            <?= htmlspecialchars($tenant['subscription_plan'] ?? 'Starter') ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-<?= htmlspecialchars($tenant['subscription_status'] ?? 'active') ?>">
                            <?= htmlspecialchars($tenant['subscription_status'] ?? 'Active') ?>
                        </span>
                    </td>
                    <td>
                        <?= number_format((int)$tenant['user_count']) ?>/<?= number_format((int)$tenant['max_users']) ?>
                    </td>
                    <td>
                        <?= number_format((int)$tenant['branch_count']) ?>/<?= number_format((int)$tenant['max_branches']) ?>
                    </td>
                    <td>
                        <?= number_format((float)$tenant['total_revenue'], 2) ?> <?= htmlspecialchars($currency) ?>
                    </td>
                    <td>
                        <?= date('M d, Y', strtotime($tenant['created_at'])) ?>
                    </td>
                    <td>
                        <div class="actions">
                            <a href="/views/superadmin/tenants/view.php?id=<?= $tenant['id'] ?>" class="btn btn-sm btn-ghost" title="View">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                            </a>
                            <a href="/views/superadmin/tenants/edit.php?id=<?= $tenant['id'] ?>" class="btn btn-sm btn-ghost" title="Edit">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                </svg>
                            </a>
                            
                            <?php if ($tenant['subscription_status'] === 'active'): ?>
                            <form method="POST" class="action-form" onsubmit="return confirm('Suspend this tenant?');">
                                <input type="hidden" name="action" value="suspend">
                                <input type="hidden" name="tenant_id" value="<?= $tenant['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-ghost" title="Suspend">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                    </svg>
                                </button>
                            </form>
                            <?php elseif ($tenant['subscription_status'] === 'suspended'): ?>
                            <form method="POST" class="action-form" onsubmit="return confirm('Activate this tenant?');">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="tenant_id" value="<?= $tenant['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-ghost" title="Activate">
                                    <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
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
    const table = document.getElementById('tenantsTable');
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