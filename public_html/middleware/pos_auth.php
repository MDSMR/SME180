<?php
// /middleware/pos_auth.php - IMPROVED VERSION
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

function use_pos_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE && session_name() === 'smorll_pos') {
        $_SESSION['session_type'] = 'pos';
        return;
    }
    
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_name('smorll_pos');
        session_set_cookie_params([
            'lifetime' => 60*60*12, 
            'path' => '/', 
            'domain' => '',
            'secure' => $secure, 
            'httponly' => true, 
            'samesite' => 'Lax'
        ]);
        session_start();
    }
    $_SESSION['session_type'] = 'pos';
}

function pos_get_tenant_id(): ?int {
    use_pos_session();
    return isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
}

function pos_get_branch_id(): ?int {
    use_pos_session();
    return isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;
}

function pos_auth_require_login(): void {
    use_pos_session();
    if (!pos_logged_in() || !pos_get_tenant_id() || !pos_get_branch_id()) {
        $dest = $_SERVER['REQUEST_URI'] ?? '/pos/index.php';
        redirect('/pos/login.php?next=' . urlencode($dest));
    }
}

// Keep backward compatibility
function pos_user(): ?array { 
    use_pos_session(); 
    return $_SESSION['pos_user'] ?? null; 
}

function pos_logged_in(): bool { 
    return pos_user() !== null && pos_get_tenant_id() !== null && pos_get_branch_id() !== null;
}