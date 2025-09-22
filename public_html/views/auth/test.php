<?php
require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/logger.php';
// CSRF protection for POST forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = req_post('csrf_token');
    if (!csrf_check($csrf)) { log_error('CSRF token invalid'); http_response_code(400); echo json_encode(['success'=>false,'error'=>'CSRF token invalid']); exit; }
}
echo "It works";declare(strict_types=1);

if (headers_sent($file, $line)) {
    error_log("Headers already sent before login bootstrap: $file:$line");
    exit('Output before session — see error log');
}

// Session settings
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_name('msahab_sid');
session_start();

require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(req_post('username') ?? '');
    $password = req_post('password') ?? '';

    if ($username === '' || $password === '') {
        $_SESSION['flash_error'] = 'Please enter username and password.';
        header('Location: /views/auth/login.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare('
            SELECT id, username, password_hash
            FROM users
            WHERE username = :u
            LIMIT 1
        ');
        $stmt->execute([':u' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            header('Location: /views/admin/dashboard.php');
            exit;
        }

        $_SESSION['flash_error'] = 'Invalid credentials.';
        header('Location: /views/auth/login.php');
        exit;

    } catch (PDOException $e) {
        error_log('Login DB error: ' . $e->getMessage());
        $_SESSION['flash_error'] = 'Server error.';
        header('Location: /views/auth/login.php');
        exit;
    }
}

$error = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Login</title>
</head>
<body>
<h1>Login</h1>
<?php if ($error): ?>
<div style="color:red;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<form method="post" action="/views/auth/login.php">
    <label>Username:<br>
        <input type="text" name="username" required>
    </label><br>
    <label>Password:<br>
        <input type="password" name="password" required>
    </label><br><br>
    <button type="submit">Sign In</button>
</form>
</body>
</html>