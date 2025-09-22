<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "✅ Script loaded<br>";

require_once __DIR__ . '/../../config/db.php';

echo "✅ PHP is working<br>";

$id = 1;
$stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo "⚠️ No user found.";
} else {
    $user = $result->fetch_assoc();
    echo "👤 Found user: " . htmlspecialchars($user['username']);
}