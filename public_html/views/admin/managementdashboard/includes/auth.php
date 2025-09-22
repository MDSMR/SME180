<?php
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: /login.php');
    exit;
}

// Redirect if not admin
if ($_SESSION['role'] !== 'admin') {
    // Optional: log unauthorized access attempt
    error_log("Unauthorized access attempt by user ID: " . $_SESSION['user_id']);
    
    header('Location: /unauthorized.php');
    exit;
}