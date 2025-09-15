<?php
// /views/superadmin/users/create.php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/config/db.php';

// Check if user is super admin
use_backend_session();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'super_admin') {
    header('Location: /views/auth/login.php');
    exit;
}

$admin_name = $_SESSION['super_admin_name'] ?? 'Super Admin';
$pdo = db();

// Get all active tenants with user counts
$tenants = $pdo->query("
    SELECT id, name, max_users,
           (SELECT COUNT(*) FROM user_tenants ut 
            JOIN users u ON ut.user_id = u.id 
            WHERE ut.tenant_id = tenants.id 
            AND u.disabled_at IS NULL) as current_users
    FROM tenants 
    WHERE is_active = 1 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Get all roles
$roles = $pdo->query("
    SELECT role_key, name 
    FROM roles 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

// Get branches for all tenants
$branches = [];
foreach ($tenants as $tenant) {
    $stmt = $pdo->prepare("
        SELECT id, name 
        FROM branches 
        WHERE tenant_id = ? AND is_active = 1 
        ORDER BY name
    ");
    $stmt->execute([$tenant['id']]);
    $branches[$tenant['id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Process form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $user_type = $_POST['user_type'] ?? 'pos';
    $role_key = $_POST['role_key'] ?? '';
    $password = $_POST['password'] ?? '';
    $pin = $_POST['pin'] ?? '';
    $selected_tenants = $_POST['tenants'] ?? [];
    $primary_tenant = $_POST['primary_tenant'] ?? '';
    $selected_branches = $_POST['branches'] ?? [];
    
    // Validation
    if (!$name || !$username || !$role_key || !$password) {
        $error = 'Name, username, role and password are required';
    } else if (empty($selected_tenants)) {
        $error = 'At least one tenant must be selected';
    } else if (!$primary_tenant || !in_array($primary_tenant, $selected_tenants)) {
        $error = 'Primary tenant must be selected from assigned tenants';
    } else if (in_array($user_type, ['pos', 'both']) && (!$pin || !preg_match('/^\d{4,6}$/', $pin))) {
        $error = 'Valid PIN (4-6 digits) is required for POS access';
    } else {
        // Check username uniqueness
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM users u 
            JOIN user_tenants ut ON u.id = ut.user_id 
            WHERE u.username = ? 
            AND ut.tenant_id IN (" . implode(',', array_map('intval', $selected_tenants)) . ")
        ");
        $stmt->execute([$username]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Username already exists in one of the selected tenants';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Get primary tenant_id for user creation
                $primary_tenant_id = intval($primary_tenant);
                
                // Create user
                $stmt = $pdo->prepare("
                    INSERT INTO users (tenant_id, name, username, email, password_hash, pass_code, role_key, user_type) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $primary_tenant_id,
                    $name,
                    $username,
                    $email ?: null,
                    password_hash($password, PASSWORD_DEFAULT),
                    in_array($user_type, ['pos', 'both']) ? password_hash($pin, PASSWORD_DEFAULT) : null,
                    $role_key,
                    $user_type
                ]);
                
                $user_id = $pdo->lastInsertId();
                
                // Create user_tenants entries
                foreach ($selected_tenants as $tenant_id) {
                    $is_primary = ($tenant_id == $primary_tenant) ? 1 : 0;
                    $stmt = $pdo->prepare("
                        INSERT INTO user_tenants (user_id, tenant_id, is_primary) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$user_id, $tenant_id, $is_primary]);
                }
                
                // Create user_branches entries
                if (!empty($selected_branches)) {
                    foreach ($selected_branches as $branch_id) {
                        // Get tenant_id for this branch
                        $stmt = $pdo->prepare("SELECT tenant_id FROM branches WHERE id = ?");
                        $stmt->execute([$branch_id]);
                        $tenant_id = $stmt->fetchColumn();
                        
                        if ($tenant_id && in_array($tenant_id, $selected_tenants)) {
                            $stmt = $pdo->prepare("
                                INSERT INTO user_branches (user_id, branch_id, tenant_id) 
                                VALUES (?, ?, ?)
                            ");
                            $stmt->execute([$user_id, $branch_id, $tenant_id]);
                        }
                    }
                }
                
                // Log the creation
                $stmt = $pdo->prepare("
                    INSERT INTO super_admin_logs (admin_id, action, tenant_id, details) 
                    VALUES (?, 'user_create', NULL, ?)
                ");
                $stmt->execute([
                    $_SESSION['super_admin_id'],
                    json_encode([
                        'user_id' => $user_id,
                        'username' => $username,
                        'tenants' => $selected_tenants
                    ])
                ]);
                
                $pdo->commit();
                header('Location: /views/superadmin/users/index.php?created=1');
                exit;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error creating user: ' . $e->getMessage();
            }
        }
    }
}

// Include the sidebar
require_once dirname(__DIR__) . '/includes/sidebar.php';
?>

<style>
    /* Microsoft 365 Style - Matching index.php */
    .main-content {
        flex: 1;
        padding: 24px;
        background: #f0f2f5;
        min-height: 100vh;
    }
    
    .content-header {
        background: white;
        border-radius: 8px;
        padding: 20px 24px;
        margin-bottom: 24px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }
    
    .breadcrumb {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #605e5c;
        margin-bottom: 12px;
    }
    
    .breadcrumb a {
        color: #0078d4;
        text-decoration: none;
    }
    
    .breadcrumb a:hover {
        text-decoration: underline;
    }
    
    .breadcrumb-separator {
        color: #a19f9d;
    }
    
    .page-title {
        font-size: 24px;
        font-weight: 600;
        color: #323130;
        margin: 0;
    }
    
    /* Alert Messages */
    .alert {
        padding: 12px 16px;
        border-radius: 4px;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 12px;
        font-size: 14px;
    }
    
    .alert-error {
        background: #fde7e9;
        color: #a80000;
        border-left: 4px solid #a80000;
    }
    
    .alert-icon {
        flex-shrink: 0;
    }
    
    /* Form Card */
    .form-card {
        background: white;
        border-radius: 8px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        overflow: hidden;
    }
    
    .form-section {
        padding: 24px;
        border-bottom: 1px solid #edebe9;
    }
    
    .form-section:last-child {
        border-bottom: none;
    }
    
    .section-title {
        font-size: 16px;
        font-weight: 600;
        color: #323130;
        margin-bottom: 20px;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    .form-group.full-width {
        grid-column: span 2;
    }
    
    .form-label {
        font-size: 14px;
        font-weight: 500;
        color: #323130;
        margin-bottom: 6px;
    }
    
    .form-label.required::after {
        content: ' *';
        color: #a80000;
    }
    
    .form-input, .form-select {
        padding: 8px 12px;
        border: 1px solid #d2d0ce;
        border-radius: 4px;
        font-size: 14px;
        background: white;
        transition: all 0.2s;
    }
    
    .form-input:hover, .form-select:hover {
        border-color: #0078d4;
    }
    
    .form-input:focus, .form-select:focus {
        outline: none;
        border-color: #0078d4;
        box-shadow: 0 0 0 1px #0078d4;
    }
    
    .form-hint {
        font-size: 12px;
        color: #605e5c;
        margin-top: 4px;
    }
    
    /* Password Toggle */
    .password-input-wrapper {
        position: relative;
    }
    
    .password-toggle {
        position: absolute;
        right: 8px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        padding: 4px;
        cursor: pointer;
        color: #605e5c;
    }
    
    .password-toggle:hover {
        color: #0078d4;
    }
    
    /* Checkbox Group */
    .checkbox-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        max-height: 200px;
        overflow-y: auto;
        padding: 12px;
        border: 1px solid #d2d0ce;
        border-radius: 4px;
        background: #faf9f8;
    }
    
    .checkbox-item {
        display: flex;
        align-items: flex-start;
        gap: 8px;
    }
    
    .checkbox-item input[type="checkbox"] {
        margin-top: 2px;
        cursor: pointer;
    }
    
    .checkbox-label {
        font-size: 14px;
        color: #323130;
        cursor: pointer;
        user-select: none;
    }
    
    .tenant-info {
        font-size: 11px;
        color: #605e5c;
        margin-top: 2px;
    }
    
    .tenant-info.warning {
        color: #c87e0a;
        font-weight: 600;
    }
    
    .tenant-info.full {
        color: #a80000;
        font-weight: 600;
    }
    
    /* Branch Section */
    .branch-section {
        margin-bottom: 16px;
        padding: 12px;
        background: #faf9f8;
        border-radius: 4px;
        border: 1px solid #edebe9;
    }
    
    .branch-section-title {
        font-size: 14px;
        font-weight: 600;
        color: #323130;
        margin-bottom: 12px;
    }
    
    /* Form Actions */
    .form-actions {
        padding: 16px 24px;
        background: #faf9f8;
        border-top: 1px solid #edebe9;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }
    
    .btn {
        padding: 8px 20px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.2s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        border: 1px solid transparent;
    }
    
    .btn-primary {
        background: #0078d4;
        color: white;
        border-color: #0078d4;
    }
    
    .btn-primary:hover {
        background: #106ebe;
        border-color: #106ebe;
    }
    
    .btn-secondary {
        background: white;
        color: #323130;
        border-color: #d2d0ce;
    }
    
    .btn-secondary:hover {
        background: #f3f2f1;
        border-color: #c8c6c4;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .form-grid {
            grid-template-columns: 1fr;
        }
        
        .checkbox-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="main-content">
    <!-- Content Header -->
    <div class="content-header">
        <div class="breadcrumb">
            <a href="/views/superadmin/dashboard.php">Dashboard</a>
            <span class="breadcrumb-separator">/</span>
            <a href="/views/superadmin/users/index.php">Users</a>
            <span class="breadcrumb-separator">/</span>
            <span>Create User</span>
        </div>
        <h1 class="page-title">Create New User</h1>
    </div>
    
    <?php if ($error): ?>
    <div class="alert alert-error">
        <svg class="alert-icon" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
        </svg>
        <div><?= htmlspecialchars($error) ?></div>
    </div>
    <?php endif; ?>
    
    <!-- Form -->
    <form method="POST" class="form-card">
        <!-- Basic Information Section -->
        <div class="form-section">
            <h2 class="section-title">Basic Information</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label required">Full Name</label>
                    <input type="text" name="name" class="form-input" 
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" 
                           required autofocus>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Username</label>
                    <input type="text" name="username" class="form-input" 
                           value="<?= htmlspecialchars($_POST['username'] ?? '') ?>" 
                           pattern="[a-zA-Z0-9_]{3,50}"
                           required>
                    <span class="form-hint">3-50 characters, letters, numbers and underscore only</span>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-input" 
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label required">User Type</label>
                    <select name="user_type" id="userType" class="form-select" required>
                        <option value="backend" <?= ($_POST['user_type'] ?? '') == 'backend' ? 'selected' : '' ?>>Backend Only</option>
                        <option value="pos" <?= ($_POST['user_type'] ?? 'pos') == 'pos' ? 'selected' : '' ?>>POS Only</option>
                        <option value="both" <?= ($_POST['user_type'] ?? '') == 'both' ? 'selected' : '' ?>>Both Backend & POS</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Role</label>
                    <select name="role_key" class="form-select" required>
                        <option value="">Select Role</option>
                        <?php foreach ($roles as $role): ?>
                        <option value="<?= $role['role_key'] ?>" <?= ($_POST['role_key'] ?? '') == $role['role_key'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($role['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Security Credentials Section -->
        <div class="form-section">
            <h2 class="section-title">Security Credentials</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label required">Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" name="password" id="password" class="form-input" 
                               required minlength="8">
                        <button type="button" class="password-toggle" onclick="togglePassword('password')">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                    </div>
                    <span class="form-hint">Minimum 8 characters</span>
                </div>
                
                <div class="form-group" id="pinGroup" style="display: <?= in_array($_POST['user_type'] ?? 'pos', ['pos', 'both']) ? 'block' : 'none' ?>;">
                    <label class="form-label required">PIN Code</label>
                    <input type="text" name="pin" class="form-input" 
                           placeholder="4-6 digit PIN" 
                           maxlength="6" 
                           pattern="[0-9]{4,6}"
                           <?= in_array($_POST['user_type'] ?? 'pos', ['pos', 'both']) ? 'required' : '' ?>>
                    <span class="form-hint">4-6 digits for POS access</span>
                </div>
            </div>
        </div>
        
        <!-- Tenant Assignment Section -->
        <div class="form-section">
            <h2 class="section-title">Tenant Assignment</h2>
            <div class="form-grid">
                <div class="form-group full-width">
                    <label class="form-label required">Assign to Tenants</label>
                    <div class="checkbox-grid">
                        <?php foreach ($tenants as $tenant): 
                            $remaining = $tenant['max_users'] - $tenant['current_users'];
                            $is_full = $remaining <= 0;
                        ?>
                        <div class="checkbox-item">
                            <input type="checkbox" name="tenants[]" value="<?= $tenant['id'] ?>" 
                                   id="tenant_<?= $tenant['id'] ?>" 
                                   class="tenant-checkbox"
                                   <?= $is_full ? 'disabled' : '' ?>
                                   <?= in_array($tenant['id'], $_POST['tenants'] ?? []) ? 'checked' : '' ?>>
                            <label for="tenant_<?= $tenant['id'] ?>" class="checkbox-label">
                                <div><?= htmlspecialchars($tenant['name']) ?></div>
                                <div class="tenant-info <?= $is_full ? 'full' : ($remaining <= 2 ? 'warning' : '') ?>">
                                    <?= $tenant['current_users'] ?>/<?= $tenant['max_users'] ?> users
                                    <?= $is_full ? '(FULL)' : "($remaining remaining)" ?>
                                </div>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Primary Tenant</label>
                    <select name="primary_tenant" id="primaryTenant" class="form-select" required>
                        <option value="">Select Primary Tenant</option>
                        <?php foreach ($tenants as $tenant): ?>
                        <option value="<?= $tenant['id'] ?>" 
                                class="primary-option" 
                                data-tenant="<?= $tenant['id'] ?>"
                                style="display: none;">
                            <?= htmlspecialchars($tenant['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Branch Assignment Section -->
        <div class="form-section">
            <h2 class="section-title">Branch Assignment</h2>
            <div class="form-group full-width">
                <label class="form-label">Assign to Branches</label>
                <div id="branchContainer">
                    <span style="color: #605e5c;">Select tenants first to see available branches</span>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <a href="/views/superadmin/users/index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Create User
            </button>
        </div>
    </form>
</div>

<script>
    // Toggle password visibility
    function togglePassword(fieldId) {
        const field = document.getElementById(fieldId);
        field.type = field.type === 'password' ? 'text' : 'password';
    }
    
    // Handle user type change
    document.getElementById('userType').addEventListener('change', function() {
        const pinGroup = document.getElementById('pinGroup');
        const pinInput = pinGroup.querySelector('input[name="pin"]');
        
        if (this.value === 'pos' || this.value === 'both') {
            pinGroup.style.display = 'block';
            pinInput.setAttribute('required', 'required');
        } else {
            pinGroup.style.display = 'none';
            pinInput.removeAttribute('required');
        }
    });
    
    // Branch data
    const branchesData = <?= json_encode($branches) ?>;
    const tenantsData = <?= json_encode($tenants) ?>;
    
    // Handle tenant selection
    document.querySelectorAll('.tenant-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateTenantSelections();
        });
    });
    
    function updateTenantSelections() {
        const selectedTenants = Array.from(document.querySelectorAll('.tenant-checkbox:checked'))
            .map(cb => cb.value);
        
        const primarySelect = document.getElementById('primaryTenant');
        const currentPrimary = primarySelect.value;
        
        // Update primary tenant options
        document.querySelectorAll('.primary-option').forEach(option => {
            const tenantId = option.getAttribute('data-tenant');
            if (selectedTenants.includes(tenantId)) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
                if (currentPrimary === tenantId) {
                    primarySelect.value = '';
                }
            }
        });
        
        // Auto-select primary if only one tenant
        if (selectedTenants.length === 1) {
            primarySelect.value = selectedTenants[0];
        }
        
        // Update branch sections
        updateBranchSections(selectedTenants);
    }
    
    function updateBranchSections(selectedTenants) {
        const container = document.getElementById('branchContainer');
        container.innerHTML = '';
        
        if (selectedTenants.length === 0) {
            container.innerHTML = '<span style="color: #605e5c;">Select tenants first to see available branches</span>';
            return;
        }
        
        selectedTenants.forEach(tenantId => {
            const tenant = tenantsData.find(t => t.id == tenantId);
            const branches = branchesData[tenantId] || [];
            
            const section = document.createElement('div');
            section.className = 'branch-section';
            
            let html = `
                <div class="branch-section-title">${tenant.name} Branches:</div>
                <div class="checkbox-grid">
            `;
            
            if (branches.length === 0) {
                html += '<span style="color: #605e5c;">No branches available</span>';
            } else {
                branches.forEach(branch => {
                    html += `
                        <div class="checkbox-item">
                            <input type="checkbox" name="branches[]" value="${branch.id}" 
                                   id="branch_${branch.id}">
                            <label for="branch_${branch.id}" class="checkbox-label">
                                ${branch.name}
                            </label>
                        </div>
                    `;
                });
            }
            
            html += '</div>';
            section.innerHTML = html;
            container.appendChild(section);
        });
    }
    
    // Initialize on page load
    updateTenantSelections();
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>