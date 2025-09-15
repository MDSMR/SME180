<?php
// /views/auth/login.php - SME 180 POS System Login
declare(strict_types=1);

// Include configuration
require_once __DIR__ . '/../../config/db.php';

// Include AuditLogger if available
$audit_path = dirname(__DIR__, 2) . '/includes/AuditLogger.php';
if (file_exists($audit_path)) {
    require_once $audit_path;
}

// Start session
use_backend_session();

// Check if super admin is already logged in
if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'super_admin') {
    redirect('/views/superadmin/dashboard.php');
    exit;
}

// If already fully logged in as regular user, redirect to dashboard
if (!empty($_SESSION['user']) && isset($_SESSION['tenant_id']) && isset($_SESSION['branch_id'])) {
    redirect('/views/admin/dashboard.php');
}

// If user authenticated but needs context, redirect to context selection
if (!empty($_SESSION['user_id']) && (!isset($_SESSION['tenant_id']) || !isset($_SESSION['branch_id']))) {
    redirect('/views/auth/context_selection.php');
}

// Initialize variables
$error = '';
$success = '';
$username = '';
$remember_checked = false;
$login_attempts = $_SESSION['login_attempts'] ?? 0;
$lockout_time = $_SESSION['lockout_time'] ?? 0;
$is_locked = false;

// Check for lockout
if ($lockout_time > 0 && (time() - $lockout_time) < 900) { // 15 minutes lockout
    $is_locked = true;
    $remaining_lockout = 900 - (time() - $lockout_time);
    $error = 'Too many failed attempts. Please try again in ' . ceil($remaining_lockout / 60) . ' minutes.';
}

// Check for messages
if (isset($_GET['timeout']) && $_GET['timeout'] == '1') {
    $error = 'Your session has expired for security. Please login again.';
}
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $success = 'You have been successfully logged out.';
}

// Check remember me cookie
if (isset($_COOKIE['pos_remember_token'])) {
    $remember_token = $_COOKIE['pos_remember_token'];
    if (isset($_COOKIE['pos_remember_user'])) {
        $username = $_COOKIE['pos_remember_user'];
        $remember_checked = true;
    }
}

