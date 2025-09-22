<?php
// /public_html/admin/logout.php — safe logout
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

// Best-effort token cleanup if you have a table for tokens (optional)
try {
    require_once __DIR__ . '/_auth_bootstrap.php'; // $pdo
    if (isset($_SESSION['admin_user_id'])) {
        // If you use user_tokens, you could revoke here
        // $st = $pdo->prepare("UPDATE user_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL");
        // $st->execute([ (int)$_SESSION['admin_user_id'] ]);
    }
} catch (Throwable $e) {
    // Ignore cleanup errors on logout
}

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

header('Location: /admin/login.php');
exit;
