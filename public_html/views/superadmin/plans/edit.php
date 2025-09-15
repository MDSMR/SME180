<?php
/**
 * SME 180 - Edit Subscription Plan
 * Path: /views/superadmin/plans/edit.php
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

// Initialize variables
$success = '';
$error = '';

// Get plan details from database
$plan = null;
try {
    // Check which columns exist
    $columns = $pdo->query("SHOW COLUMNS FROM subscription_plans")->fetchAll(PDO::FETCH_COLUMN);
    $has_features_json = in_array('features_json', $columns);
    $has_features = in_array('features', $columns);
    $features_column = $has_features_json ? 'features_json' : ($has_features ? 'features' : 'NULL as features_json');
    
    $stmt = $pdo->prepare("
        SELECT 
            id,
            plan_key,
            name,
            description,
            max_users,
            max_branches,
            max_products,
            monthly_price,
            yearly_price,
            $features_column,
            is_active
        FROM subscription_plans 
        WHERE id = ?
    ");
    $stmt->execute([$plan_id]);
    $plan = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$plan) {
        header('Location: /views/superadmin/plans/index.php');
        exit;
    }
} catch (Exception $e) {
    die("Database Error: " . $e->getMessage());
}

// Parse features from database
$features = [];
$features_data = $plan['features_json'] ?? $plan['features'] ?? null;
if (!empty($features_data)) {
    $features = is_string($features_data) ? (json_decode($features_data, true) ?: []) : [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate input
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $plan_key = trim($_POST['plan_key'] ?? '');
        $monthly_price = (float)($_POST['monthly_price'] ?? 0);
        $yearly_price = (float)($_POST['yearly_price'] ?? 0);
        $max_users = (int)($_POST['max_users'] ?? 5);
        $max_branches = (int)($_POST['max_branches'] ?? 1);
        $max_products = (int)($_POST['max_products'] ?? 50);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Collect features
        $selected_features = [];
        $feature_keys = [
            'pos', 'loyalty', 'stockflow', 'table_management', 'api_access',
            'reports_basic', 'reports_advanced', 'multi_branch', 'online_ordering',
            'kitchen_display', 'customer_app', 'white_label', 'custom_reports',
            'data_export', 'integration'
        ];
        
        foreach ($feature_keys as $key) {
            $selected_features[$key] = isset($_POST['feature_' . $key]) ? true : false;
        }
        
        // Validation
        if (empty($name)) {
            throw new Exception('Plan name is required');
        }
        
        if (empty($plan_key)) {
            throw new Exception('Plan key is required');
        }
        
        // Check if plan_key already exists (excluding current plan)
        $stmt = $pdo->prepare("SELECT id FROM subscription_plans WHERE plan_key = ? AND id != ?");
        $stmt->execute([$plan_key, $plan_id]);
        if ($stmt->fetch()) {
            throw new Exception('Plan key already exists for another plan');
        }
        
        // Build update query based on available columns
        $update_columns = [
            'name = ?',
            'description = ?',
            'plan_key = ?',
            'monthly_price = ?',
            'yearly_price = ?',
            'max_users = ?',
            'max_branches = ?',
            'max_products = ?',
            'is_active = ?'
        ];
        
        $params = [
            $name,
            $description,
            $plan_key,
            $monthly_price,
            $yearly_price,
            $max_users,
            $max_branches,
            $max_products,
            $is_active
        ];
        
        // Add features column if it exists
        if ($has_features_json) {
            $update_columns[] = 'features_json = ?';
            $params[] = json_encode($selected_features);
        } elseif ($has_features) {
            $update_columns[] = 'features = ?';
            $params[] = json_encode($selected_features);
        }
        
        $params[] = $plan_id;
        
        // Update plan
        $sql = "UPDATE subscription_plans SET " . implode(', ', $update_columns) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        $success = 'Plan updated successfully!';
        
        // Refresh plan data
        $stmt = $pdo->prepare("
            SELECT 
                id,
                plan_key,
                name,
                description,
                max_users,
                max_branches,
                max_products,
                monthly_price,
                yearly_price,
                $features_column,
                is_active
            FROM subscription_plans 
            WHERE id = ?
        ");
        $stmt->execute([$plan_id]);
        $plan = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Re-parse features
        $features = [];
        $features_data = $plan['features_json'] ?? $plan['features'] ?? null;
        if (!empty($features_data)) {
            $features = is_string($features_data) ? (json_decode($features_data, true) ?: []) : [];
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
    .edit-container {
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
    
    /* Form Sections */
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
    
    /* Features Grid */
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
    
    /* Messages */
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
    
    @media (max-width: 768px) {
        .edit-container {
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

<div class="edit-container">
    <div class="page-header">
        <h1 class="page-title">Edit Plan: <?= htmlspecialchars($plan['name']) ?></h1>
        <p class="page-subtitle">Update subscription plan details</p>
    </div>
    
    <?php if (!empty($success)): ?>
    <div class="alert alert-success">
        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <?= htmlspecialchars($success) ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($error)): ?>
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
                        <input type="text" name="name" class="form-input" 
                               value="<?= htmlspecialchars($plan['name']) ?>" required>
                        <span class="form-hint">Display name for the plan</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">
                            Plan Key <span class="required">*</span>
                        </label>
                        <input type="text" name="plan_key" class="form-input" 
                               value="<?= htmlspecialchars($plan['plan_key']) ?>" required 
                               pattern="[a-z0-9_-]+" title="Only lowercase letters, numbers, hyphens and underscores">
                        <span class="form-hint">Unique identifier (lowercase, no spaces)</span>
                    </div>
                </div>
                
                <div class="form-group" style="margin-top: 20px;">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-textarea"><?= htmlspecialchars($plan['description'] ?? '') ?></textarea>
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
                        <label class="form-label">Monthly Price</label>
                        <div class="price-input-wrapper">
                            <span class="currency-prefix"><?= htmlspecialchars($currency) ?></span>
                            <input type="number" name="monthly_price" class="form-input price-input" 
                                   value="<?= $plan['monthly_price'] ?>" step="0.01" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Yearly Price</label>
                        <div class="price-input-wrapper">
                            <span class="currency-prefix"><?= htmlspecialchars($currency) ?></span>
                            <input type="number" name="yearly_price" class="form-input price-input" 
                                   value="<?= $plan['yearly_price'] ?>" step="0.01" min="0">
                        </div>
                        <span class="form-hint" id="discountHint"></span>
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
                               value="<?= $plan['max_users'] ?>" min="1" max="9999">
                        <span class="form-hint">Set to 999 or higher for unlimited</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Maximum Branches</label>
                        <input type="number" name="max_branches" class="form-input" 
                               value="<?= $plan['max_branches'] ?>" min="1" max="9999">
                        <span class="form-hint">Set to 999 or higher for unlimited</span>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">Maximum Products</label>
                        <input type="number" name="max_products" class="form-input" 
                               value="<?= $plan['max_products'] ?>" min="1" max="99999">
                        <span class="form-hint">Set to 9999 or higher for unlimited</span>
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
                    <?php $checked = isset($features[$key]) && $features[$key]; ?>
                    <label class="feature-card <?= $checked ? 'selected' : '' ?>" onclick="toggleFeature(this)">
                        <div class="feature-header">
                            <input type="checkbox" name="feature_<?= $key ?>" class="feature-checkbox"
                                   <?= $checked ? 'checked' : '' ?>>
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
                            <input type="checkbox" name="is_active" <?= ($plan['is_active'] ?? 1) ? 'checked' : '' ?>>
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
                    Save Changes
                </button>
            </div>
        </div>
    </form>
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

// Calculate and display yearly discount
function calculateDiscount() {
    const monthly = parseFloat(document.querySelector('input[name="monthly_price"]').value) || 0;
    const yearly = parseFloat(document.querySelector('input[name="yearly_price"]').value) || 0;
    const hint = document.getElementById('discountHint');
    
    if (monthly > 0 && yearly > 0) {
        const yearlyEquivalent = monthly * 12;
        const discount = ((yearlyEquivalent - yearly) / yearlyEquivalent) * 100;
        
        if (discount > 0) {
            hint.textContent = `Customers save ${discount.toFixed(1)}% with yearly billing`;
            hint.style.color = '#10B981';
        } else if (discount < 0) {
            hint.textContent = `Yearly price is higher than monthly equivalent`;
            hint.style.color = '#EF4444';
        } else {
            hint.textContent = `No discount on yearly billing`;
            hint.style.color = '#6B7280';
        }
    } else {
        hint.textContent = '';
    }
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners for discount calculation
    document.querySelector('input[name="monthly_price"]').addEventListener('input', calculateDiscount);
    document.querySelector('input[name="yearly_price"]').addEventListener('input', calculateDiscount);
    
    // Initial calculation
    calculateDiscount();
});
</script>

<?php
// Include the footer (closes the layout)
require_once dirname(__DIR__) . '/includes/footer.php';
?>