// Process login form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$is_locked) {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $remember = isset($_POST['remember']) ? true : false;
    
    try {
        // Validate inputs
        if (empty($username) || empty($password)) {
            throw new RuntimeException('Please enter both username and password.');
        }
        
        $pdo = db();
        $authenticated = false;
        
        // FIRST: Check super_admins table
        $stmt = $pdo->prepare("
            SELECT id, username, password_hash, email, name, is_active
            FROM super_admins 
            WHERE username = :username OR email = :email
            LIMIT 1
        ");
        $stmt->execute([
            ':username' => $username,
            ':email' => $username
        ]);
        $super_admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($super_admin) {
            // Verify super admin password
            if (!password_verify($password, $super_admin['password_hash'])) {
                throw new RuntimeException('Invalid username or password.');
            }
            
            if (!$super_admin['is_active']) {
                throw new RuntimeException('This account has been deactivated.');
            }
            
            // Super admin authenticated successfully
            $_SESSION['user_type'] = 'super_admin';
            $_SESSION['super_admin_id'] = $super_admin['id'];
            $_SESSION['super_admin_name'] = $super_admin['name'] ?? $super_admin['username'];
            $_SESSION['super_admin_email'] = $super_admin['email'];
            
            // Update last login
            $stmt = $pdo->prepare("UPDATE super_admins SET last_login = NOW() WHERE id = :id");
            $stmt->execute([':id' => $super_admin['id']]);
            
            // Log super admin login (if super_admin_logs table exists)
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO super_admin_logs (admin_id, action, details, ip_address, user_agent, created_at)
                    VALUES (:admin_id, 'login', :details, :ip, :agent, NOW())
                ");
                $stmt->execute([
                    ':admin_id' => $super_admin['id'],
                    ':details' => json_encode(['username' => $username, 'success' => true]),
                    ':ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                    ':agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
                ]);
            } catch (PDOException $e) {
                // Table might not exist yet, ignore
            }
            
            // Clear login attempts
            unset($_SESSION['login_attempts'], $_SESSION['lockout_time']);
            
            // Regenerate session for security
            session_regenerate_id(true);
            
            // Redirect to super admin dashboard
            redirect('/views/superadmin/dashboard.php');
            exit;
        }
        
        // If not super admin, continue with regular user authentication
        if (!$authenticated) {
            // Step 1: Authenticate user
            $stmt = $pdo->prepare("
                SELECT 
                    id, 
                    username, 
                    password_hash, 
                    role_key, 
                    tenant_id,
                    name,
                    email,
                    disabled_at
                FROM users
                WHERE username = :username 
                    AND disabled_at IS NULL
                LIMIT 1
            ");
            
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                // Increment failed attempts
                $_SESSION['login_attempts'] = ($login_attempts + 1);
                
                if ($_SESSION['login_attempts'] >= 5) {
                    $_SESSION['lockout_time'] = time();
                    $is_locked = true;
                }
                
                // Audit log failed attempt
                if (class_exists('AuditLogger')) {
                    AuditLogger::logLogin($username, false, $user ? (int)$user['id'] : 0, 0);
                }
                
                throw new RuntimeException('Invalid username or password.');
            }
            
            // Reset login attempts
            unset($_SESSION['login_attempts']);
            unset($_SESSION['lockout_time']);
            
            // Set user type for regular users
            $_SESSION['user_type'] = 'tenant_user';
            
            // Step 2: Get user's tenants
            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    t.id,
                    t.name,
                    CASE 
                        WHEN ut.is_primary = 1 THEN 1
                        WHEN t.id = :default_tenant1 THEN 1
                        ELSE 0
                    END as is_default
                FROM tenants t
                LEFT JOIN user_tenants ut ON t.id = ut.tenant_id AND ut.user_id = :user_id1
                WHERE (ut.user_id = :user_id2 OR t.id = :default_tenant2)
                    AND t.is_active = 1
                ORDER BY is_default DESC, t.name ASC
            ");
            
            $stmt->execute([
                ':user_id1' => $user['id'],
                ':user_id2' => $user['id'],
                ':default_tenant1' => $user['tenant_id'],
                ':default_tenant2' => $user['tenant_id']
            ]);
            
            $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($tenants)) {
                throw new RuntimeException('No active tenant access. Please contact your administrator.');
            }
            
            // Store basic user info in session
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_name'] = $user['name'] ?? $user['username'];
            $_SESSION['user_email'] = $user['email'] ?? '';
            $_SESSION['user_role'] = $user['role_key'];
            $_SESSION['login_time'] = time();
            $_SESSION['last_activity'] = time();
            
            // Check if device has remembered context
            $remembered_context = null;
            if (isset($_COOKIE['pos_device_token'])) {
                $device_token = $_COOKIE['pos_device_token'];
                $stmt = $pdo->prepare("
                    SELECT last_tenant_id, last_branch_id
                    FROM user_devices
                    WHERE user_id = :user_id 
                        AND device_token = :token
                        AND remember_context = 1
                        AND expires_at > NOW()
                    LIMIT 1
                ");
                $stmt->execute([
                    ':user_id' => $user['id'],
                    ':token' => $device_token
                ]);
                $remembered_context = $stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // Handle auto-selection logic
            if (count($tenants) === 1) {
                // Single tenant - check branches
                $tenant_id = (int)$tenants[0]['id'];
                
                $stmt = $pdo->prepare("
                    SELECT b.id, b.name
                    FROM branches b
                    JOIN user_branches ub ON b.id = ub.branch_id
                    WHERE ub.user_id = :user_id
                        AND b.tenant_id = :tenant_id
                        AND b.is_active = 1
                ");
                $stmt->execute([
                    ':user_id' => $user['id'],
                    ':tenant_id' => $tenant_id
                ]);
                $branches = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($branches) === 1) {
                    // Single tenant, single branch - auto-select both
                    $_SESSION['tenant_id'] = $tenant_id;
                    $_SESSION['tenant_name'] = $tenants[0]['name'];
                    $_SESSION['branch_id'] = (int)$branches[0]['id'];
                    $_SESSION['branch_name'] = $branches[0]['name'];
                    
                    // Set complete user session
                    $_SESSION['user'] = [
                        'id' => (int)$user['id'],
                        'username' => $user['username'],
                        'name' => $user['name'] ?? $user['username'],
                        'email' => $user['email'] ?? '',
                        'role_key' => $user['role_key'],
                        'tenant_id' => $tenant_id,
                        'tenant_name' => $tenants[0]['name'],
                        'branch_id' => (int)$branches[0]['id'],
                        'branch_name' => $branches[0]['name']
                    ];
                    
                    // Handle remember me
                    if ($remember) {
                        $remember_token = bin2hex(random_bytes(32));
                        setcookie('pos_remember_token', $remember_token, time() + (30 * 86400), '/', '', true, true);
                        setcookie('pos_remember_user', $username, time() + (30 * 86400), '/', '', true, true);
                    }
                    
                    // Update last login
                    $update_stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
                    $update_stmt->execute([':id' => $user['id']]);
                    
                    // Audit log successful login
                    if (class_exists('AuditLogger')) {
                        AuditLogger::logLogin($username, true, (int)$user['id'], $tenant_id, (int)$branches[0]['id']);
                    }
                    
                    // Regenerate session ID
                    session_regenerate_id(true);
                    
                    // Redirect to dashboard
                    redirect('/views/admin/dashboard.php');
                    exit;
                }
            }
            
            // Multiple tenants or branches - need context selection
            $_SESSION['available_tenants'] = $tenants;
            
            // Check for remembered context
            if ($remembered_context) {
                $_SESSION['remembered_tenant_id'] = $remembered_context['last_tenant_id'];
                $_SESSION['remembered_branch_id'] = $remembered_context['last_branch_id'];
            }
            
            // Redirect to context selection
            redirect('/views/auth/context_selection.php');
            exit;
        }
        
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Exception $e) {
        error_log('Login error: ' . $e->getMessage());
        $error = 'An error occurred during login. Please try again.';
    }
}

