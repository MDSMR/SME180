<?php
declare(strict_types=1);

/**
 * Enforces role-based access control.
 * Redirects to login if session is missing.
 * Sends 403 Forbidden if role is invalid.
 */

function require_role(string ...$roles): void {
    // Ensure session is active
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Redirect to login if role is missing
    if (empty($_SESSION['role'])) {
        header('Location: /views/auth/login.php');
        exit;
    }

    // Deny access if role is not allowed
    if (!in_array($_SESSION['role'], $roles, true)) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Access denied';
        exit;
    }
}