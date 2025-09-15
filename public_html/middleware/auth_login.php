<?php
// middleware/auth_login.php - FIXED VERSION
declare(strict_types=1);

// Include database config FIRST
require_once __DIR__ . '/../config/db.php';

/**
 * Start session safely
 */
function auth_session_start_safe(): void {
    use_backend_session();
    
    // Validate tenant/branch exist in session
    if (isset($_SESSION['user']) && 
        (!isset($_SESSION['tenant_id']) || !isset($_SESSION['branch_id']))) {
        // Invalid session state - force re-login
        session_destroy();
        redirect('/views/auth/login.php');
    }
}

/**
 * Get user from session
 */
function auth_user(): ?array {
    auth_session_start_safe();
    return $_SESSION['user'] ?? null;
}

/**
 * Check if user is logged in
 */
function auth_logged_in(): bool {
    return auth_user() !== null;
}

/**
 * Require login
 */
function auth_require_login(): void {
    if (!auth_logged_in()) {
        $dest = $_SERVER['REQUEST_URI'] ?? '/views/admin/dashboard.php';
        redirect('/views/auth/login.php?next=' . urlencode($dest));
        exit;
    }
    // Also validate tenant/branch
    auth_require_tenant();
}

/**
 * Get tenant ID
 */
function auth_get_tenant_id(): ?int {
    auth_session_start_safe();
    return isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
}

/**
 * Get branch ID
 */
function auth_get_branch_id(): ?int {
    auth_session_start_safe();
    return isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;
}

/**
 * Require tenant and branch
 */
function auth_require_tenant(): void {
    if (!auth_get_tenant_id() || !auth_get_branch_id()) {
        error_log('Access denied: Missing tenant/branch context');
        redirect('/views/auth/login.php');
        exit;
    }
}

/**
 * Require specific role
 */
function auth_require_role(array $allowedRoles): void {
    $u = auth_user();
    if (!$u) {
        redirect('/views/auth/login.php');
        exit;
    }
    $role = (string)($u['role_key'] ?? '');
    if ($role === '' || !in_array($role, $allowedRoles, true)) {
        header('HTTP/1.1 403 Forbidden');
        redirect('/views/admin/dashboard.php');
        exit;
    }
}

/**
 * Logout function
 */
function auth_logout(): void {
    auth_session_start_safe();
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}
?>