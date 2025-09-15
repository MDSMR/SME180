<?php
/**
 * Diagnostic file to check and set super admin session
 * Upload this as: /views/superadmin/check_session.php
 * 
 * This file will:
 * 1. Show current session variables
 * 2. Allow you to set super admin session manually
 * 3. Help identify the redirect issue
 */

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle form submission to set session
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'set_super_admin') {
        $_SESSION['user_type'] = 'super_admin';
        $_SESSION['super_admin_name'] = $_POST['admin_name'] ?? 'Super Admin';
        $_SESSION['super_admin_email'] = $_POST['admin_email'] ?? 'admin@sme180.com';
        $_SESSION['is_super_admin'] = true;
        $message = "✅ Super Admin session has been set!";
    } elseif ($_POST['action'] === 'clear_session') {
        session_destroy();
        session_start();
        $message = "🗑️ Session cleared!";
    }
}

// Check current authentication status
$auth_checks = [
    'user_type = super_admin' => (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'super_admin'),
    'role = super_admin' => (isset($_SESSION['role']) && $_SESSION['role'] === 'super_admin'),
    'is_super_admin = true' => (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] === true),
    'user[role] = super_admin' => (isset($_SESSION['user']['role']) && $_SESSION['user']['role'] === 'super_admin'),
    'user[user_type] = super_admin' => (isset($_SESSION['user']['user_type']) && $_SESSION['user']['user_type'] === 'super_admin'),
];

$is_authorized = false;
foreach ($auth_checks as $check => $result) {
    if ($result) {
        $is_authorized = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Session Diagnostic</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            max-width: 800px;
            width: 100%;
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .content {
            padding: 30px;
        }
        .status-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .status-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 15px;
            color: #1e293b;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 15px;
        }
        .status-authorized {
            background: #10b981;
            color: white;
        }
        .status-unauthorized {
            background: #ef4444;
            color: white;
        }
        .check-list {
            list-style: none;
        }
        .check-item {
            padding: 8px 0;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
        }
        .check-icon {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: bold;
        }
        .check-pass {
            background: #10b981;
            color: white;
        }
        .check-fail {
            background: #ef4444;
            color: white;
        }
        .session-data {
            background: #1e293b;
            color: #e2e8f0;
            padding: 20px;
            border-radius: 8px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            overflow-x: auto;
            margin-bottom: 20px;
        }
        .form-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #1e293b;
            margin-bottom: 5px;
        }
        .form-input {
            width: 100%;
            padding: 10px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            margin-right: 10px;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        .btn-danger:hover {
            background: #dc2626;
        }
        .btn-success {
            background: #10b981;
            color: white;
            text-decoration: none;
            display: inline-block;
        }
        .btn-success:hover {
            background: #059669;
        }
        .message {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            background: #10b981;
            color: white;
            font-weight: 500;
        }
        .warning {
            background: #fef3c7;
            border: 1px solid #fbbf24;
            color: #92400e;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        pre {
            white-space: pre-wrap;
            word-wrap: break-word;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🔧 Super Admin Session Diagnostic</h1>
            <p>Check and configure your super admin session</p>
        </div>
        
        <div class="content">
            <?php if (isset($message)): ?>
                <div class="message"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <!-- Authorization Status -->
            <div class="status-card">
                <h2 class="status-title">Authorization Status</h2>
                <?php if ($is_authorized): ?>
                    <span class="status-badge status-authorized">✓ AUTHORIZED</span>
                    <p style="margin-bottom: 20px; color: #10b981;">You are authorized as Super Admin!</p>
                    <a href="/views/superadmin/dashboard.php" class="btn btn-success">Go to Dashboard →</a>
                <?php else: ?>
                    <span class="status-badge status-unauthorized">✗ NOT AUTHORIZED</span>
                    <p style="margin-bottom: 20px; color: #ef4444;">You are not currently authorized as Super Admin.</p>
                <?php endif; ?>
                
                <h3 style="margin-top: 20px; margin-bottom: 10px; font-size: 16px;">Authentication Checks:</h3>
                <ul class="check-list">
                    <?php foreach ($auth_checks as $check => $result): ?>
                    <li class="check-item">
                        <span class="check-icon <?= $result ? 'check-pass' : 'check-fail' ?>">
                            <?= $result ? '✓' : '✗' ?>
                        </span>
                        <span><?= htmlspecialchars($check) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <!-- Current Session Data -->
            <div class="status-card">
                <h2 class="status-title">Current Session Data</h2>
                <div class="session-data">
                    <pre><?= htmlspecialchars(print_r($_SESSION, true)) ?></pre>
                </div>
            </div>

            <!-- Set Super Admin Session -->
            <?php if (!$is_authorized): ?>
            <div class="form-section">
                <h2 class="status-title">Set Super Admin Session</h2>
                <div class="warning">
                    ⚠️ Use this only for testing/development. In production, use proper login.
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="set_super_admin">
                    <div class="form-group">
                        <label class="form-label">Admin Name</label>
                        <input type="text" name="admin_name" class="form-input" value="Super Admin" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Admin Email</label>
                        <input type="email" name="admin_email" class="form-input" value="admin@sme180.com">
                    </div>
                    <button type="submit" class="btn btn-primary">Set Super Admin Session</button>
                </form>
            </div>
            <?php endif; ?>

            <!-- Actions -->
            <div class="form-section">
                <h2 class="status-title">Actions</h2>
                <form method="POST" style="display: inline;">
                    <input type="hidden" name="action" value="clear_session">
                    <button type="submit" class="btn btn-danger">Clear Session</button>
                </form>
                <?php if ($is_authorized): ?>
                    <a href="/views/superadmin/dashboard.php" class="btn btn-success">Go to Dashboard</a>
                <?php endif; ?>
            </div>

            <!-- Instructions -->
            <div class="status-card">
                <h2 class="status-title">📝 Instructions</h2>
                <ol style="line-height: 1.8; font-size: 14px; color: #64748b;">
                    <li>This diagnostic shows your current session state</li>
                    <li>If you're not authorized, you can temporarily set the session for testing</li>
                    <li>Once authorized, click "Go to Dashboard" to access the super admin panel</li>
                    <li>For production, ensure your login system sets the correct session variables</li>
                    <li>The redirect loop occurs when the session doesn't match expected values</li>
                </ol>
            </div>
        </div>
    </div>
</body>
</html>