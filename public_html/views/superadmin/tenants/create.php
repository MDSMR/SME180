<?php
/**
 * SME 180 - Create New Tenant
 * Path: /views/superadmin/tenants/create.php
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

// Include the sidebar
require_once dirname(__DIR__) . '/includes/sidebar.php';

$pdo = db();
$error = '';
$success = false;
$credentials = [];

// Get subscription plans from database or use defaults
try {
    $stmt = $pdo->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY monthly_price ASC");
    $plans = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // Table might not exist, use defaults
    $plans = [
        ['id' => 1, 'plan_key' => 'starter', 'name' => 'Starter', 'max_users' => 5, 'max_branches' => 1, 'max_products' => 50],
        ['id' => 2, 'plan_key' => 'professional', 'name' => 'Professional', 'max_users' => 20, 'max_branches' => 3, 'max_products' => 500],
        ['id' => 3, 'plan_key' => 'enterprise', 'name' => 'Enterprise', 'max_users' => 999, 'max_branches' => 999, 'max_products' => 9999]
    ];
}

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate inputs
        $tenant_name = trim($_POST['tenant_name'] ?? '');
        $plan_key = $_POST['subscription_plan'] ?? 'starter';
        $subscription_status = $_POST['subscription_status'] ?? 'trial';
        $start_date = $_POST['start_date'] ?? date('Y-m-d');
        $expires_date = $_POST['expires_date'] ?? date('Y-m-d', strtotime('+30 days'));
        $max_users = intval($_POST['max_users'] ?? 5);
        $max_branches = intval($_POST['max_branches'] ?? 1);
        $max_products = intval($_POST['max_products'] ?? 50);
        $billing_email = trim($_POST['billing_email'] ?? '');
        $billing_contact = trim($_POST['billing_contact'] ?? '');
        $currency_symbol = $_POST['currency_symbol'] ?? '$';
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if (empty($tenant_name)) {
            throw new Exception('Tenant name is required');
        }
        
        // Start transaction
        $pdo->beginTransaction();
        
        // Create tenant
        $stmt = $pdo->prepare("
            INSERT INTO tenants (
                name, 
                subscription_plan, 
                subscription_status,
                subscription_starts_at,
                subscription_expires_at,
                max_users,
                max_branches,
                max_products,
                billing_email,
                billing_contact,
                currency_symbol,
                is_active,
                created_at
            ) VALUES (
                :name,
                :plan,
                :status,
                :starts_at,
                :expires_at,
                :max_users,
                :max_branches,
                :max_products,
                :billing_email,
                :billing_contact,
                :currency_symbol,
                :is_active,
                NOW()
            )
        ");
        
        $stmt->execute([
            ':name' => $tenant_name,
            ':plan' => $plan_key,
            ':status' => $subscription_status,
            ':starts_at' => $start_date . ' 00:00:00',
            ':expires_at' => $expires_date . ' 23:59:59',
            ':max_users' => $max_users,
            ':max_branches' => $max_branches,
            ':max_products' => $max_products,
            ':billing_email' => $billing_email ?: null,
            ':billing_contact' => $billing_contact ?: null,
            ':currency_symbol' => $currency_symbol,
            ':is_active' => $is_active
        ]);
        
        $tenant_id = (int)$pdo->lastInsertId();
        
        // Create default admin user
        $admin_username = strtolower(str_replace(' ', '_', $tenant_name)) . '_admin';
        $admin_password = 'Admin@' . bin2hex(random_bytes(4));
        
        $stmt = $pdo->prepare("
            INSERT INTO users (
                tenant_id,
                username,
                password_hash,
                name,
                email,
                role_key,
                created_at
            ) VALUES (
                :tenant_id,
                :username,
                :password_hash,
                :name,
                :email,
                'admin',
                NOW()
            )
        ");
        
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':username' => $admin_username,
            ':password_hash' => password_hash($admin_password, PASSWORD_DEFAULT),
            ':name' => 'Administrator',
            ':email' => $billing_email ?: null
        ]);
        
        $user_id = (int)$pdo->lastInsertId();
        
        // Create default branch
        $stmt = $pdo->prepare("
            INSERT INTO branches (
                tenant_id,
                name,
                branch_type,
                is_active,
                created_at
            ) VALUES (
                :tenant_id,
                'Main Branch',
                'mixed',
                1,
                NOW()
            )
        ");
        
        $stmt->execute([':tenant_id' => $tenant_id]);
        $branch_id = (int)$pdo->lastInsertId();
        
        // Link user to branch
        $stmt = $pdo->prepare("
            INSERT INTO user_branches (user_id, branch_id, created_at)
            VALUES (:user_id, :branch_id, NOW())
        ");
        $stmt->execute([':user_id' => $user_id, ':branch_id' => $branch_id]);
        
        // Log action
        try {
            $stmt = $pdo->prepare("
                INSERT INTO super_admin_logs (admin_id, action, details, tenant_id, ip_address, created_at)
                VALUES (?, 'create_tenant', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['super_admin_id'],
                json_encode(['tenant_name' => $tenant_name, 'plan' => $plan_key]),
                $tenant_id,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (Exception $e) {
            // Logging failed, continue
        }
        
        $pdo->commit();
        
        $success = true;
        $credentials = [
            'tenant_name' => $tenant_name,
            'admin_username' => $admin_username,
            'admin_password' => $admin_password,
            'tenant_id' => $tenant_id
        ];
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}
?>

<style>
    .create-container {
        padding: 24px;
        max-width: 900px;
        margin: 0 auto;
    }
    
    .page-header {
        margin-bottom: 32px;
    }
    
    .page-title {
        font-size: 28px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 8px;
    }
    
    .card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 24px;
    }
    
    .card-header {
        padding: 20px 24px;
        border-bottom: 1px solid #E5E7EB;
        background: #F9FAFB;
    }
    
    .card-title {
        font-size: 16px;
        font-weight: 600;
        color: #111827;
    }
    
    .card-body {
        padding: 24px;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }
    
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    
    label {
        font-size: 14px;
        font-weight: 500;
        color: #374151;
    }
    
    .required {
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
    
    textarea {
        resize: vertical;
        min-height: 100px;
    }
    
    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .checkbox-group input {
        width: auto;
    }
    
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        padding: 20px 24px;
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
    }
    
    .btn-secondary {
        background: white;
        color: #6B7280;
        border: 1px solid #D1D5DB;
    }
    
    .btn-secondary:hover {
        background: #F9FAFB;
    }
    
    .alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        font-size: 14px;
    }
    
    .alert-error {
        background: #FEE2E2;
        color: #991B1B;
        border: 1px solid #FCA5A5;
    }
    
    .success-card {
        background: white;
        border: 1px solid #A7F3D0;
        border-radius: 12px;
        padding: 32px;
        text-align: center;
    }
    
    .success-icon {
        width: 64px;
        height: 64px;
        background: #10B981;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 24px;
        font-size: 32px;
        color: white;
    }
    
    .credentials-box {
        background: #F9FAFB;
        border-radius: 8px;
        padding: 20px;
        margin: 24px auto;
        max-width: 500px;
        text-align: left;
    }
    
    .credential-item {
        display: flex;
        justify-content: space-between;
        padding: 12px 0;
        border-bottom: 1px solid #E5E7EB;
    }
    
    .credential-item:last-child {
        border-bottom: none;
    }
    
    .credential-label {
        font-weight: 500;
        color: #374151;
    }
    
    .credential-value {
        font-family: monospace;
        color: #111827;
    }
</style>

<div class="create-container">
    <div class="page-header">
        <h1 class="page-title">Create New Tenant</h1>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-error">
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="success-card">
        <div class="success-icon">✓</div>
        <h2 style="font-size: 24px; margin-bottom: 16px;">Tenant Created Successfully!</h2>
        <p style="color: #6B7280; margin-bottom: 24px;">The tenant has been created with the following credentials:</p>
        
        <div class="credentials-box">
            <div class="credential-item">
                <span class="credential-label">Tenant ID:</span>
                <span class="credential-value">#<?= $credentials['tenant_id'] ?></span>
            </div>
            <div class="credential-item">
                <span class="credential-label">Tenant Name:</span>
                <span class="credential-value"><?= htmlspecialchars($credentials['tenant_name']) ?></span>
            </div>
            <div class="credential-item">
                <span class="credential-label">Admin Username:</span>
                <span class="credential-value"><?= htmlspecialchars($credentials['admin_username']) ?></span>
            </div>
            <div class="credential-item">
                <span class="credential-label">Admin Password:</span>
                <span class="credential-value"><?= htmlspecialchars($credentials['admin_password']) ?></span>
            </div>
        </div>
        
        <p style="color: #F59E0B; font-size: 14px; margin-bottom: 24px;">
            ⚠️ Please save these credentials securely. The password cannot be retrieved later.
        </p>
        
        <div style="display: flex; gap: 12px; justify-content: center;">
            <a href="/views/superadmin/tenants/index.php" class="btn btn-secondary">Back to Tenants</a>
            <a href="/views/superadmin/tenants/view.php?id=<?= $credentials['tenant_id'] ?>" class="btn btn-primary">View Tenant</a>
        </div>
    </div>
    <?php else: ?>
    
    <form method="POST">
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Tenant Information</h3>
            </div>
            <div class="card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Tenant Name <span class="required">*</span></label>
                        <input type="text" name="tenant_name" required placeholder="Company Name">
                    </div>
                    
                    <div class="form-group">
                        <label>Subscription Plan <span class="required">*</span></label>
                        <select name="subscription_plan" required onchange="updatePlanLimits(this.value)">
                            <?php foreach ($plans as $plan): ?>
                            <option value="<?= htmlspecialchars($plan['plan_key']) ?>" 
                                    data-users="<?= $plan['max_users'] ?>"
                                    data-branches="<?= $plan['max_branches'] ?>"
                                    data-products="<?= $plan['max_products'] ?>">
                                <?= htmlspecialchars($plan['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Status <span class="required">*</span></label>
                        <select name="subscription_status" required>
                            <option value="trial" selected>Trial</option>
                            <option value="active">Active</option>
                            <option value="suspended">Suspended</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Currency Symbol</label>
                        <select name="currency_symbol">
                            <option value="$">$ (USD)</option>
                            <option value="€">€ (EUR)</option>
                            <option value="£">£ (GBP)</option>
                            <option value="EGP">EGP</option>
                            <option value="SAR">SAR</option>
                            <option value="AED">AED</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" value="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Expires Date</label>
                        <input type="date" name="expires_date" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Max Users</label>
                        <input type="number" name="max_users" id="max_users" value="5" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label>Max Branches</label>
                        <input type="number" name="max_branches" id="max_branches" value="1" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label>Max Products</label>
                        <input type="number" name="max_products" id="max_products" value="50" min="1">
                    </div>
                    
                    <div class="form-group">
                        <label>Billing Email</label>
                        <input type="email" name="billing_email" placeholder="billing@example.com">
                    </div>
                    
                    <div class="form-group">
                        <label>Billing Contact</label>
                        <input type="text" name="billing_contact" placeholder="Contact Person">
                    </div>
                    
                    <div class="form-group">
                        <label>Active Status</label>
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="is_active" value="1" checked>
                            <label for="is_active">Tenant is active</label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <a href="/views/superadmin/tenants/index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">Create Tenant</button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function updatePlanLimits(planKey) {
    const select = document.querySelector('select[name="subscription_plan"]');
    const option = select.querySelector(`option[value="${planKey}"]`);
    
    if (option) {
        document.getElementById('max_users').value = option.dataset.users;
        document.getElementById('max_branches').value = option.dataset.branches;
        document.getElementById('max_products').value = option.dataset.products;
    }
}

// Initialize with first plan
updatePlanLimits(document.querySelector('select[name="subscription_plan"]').value);
</script>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
?>