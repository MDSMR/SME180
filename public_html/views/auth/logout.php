<?php
// /views/auth/logout.php - Using your system's session handler
require_once __DIR__ . '/../../config/db.php';

// Use the same session function as login
if (function_exists('use_backend_session')) {
    use_backend_session();
} else {
    session_start();
}

// Now clear the session
$_SESSION = [];

// Destroy session
if (session_id()) {
    session_destroy();
}

// Clear cookies
setcookie(session_name(), '', time() - 3600, '/');
setcookie('PHPSESSID', '', time() - 3600, '/');
setcookie('pos_remember_token', '', time() - 3600, '/');
setcookie('pos_remember_user', '', time() - 3600, '/');

// Redirect
header('Location: /views/auth/login.php?logout=1');
exit;