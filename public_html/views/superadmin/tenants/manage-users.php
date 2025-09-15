<?php
// /views/superadmin/tenants/manage-users.php
declare(strict_types=1);
require_once __DIR__ . '/../../../config/db.php';

// Check if user is super admin
use_backend_session();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'super_admin') {
    redirect('/views/auth/login.php');
    exit;
}

$pdo = db();
$tenant_id = isset($_GET['tenant_id']) ? intval($_GET['tenant_id']) : 0;
$message = '';
$message_type = '';

// Get tenant info
$stmt = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
$stmt->execute([$tenant_id]);
$tenant = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$tenant) {
    header('Location: /views/superadmin/tenants/index.php');
    exit;
}

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = intval($_POST['user_id'] ?? 0);
    
    try {
        switch ($action) {
            case 'reset_password':
                $new_password = bin2hex(random_bytes(4)) . '!';
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([password_hash($new_password, PASSWORD_DEFAULT), $user_id, $tenant_id]);
                $message = "Password reset to: $new_password";
                $message_type = 'success';
                break;
                
            case 'reset_pin':
                $new_pin = str_pad(strval(rand(0, 9999)), 4, '0', STR_PAD_LEFT);
                $stmt = $pdo->prepare("UPDATE users SET pass_code = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([password_hash($new_pin, PASSWORD_DEFAULT), $user_id, $tenant_id]);
                $message = "PIN reset to: $new_pin";
                $message_type = 'success';
                break;
                
            case 'disable_user':
                $stmt = $pdo->prepare("UPDATE users SET disabled_at = NOW(), disabled_by = ? WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$_SESSION['super_admin_id'], $user_id, $tenant_id]);
                $message = "User disabled successfully";
                $message_type = 'success';
                break;
                
            case 'enable_user':
                $stmt = $pdo->prepare("UPDATE users SET disabled_at = NULL, disabled_by = NULL WHERE id = ? AND tenant_id = ?");
                $stmt->execute([$user_id, $tenant_id]);
                $message = "User enabled successfully";
                $message_type = 'success';
                break;
                
            case 'create_user':
                $username = trim($_POST['username'] ?? '');
                $name = trim($_POST['name'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $role_key = $_POST['role_key'] ?? 'pos_waiter';
                $password = trim($_POST['password'] ?? '');
                
                if (empty($username) || empty($name)) {
                    throw new Exception('Username and name are required');
                }
                
                if (empty($password)) {
                    $password = 'Temp' . bin2hex(random_bytes(4)) . '!';
                }
                
                $stmt = $pdo->prepare("
                    INSERT INTO users (tenant_id, username, name, email, password_hash, role_key)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $tenant_id,
                    $username,
                    $name,
                    $email ?: null,
                    password_hash($password, PASSWORD_DEFAULT),
                    $role_key
                ]);
                
                $message = "User created successfully. Password: $password";
                $message_type = 'success';
                break;
        }
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $message_type = 'error';
    }
}

// Get users for this tenant
$stmt = $pdo->prepare("
    SELECT u.*, r.name as role_name,
           (SELECT COUNT(*) FROM user_branches WHERE user_id = u.id) as branch_count
    FROM users u
    LEFT JOIN roles r ON u.role_key = r.role_key
    WHERE u.tenant_id = ?
    ORDER BY u.created_at DESC
");
$stmt->execute([$tenant_id]);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get available roles
$stmt = $pdo->query("SELECT DISTINCT role_key, name FROM roles ORDER BY name");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Users - <?= htmlspecialchars($tenant['name']) ?> - SME 180</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        :root {
            --primary: #8B5CF6;
            --primary-dark: #7C3AED;
            --primary-light: #A78BFA;
            --secondary: #06B6D4;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --dark: #1F2937;
            --gray-dark: #4B5563;
            --gray: #6B7280;
            --gray-light: #9CA3AF;
            --gray-lighter: #E5E7EB;
            --white: #FFFFFF;
            --bg-light: #F9FAFB;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--bg-light);
            color: var(--dark);
            line-height: 1.6;
        }
        
        /* Header */
        .header {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: white;
            padding: 1.5rem 0;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .header-content {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            text-decoration: none;
            color: white;
        }
        
        .logo-icon {
            width: 40px;
            height: 40px;
            background: rgba(255,255,255,0.2);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .logo-text {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .header-nav {
            display: flex;
            gap: 1rem;
        }
        
        .nav-link {
            color: white;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            transition: background 0.2s;
        }
        
        .nav-link:hover {
            background: rgba(255,255,255,0.1);
        }
        
        /* Container */
        .container {
            max-width: 1400px;
            margin: 2rem auto;
            padding: 0 2rem;
        }
        
        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 2rem;
            font-size: 0.875rem;
            color: var(--gray);
        }
        
        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }
        
        .breadcrumb a:hover {
            text-decoration: underline;
        }
        
        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--dark);
        }
        
        .tenant-info {
            background: white;
            padding: 1rem 1.5rem;
            border-radius: 0.5rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .tenant-name {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .tenant-plan {
            font-size: 0.875rem;
            color: var(--gray);
        }
        
        /* Alert */
        .alert {
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .alert-success {
            background: #D1FAE5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }
        
        .alert-error {
            background: #FEE2E2;
            color: var(--danger);
            border: 1px solid #FECACA;
        }
        
        /* Card */
        .card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .card-header {
            padding: 1.5rem;
            border-bottom: 1px solid var(--gray-lighter);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        /* Table */
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: var(--bg-light);
            padding: 0.75rem 1rem;
            text-align: left;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--gray);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        td {
            padding: 1rem;
            border-top: 1px solid var(--gray-lighter);
            font-size: 0.875rem;
        }
        
        tbody tr:hover {
            background: var(--bg-light);
        }
        
        /* User Info */
        .user-info {
            display: flex;
            flex-direction: column;
        }
        
        .user-name {
            font-weight: 600;
            color: var(--dark);
        }
        
        .user-username {
            font-size: 0.75rem;
            color: var(--gray);
        }
        
        /* Status Badge */
        .status-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-active {
            background: #D1FAE5;
            color: #065F46;
        }
        
        .status-disabled {
            background: #FEE2E2;
            color: #991B1B;
        }
        
        /* Role Badge */
        .role-badge {
            display: inline-block;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--primary);
            color: white;
        }
        
        .role-badge.admin {
            background: var(--danger);
        }
        
        .role-badge.manager {
            background: var(--warning);
        }
        
        /* Actions */
        .action-buttons {
            display: flex;
            gap: 0.25rem;
        }
        
        .btn {
            padding: 0.5rem 1rem;
            border: none;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .btn-sm {
            padding: 0.25rem 0.5rem;
            font-size: 0.625rem;
        }
        
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
        }
        
        .btn-secondary {
            background: white;
            color: var(--gray-dark);
            border: 1px solid var(--gray-lighter);
        }
        
        .btn-secondary:hover {
            background: var(--bg-light);
        }
        
        .btn-danger {
            background: var(--danger);
            color: white;
        }
        
        .btn-danger:hover {
            background: #DC2626;
        }
        
        .btn-success {
            background: var(--success);
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 0.5rem;
            padding: 2rem;
            max-width: 500px;
            width: 90%;
        }
        
        .modal-header {
            margin-bottom: 1.5rem;
        }
        
        .modal-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--dark);
        }
        
        .form-group {
            margin-bottom: 1rem;
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--gray-dark);
            margin-bottom: 0.25rem;
        }
        
        .form-input {
            width: 100%;
            padding: 0.5rem;
            border: 1px solid var(--gray-lighter);
            border-radius: 0.375rem;
            font-size: 0.875rem;
        }
        
        .form-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <div class="header-content">
            <a href="/views/superadmin/dashboard.php" class="logo">
                <div class="logo-icon">🚀</div>
                <span class="logo-text">SME 180</span>
            </a>
            <nav class="header-nav">
                <a href="/views/superadmin/dashboard.php" class="nav-link">Dashboard</a>
                <a href="/views/superadmin/tenants/index.php" class="nav-link">Tenants</a>
                <a href="/views/superadmin/subscription-plans.php" class="nav-link">Plans</a>
                <a href="/views/superadmin/billing/index.php" class="nav-link">Billing</a>
                <a href="/views/superadmin/security/index.php" class="nav-link">Security</a>
                <a href="/views/superadmin/audit_logs.php" class="nav-link">Audit</a>
            </nav>
        </div>
    </header>
    
    <div class="container">
        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="/views/superadmin/dashboard.php">Dashboard</a>
            <span>/</span>
            <a href="/views/superadmin/tenants/index.php">Tenants</a>
            <span>/</span>
            <span>Manage Users</span>
        </div>
        
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Manage Users</h1>
            <div class="tenant-info">
                <div class="tenant-name"><?= htmlspecialchars($tenant['name']) ?></div>
                <div class="tenant-plan">Plan: <?= ucfirst($tenant['subscription_plan']) ?> • Max Users: <?= $tenant['max_users'] ?></div>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>">
            <?= $message_type === 'success' ? '✓' : '⚠️' ?> <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <!-- Users Table -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Users (<?= count($users) ?> / <?= $tenant['max_users'] ?>)</h3>
                <button class="btn btn-primary" onclick="openCreateModal()">
                    ➕ Create User
                </button>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>User</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Branches</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                    <tr>
                        <td>#<?= $user['id'] ?></td>
                        <td>
                            <div class="user-info">
                                <span class="user-name"><?= htmlspecialchars($user['name']) ?></span>
                                <span class="user-username">@<?= htmlspecialchars($user['username']) ?></span>
                            </div>
                        </td>
                        <td><?= htmlspecialchars($user['email'] ?? '-') ?></td>
                        <td>
                            <span class="role-badge <?= $user['role_key'] ?>">
                                <?= htmlspecialchars($user['role_name'] ?? $user['role_key']) ?>
                            </span>
                        </td>
                        <td><?= $user['branch_count'] ?></td>
                        <td>
                            <?php if ($user['disabled_at']): ?>
                            <span class="status-badge status-disabled">Disabled</span>
                            <?php else: ?>
                            <span class="status-badge status-active">Active</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $user['last_login'] ? date('M d, Y', strtotime($user['last_login'])) : 'Never' ?></td>
                        <td>
                            <div class="action-buttons">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Reset password for this user?')">
                                        🔑 Reset Password
                                    </button>
                                </form>
                                
                                <?php if ($user['pass_code']): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <input type="hidden" name="action" value="reset_pin">
                                    <button type="submit" class="btn btn-secondary btn-sm" onclick="return confirm('Reset PIN for this user?')">
                                        🔢 Reset PIN
                                    </button>
                                </form>
                                <?php endif; ?>
                                
                                <?php if ($user['disabled_at']): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <input type="hidden" name="action" value="enable_user">
                                    <button type="submit" class="btn btn-success btn-sm">
                                        ✓ Enable
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                    <input type="hidden" name="action" value="disable_user">
                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Disable this user?')">
                                        🚫 Disable
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 2rem; color: var(--gray);">
                            No users found for this tenant
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Create User Modal -->
    <div id="createModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title">Create New User</h3>
            </div>
            <form method="POST">
                <input type="hidden" name="action" value="create_user">
                
                <div class="form-group">
                    <label class="form-label">Username *</label>
                    <input type="text" name="username" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Full Name *</label>
                    <input type="text" name="name" class="form-input" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email</label>
                    <input type="email" name="email" class="form-input">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Role</label>
                    <select name="role_key" class="form-input">
                        <?php foreach ($roles as $role): ?>
                        <option value="<?= htmlspecialchars($role['role_key']) ?>">
                            <?= htmlspecialchars($role['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password (leave blank for auto-generate)</label>
                    <input type="text" name="password" class="form-input">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeCreateModal()">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create User</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        function openCreateModal() {
            document.getElementById('createModal').classList.add('active');
        }
        
        function closeCreateModal() {
            document.getElementById('createModal').classList.remove('active');
        }
        
        // Close modal on outside click
        document.getElementById('createModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeCreateModal();
            }
        });
    </script>
</body>
</html>