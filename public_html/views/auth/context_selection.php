<?php
// /views/auth/context_selection.php - SME 180 Context Selection
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

// Include AuditLogger if available
$audit_path = dirname(__DIR__, 2) . '/includes/AuditLogger.php';
if (file_exists($audit_path)) {
    require_once $audit_path;
}

use_backend_session();

// Check if user is logged in but needs context
if (empty($_SESSION['user_id'])) {
    redirect('/views/auth/login.php');
    exit;
}

// If already has context, redirect to dashboard
if (isset($_SESSION['tenant_id']) && isset($_SESSION['branch_id'])) {
    redirect('/views/admin/dashboard.php');
    exit;
}

$error = '';
$success = '';

// Get available tenants from session or database
$tenants = $_SESSION['available_tenants'] ?? [];
if (empty($tenants)) {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                t.id,
                t.name,
                t.address,
                CASE 
                    WHEN ut.is_primary = 1 THEN 1
                    WHEN u.tenant_id = t.id THEN 1
                    ELSE 0
                END as is_default
            FROM tenants t
            JOIN users u ON u.id = :user_id
            LEFT JOIN user_tenants ut ON t.id = ut.tenant_id AND ut.user_id = :user_id2
            WHERE (ut.user_id = :user_id3 OR t.id = u.tenant_id)
                AND t.is_active = 1
            ORDER BY is_default DESC, t.name ASC
        ");
        
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':user_id2' => $_SESSION['user_id'],
            ':user_id3' => $_SESSION['user_id']
        ]);
        
        $tenants = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $_SESSION['available_tenants'] = $tenants;
    } catch (Exception $e) {
        error_log('Failed to load tenants: ' . $e->getMessage());
        $error = 'Unable to load available companies.';
    }
}

// Get remembered context if available
$remembered_tenant = $_SESSION['remembered_tenant_id'] ?? null;
$remembered_branch = $_SESSION['remembered_branch_id'] ?? null;

