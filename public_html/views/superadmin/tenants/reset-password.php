<?php
/**
 * SME 180 - Reset Tenant User Passwords
 * Path: /views/superadmin/tenants/reset-password.php
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

// Get all users for this tenant
$stmt = $pdo->prepare("
    SELECT u.*, 
           (SELECT GROUP_CONCAT(b.name SEPARATOR ', ') 
            FROM branches b 
            JOIN user_branches ub ON b.id = ub.branch_id 
            WHERE ub.user_id = u.id) as branches
    FROM users u
    WHERE u.tenant_id = ?
    ORDER BY u.role_key = 'admin' DESC, u.created_at DESC
");
$stmt->execute([$tenant_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$message_type = '';
$reset_details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'reset_single') {
            $user_id = (int)($_POST['user_id'] ?? 0);
            $new_password = trim($_POST['new_password'] ?? '');
            
            if (!$user_id) {
                throw new Exception('Invalid user selected');
            }
            
            if (empty($new_password)) {
                $new_password = 'Pass@' . bin2hex(random_bytes(4));
            }
            
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND tenant_id = ?");
            $stmt->execute([$user_id, $tenant_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                throw new Exception('User not found');
            }
            
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([password_hash($new_password, PASSWORD_DEFAULT), $user_id]);
            
            $reset_details[] = [
                'username' => $user['username'],
                'password' => $new_password,
                'name' => $user['name']
            ];
            
            $message = "Password reset successfully!";
            $message_type = 'success';
            
        } elseif ($action === 'reset_all') {
            $pdo->beginTransaction();
            
            foreach ($users as $user) {
                $new_password = 'Pass@' . bin2hex(random_bytes(4));
                
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([password_hash($new_password, PASSWORD_DEFAULT), $user['id']]);
                
                $reset_details[] = [
                    'username' => $user['username'],
                    'password' => $new_password,
                    'name' => $user['name'],
                    'role' => $user['role_key']
                ];
            }
            
            $pdo->commit();
            $message = "All passwords reset successfully!";
            $message_type = 'success';
            
        } elseif ($action === 'reset_admin') {
            $stmt = $pdo->prepare("
                SELECT * FROM users 
                WHERE tenant_id = ? AND role_key = 'admin' 
                ORDER BY created_at ASC 
                LIMIT 1
            ");
            $stmt->execute([$tenant_id]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$admin) {
                throw new Exception('No admin user found for this tenant');
            }
            
            $new_password = 'Admin@' . bin2hex(random_bytes(4));
            
            $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([password_hash($new_password, PASSWORD_DEFAULT), $admin['id']]);
            
            $reset_details[] = [
                'username' => $admin['username'],
                'password' => $new_password,
                'name' => $admin['name']
            ];
            
            $message = "Admin password reset successfully!";
            $message_type = 'success';
        }
        
        // Log action
        try {
            $stmt = $pdo->prepare("
                INSERT INTO super_admin_logs (admin_id, action, details, tenant_id, ip_address, created_at)
                VALUES (?, 'reset_password', ?, ?, ?, NOW())
            ");
            $stmt->execute([
                $_SESSION['super_admin_id'],
                json_encode(['action' => $action, 'affected_users' => count($reset_details)]),
                $tenant_id,
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);
        } catch (Exception $e) {
            // Logging failed, continue
        }
        
    } catch (Exception $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'error';
    }
}
?>

<style>
    .reset-container {
        padding: 24px;
        max-width: 1200px;
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
    
    .page-subtitle {
        font-size: 14px;
        color: #6B7280;
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
    
    .warning-box {
        background: #FEF3C7;
        border-left: 4px solid #F59E0B;
        padding: 16px;
        margin-bottom: 20px;
        border-radius: 6px;
        font-size: 14px;
        color: #92400E;
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
    
    .credentials-box {
        background: #D1FAE5;
        border: 1px solid #6EE7B7;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 24px;
    }
    
    .credentials-box h3 {
        color: #065F46;
        margin-bottom: 16px;
        font-size: 16px;
    }
    
    .credential-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px;
        background: white;
        border-radius: 6px;
        margin-bottom: 8px;
        border: 1px solid #D1D5DB;
    }
    
    .credential-info {
        display: flex;
        align-items: center;
        gap: 16px;
    }
    
    .credential-password {
        font-family: monospace;
        font-size: 14px;
        background: #F3F4F6;
        padding: 4px 8px;
        border-radius: 4px;
    }
    
    .user-list {
        margin-top: 20px;
    }
    
    .user-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 16px;
        border: 1px solid #E5E7EB;
        border-radius: 8px;
        margin-bottom: 12px;
        transition: all 0.2s;
    }
    
    .user-item:hover {
        background: #F9FAFB;
    }
    
    .user-info {
        flex: 1;
    }
    
    .user-name {
        font-weight: 600;
        color: #111827;
        margin-bottom: 4px;
    }
    
    .user-details {
        font-size: 13px;
        color: #6B7280;
    }
    
    .badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        margin-left: 8px;
    }
    
    .badge-admin { background: #FEF3C7; color: #92400E; }
    .badge-manager { background: #EDE9FE; color: #6D28D9; }
    .badge-user { background: #DBEAFE; color: #1E3A8A; }
    
    .action-buttons {
        display: flex;
        gap: 12px;
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
    
    .btn-large {
        padding: 12px 24px;
        font-size: 15px;
    }
    
    .btn-primary { background: #7c3aed; color: white; }
    .btn-primary:hover { background: #6d28d9; }
    .btn-warning { background: #F59E0B; color: white; }
    .btn-warning:hover { background: #D97706; }
    .btn-danger { background: #EF4444; color: white; }
    .btn-danger:hover { background: #DC2626; }
    .btn-secondary { background: white; color: #6B7280; border: 1px solid #D1D5DB; }
    .btn-secondary:hover { background: #F9FAFB; }
    
    .copy-btn {
        background: #7c3aed;
        color: white;
        border: none;
        padding: 4px 12px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 12px;
    }
    
    .copy-btn:hover {
        background: #6d28d9;
    }
    
    .modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    
    .modal.active {
        display: flex;
    }
    
    .modal-content {
        background: white;
        border-radius: 12px;
        padding: 24px;
        max-width: 500px;
        width: 90%;
    }
    
    .modal-header {
        margin-bottom: 20px;
    }
    
    .modal-title {
        font-size: 18px;
        font-weight: 600;
        color: #111827;
    }
    
    .form-group {
        margin-bottom: 16px;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        font-size: 14px;
        font-weight: 500;
        color: #374151;
    }
    
    .form-group input {
        width: 100%;
        padding: 8px 12px;
        border: 1px solid #D1D5DB;
        border-radius: 6px;
        font-size: 14px;
    }
</style>

<div class="reset-container">
    <div class="page-header">
        <h1 class="page-title">Reset Passwords</h1>
        <p class="page-subtitle">Tenant: <?= htmlspecialchars($tenant['name']) ?></p>
    </div>
    
    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($reset_details)): ?>
    <div class="credentials-box">
        <h3>New Credentials - Please save these securely!</h3>
        <?php foreach ($reset_details as $detail): ?>
        <div class="credential-item">
            <div class="credential-info">
                <strong><?= htmlspecialchars($detail['name'] ?? $detail['username']) ?></strong>
                <span style="color: #6B7280;">@<?= htmlspecialchars($detail['username']) ?></span>
            </div>
            <div style="display: flex; align-items: center; gap: 8px;">
                <span class="credential-password" id="pwd_<?= md5($detail['username']) ?>">
                    <?= htmlspecialchars($detail['password']) ?>
                </span>
                <button class="copy-btn" onclick="copyPassword('pwd_<?= md5($detail['username']) ?>')">Copy</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Quick Actions</h3>
        </div>
        <div class="card-body">
            <div class="warning-box">
                <strong>⚠️ Warning:</strong> Resetting passwords will immediately invalidate current passwords. Users will need to use the new passwords to login.
            </div>
            
            <div class="action-buttons">
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="reset_admin">
                    <button type="submit" class="btn btn-warning btn-large" 
                            onclick="return confirm('Reset admin user password?')">
                        Reset Admin Password
                    </button>
                </form>
                
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="reset_all">
                    <button type="submit" class="btn btn-danger btn-large" 
                            onclick="return confirm('Reset ALL user passwords for this tenant? This cannot be undone.')">
                        Reset All Passwords
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Users (<?= count($users) ?>)</h3>
        </div>
        <div class="card-body">
            <div class="user-list">
                <?php if (empty($users)): ?>
                <p style="text-align: center; color: #6B7280; padding: 32px;">No users found for this tenant</p>
                <?php else: ?>
                <?php foreach ($users as $user): ?>
                <div class="user-item">
                    <div class="user-info">
                        <div class="user-name">
                            <?= htmlspecialchars($user['name']) ?>
                            <span class="badge badge-<?= strpos($user['role_key'], 'admin') !== false ? 'admin' : (strpos($user['role_key'], 'manager') !== false ? 'manager' : 'user') ?>">
                                <?= htmlspecialchars($user['role_key']) ?>
                            </span>
                        </div>
                        <div class="user-details">
                            Username: <?= htmlspecialchars($user['username']) ?>
                            <?php if ($user['email']): ?>
                            • Email: <?= htmlspecialchars($user['email']) ?>
                            <?php endif; ?>
                            <?php if ($user['branches']): ?>
                            • Branches: <?= htmlspecialchars($user['branches']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div>
                        <button class="btn btn-primary" onclick="showResetModal(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username'], ENT_QUOTES) ?>')">
                            Reset Password
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div style="margin-top: 24px;">
        <a href="/views/superadmin/tenants/view.php?id=<?= $tenant_id ?>" class="btn btn-secondary btn-large">← Back to Tenant</a>
    </div>
</div>

<!-- Reset Single User Modal -->
<div id="resetModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2 class="modal-title">Reset User Password</h2>
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="reset_single">
            <input type="hidden" name="user_id" id="reset_user_id">
            
            <p style="margin-bottom: 20px;">Resetting password for: <strong id="reset_username"></strong></p>
            
            <div class="form-group">
                <label>New Password (leave blank to auto-generate)</label>
                <input type="text" name="new_password" placeholder="Auto-generate secure password">
            </div>
            
            <div class="action-buttons">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-primary">Reset Password</button>
            </div>
        </form>
    </div>
</div>

<script>
function showResetModal(userId, username) {
    document.getElementById('reset_user_id').value = userId;
    document.getElementById('reset_username').textContent = username;
    document.getElementById('resetModal').classList.add('active');
}

function closeModal() {
    document.getElementById('resetModal').classList.remove('active');
}

function copyPassword(elementId) {
    const element = document.getElementById(elementId);
    const text = element.textContent.trim();
    
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(() => {
            // Show temporary success message
            const btn = event.target;
            const originalText = btn.textContent;
            btn.textContent = 'Copied!';
            setTimeout(() => {
                btn.textContent = originalText;
            }, 2000);
        });
    } else {
        // Fallback for older browsers
        const textarea = document.createElement('textarea');
        textarea.value = text;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
    }
}

// Close modal when clicking outside
document.getElementById('resetModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

<?php
require_once dirname(__DIR__) . '/includes/footer.php';
?>