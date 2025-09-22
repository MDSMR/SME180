<?php
// Unified, safe session bootstrap for protected pages
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

if (empty($_SESSION['user_id'])) {
    header('Location: /views/auth/login.php');
    exit;
}