// Process form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tenant_id = (int)($_POST['tenant_id'] ?? 0);
    $branch_id = (int)($_POST['branch_id'] ?? 0);
    $remember_context = isset($_POST['remember_context']) ? true : false;
    
    try {
        if ($tenant_id <= 0) {
            throw new RuntimeException('Please select a company.');
        }
        
        if ($branch_id <= 0) {
            throw new RuntimeException('Please select a branch.');
        }
        
        $pdo = db();
        
        // Verify user has access to this tenant
        $stmt = $pdo->prepare("
            SELECT t.id, t.name
            FROM tenants t
            LEFT JOIN user_tenants ut ON t.id = ut.tenant_id AND ut.user_id = :user_id
            JOIN users ON users.id = :user_id2
            WHERE t.id = :tenant_id
                AND (ut.user_id = :user_id3 OR t.id = users.tenant_id)
                AND t.is_active = 1
            LIMIT 1
        ");
        
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':user_id2' => $_SESSION['user_id'],
            ':user_id3' => $_SESSION['user_id'],
            ':tenant_id' => $tenant_id
        ]);
        
        $tenant = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tenant) {
            throw new RuntimeException('Invalid company selection.');
        }
        
        // Verify user has access to this branch
        $stmt = $pdo->prepare("
            SELECT b.id, b.name
            FROM branches b
            JOIN user_branches ub ON b.id = ub.branch_id
            WHERE ub.user_id = :user_id
                AND b.id = :branch_id
                AND b.tenant_id = :tenant_id
                AND b.is_active = 1
            LIMIT 1
        ");
        
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':branch_id' => $branch_id,
            ':tenant_id' => $tenant_id
        ]);
        
        $branch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$branch) {
            throw new RuntimeException('Invalid branch selection.');
        }
        
        // Get user details
        $stmt = $pdo->prepare("
            SELECT username, name, email, role_key
            FROM users
            WHERE id = :user_id
            LIMIT 1
        ");
        $stmt->execute([':user_id' => $_SESSION['user_id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Set session variables
        $_SESSION['tenant_id'] = $tenant_id;
        $_SESSION['tenant_name'] = $tenant['name'];
        $_SESSION['branch_id'] = $branch_id;
        $_SESSION['branch_name'] = $branch['name'];
        
        // Set complete user array
        $_SESSION['user'] = [
            'id' => $_SESSION['user_id'],
            'username' => $user['username'],
            'name' => $user['name'] ?? $user['username'],
            'email' => $user['email'] ?? '',
            'role_key' => $user['role_key'],
            'tenant_id' => $tenant_id,
            'tenant_name' => $tenant['name'],
            'branch_id' => $branch_id,
            'branch_name' => $branch['name']
        ];
        
        // Get all branches for this tenant (for switching later)
        $stmt = $pdo->prepare("
            SELECT b.id, b.name
            FROM branches b
            JOIN user_branches ub ON b.id = ub.branch_id
            WHERE ub.user_id = :user_id
                AND b.tenant_id = :tenant_id
                AND b.is_active = 1
            ORDER BY b.name
        ");
        $stmt->execute([
            ':user_id' => $_SESSION['user_id'],
            ':tenant_id' => $tenant_id
        ]);
        $_SESSION['available_branches'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Handle remember context
        if ($remember_context) {
            // Generate or get device token
            $device_token = $_COOKIE['pos_device_token'] ?? bin2hex(random_bytes(32));
            setcookie('pos_device_token', $device_token, time() + (365 * 86400), '/', '', true, true);
            
            // Store in database
            $stmt = $pdo->prepare("
                INSERT INTO user_devices (
                    user_id, device_token, device_name, 
                    last_tenant_id, last_branch_id, 
                    remember_context, last_login, expires_at
                ) VALUES (
                    :user_id, :token, :device,
                    :tenant_id, :branch_id,
                    1, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY)
                )
                ON DUPLICATE KEY UPDATE
                    last_tenant_id = VALUES(last_tenant_id),
                    last_branch_id = VALUES(last_branch_id),
                    remember_context = 1,
                    last_login = NOW(),
                    expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
            ");
            
            $stmt->execute([
                ':user_id' => $_SESSION['user_id'],
                ':token' => $device_token,
                ':device' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
                ':tenant_id' => $tenant_id,
                ':branch_id' => $branch_id
            ]);
        }
        
        // Update last login
        $stmt = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = :id");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        
        // Audit log
        if (class_exists('AuditLogger')) {
            AuditLogger::logLogin(
                $_SESSION['username'], 
                true, 
                $_SESSION['user_id'], 
                $tenant_id, 
                $branch_id
            );
        }
        
        // Clean up temporary session variables
        unset($_SESSION['available_tenants']);
        unset($_SESSION['remembered_tenant_id']);
        unset($_SESSION['remembered_branch_id']);
        
        // Regenerate session ID for security
        session_regenerate_id(true);
        
        // Redirect to dashboard or intended destination
        $redirect_url = $_SESSION['redirect_after_login'] ?? '/views/admin/dashboard.php';
        unset($_SESSION['redirect_after_login']);
        redirect($redirect_url);
        exit;
        
    } catch (RuntimeException $e) {
        $error = $e->getMessage();
    } catch (Exception $e) {
        error_log('Context selection error: ' . $e->getMessage());
        $error = 'An error occurred. Please try again.';
    }
}

// Preselect default tenant
$default_tenant_id = 0;
foreach ($tenants as $t) {
    if ($t['is_default'] ?? false) {
        $default_tenant_id = $t['id'];
        break;
    }
}
if ($default_tenant_id === 0 && count($tenants) === 1) {
    $default_tenant_id = $tenants[0]['id'];
}

// Use remembered tenant if available
if ($remembered_tenant) {
    $default_tenant_id = $remembered_tenant;
}

// Get user name
$user_name = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Workspace - SME 180</title>
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
        
        .form-select, .static-select {
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
            cursor: pointer;
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2394A3B8' d='M10.293 3.293L6 7.586 1.707 3.293A1 1 0 00.293 4.707l5 5a1 1 0 001.414 0l5-5a1 1 0 10-1.414-1.414z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 16px center;
            padding-right: 40px;
        }
        
        .form-select:hover {
            border-color: #CBD5E1;
        }
        
        .form-select:hover ~ .input-icon {
            stroke: #64748B;
        }
        
        .form-select:focus {
            outline: none;
            border-color: #8B5CF6;
            box-shadow: 0 0 0 4px rgba(139, 92, 246, 0.1),
                        0 2px 8px rgba(139, 92, 246, 0.08);
        }
        
        .form-select:focus ~ .input-icon {
            stroke: #8B5CF6;
        }
        
        .form-select::placeholder {
            color: #CBD5E1;
            font-size: 14px;
        }
        
        .form-select:disabled {
            background-color: #F8FAFC;
            cursor: not-allowed;
            opacity: 0.6;
        }
        
        .static-select {
            background-color: #F8FAFC;
            border: 2px solid #8B5CF6;
            color: #334155;
            font-weight: 500;
            cursor: default;
            background-image: none;
            padding-right: 48px;
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
        
        /* User info at bottom - matching login page */
        .user-info {
            margin-top: 32px;
            padding-top: 24px;
            border-top: 1px solid #E2E8F0;
            font-size: 14px;
            color: #64748B;
            text-align: center;
        }
        
        .user-info strong {
            color: #334155;
            font-weight: 600;
        }
        
        .logout-link {
            color: #8B5CF6;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-block;
            margin-left: 10px;
        }
        
        .logout-link:hover {
            color: #6366F1;
            transform: translateY(-1px);
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
        
        /* Loading state for branch dropdown */
        .loading {
            text-align: center;
            padding: 20px;
            color: #64748B;
            font-size: 14px;
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 3px solid #E2E8F0;
            border-top: 3px solid #8B5CF6;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 12px;
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
            
            .form-select {
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
            <p class="logo-subtitle">Select Your Workspace</p>
        </div>
        
        <form method="POST" action="" id="contextForm">
            <div class="form-group">
                <label for="tenant_id" class="form-label">Company</label>
                <div class="input-wrapper">
                    <?php if (count($tenants) === 1): ?>
                        <div class="static-select">
                            <?= htmlspecialchars($tenants[0]['name']) ?>
                        </div>
                        <input type="hidden" name="tenant_id" id="tenant_id" value="<?= $tenants[0]['id'] ?>">
                    <?php else: ?>
                        <select name="tenant_id" id="tenant_id" class="form-select" required onchange="loadBranches()">
                            <option value="">Select a company...</option>
                            <?php foreach ($tenants as $tenant): ?>
                            <option value="<?= $tenant['id'] ?>" <?= ($tenant['id'] == $default_tenant_id) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tenant['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <!-- Company Icon -->
                    <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
                    </svg>
                </div>
            </div>
            
            <div class="form-group" id="branchSelection">
                <label for="branch_id" class="form-label">Branch</label>
                <div class="input-wrapper">
                    <?php if (count($tenants) === 1): ?>
                        <div class="loading">
                            <div class="loading-spinner"></div>
                            <p>Loading branches...</p>
                        </div>
                    <?php else: ?>
                        <select name="branch_id" id="branch_id" class="form-select" required disabled>
                            <option value="">Select company first...</option>
                        </select>
                        <!-- Branch Icon -->
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="form-options">
                <label class="checkbox-wrapper">
                    <input type="checkbox" name="remember_context" id="remember_context" class="checkbox-input" checked>
                    <span class="checkbox-custom">
                        <svg fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                        </svg>
                    </span>
                    <span class="checkbox-label">Remember my selection on this device</span>
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <span id="btnText">CONTINUE TO DASHBOARD</span>
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
        
        <!-- User info section at bottom with separator -->
        <div class="user-info">
            Logged in as <strong><?= htmlspecialchars($user_name) ?></strong>
            <a href="/views/auth/logout.php" class="logout-link">Sign out</a>
        </div>
    </div>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loading-content">
            <div class="loading-spinner-large"></div>
            <div class="loading-text">Loading workspace...</div>
        </div>
    </div>
    
    <script>
        // Auto-load branches
        window.onload = function() {
            const tenantInput = document.getElementById('tenant_id');
            if (tenantInput && tenantInput.value) {
                loadBranches();
            }
        };
        
        function loadBranches() {
            const tenantInput = document.getElementById('tenant_id');
            const tenantId = tenantInput ? tenantInput.value : '';
            const branchSelection = document.getElementById('branchSelection');
            
            if (!tenantId) {
                branchSelection.innerHTML = `
                    <label for="branch_id" class="form-label">Branch</label>
                    <div class="input-wrapper">
                        <select name="branch_id" id="branch_id" class="form-select" required disabled>
                            <option value="">Select company first...</option>
                        </select>
                        <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                        </svg>
                    </div>
                `;
                return;
            }
            
            // Show loading
            branchSelection.innerHTML = `
                <label for="branch_id" class="form-label">Branch</label>
                <div class="loading">
                    <div class="loading-spinner"></div>
                    <p>Loading branches...</p>
                </div>
            `;
            
            // Fetch branches
            fetch('/api/get_user_branches.php?tenant_id=' + tenantId)
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        branchSelection.innerHTML = `
                            <label for="branch_id" class="form-label">Branch</label>
                            <div class="alert alert-error">${data.error}</div>
                        `;
                        return;
                    }
                    
                    if (data.branches.length === 0) {
                        branchSelection.innerHTML = `
                            <label for="branch_id" class="form-label">Branch</label>
                            <div class="alert alert-error">No branches available</div>
                        `;
                        return;
                    }
                    
                    // Single branch
                    if (data.branches.length === 1) {
                        const branch = data.branches[0];
                        branchSelection.innerHTML = `
                            <label for="branch_id" class="form-label">Branch</label>
                            <div class="input-wrapper">
                                <div class="static-select">
                                    ${escapeHtml(branch.name)}
                                </div>
                                <input type="hidden" name="branch_id" id="branch_id" value="${branch.id}">
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </div>
                        `;
                    } 
                    // Multiple branches
                    else {
                        let html = `
                            <label for="branch_id" class="form-label">Branch</label>
                            <div class="input-wrapper">
                                <select name="branch_id" id="branch_id" class="form-select" required>
                                    <option value="">Select a branch...</option>
                        `;
                        
                        const defaultBranchId = data.remembered_branch || 0;
                        data.branches.forEach(branch => {
                            const selected = (branch.id == defaultBranchId) ? ' selected' : '';
                            html += `<option value="${branch.id}"${selected}>${escapeHtml(branch.name)}</option>`;
                        });
                        
                        html += `</select>
                                <svg class="input-icon" xmlns="http://www.w3.org/2000/svg" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                                </svg>
                            </div>
                        `;
                        
                        branchSelection.innerHTML = html;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    branchSelection.innerHTML = `
                        <label for="branch_id" class="form-label">Branch</label>
                        <div class="alert alert-error">Failed to load branches</div>
                    `;
                });
        }
        
        function escapeHtml(text) {
            if (!text) return '';
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.toString().replace(/[&<>"']/g, m => map[m]);
        }
        
        // Form submission
        document.getElementById('contextForm').addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            const btnText = document.getElementById('btnText');
            const spinner = document.getElementById('spinner');
            const overlay = document.getElementById('loadingOverlay');
            
            if (!submitBtn.disabled) {
                btnText.textContent = 'LOADING';
                spinner.style.display = 'inline-block';
                submitBtn.disabled = true;
                
                // Show loading overlay after a brief delay
                setTimeout(() => {
                    overlay.classList.add('show');
                }, 300);
            }
        });
        
        // Auto-focus company field if empty
        window.addEventListener('load', function() {
            const tenantSelect = document.getElementById('tenant_id');
            if (tenantSelect && tenantSelect.tagName === 'SELECT' && !tenantSelect.value) {
                tenantSelect.focus();
            }
        });
        
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