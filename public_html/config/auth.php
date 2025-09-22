<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();

/**
 * Minimal, robust admin auth layer compatible with your schema.
 * - Uses users.username + users.password_hash (bcrypt) primarily.
 * - Falls back to users.pass_code if password_hash is empty (legacy support).
 */

function admin_login(PDO $pdo, string $username, string $password): bool {
    $sql = "SELECT id, username, password_hash, pass_code, role, role_id FROM users WHERE username = :u LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([':u' => $username]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    if (!$u) return false;

    $ok = false;
    if (!empty($u['password_hash'])) {
        // Modern path (bcrypt)
        $ok = password_verify($password, $u['password_hash']);
    }
    if (!$ok && !empty($u['pass_code'])) {
        // Legacy fallback
        $ok = hash_equals($u['pass_code'], $password);
    }
    if (!$ok) return false;

    // Set session
    $_SESSION['admin_user_id'] = (int)$u['id'];
    $_SESSION['admin_username'] = (string)$u['username'];
    $_SESSION['admin_role'] = (string)($u['role'] ?: 'staff');
    $_SESSION['admin_role_id'] = (int)($u['role_id'] ?: 2);
    return true;
}

function admin_require_auth(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['admin_user_id'])) {
        header("Location: /admin/login.php?next=" . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/dashboard.php'));
        exit;
    }
}

function admin_logout(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    // Clear session
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
