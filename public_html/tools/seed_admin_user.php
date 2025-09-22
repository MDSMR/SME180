<?php
// /public_html/admin/tools/seed_admin_user.php
// Creates or updates an admin user with username=admin and password=admin123
// Run once from the browser, then delete this file for security.
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../_auth_bootstrap.php';

$username = 'admin';
$password = 'admin123';
$email    = 'admin@example.com';
$name     = 'Administrator';
$role_id  = 1; // ensure roles table has id=1 for admin, or adjust below

try {
  // Ensure roles has an admin role (id=1) if not exists
  $pdo->exec("INSERT INTO roles (id, role_name, name) VALUES (1,'admin','Administrator')
              ON DUPLICATE KEY UPDATE role_name=VALUES(role_name), name=VALUES(name)");

  // Upsert user
  $hash = password_hash($password, PASSWORD_DEFAULT);
  $stmt = $pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
  $stmt->execute([$username]);
  $id = $stmt->fetchColumn();

  if ($id) {
    $u = $pdo->prepare("UPDATE users SET email=?, password_hash=?, role='admin', role_id=?, name=? WHERE id=?");
    $u->execute([$email, $hash, $role_id, $name, $id]);
    echo "<h1>Updated existing admin user</h1>";
  } else {
    $u = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, role_id, name) VALUES (?,?,?,?,?,?)");
    $u->execute([$username, $email, $hash, 'admin', $role_id, $name]);
    echo "<h1>Created new admin user</h1>";
  }
  echo "<p>Username: <b>admin</b></p><p>Password: <b>admin123</b></p>";
  echo "<p>Now go to <a href='/admin/login.php'>/admin/login.php</a> and sign in. Then DELETE this script.</p>";
} catch (Throwable $e) {
  http_response_code(500);
  echo "<h1>Failed to seed admin user</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
