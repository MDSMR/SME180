<?php
/**
 * SME 180 - Edit Tenant
 * Path: /views/superadmin/tenants/edit.php
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

// Get tenant details
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([$tenant_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    redirect('/views/superadmin/tenants/index.php');
    exit;
}

// Get currency setting for this tenant
$stmt = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = ? AND `key` = 'currency' LIMIT 1");
$stmt->execute([$tenant_id]);
$currencyResult = $stmt->fetch(PDO::FETCH_ASSOC);
$current_currency = $currencyResult['value'] ?? 'EGP';

// Get subscription plans from database
$stmt = $pdo->query("SELECT * FROM subscription_plans ORDER BY monthly_price");
$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// If no plans in database or too few, ensure we have the standard plans
if (count($plans) < 4) {
    // Check existing plans
    $existingPlans = [];
    foreach ($plans as $plan) {
        $existingPlans[$plan['plan_key']] = true;
    }
    
    // Default plans to ensure exist
    $defaultPlans = [
        ['plan_key' => 'starter', 'name' => 'Starter', 'max_users' => 5, 'max_branches' => 1, 'max_products' => 50, 'monthly_price' => 29.99, 'yearly_price' => 299.99],
        ['plan_key' => 'professional', 'name' => 'Professional', 'max_users' => 20, 'max_branches' => 3, 'max_products' => 500, 'monthly_price' => 99.99, 'yearly_price' => 999.99],
        ['plan_key' => 'enterprise', 'name' => 'Enterprise', 'max_users' => 999, 'max_branches' => 999, 'max_products' => 9999, 'monthly_price' => 299.99, 'yearly_price' => 2999.99],
        ['plan_key' => 'custom', 'name' => 'Custom', 'max_users' => 998, 'max_branches' => 998, 'max_products' => 9998, 'monthly_price' => 0.00, 'yearly_price' => 0.00]
    ];
    
    // Insert missing plans
    foreach ($defaultPlans as $plan) {
        if (!isset($existingPlans[$plan['plan_key']])) {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO subscription_plans 
                    (plan_key, name, max_users, max_branches, max_products, monthly_price, yearly_price, is_active) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, 1)
                ");
                $stmt->execute([
                    $plan['plan_key'],
                    $plan['name'],
                    $plan['max_users'],
                    $plan['max_branches'],
                    $plan['max_products'],
                    $plan['monthly_price'],
                    $plan['yearly_price']
                ]);
            } catch (Exception $e) {
                // Plan might already exist, continue
            }
        }
    }
    
    // Fetch plans again after inserting
    $stmt = $pdo->query("SELECT * FROM subscription_plans ORDER BY monthly_price");
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get tenant statistics
$stmt = $pdo->prepare("
    SELECT 
        (SELECT COUNT(*) FROM users WHERE tenant_id = ? AND disabled_at IS NULL) as user_count,
        (SELECT COUNT(*) FROM branches WHERE tenant_id = ? AND is_active = 1) as branch_count,
        (SELECT COUNT(*) FROM products WHERE tenant_id = ? AND is_active = 1) as product_count
");
$stmt->execute([$tenant_id, $tenant_id, $tenant_id]);
$stats = $stmt->fetch(PDO::FETCH_ASSOC);

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $name = trim($_POST['name'] ?? '');
        $plan_key = $_POST['plan_key'] ?? 'starter';
        $subscription_status = $_POST['subscription_status'] ?? 'active';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        $billing_email = trim($_POST['billing_email'] ?? '');
        $billing_contact = trim($_POST['billing_contact'] ?? '');
        $subscription_expires_at = $_POST['subscription_expires_at'] ?? null;
        $max_users = (int)($_POST['max_users'] ?? 5);
        $max_branches = (int)($_POST['max_branches'] ?? 1);
        $max_products = (int)($_POST['max_products'] ?? 50);
        $currency = $_POST['currency'] ?? 'EGP';
        $grace_period_days = (int)($_POST['grace_period_days'] ?? 7);
        $payment_method = $_POST['payment_method'] ?? 'manual';
        
        // Update tenant
        $stmt = $pdo->prepare("
            UPDATE tenants SET
                name = ?,
                subscription_plan = ?,
                subscription_status = ?,
                is_active = ?,
                billing_email = ?,
                billing_contact = ?,
                subscription_expires_at = ?,
                max_users = ?,
                max_branches = ?,
                max_products = ?,
                grace_period_days = ?,
                payment_method = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        
        $stmt->execute([
            $name,
            $plan_key,
            $subscription_status,
            $is_active,
            $billing_email ?: null,
            $billing_contact ?: null,
            $subscription_expires_at ?: null,
            $max_users,
            $max_branches,
            $max_products,
            $grace_period_days,
            $payment_method,
            $tenant_id
        ]);
        
        // Update currency in settings table
        $stmt = $pdo->prepare("
            INSERT INTO settings (tenant_id, `key`, value, updated_at) 
            VALUES (?, 'currency', ?, NOW())
            ON DUPLICATE KEY UPDATE value = ?, updated_at = NOW()
        ");
        $stmt->execute([$tenant_id, $currency, $currency]);
        
        // Update features based on plan
        $stmt = $pdo->prepare("SELECT features_json FROM subscription_plans WHERE plan_key = ?");
        $stmt->execute([$plan_key]);
        $planData = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($planData && $planData['features_json']) {
            $stmt = $pdo->prepare("UPDATE tenants SET features_json = ? WHERE id = ?");
            $stmt->execute([$planData['features_json'], $tenant_id]);
        }
        
        // Log action
        try {
            $stmt = $pdo->prepare("
                INSERT INTO super_admin_logs (admin_id, action, tenant_id, details, ip_address, user_agent, created_at)
                VALUES (?, 'edit_tenant', ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['super_admin_id'],
                $tenant_id,
                json_encode(['changes' => $_POST]),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            // Logging failed, continue
        }
        
        $message = 'Tenant updated successfully!';
        $message_type = 'success';
        
        // Refresh tenant data
        $stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
        $stmt->execute([$tenant_id]);
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Refresh currency
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = ? AND `key` = 'currency' LIMIT 1");
        $stmt->execute([$tenant_id]);
        $currencyResult = $stmt->fetch(PDO::FETCH_ASSOC);
        $current_currency = $currencyResult['value'] ?? 'EGP';
        
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'error';
    }
}
?>

<style>
    .edit-container {
        padding: 24px;
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .page-header {
        margin-bottom: 32px;
        display: flex;
        justify-content: space-between;
        align-items: center;
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
    
    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 24px;
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
    
    .stats-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        gap: 16px;
        margin-bottom: 24px;
    }
    
    .stat-box {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 8px;
        padding: 16px;
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
    
    .card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 24px;
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
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }
    
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    
    label {
        font-size: 13px;
        font-weight: 500;
        color: #374151;
    }
    
    .label-required::after {
        content: " *";
        color: #EF4444;
    }
    
    input, select, textarea {
        padding: 8px 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s;
    }
    
    input:focus, select:focus, textarea:focus {
        outline: none;
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
    }
    
    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 8px;
        padding-top: 8px;
    }
    
    .form-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        padding: 16px 20px;
        background: #F9FAFB;
        border-top: 1px solid #E5E7EB;
    }
    
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
        border: 1px solid #D1D5DB;
    }
    
    .btn-secondary:hover {
        background: #F9FAFB;
    }
    
    .info-text {
        font-size: 12px;
        color: #6B7280;
        margin-top: 4px;
    }
    
    .status-active { color: #10B981; }
    .status-inactive { color: #EF4444; }
</style>

<div class="edit-container">
    <div class="page-header">
        <div>
            <h1 class="page-title">Edit Tenant</h1>
            <p class="page-subtitle">Tenant ID: #<?= $tenant_id ?> - <?= htmlspecialchars($tenant['name']) ?></p>
        </div>
        <a href="/views/superadmin/tenants/index.php" class="btn btn-secondary">
            Back to List
        </a>
    </div>
    
    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    
    <!-- Statistics -->
    <div class="stats-row">
        <div class="stat-box">
            <div class="stat-value"><?= number_format((int)$stats['user_count']) ?>/<?= number_format((int)$tenant['max_users']) ?></div>
            <div class="stat-label">Users</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= number_format((int)$stats['branch_count']) ?>/<?= number_format((int)$tenant['max_branches']) ?></div>
            <div class="stat-label">Branches</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= number_format((int)$stats['product_count']) ?>/<?= number_format((int)$tenant['max_products']) ?></div>
            <div class="stat-label">Products</div>
        </div>
        <div class="stat-box">
            <div class="stat-value class="<?= $tenant['is_active'] ? 'status-active' : 'status-inactive' ?>">
                <?= $tenant['is_active'] ? 'Active' : 'Inactive' ?>
            </div>
            <div class="stat-label">Status</div>
        </div>
    </div>
    
    <form method="POST">
        <!-- Basic Information -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Basic Information</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="label-required">Tenant Name</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($tenant['name']) ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Billing Email</label>
                        <input type="email" name="billing_email" value="<?= htmlspecialchars($tenant['billing_email'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Billing Contact</label>
                        <input type="text" name="billing_contact" value="<?= htmlspecialchars($tenant['billing_contact'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Currency</label>
                        <select name="currency">
                            <option value="EGP" <?= $current_currency === 'EGP' ? 'selected' : '' ?>>EGP - Egyptian Pound</option>
                            <option value="USD" <?= $current_currency === 'USD' ? 'selected' : '' ?>>USD - US Dollar</option>
                            <option value="EUR" <?= $current_currency === 'EUR' ? 'selected' : '' ?>>EUR - Euro</option>
                            <option value="GBP" <?= $current_currency === 'GBP' ? 'selected' : '' ?>>GBP - British Pound</option>
                            <option value="SAR" <?= $current_currency === 'SAR' ? 'selected' : '' ?>>SAR - Saudi Riyal</option>
                            <option value="AED" <?= $current_currency === 'AED' ? 'selected' : '' ?>>AED - UAE Dirham</option>
                            <option value="KWD" <?= $current_currency === 'KWD' ? 'selected' : '' ?>>KWD - Kuwaiti Dinar</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method">
                            <option value="manual" <?= ($tenant['payment_method'] ?? 'manual') === 'manual' ? 'selected' : '' ?>>Manual</option>
                            <option value="stripe" <?= ($tenant['payment_method'] ?? '') === 'stripe' ? 'selected' : '' ?>>Stripe</option>
                            <option value="paypal" <?= ($tenant['payment_method'] ?? '') === 'paypal' ? 'selected' : '' ?>>PayPal</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Active Status</label>
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="is_active" <?= $tenant['is_active'] ? 'checked' : '' ?>>
                            <label for="is_active">Tenant is active and can access the system</label>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Subscription Settings -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Subscription Settings</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="label-required">Subscription Plan</label>
                        <select name="plan_key" id="plan_select" onchange="updatePlanLimits()">
                            <?php if (empty($plans)): ?>
                                <!-- Fallback if no plans in database -->
                                <option value="starter" <?= $tenant['subscription_plan'] === 'starter' ? 'selected' : '' ?> 
                                        data-users="5" data-branches="1" data-products="50">
                                    Starter ($29.99/mo)
                                </option>
                                <option value="professional" <?= $tenant['subscription_plan'] === 'professional' ? 'selected' : '' ?>
                                        data-users="20" data-branches="3" data-products="500">
                                    Professional ($99.99/mo)
                                </option>
                                <option value="enterprise" <?= $tenant['subscription_plan'] === 'enterprise' ? 'selected' : '' ?>
                                        data-users="999" data-branches="999" data-products="9999">
                                    Enterprise ($299.99/mo)
                                </option>
                                <option value="custom" <?= $tenant['subscription_plan'] === 'custom' ? 'selected' : '' ?>
                                        data-users="998" data-branches="998" data-products="9998">
                                    Custom (Contact Sales)
                                </option>
                            <?php else: ?>
                                <?php foreach ($plans as $plan): ?>
                                <?php 
                                    // Cast to float to avoid type error
                                    $monthly_price = floatval($plan['monthly_price'] ?? 0);
                                ?>
                                <option value="<?= htmlspecialchars($plan['plan_key']) ?>" 
                                        data-users="<?= intval($plan['max_users']) ?>"
                                        data-branches="<?= intval($plan['max_branches']) ?>"
                                        data-products="<?= intval($plan['max_products']) ?>"
                                        <?= $tenant['subscription_plan'] === $plan['plan_key'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($plan['name']) ?> 
                                    <?php if ($monthly_price > 0): ?>
                                        ($<?= number_format($monthly_price, 2) ?>/mo)
                                    <?php else: ?>
                                        (Contact Sales)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <div class="info-text">
                            <?php if (count($plans) == 0): ?>
                                <span style="color: #EF4444;">Warning: No plans in database. Using defaults.</span>
                            <?php elseif (count($plans) < 4): ?>
                                <span style="color: #F59E0B;">Note: Only <?= count($plans) ?> plan(s) available.</span>
                            <?php else: ?>
                                Changing the plan will update the limits below
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="label-required">Subscription Status</label>
                        <select name="subscription_status">
                            <option value="trial" <?= $tenant['subscription_status'] === 'trial' ? 'selected' : '' ?>>Trial</option>
                            <option value="active" <?= $tenant['subscription_status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="suspended" <?= $tenant['subscription_status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                            <option value="cancelled" <?= $tenant['subscription_status'] === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Subscription Expires At</label>
                        <input type="datetime-local" name="subscription_expires_at" 
                               value="<?= $tenant['subscription_expires_at'] ? date('Y-m-d\TH:i', strtotime($tenant['subscription_expires_at'])) : '' ?>">
                        <div class="info-text">Leave empty for no expiration</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Grace Period (Days)</label>
                        <input type="number" name="grace_period_days" value="<?= intval($tenant['grace_period_days'] ?? 7) ?>" min="0" max="30">
                        <div class="info-text">Days after expiration before suspension</div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Resource Limits -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Resource Limits</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Max Users</label>
                        <input type="number" name="max_users" id="max_users" value="<?= intval($tenant['max_users']) ?>" min="1">
                        <div class="info-text">Currently using: <?= intval($stats['user_count']) ?> users</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Max Branches</label>
                        <input type="number" name="max_branches" id="max_branches" value="<?= intval($tenant['max_branches']) ?>" min="1">
                        <div class="info-text">Currently using: <?= intval($stats['branch_count']) ?> branches</div>
                    </div>
                    
                    <div class="form-group">
                        <label>Max Products</label>
                        <input type="number" name="max_products" id="max_products" value="<?= intval($tenant['max_products']) ?>" min="1">
                        <div class="info-text">Currently using: <?= intval($stats['product_count']) ?> products</div>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <div>
                    <span class="info-text">Last updated: <?= $tenant['updated_at'] ? date('M d, Y H:i', strtotime($tenant['updated_at'])) : 'Never' ?></span>
                </div>
                <div style="display: flex; gap: 12px;">
                    <a href="/views/superadmin/tenants/view.php?id=<?= $tenant_id ?>" class="btn btn-secondary">Cancel</a>
                    <button type="submit" class="btn btn-primary">Save Changes</button>
                </div>
            </div>
        </div>
    </form>
</div>

<script>
function updatePlanLimits() {
    const select = document.getElementById('plan_select');
    const selectedOption = select.options[select.selectedIndex];
    
    if (selectedOption && selectedOption.value !== 'custom') {
        document.getElementById('max_users').value = selectedOption.dataset.users || 5;
        document.getElementById('max_branches').value = selectedOption.dataset.branches || 1;
        document.getElementById('max_products').value = selectedOption.dataset.products || 50;
    }
}

// Debug: Log available plans
console.log("Available plans: <?= count($plans) ?>");
<?php foreach ($plans as $plan): ?>
console.log("Plan: <?= htmlspecialchars($plan['plan_key']) ?> - <?= htmlspecialchars($plan['name']) ?>");
<?php endforeach; ?>
</script>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
?>