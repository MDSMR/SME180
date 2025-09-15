<?php
// /controllers/auth/logout.php - Redirect to main logout
// This file just redirects to the actual logout implementation

// Redirect to the main logout file
header('Location: /views/auth/logout.php');
exit;
?>