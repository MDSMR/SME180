<?php
/**
 * SME 180 - Create Subscription Plan
 * Path: /views/superadmin/plans/create.php
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

$pdo = db();
$error = '';
$success = false;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate inputs
        $name = trim($_POST['name'] ?? '');
        $plan_key = trim($_POST['plan_key'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $max_users = intval($_POST['max_users'] ?? 5);
        $max_branches = intval($_POST['max_branches'] ?? 1);
        $max_products = intval($_POST['max_products'] ?? 100);
        $monthly_price = floatval($_POST['monthly_price'] ?? 0);
        $yearly_price = floatval($_POST['yearly_price'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Handle features
        $features = [];
        $available_features = [
            'pos', 'loyalty', 'stockflow', 'table_management', 'api_access',
            'reports_basic', 'reports_advanced', 'multi_branch', 'online_ordering',
            'kitchen_display', 'customer_app', 'white_label', 'custom_reports',
            'data_export', 'integration'
        ];
        
        foreach ($available_features as $feature) {
            $features[$feature] = isset($_POST['feature_' . $feature]);
        }
        
        if (empty($name)) {
            throw new Exception('Plan name is required');
        }
        
        if (empty($plan_key)) {
            // Auto-generate plan key from name
            $plan_key = strtolower(str_replace(' ', '_', $name));
            $plan_key = preg_replace('/[^a-z0-9_-]/', '', $plan_key);
        }
        
        // Check if plan key already exists
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM subscription_plans WHERE plan_key = ?");
        $stmt->execute([$plan_key]);
        if ($stmt->fetchColumn() > 0) {
            throw new Exception('Plan key already exists. Please use a different key.');
        }
        
        // Check which columns exist in the table
        $columns = $pdo->query("SHOW COLUMNS FROM subscription_plans")->fetchAll(PDO::FETCH_COLUMN);
        $has_features_json = in_array('features_json', $columns);
        $has_features = in_array('features', $columns);
        
        // Prepare the INSERT statement based on available columns
        $insert_columns = [
            'plan_key', 'name', 'description', 'max_users', 'max_branches', 
            'max_products', 'monthly_price', 'yearly_price', 'is_active'
        ];
        $insert_values = [
            ':plan_key', ':name', ':description', ':max_users', ':max_branches',
            ':max_products', ':monthly_price', ':yearly_price', ':is_active'
        ];
        $params = [
            ':plan_key' => $plan_key,
            ':name' => $name,
            ':description' => $description ?: null,
            ':max_users' => $max_users,
            ':max_branches' => $max_branches,
            ':max_products' => $max_products,
            ':monthly_price' => $monthly_price,
            ':yearly_price' => $yearly_price,
            ':is_active' => $is_active
        ];
        
        // Add features column if it exists
        if ($has_features_json) {
            $insert_columns[] = 'features_json';
            $insert_values[] = ':features_json';
            $params[':features_json'] = json_encode($features);
        } elseif ($has_features) {
            $insert_columns[] = 'features';
            $insert_values[] = ':features';
            $params[':features'] = json_encode($features);
        }
        
        // Insert new plan
        $sql = "INSERT INTO subscription_plans (" . implode(', ', $insert_columns) . ") 
                VALUES (" . implode(', ', $insert_values) . ")";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $plan_id = $pdo->lastInsertId();
        $success = true;
        
        // Log action
        try {
            $stmt = $pdo->prepare("
                INSERT INTO super_admin_logs (admin_id, action, details, ip_address, user_agent, created_at)
                VALUES (?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['super_admin_id'] ?? 1,
                'create_plan',
                json_encode(['plan_id' => $plan_id, 'plan_key' => $plan_key, 'name' => $name]),
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            // Logging table might not exist
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Get currency from database
try {
    $stmt = $pdo->query("SELECT default_currency_symbol FROM tenants WHERE id = 1 LIMIT 1");
    $currencyResult = $stmt->fetch(PDO::FETCH_ASSOC);
    $currency = $currencyResult['default_currency_symbol'] ?? '$';
} catch (Exception $e) {
    $currency = '$';
}

// Available features list
$available_features = [
    'pos' => ['name' => 'POS System', 'icon' => '💳', 'description' => 'Point of sale functionality'],
    'loyalty' => ['name' => 'Loyalty Programs', 'icon' => '🎁', 'description' => 'Customer rewards and points'],
    'stockflow' => ['name' => 'Inventory Management', 'icon' => '📦', 'description' => 'Stock tracking and control'],
    'table_management' => ['name' => 'Table Management', 'icon' => '🍽️', 'description' => 'Restaurant table tracking'],
    'api_access' => ['name' => 'API Access', 'icon' => '🔌', 'description' => 'Developer API integration'],
    'reports_basic' => ['name' => 'Basic Reports', 'icon' => '📊', 'description' => 'Essential business reports'],
    'reports_advanced' => ['name' => 'Advanced Reports', 'icon' => '📈', 'description' => 'Detailed analytics'],
    'multi_branch' => ['name' => 'Multi-Branch Support', 'icon' => '🏢', 'description' => 'Multiple locations'],
    'online_ordering' => ['name' => 'Online Ordering', 'icon' => '🛒', 'description' => 'Web & app ordering'],
    'kitchen_display' => ['name' => 'Kitchen Display', 'icon' => '👨‍🍳', 'description' => 'Kitchen management system'],
    'customer_app' => ['name' => 'Customer App', 'icon' => '📱', 'description' => 'Mobile application'],
    'white_label' => ['name' => 'White Label', 'icon' => '🏷️', 'description' => 'Custom branding options'],
    'custom_reports' => ['name' => 'Custom Reports', 'icon' => '📋', 'description' => 'Tailored reporting'],
    'data_export' => ['name' => 'Data Export', 'icon' => '💾', 'description' => 'Export capabilities'],
    'integration' => ['name' => '3rd Party Integration', 'icon' => '🔗', 'description' => 'External services']
];
?>

<style>
    .create-container {
        padding: 24px;
        max-width: 1200px;
        margin: 0 auto;
    }
    
    .page-header {
        margin-bottom: 24px;
    }
    
    .page-title {
        font-size: 26px;
        font-weight: 700;
        color: #111827;
        margin-bottom: 4px;
    }
    
    .page-subtitle {
        font-size: 14px;
        color: #6B7280;
    }
    
    /* Form Sections - Clean Design */
    .form-card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        margin-bottom: 20px;
        overflow: hidden;
    }
    
    .form-card-header {
        padding: 16px 20px;
        border-bottom: 1px solid #E5E7EB;
        background: linear-gradient(to bottom, #FAFBFC, #F9FAFB);
    }
    
    .form-card-title {
        font-size: 15px;
        font-weight: 600;
        color: #111827;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .form-card-body {
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
    }
    
    .form-label {
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 6px;
    }
    
    .form-label .required {
        color: #EF4444;
    }
    
    .form-input,
    .form-select,
    .form-textarea {
        padding: 10px 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s;
        background: white;
    }
    
    .form-input:focus,
    .form-select:focus,
    .form-textarea:focus {
        outline: none;
        border-color: #7c3aed;
        box-shadow: 0 0 0 3px rgba(124, 58, 237, 0.1);
    }
    
    .form-textarea {
        resize: vertical;
        min-height: 80px;
    }
    
    .form-hint {
        font-size: 12px;
        color: #6B7280;
        margin-top: 4px;
    }
    
    .price-input-wrapper {
        position: relative;
    }
    
    .currency-prefix {
        position: absolute;
        left: 12px;
        top: 50%;
        transform: translateY(-50%);
        color: #6B7280;
        font-weight: 600;
        font-size: 14px;
    }
    
    .price-input {
        padding-left: 30px !important;
    }
    
    /* Features Grid - Better Design */
    .features-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: 12px;
    }
    
    .feature-card {
        border: 1px solid #E5E7EB;
        border-radius: 8px;
        padding: 12px;
        cursor: pointer;
        transition: all 0.2s;
        background: white;
    }
    
    .feature-card:hover {
        border-color: #D1D5DB;
        background: #F9FAFB;
    }
    
    .feature-card.selected {
        border-color: #7c3aed;
        background: #F3F0FF;
    }
    
    .feature-header {
        display: flex;
        align-items: flex-start;
        gap: 10px;
    }
    
    .feature-checkbox {
        margin-top: 2px;
    }
    
    .feature-content {
        flex: 1;
    }
    
    .feature-name {
        font-size: 13px;
        font-weight: 600;
        color: #111827;
        display: flex;
        align-items: center;
        gap: 6px;
    }
    
    .feature-description {
        font-size: 11px;
        color: #6B7280;
        margin-top: 2px;
    }
    
    /* Toggle Switch */
    .toggle-wrapper {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .toggle-switch {
        position: relative;
        width: 44px;
        height: 24px;
    }
    
    .toggle-switch input {
        opacity: 0;
        width: 0;
        height: 0;
    }
    
    .toggle-slider {
        position: absolute;
        cursor: pointer;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background-color: #D1D5DB;
        transition: 0.3s;
        border-radius: 24px;
    }
    
    .toggle-slider:before {
        position: absolute;
        content: "";
        height: 20px;
        width: 20px;
        left: 2px;
        bottom: 2px;
        background-color: white;
        transition: 0.3s;
        border-radius: 50%;
    }
    
    input:checked + .toggle-slider {
        background-color: #7c3aed;
    }
    
    input:checked + .toggle-slider:before {
        transform: translateX(20px);
    }
    
    /* Success Message */
    .alert {
        padding: 16px;
        border-radius: 8px;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 12px;
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
    
    /* Action Buttons */
    .form-actions {
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        padding: 20px;
        background: #F9FAFB;
        border-top: 1px solid #E5E7EB;
        margin: 0 -20px -20px;
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
    
    /* Success Card */
    .success-card {
        background: white;
        border: 1px solid #E5E7EB;
        border-radius: 12px;
        padding: 40px;
        text-align: center;
        max-width: 500px;
        margin: 0 auto;
    }
    
    .success-icon {
        width: 64px;
        height: 64px;
        background: #10B981;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        font-size: 32px;
        color: white;
    }
    
    .success-title {
        font-size: 20px;
        font-weight: 600;
        color: #111827;
        margin-bottom: 8px;
    }
    
    .success-text {
        font-size: 14px;
        color: #6B7280;
        margin-bottom: 24px;
    }
    
    @media (max-width: 768px) {
        .create-container {
            padding: 16px;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
        
        .features-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="create-container">
    <div class="page-header">
        <h1 class="page-title">Create Subscription Plan</h1>
        <p class="page-subtitle">Define a new subscription plan for tenants</p>
    </div>
    
    <?php if ($success): ?>
    <div class="success-card">
        <div class="success-icon">✓</div>
        <div class="success-title">Plan Created Successfully!</div>
        <div class="success-text">The subscription plan has been created and is now available.</div>
        <div style="display: flex; gap: 12px; justify-content: center;">
            <a href="/views/superadmin/plans/index.php" class="btn btn-secondary">View All Plans</a>
            <a href="/views/superadmin/plans/create.php" class="btn btn-primary">Create Another</a>
        </div>
    </div>
    <?php else: ?>
    
    <?php if ($error): ?>
    <div class="alert alert-error">
        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    
    <form method="POST">
        <!-- Basic Information -->
        <div class="form-card">
            <div class="form-card-header">
                <h3 class="form-card-title">
                    <span>📋</span> Basic Information
                </h3>
            </div>
            <div class="form-card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            Plan Name <span class="required">*</span>
                        </label>
                        <input type="text" name="name" class="form-input" required 
                               placeholder="e.g., Professional" value="<?= $_POST['name'] ?? '' ?>">
                        <span class="form-hint">Display name for the plan</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Plan Key</label>
                        <input type="text" name="plan_key" class="form-input" 
                               pattern="[a-z0-9_-]+" placeholder="e.g., professional"
                               value="<?= $_POST['plan_key'] ?? '' ?>">
                        <span class="form-hint">Auto-generated if left empty</span>
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 20px;">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-textarea" 
                              placeholder="Brief description of what this plan offers"><?= $_POST['description'] ?? '' ?></textarea>
                </div>
            </div>
        </div>
        
        <!-- Pricing -->
        <div class="form-card">
            <div class="form-card-header">
                <h3 class="form-card-title">
                    <span>💰</span> Pricing
                </h3>
            </div>
            <div class="form-card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">
                            Monthly Price <span class="required">*</span>
                        </label>
                        <div class="price-input-wrapper">
                            <span class="currency-prefix"><?= $currency ?></span>
                            <input type="number" name="monthly_price" class="form-input price-input" 
                                   step="0.01" min="0" required value="<?= $_POST['monthly_price'] ?? '29.99' ?>">
                        </div>
                        <span class="form-hint">Monthly subscription cost</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Yearly Price</label>
                        <div class="price-input-wrapper">
                            <span class="currency-prefix"><?= $currency ?></span>
                            <input type="number" name="yearly_price" class="form-input price-input" 
                                   step="0.01" min="0" value="<?= $_POST['yearly_price'] ?? '' ?>"
                                   placeholder="Optional annual price">
                        </div>
                        <span class="form-hint">Leave empty for no yearly option</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Limits -->
        <div class="form-card">
            <div class="form-card-header">
                <h3 class="form-card-title">
                    <span>📊</span> Plan Limits
                </h3>
            </div>
            <div class="form-card-body">
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label">Maximum Users</label>
                        <input type="number" name="max_users" class="form-input" 
                               min="1" value="<?= $_POST['max_users'] ?? '5' ?>">
                        <span class="form-hint">Set 999+ for unlimited</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Maximum Branches</label>
                        <input type="number" name="max_branches" class="form-input" 
                               min="1" value="<?= $_POST['max_branches'] ?? '1' ?>">
                        <span class="form-hint">Set 999+ for unlimited</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Maximum Products</label>
                        <input type="number" name="max_products" class="form-input" 
                               min="1" value="<?= $_POST['max_products'] ?? '100' ?>">
                        <span class="form-hint">Set 9999+ for unlimited</span>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Features -->
        <div class="form-card">
            <div class="form-card-header">
                <h3 class="form-card-title">
                    <span>✨</span> Available Features
                </h3>
            </div>
            <div class="form-card-body">
                <div class="features-grid">
                    <?php foreach ($available_features as $key => $feature): ?>
                    <label class="feature-card" onclick="toggleFeature(this)">
                        <div class="feature-header">
                            <input type="checkbox" name="feature_<?= $key ?>" class="feature-checkbox"
                                   <?= in_array($key, ['pos', 'reports_basic']) ? 'checked' : '' ?>>
                            <div class="feature-content">
                                <div class="feature-name">
                                    <span><?= $feature['icon'] ?></span>
                                    <?= htmlspecialchars($feature['name']) ?>
                                </div>
                                <div class="feature-description">
                                    <?= htmlspecialchars($feature['description']) ?>
                                </div>
                            </div>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Settings -->
        <div class="form-card">
            <div class="form-card-header">
                <h3 class="form-card-title">
                    <span>⚙️</span> Settings
                </h3>
            </div>
            <div class="form-card-body">
                <div class="form-group">
                    <label class="form-label">Plan Status</label>
                    <div class="toggle-wrapper">
                        <label class="toggle-switch">
                            <input type="checkbox" name="is_active" checked>
                            <span class="toggle-slider"></span>
                        </label>
                        <span>Active (available for new subscriptions)</span>
                    </div>
                </div>
            </div>
            
            <div class="form-actions">
                <a href="/views/superadmin/plans/index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Create Plan
                </button>
            </div>
        </div>
    </form>
    <?php endif; ?>
</div>

<script>
function toggleFeature(element) {
    const checkbox = element.querySelector('.feature-checkbox');
    if (element.classList.contains('selected')) {
        element.classList.remove('selected');
    } else {
        element.classList.add('selected');
    }
}

// Initialize selected state
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.feature-checkbox:checked').forEach(function(checkbox) {
        checkbox.closest('.feature-card').classList.add('selected');
    });
});

// Auto-generate yearly price suggestion
document.querySelector('input[name="monthly_price"]').addEventListener('input', function() {
    const monthly = parseFloat(this.value) || 0;
    const yearlyInput = document.querySelector('input[name="yearly_price"]');
    if (monthly > 0 && !yearlyInput.value) {
        yearlyInput.placeholder = (monthly * 12 * 0.8).toFixed(2) + ' (20% discount)';
    }
});
</script>

<?php
// Include the footer (closes the layout)
require_once dirname(__DIR__) . '/includes/footer.php';
?>