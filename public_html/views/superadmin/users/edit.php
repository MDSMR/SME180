<?php
// /views/superadmin/users/edit.php
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

// Get user ID
$user_id = (int)($_GET['id'] ?? 0);
if (!$user_id) {
    header('Location: /views/superadmin/users/index.php');
    exit;
}

// Fetch user data
$stmt = $pdo->prepare("
    SELECT u.*, r.name as role_name 
    FROM users u 
    LEFT JOIN roles r ON u.role_key = r.role_key 
    WHERE u.id = ?
");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: /views/superadmin/users/index.php');
    exit;
}

// Get user's tenants
$stmt = $pdo->prepare("
    SELECT ut.tenant_id, ut.is_primary, t.name 
    FROM user_tenants ut 
    JOIN tenants t ON ut.tenant_id = t.id 
    WHERE ut.user_id = ? 
    ORDER BY ut.is_primary DESC, t.name
");
$stmt->execute([$user_id]);
$user_tenants   = $stmt->fetchAll(PDO::FETCH_ASSOC);
$user_tenant_ids = array_column($user_tenants, 'tenant_id');
$primary_tenant_id = null;
foreach ($user_tenants as $ut) {
    if ($ut['is_primary']) {
        $primary_tenant_id = $ut['tenant_id'];
        break;
    }
}

// Get user's branches
$stmt = $pdo->prepare("
    SELECT ub.branch_id, ub.tenant_id, b.name as branch_name, t.name as tenant_name 
    FROM user_branches ub 
    JOIN branches b ON ub.branch_id = b.id 
    JOIN tenants t ON b.tenant_id = t.id 
    WHERE ub.user_id = ? 
    ORDER BY t.name, b.name
");
$stmt->execute([$user_id]);
$user_branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
$user_branch_ids = array_column($user_branches, 'branch_id');

// Get all tenants
$tenants = $pdo->query("
    SELECT id, name, max_users 
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

// Get all branches grouped by tenant
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
    $name              = trim($_POST['name'] ?? '');
    $username          = trim($_POST['username'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $user_type         = $_POST['user_type'] ?? 'pos';
    $role_key          = $_POST['role_key'] ?? '';
    $new_password      = $_POST['password'] ?? '';
    $new_pin           = $_POST['pin'] ?? '';
    $selected_tenants  = $_POST['tenants'] ?? [];
    $primary_tenant    = $_POST['primary_tenant'] ?? '';
    $selected_branches = $_POST['branches'] ?? [];
    
    // Validation
    if (!$name || !$username || !$role_key) {
        $error = 'Name, username, and role are required';
    } else if (empty($selected_tenants)) {
        $error = 'At least one tenant must be selected';
    } else if (!$primary_tenant || !in_array($primary_tenant, $selected_tenants)) {
        $error = 'Primary tenant must be selected from assigned tenants';
    } else if (in_array($user_type, ['pos', 'both']) && $new_pin && !preg_match('/^\d{4,6}$/', $new_pin)) {
        $error = 'PIN must be 4–6 digits';
    } else {
        // Check username uniqueness (excluding current user)
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM users u 
            JOIN user_tenants ut ON u.id = ut.user_id 
            WHERE u.username = ? 
            AND u.id != ? 
            AND ut.tenant_id IN (" . implode(',', array_map('intval', $selected_tenants)) . ")
        ");
        $stmt->execute([$username, $user_id]);
        if ($stmt->fetchColumn() > 0) {
            $error = 'Username already exists in one of the selected tenants';
        } else {
            try {
                $pdo->beginTransaction();
                
                // Update user
                $update_fields = [
                    'name'      => $name,
                    'username'  => $username,
                    'email'     => $email ?: null,
                    'user_type' => $user_type,
                    'role_key'  => $role_key
                ];
                
                $sql = "UPDATE users SET 
                        name = :name,
                        username = :username,
                        email = :email,
                        user_type = :user_type,
                        role_key = :role_key";
                
                if ($new_password) {
                    $sql .= ", password_hash = :password_hash";
                    $update_fields['password_hash'] = password_hash($new_password, PASSWORD_DEFAULT);
                }
                
                if ($new_pin && in_array($user_type, ['pos', 'both'])) {
                    $sql .= ", pass_code = :pass_code";
                    $update_fields['pass_code'] = password_hash($new_pin, PASSWORD_DEFAULT);
                }
                
                $sql .= " WHERE id = :id";
                $update_fields['id'] = $user_id;
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute($update_fields);
                
                // Update user_tenants
                $pdo->prepare("DELETE FROM user_tenants WHERE user_id = ?")->execute([$user_id]);
                foreach ($selected_tenants as $tenant_id) {
                    $is_primary = ($tenant_id == $primary_tenant) ? 1 : 0;
                    $stmt = $pdo->prepare("
                        INSERT INTO user_tenants (user_id, tenant_id, is_primary) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$user_id, $tenant_id, $is_primary]);
                }
                
                // Update user_branches
                $pdo->prepare("DELETE FROM user_branches WHERE user_id = ?")->execute([$user_id]);
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
                
                // Log the update
                $stmt = $pdo->prepare("
                    INSERT INTO super_admin_logs (admin_id, action, tenant_id, details) 
                    VALUES (?, 'user_update', NULL, ?)
                ");
                $stmt->execute([
                    $_SESSION['super_admin_id'],
                    json_encode([
                        'user_id'  => $user_id,
                        'username' => $username,
                        'changes'  => array_keys($update_fields)
                    ])
                ]);
                
                $pdo->commit();
                header("Location: /views/superadmin/users/edit.php?id=$user_id&success=1");
                exit;
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error updating user: ' . $e->getMessage();
            }
        }
    }
}

if (isset($_GET['success'])) {
    $success = 'User updated successfully';
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
    
    .alert-success {
        background: #dff6dd;
        color: #0e700e;
        border-left: 4px solid #0e700e;
    }
    
    .alert-error {
        background: #fde7e9;
        color: #a80000;
        border-left: 4px solid #a80000;
    }
    
    .alert-icon {
        flex-shrink: 0;
    }
    
    /* User Info Card */
    .info-card {
        background: white;
        border-radius: 8px;
        padding: 20px 24px;
        margin-bottom: 24px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 16px;
    }
    
    .info-item {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    
    .info-label {
        font-size: 12px;
        font-weight: 600;
        color: #605e5c;
        text-transform: uppercase;
    }
    
    .info-value {
        font-size: 14px;
        color: #323130;
    }
    
    .status-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 12px;
        font-weight: 500;
    }
    
    .status-badge.active {
        background: #dff6dd;
        color: #0e700e;
    }
    
    .status-badge.disabled {
        background: #fde7e9;
        color: #a80000;
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
    
    .help-text {
        font-size: 12px;
        color: #797775;
        margin-top: 4px;
        font-style: italic;
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
        
        .info-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="main-content">
    <!-- Content Header -->
    <div class="content-header">
        <!-- Breadcrumb removed as requested -->
        <h1 class="page-title">Edit User: <?= htmlspecialchars($user['name']) ?></h1>
    </div>
    
    <?php if ($success): ?>
    <div class="alert alert-success">
        <svg class="alert-icon" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"/>
        </svg>
        <div><?= htmlspecialchars($success) ?></div>
    </div>
    <?php endif; ?>
    
    <?php if ($error): ?>
    <div class="alert alert-error">
        <svg class="alert-icon" width="20" height="20" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"/>
        </svg>
        <div><?= htmlspecialchars($error) ?></div>
    </div>
    <?php endif; ?>
    
    <!-- User Info Card -->
    <div class="info-card">
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">User ID</span>
                <span class="info-value">#<?= $user['id'] ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Created</span>
                <span class="info-value"><?= date('M j, Y g:i A', strtotime($user['created_at'])) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Last Login</span>
                <span class="info-value">
                    <?= $user['last_login'] ? date('M j, Y g:i A', strtotime($user['last_login'])) : 'Never' ?>
                </span>
            </div>
            <div class="info-item">
                <span class="info-label">Status</span>
                <span class="info-value">
                    <?php if ($user['disabled_at']): ?>
                        <span class="status-badge disabled">Disabled</span>
                    <?php else: ?>
                        <span class="status-badge active">Active</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>
    </div>
    
    <!-- Form -->
    <form method="POST" class="form-card">
        <!-- Basic Information Section -->
        <div class="form-section">
            <h2 class="section-title">Basic Information</h2>
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label required">Full Name</label>
                    <input type="text" name="name" class="form-input" 
                           value="<?= htmlspecialchars($user['name']) ?>" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Username</label>
                    <input type="text" name="username" class="form-input" 
                           value="<?= htmlspecialchars($user['username']) ?>" required>
                    <span class="form-hint">Must be unique within assigned tenants</span>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" class="form-input" 
                           value="<?= htmlspecialchars($user['email'] ?? '') ?>">
                </div>
                
                <div class="form-group">
                    <label class="form-label required">User Type</label>
                    <select name="user_type" id="userType" class="form-select" required>
                        <option value="backend" <?= $user['user_type'] == 'backend' ? 'selected' : '' ?>>Backend Only</option>
                        <option value="pos" <?= $user['user_type'] == 'pos' ? 'selected' : '' ?>>POS Only</option>
                        <option value="both" <?= $user['user_type'] == 'both' ? 'selected' : '' ?>>Both Backend & POS</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label required">Role</label>
                    <select name="role_key" class="form-select" required>
                        <option value="">Select Role</option>
                        <?php foreach ($roles as $role): ?>
                        <option value="<?= $role['role_key'] ?>" <?= $user['role_key'] == $role['role_key'] ? 'selected' : '' ?>>
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
                    <label class="form-label">New Password</label>
                    <div class="password-input-wrapper">
                        <input type="password" name="password" id="password" class="form-input" 
                               placeholder="Enter new password">
                        <button type="button" class="password-toggle" onclick="togglePassword('password')">
                            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </button>
                    </div>
                    <span class="help-text">Leave blank to keep current password</span>
                </div>
                
        <div class="form-group" id="pinGroup" style="display: <?= in_array($user['user_type'], ['pos', 'both']) ? 'block' : 'none' ?>;">
                    <label class="form-label">New PIN Code</label>
                    <input type="text" name="pin" class="form-input" 
                           placeholder="Enter new PIN" maxlength="6" pattern="[0-9]{4,6}">
                    <span class="help-text">Leave blank to keep current PIN</span>
                </div>
            </div>
        </div>
        
        <!-- Tenant Assignment Section -->
        <div class="form-section">
            <h2 class="section-title">Tenant Assignment</h2>
            <div class="form-grid">
                <div class="form-group full-width">
                    <label class="form-label required">Assigned Tenants</label>
                    <div class="checkbox-grid">
                        <?php foreach ($tenants as $tenant): ?>
                        <div class="checkbox-item">
                            <input type="checkbox" name="tenants[]" value="<?= $tenant['id'] ?>" 
                                   id="tenant_<?= $tenant['id'] ?>" class="tenant-checkbox"
                                   <?= in_array($tenant['id'], $user_tenant_ids) ? 'checked' : '' ?>>
                            <label for="tenant_<?= $tenant['id'] ?>" class="checkbox-label">
                                <?= htmlspecialchars($tenant['name']) ?>
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
                        <option value="<?= $tenant['id'] ?>" class="primary-option" 
                                data-tenant="<?= $tenant['id'] ?>"
                                <?= $primary_tenant_id == $tenant['id'] ? 'selected' : '' ?>
                                style="display: <?= in_array($tenant['id'], $user_tenant_ids) ? 'block' : 'none' ?>;">
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
                <label class="form-label">Assigned Branches</label>
                <div id="branchContainer">
                    <?php foreach ($tenants as $tenant): ?>
                        <?php if (in_array($tenant['id'], $user_tenant_ids)): ?>
                        <div class="branch-section" id="branches_tenant_<?= $tenant['id'] ?>" 
                             style="display: <?= in_array($tenant['id'], $user_tenant_ids) ? 'block' : 'none' ?>;">
                            <div class="branch-section-title"><?= htmlspecialchars($tenant['name']) ?> Branches:</div>
                            <div class="checkbox-grid">
                                <?php if (empty($branches[$tenant['id']])): ?>
                                    <span style="color: #605e5c;">No branches available</span>
                                <?php else: ?>
                                    <?php foreach ($branches[$tenant['id']] as $branch): ?>
                                    <div class="checkbox-item">
                                        <input type="checkbox" name="branches[]" value="<?= $branch['id'] ?>" 
                                               id="branch_<?= $branch['id'] ?>"
                                               <?= in_array($branch['id'], $user_branch_ids) ? 'checked' : '' ?>>
                                        <label for="branch_<?= $branch['id'] ?>" class="checkbox-label">
                                            <?= htmlspecialchars($branch['name']) ?>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <a href="/views/superadmin/users/index.php" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                Update User
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
        if (this.value === 'pos' || this.value === 'both') {
            pinGroup.style.display = 'block';
        } else {
            pinGroup.style.display = 'none';
        }
    });
    
    // Handle tenant selection
    const tenantCheckboxes = document.querySelectorAll('.tenant-checkbox');
    const primarySelect = document.getElementById('primaryTenant');
    const branchesData = <?= json_encode($branches) ?>;
    
    tenantCheckboxes.forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const tenantId = this.value;
            const option = primarySelect.querySelector(`option[data-tenant="${tenantId}"]`);
            const branchSection = document.getElementById(`branches_tenant_${tenantId}`);
            
            if (this.checked) {
                // Show in primary tenant dropdown
                if (option) option.style.display = 'block';
                
                // Create/show branch section if not exists
                if (!branchSection) {
                    createBranchSection(tenantId);
                } else {
                    branchSection.style.display = 'block';
                }
            } else {
                // Hide from primary tenant dropdown
                if (option) {
                    option.style.display = 'none';
                    if (primarySelect.value === tenantId) {
                        primarySelect.value = '';
                    }
                }
                
                // Hide branch section
                if (branchSection) {
                    branchSection.style.display = 'none';
                    // Uncheck all branches
                    branchSection.querySelectorAll('input[type="checkbox"]').forEach(cb => {
                        cb.checked = false;
                    });
                }
            }
            
            // Auto-select primary if only one tenant selected
            const checkedTenants = document.querySelectorAll('.tenant-checkbox:checked');
            if (checkedTenants.length === 1) {
                primarySelect.value = checkedTenants[0].value;
            }
        });
    });
    
    function createBranchSection(tenantId) {
        const container = document.getElementById('branchContainer');
        const tenantData = <?= json_encode($tenants) ?>;
        const tenant = tenantData.find(t => t.id == tenantId);
        const branches = branchesData[tenantId] || [];
        
        const section = document.createElement('div');
        section.className = 'branch-section';
        section.id = `branches_tenant_${tenantId}`;
        
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
    }
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>