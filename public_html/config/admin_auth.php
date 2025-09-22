<?php
// /public_html/config/admin_auth.php
declare(strict_types=1);

// Safe session bootstrap (callable many times)
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    if (!headers_sent()) {
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_name('SMORLLSESS');
    }
    session_start();
}

require_once __DIR__ . '/db.php';
$__pdo = null;
/** @return PDO */
function auth_db(): PDO {
    // Singletonish PDO for this file
    global $__pdo;
    if ($__pdo instanceof PDO) return $__pdo;
    $__pdo = db();
    $__pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $__pdo;
}

/**
 * Log in using users.username and either:
 * - users.password_hash (bcrypt)  OR
 * - users.pass_code (legacy plain)
 */
function admin_login(string $username, string $password): bool {
    $pdo = auth_db();
    $st = $pdo->prepare("SELECT id, username, password_hash, pass_code, role, role_id
                         FROM users WHERE username=:u LIMIT 1");
    $st->execute([':u' => $username]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;

    $ok = false;
    if (!empty($u['password_hash'])) {
        $ok = password_verify($password, $u['password_hash']);
    }
    if (!$ok && !empty($u['pass_code'])) {
        $ok = hash_equals((string)$u['pass_code'], $password);
    }
    if (!$ok) return false;

    $_SESSION['admin_user_id'] = (int)$u['id'];
    $_SESSION['admin_username'] = (string)$u['username'];
    $_SESSION['admin_role'] = (string)($u['role'] ?: 'staff');
    $_SESSION['admin_role_id'] = (int)($u['role_id'] ?: 2);
    return true;
}

/** Require admin session, or redirect to login */
function admin_require_auth(): void {
    if (empty($_SESSION['admin_user_id'])) {
        $next = $_SERVER['REQUEST_URI'] ?? '/admin/dashboard.php';
        header('Location: /admin/login.php?next=' . urlencode($next));
        exit;
    }
}

/** Logout and destroy session */
function admin_logout(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}