// Get current session user if exists (for partial login state)
$session_user = $_SESSION['user_name'] ?? $_SESSION['username'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SME 180</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Inter', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 25%, #6366F1 50%, #3B82F6 75%, #06B6D4 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        /* Copilot-style animated background */
        body::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 30% 107%, rgba(139, 92, 246, 0.5) 0%, transparent 40%),
                        radial-gradient(circle at 70% 10%, rgba(99, 102, 241, 0.4) 0%, transparent 40%),
                        radial-gradient(circle at 90% 60%, rgba(6, 182, 212, 0.3) 0%, transparent 40%);
            animation: copilotFlow 25s ease-in-out infinite;
        }
        
        body::after {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle at 10% 50%, rgba(59, 130, 246, 0.3) 0%, transparent 45%),
                        radial-gradient(circle at 80% 80%, rgba(139, 92, 246, 0.4) 0%, transparent 45%);
            animation: copilotFlow 30s ease-in-out infinite reverse;
        }
        
        /* Floating light particles - Copilot style */
        .particle {
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }
        
        .particle1 {
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.4) 0%, transparent 70%);
            top: -200px;
            right: -200px;
            animation: float1 20s infinite ease-in-out;
        }
        
        .particle2 {
            width: 350px;
            height: 350px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, transparent 70%);
            bottom: -175px;
            left: -175px;
            animation: float2 25s infinite ease-in-out;
        }
        
        .particle3 {
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.3) 0%, transparent 70%);
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            animation: float3 30s infinite ease-in-out;
        }
        
        @keyframes copilotFlow {
            0%, 100% {
                transform: translate(0, 0) scale(1) rotate(0deg);
            }
            25% {
                transform: translate(-30px, 30px) scale(1.1) rotate(90deg);
            }
            50% {
                transform: translate(30px, -30px) scale(0.9) rotate(180deg);
            }
            75% {
                transform: translate(-20px, -20px) scale(1.05) rotate(270deg);
            }
        }
        
        @keyframes float1 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(-50px, 50px) scale(1.2); }
        }
        
        @keyframes float2 {
            0%, 100% { transform: translate(0, 0) scale(1); }
            50% { transform: translate(50px, -30px) scale(0.9); }
        }
        
        @keyframes float3 {
            0%, 100% { transform: translate(-50%, -50%) scale(1); }
            50% { transform: translate(-45%, -55%) scale(1.1); }
        }
        
        /* Login container with glass effect */
        .login-container {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 20px;
            box-shadow: 0 32px 64px rgba(0, 0, 0, 0.15),
                        0 12px 24px rgba(0, 0, 0, 0.1),
                        inset 0 2px 0 rgba(255, 255, 255, 1);
            width: 100%;
            max-width: 460px;
            padding: 48px;
            position: relative;
            z-index: 10;
            border: 1px solid rgba(255, 255, 255, 0.5);
        }
        
        /* Logo and branding */
        .logo-container {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }
        
        .logo-wrapper {
            display: inline-flex;
            align-items: center;
            gap: 16px;
            position: relative;
        }
        
        /* Copilot-inspired animated logo */
        .logo-icon {
            width: 48px;
            height: 48px;
            position: relative;
        }
        
        .logo-text {
            font-size: 44px;
            font-weight: 700;
            background: linear-gradient(135deg, #8B5CF6 0%, #6366F1 50%, #06B6D4 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -1.5px;
            position: relative;
            font-family: 'Segoe UI', sans-serif;
        }
        
        .logo-subtitle {
            color: #64748B;
            margin-top: 10px;
            font-size: 14px;
            font-weight: 500;
            letter-spacing: 0.3px;
        }
        
        /* Form styling */
        .form-group {
            margin-bottom: 24px;
            position: relative;
        }
        
        .form-label {
            display: block;
            margin-bottom: 10px;
            color: #475569;
            font-weight: 600;
            font-size: 14px;
            letter-spacing: 0.2px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            stroke: #94A3B8;
            fill: none;
            z-index: 2;
            pointer-events: none;
            transition: stroke 0.3s ease;
        }
        
        .form-input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            border: 2px solid #E2E8F0;
            border-radius: 12px;
            font-size: 15px;
            font-family: 'Segoe UI', sans-serif;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: #FFFFFF;
            position: relative;
            z-index: 1;
        }
        
        .form-input:hover {
            border-color: #CBD5E1;
        }
        
        .form-input:hover ~ .input-icon {
            stroke: #64748B;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #8B5CF6;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1),
                        0 2px 8px rgba(139, 92, 246, 0.08);
        }
        
        .form-input:focus ~ .input-icon {
            stroke: #8B5CF6;
        }
        
        .form-input::placeholder {
            color: #CBD5E1;
            font-size: 14px;
        }
        
        .form-input:disabled {
            background-color: #F8FAFC;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .password-input-wrapper {
            position: relative;
        }
        
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 4px;
            color: #94A3B8;
            transition: all 0.2s;
            border-radius: 4px;
        }
        
        .password-toggle:hover {
            color: #64748B;
            background: #F1F5F9;
        }
        
        /* Checkbox styling - Copilot style */
        .form-options {
            margin-bottom: 28px;
        }
        
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            position: relative;
            cursor: pointer;
        }
        
        .checkbox-input {
            position: absolute;
            opacity: 0;
            cursor: pointer;
        }
        
        .checkbox-custom {
            width: 20px;
            height: 20px;
            border: 2px solid #CBD5E1;
            border-radius: 6px;
            margin-right: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            cursor: pointer;
            background: white;
        }
        
        .checkbox-input:checked ~ .checkbox-custom {
            background: linear-gradient(135deg, #8B5CF6, #6366F1);
            border-color: transparent;
            animation: checkBounce 0.3s ease;
        }
        
        @keyframes checkBounce {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .checkbox-custom svg {
            width: 12px;
            height: 12px;
            color: white;
            opacity: 0;
            transform: scale(0);
            transition: all 0.2s cubic-bezier(0.12, 0.4, 0.29, 1.46);
        }
        
        .checkbox-input:checked ~ .checkbox-custom svg {
            opacity: 1;
            transform: scale(1);
        }
        
        .checkbox-label {
            font-size: 14px;
            color: #64748B;
            user-select: none;
            cursor: pointer;
        }
        
        /* Submit button - Copilot gradient style */
        .btn {
            width: 100%;
            padding: 14px 24px;
            border: none;
            border-radius: 12px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-family: 'Segoe UI', sans-serif;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #8B5CF6 0%, #6366F1 50%, #3B82F6 100%);
            color: white;
            box-shadow: 0 4px 14px rgba(139, 92, 246, 0.3),
                        0 2px 4px rgba(139, 92, 246, 0.2);
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, 
                transparent, 
                rgba(255, 255, 255, 0.2), 
                transparent);
            transition: left 0.6s;
        }
        
        .btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 92, 246, 0.4),
                        0 3px 6px rgba(139, 92, 246, 0.3);
            background: linear-gradient(135deg, #9F6FF7 0%, #7376F2 50%, #4B8BF7 100%);
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-primary:active:not(:disabled) {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(139, 92, 246, 0.3);
        }
        
        .btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none !important;
        }
        
        /* Loading spinner */
        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            display: inline-block;
            margin-left: 8px;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Alerts - Copilot style */
        .alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-top: 24px;
            font-size: 14px;
            animation: slideIn 0.3s ease-out;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-icon {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        
        .alert-error {
            background: linear-gradient(135deg, #FEE2E2, #FECACA);
            color: #991B1B;
            border: 1px solid #FCA5A5;
        }
        
        .alert-success {
            background: linear-gradient(135deg, #D1FAE5, #A7F3D0);
            color: #065F46;
            border: 1px solid #6EE7B7;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        
        .loading-overlay.show {
            display: flex;
        }
        
        .loading-content {
            text-align: center;
        }
        
        .loading-spinner-large {
            width: 60px;
            height: 60px;
            border: 4px solid #E2E8F0;
            border-top: 4px solid;
            border-top-color: #8B5CF6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        .loading-text {
            color: #64748B;
            font-size: 16px;
            font-weight: 500;
        }
        
        /* Responsive design */
        @media (max-width: 480px) {
            .login-container {
                padding: 32px 24px;
                margin: 20px;
            }
            
            .logo-text {
                font-size: 36px;
            }
            
            .logo-icon {
                width: 40px;
                height: 40px;
            }
            
            .form-input {
                font-size: 16px;
            }
        }
    </style>
</head>
<body>
    <!-- Copilot-style floating particles -->
    <div class="particle particle1"></div>
    <div class="particle particle2"></div>
    <div class="particle particle3"></div>
    
    <div class="login-container">
        <div class="logo-container">
            <div class="logo-wrapper">
                <div class="logo-icon">
                  <svg class="logo-svg" width="48" height="48" viewBox="0 0 200 200"
                       xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Animated SME 180 icon">
                    <defs>
                      <!-- Use page palette -->
                      <linearGradient id="gradOuter" x1="0%" y1="0%" x2="100%" y2="100%">
                        <stop offset="0%" stop-color="#8B5CF6"/>
                        <stop offset="100%" stop-color="#6366F1"/>
                      </linearGradient>
                      <radialGradient id="gradOrb" cx="30%" cy="30%" r="70%">
                        <stop offset="0%" stop-color="#8B5CF6"/>
                        <stop offset="100%" stop-color="#06B6D4"/>
                      </radialGradient>
                      <style><![CDATA[
                        .rot-ccw { animation: rot-ccw 6s linear infinite; transform-origin: 100px 100px; }
                        .rot-cw  { animation: rot-cw  5s linear infinite; transform-origin: 100px 100px; }
                        .pulse   { animation: pulse   2.2s ease-in-out infinite; transform-origin: 100px 100px; }
                        @keyframes rot-ccw { from { transform: rotate(0deg); } to { transform: rotate(-360deg); } }
                        @keyframes rot-cw  { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
                        @keyframes pulse   { 0%,100% { transform: scale(1); opacity: 1; }
                                             50%     { transform: scale(1.15); opacity: 0.9; } }
                      ]]></style>
                    </defs>
                    <!-- Outer arc -->
                    <circle class="rot-ccw"
                            cx="100" cy="100" r="75"
                            fill="none" stroke="url(#gradOuter)" stroke-width="8" stroke-linecap="round"
                            stroke-dasharray="235 235" transform="rotate(-45 100 100)"/>
                    <!-- Inner arc -->
                    <circle class="rot-cw"
                            cx="100" cy="100" r="52"
                            fill="none" stroke="#06B6D4" stroke-width="8" stroke-linecap="round"
                            stroke-dasharray="163 163" transform="rotate(135 100 100)"/>
                    <!-- Center orb -->
                    <circle class="pulse" cx="100" cy="100" r="30" fill="url(#gradOrb)"/>
                  </svg>
                </div>
                <div class="logo-text">SME 180</div>
            </div>
            <p class="logo-subtitle">Enterprise Point of Sale System</p>
        </div>
        
        <form method="POST" action="" id="loginForm">
            <div class="form-group">
                <label for="username" class="form-label">Username</label>
                <div class="input-wrapper">
                    <input 
                        type="text" 
                        name="username" 
                        id="username" 
                        class="form-input" 
                        placeholder="Enter your username or email"
                        value="<?= htmlspecialchars($username) ?>"
                        autocomplete="username"
                        required
                        <?= $is_locked ? 'disabled' : '' ?>
                    >
                    <!-- Fixed Username Icon - Placed AFTER input -->
                    <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4" stroke-linecap="round" stroke-linejoin="round"></circle>
                    </svg>
                </div>
            </div>
            
            <div class="form-group">
                <label for="password" class="form-label">Password</label>
                <div class="password-input-wrapper">
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            name="password" 
                            id="password" 
                            class="form-input" 
                            placeholder="Enter your password"
                            autocomplete="current-password"
                            required
                            <?= $is_locked ? 'disabled' : '' ?>
                        >
                        <!-- Fixed Password Icon - Placed AFTER input -->
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2" stroke-linecap="round" stroke-linejoin="round"></rect>
                            <path d="M7 11V7a5 5 0 0110 0v4" stroke-linecap="round" stroke-linejoin="round"></path>
                        </svg>
                        <button type="button" class="password-toggle" onclick="togglePassword()" title="Toggle password visibility">
                            <svg id="eye-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                <!-- Eye Open Icon -->
                                <path stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                                <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round"></circle>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="form-options">
                <label class="checkbox-wrapper">
                    <input 
                        type="checkbox" 
                        name="remember" 
                        id="remember" 
                        class="checkbox-input"
                        <?= $remember_checked ? 'checked' : '' ?>
                        <?= $is_locked ? 'disabled' : '' ?>
                    >
                    <span class="checkbox-custom">
                        <svg fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                        </svg>
                    </span>
                    <span class="checkbox-label">Keep me signed in</span>
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary" id="submitBtn" <?= $is_locked ? 'disabled' : '' ?>>
                <span id="btnText">SIGN IN</span>
                <div class="spinner" id="spinner" style="display: none;"></div>
            </button>
        </form>
        
        <?php if ($error): ?>
        <div class="alert alert-error">
            <svg class="alert-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
        <div class="alert alert-success">
            <svg class="alert-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
            <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="loading-spinner-large"></div>
            <div class="loading-text">Authenticating your credentials...</div>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const eyeIcon = document.getElementById('eye-icon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                // Eye Slash Icon (password visible)
                eyeIcon.innerHTML = `
                    <g stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                        <line x1="1" y1="1" x2="23" y2="23"></line>
                    </g>
                `;
            } else {
                passwordInput.type = 'password';
                // Eye Open Icon (password hidden)
                eyeIcon.innerHTML = `
                    <path stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                    <circle cx="12" cy="12" r="3" stroke-linecap="round" stroke-linejoin="round"></circle>
                `;
            }
        }
        
        // Form submission with loading state
        document.getElementById('loginForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('spinner');
            const overlay = document.getElementById('loadingOverlay');
            
            if (!submitBtn.disabled) {
                btnText.textContent = 'SIGNING IN';
                spinner.style.display = 'inline-block';
                submitBtn.disabled = true;
                
                // Show loading overlay after a brief delay
                setTimeout(() => {
                    overlay.classList.add('show');
                }, 300);
            }
        });
        
        // Auto-focus username field
        window.onload = function() {
            const usernameField = document.getElementById('username');
            if (usernameField && !usernameField.value) {
                usernameField.focus();
            }
        };
        
        // Add enter key support for form
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.form) {
                const submitBtn = document.getElementById('submitBtn');
                if (!submitBtn.disabled) {
                    e.target.form.requestSubmit();
                }
            }
        });
    </script>
</body>
</html>