<?php
require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../../config/db.php';
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Validate ID
$id = isset(req_get('id')) ? intval(req_get('id')) : 0;
if ($id <= 0) {
    die("❌ Invalid user ID.");
}

// Disable user
$stmt = $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?");
try {
    $stmt->execute([$id]);
    echo "✅ User disabled successfully.";
} catch (PDOException $e) {
    echo "❌ Failed to disable user: " . $e->getMessage();
}