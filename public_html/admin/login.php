<?php
// /public_html/admin/login.php — minimal login that keeps your theme/header
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
session_start();

require_once __DIR__ . '/_auth_bootstrap.php'; // provides $pdo
// Try to include your auth helpers if present
$auth_includes = [
    __DIR__ . '/../config/auth.php',
    __DIR__ . '/_auth.php',
];
foreach ($auth_includes as $inc) {
    if (file_exists($inc)) { require_once $inc; break; }
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = trim($_POST['username'] ?? '');
    $p = (string)($_POST['password'] ?? '');

    try {
        $st = $pdo->prepare("SELECT id, username, password_hash, pass_code, role_id FROM users WHERE username = ? LIMIT 1");
        $st->execute([$u]);
        $user = $st->fetch(PDO::FETCH_ASSOC);

        $ok = false;
        if ($user) {
            // Prefer bcrypt in password_hash
            if (!empty($user['password_hash'])) {
                $ok = password_verify($p, $user['password_hash']);
            }
            // Fallback to pass_code (plain) if password_hash empty
            if (!$ok && !empty($user['pass_code'])) {
                $ok = hash_equals($user['pass_code'], $p);
            }
        }

        if ($ok) {
            $_SESSION['admin_user_id'] = (int)$user['id'];
            $_SESSION['admin_username'] = $user['username'];
            header('Location: /admin/dashboard.php');
            exit;
        } else {
            $err = 'Invalid username or password.';
        }
    } catch (Throwable $e) {
        $err = 'Login error: ' . $e->getMessage();
    }
}

// Include your original header to keep theme/nav (even on login if you want it visible)
$header = __DIR__ . '/../views/admin/_header.php';
if (file_exists($header)) { include $header; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Admin Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
</head>
<body>
<div style="max-width:360px;margin:40px auto;padding:20px;border:1px solid #ddd;border-radius:10px;">
  <h1>Sign in</h1>
  <?php if ($err): ?>
    <div style="color:#b00;margin-bottom:10px;"><?= htmlspecialchars($err) ?></div>
  <?php endif; ?>
  <form method="post">
    <label>Username</label>
    <input type="text" name="username" required style="width:100%;padding:8px;margin-bottom:10px;">
    <label>Password</label>
    <input type="password" name="password" required style="width:100%;padding:8px;margin-bottom:10px;">
    <button type="submit">Login</button>
  </form>
</div>
</body>
</html>
