<?php
// /views/superadmin/users/reset_credentials.php
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

// Process form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['password'] ?? '';
    $new_pin = $_POST['pin'] ?? '';
    
    if (empty($new_password) && empty($new_pin)) {
        $error = 'Please provide at least one credential to reset';
    } else if ($new_pin && !preg_match('/^\d{4,6}$/', $new_pin)) {
        $error = 'PIN must be 4-6 digits';
    } else {
        try {
            $pdo->beginTransaction();
            
            $updates = [];
            $params = [];
            
            if ($new_password) {
                $updates[] = "password_hash = ?";
                $params[] = password_hash($new_password, PASSWORD_DEFAULT);
            }
            
            if ($new_pin && in_array($user['user_type'], ['pos', 'both'])) {
                $updates[] = "pass_code = ?";
                $params[] = password_hash($new_pin, PASSWORD_DEFAULT);
            }
            
            if (!empty($updates)) {
                $params[] = $user_id;
                $sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                
                // Log the credential reset
                $stmt = $pdo->prepare("
                    INSERT INTO super_admin_logs (admin_id, action, tenant_id, details) 
                    VALUES (?, 'credential_reset', NULL, ?)
                ");
                $stmt->execute([
                    $_SESSION['super_admin_id'],
                    json_encode([
                        'user_id' => $user_id,
                        'username' => $user['username'],
                        'credentials_reset' => array_filter(['password' => !empty($new_password), 'pin' => !empty($new_pin)])
                    ])
                ]);
                
                $pdo->commit();
                $success = 'Credentials reset successfully. The user will need to use the new credentials to login.';
            }
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error resetting credentials: ' . $e->getMessage();
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
    
    /* User Card */
    .user-card {
        background: white;
        border-radius: 8px;
        padding: 24px;
        margin-bottom: 24px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
    }
    
    .user-header {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .user-avatar {
        width: 64px;
        height: 64px;
        border-radius: 50%;
        background: linear-gradient(135deg, #0078d4, #40e0d0);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        font-weight: 600;
    }
    
    .user-info h2 {
        font-size: 20px;
        font-weight: 600;
        color: #323130;
        margin: 0 0 8px 0;
    }
    
    .user-meta {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }
    
    .meta-item {
        display: flex;
        align-items: center;
        gap: 4px;
        font-size: 14px;
        color: #605e5c;
    }
    
    .meta-separator {
        color: #a19f9d;
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
    
    .form-header {
        padding: 20px 24px;
        border-bottom: 1px solid #edebe9;
        background: #faf9f8;
    }
    
    .form-title {
        font-size: 16px;
        font-weight: 600;
        color: #323130;
        margin: 0;
    }
    
    .form-body {
        padding: 24px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-label {
        display: block;
        font-size: 14px;
        font-weight: 500;
        color: #323130;
        margin-bottom: 6px;
    }
    
    .form-input {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #d2d0ce;
        border-radius: 4px;
        font-size: 14px;
        background: white;
        transition: all 0.2s;
    }
    
    .form-input:hover {
        border-color: #0078d4;
    }
    
    .form-input:focus {
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
    
    /* Info Box */
    .info-box {
        background: #f3f2f1;
        border: 1px solid #edebe9;
        border-radius: 4px;
        padding: 16px;
        margin-top: 24px;
    }
    
    .info-box-title {
        font-size: 14px;
        font-weight: 600;
        color: #323130;
        margin-bottom: 12px;
    }
    
    .info-list {
        list-style: none;
        padding: 0;
        margin: 0;
    }
    
    .info-list li {
        position: relative;
        padding-left: 20px;
        margin-bottom: 8px;
        font-size: 13px;
        color: #605e5c;
        line-height: 1.5;
    }
    
    .info-list li::before {
        content: '•';
        position: absolute;
        left: 8px;
        color: #a19f9d;
    }
    
    /* Form Actions */
    .form-actions {
        padding: 16px 24px;
        background: #faf9f8;
        border-top: 1px solid #edebe9;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .action-buttons {
        display: flex;
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
    
    .btn-link {
        background: none;
        color: #0078d4;
        border: none;
        padding: 8px 12px;
    }
    
    .btn-link:hover {
        text-decoration: underline;
    }
    
    /* Responsive */
    @media (max-width: 768px) {
        .user-meta {
            flex-direction: column;
            gap: 8px;
        }
        
        .form-actions {
            flex-direction: column;
            gap: 12px;
        }
        
        .action-buttons {
            width: 100%;
        }
        
        .action-buttons .btn {
            flex: 1;
            justify-content: center;
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
            <span>Reset Credentials</span>
        </div>
        <h1 class="page-title">Reset User Credentials</h1>
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
    
    <!-- User Card -->
    <div class="user-card">
        <div class="user-header">
            <div class="user-avatar">
                <?= strtoupper(substr($user['name'], 0, 1)) ?>
            </div>
            <div class="user-info">
                <h2><?= htmlspecialchars($user['name']) ?></h2>
                <div class="user-meta">
                    <span class="meta-item">@<?= htmlspecialchars($user['username']) ?></span>
                    <span class="meta-separator">•</span>
                    <span class="meta-item"><?= htmlspecialchars($user['role_name'] ?? $user['role_key']) ?></span>
                    <span class="meta-separator">•</span>
                    <span class="meta-item">
                        <?php if ($user['user_type'] == 'backend'): ?>
                            Backend Only
                        <?php elseif ($user['user_type'] == 'pos'): ?>
                            POS Only
                        <?php else: ?>
                            Backend & POS
                        <?php endif; ?>
                    </span>
                    <span class="meta-separator">•</span>
                    <span class="meta-item">
                        <?php if ($user['disabled_at']): ?>
                            <span class="status-badge disabled">Disabled</span>
                        <?php else: ?>
                            <span class="status-badge active">Active</span>
                        <?php endif; ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Reset Form -->
    <form method="POST" class="form-card">
        <div class="form-header">
            <h3 class="form-title">Reset Credentials</h3>
        </div>
        
        <div class="form-body">
            <div class="form-group">
                <label class="form-label">New Password</label>
                <div class="password-input-wrapper">
                    <input type="password" name="password" id="password" class="form-input" 
                           placeholder="Enter new password (leave blank to keep current)">
                    <button type="button" class="password-toggle" onclick="togglePassword('password')">
                        <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                    </button>
                </div>
                <div class="form-hint">Minimum 8 characters recommended</div>
            </div>
            
            <?php if (in_array($user['user_type'], ['pos', 'both'])): ?>
            <div class="form-group">
                <label class="form-label">New PIN Code</label>
                <input type="text" name="pin" class="form-input" 
                       placeholder="Enter new PIN (leave blank to keep current)"
                       maxlength="6" 
                       pattern="[0-9]{4,6}">
                <div class="form-hint">4-6 digits for POS access</div>
            </div>
            <?php endif; ?>
            
            <div class="info-box">
                <h4 class="info-box-title">Important Notes:</h4>
                <ul class="info-list">
                    <li>The user will be logged out of all active sessions</li>
                    <li>They will need to use the new credentials to login</li>
                    <li>Consider informing the user of their new credentials</li>
                    <?php if ($user['email']): ?>
                    <li>You can send the new credentials to: <?= htmlspecialchars($user['email']) ?></li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
        
        <div class="form-actions">
            <button type="button" class="btn-link" onclick="generateCredentials()">
                Generate Random Credentials
            </button>
            <div class="action-buttons">
                <a href="/views/superadmin/users/index.php" class="btn btn-secondary">Cancel</a>
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                    </svg>
                    Reset Credentials
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    // Toggle password visibility
    function togglePassword(fieldId) {
        const field = document.getElementById(fieldId);
        field.type = field.type === 'password' ? 'text' : 'password';
    }
    
    // Generate random credentials
    function generateCredentials() {
        // Generate random password
        const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
        let password = '';
        for (let i = 0; i < 12; i++) {
            password += chars.charAt(Math.floor(Math.random() * chars.length));
        }
        document.getElementById('password').value = password;
        document.getElementById('password').type = 'text';
        
        // Generate random PIN if applicable
        const pinInput = document.querySelector('input[name="pin"]');
        if (pinInput) {
            const pin = Math.floor(Math.random() * 900000) + 100000;
            pinInput.value = pin.toString();
        }
    }
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>