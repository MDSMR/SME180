<?php
require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/logger.php';
// CSRF protection for POST forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = req_post('csrf_token');
    if (!csrf_check($csrf)) { log_error('CSRF token invalid'); http_response_code(400); echo json_encode(['success'=>false,'error'=>'CSRF token invalid']); exit; }
}
declare(strict_types=1);

require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/role_check.php';
require_role('Admin');

require_once __DIR__ . '/../../config/db.php'; // $pdo = new PDO(...)

$errors = [];
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim(req_post('username') ?? '');
    $password = req_post('password') ?? '';
    $role     = trim(req_post('role') ?? '');
    $passCode = trim(req_post('pass_code') ?? '');

    // Basic validation
    if ($username === '') {
        $errors[] = 'Username is required.';
    }
    if ($password === '') {
        $errors[] = 'Password is required.';
    }
    if (!in_array($role, ['Admin', 'Manager', 'Staff'], true)) {
        $errors[] = 'Invalid role selected.';
    }

    if (!$errors) {
        try {
            // Check if username already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = 'Username already taken.';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare(
                    "INSERT INTO users (username, password_hash, role, pass_code) 
                     VALUES (:username, :password_hash, :role, :pass_code)"
                );
                $stmt->execute([
                    ':username'      => $username,
                    ':password_hash' => $hash,
                    ':role'          => $role,
                    ':pass_code'     => $passCode !== '' ? $passCode : null
                ]);

                $success = "User '{$username}' created successfully.";
            }
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create User</title>
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <style>
        :root { --accent:#0b5fff; --bg:#f7f8fa; --text:#222; }
        body { font-family: system-ui, sans-serif; background: var(--bg); margin:0; }
        main { max-width: 600px; margin: 24px auto; background: #fff; padding: 24px; border: 1px solid #e6e8eb; border-radius: 8px; }
        h1 { margin-top: 0; }
        label { display:block; margin-top: 12px; font-weight: 600; }
        input, select { width:100%; padding: 8px; margin-top:4px; border: 1px solid #ccc; border-radius: 4px; }
        .btn { background: var(--accent); color: #fff; padding: 10px 16px; border: none; border-radius: 4px; margin-top: 16px; cursor: pointer; }
        .btn:hover { background: #094bd1; }
        .msg { padding: 10px; margin-top: 16px; border-radius: 4px; }
        .error { background: #ffecec; color: #d8000c; }
        .success { background: #eaffea; color: #4f8a10; }
    </style>
</head>
<body>

<?php include __DIR__ . '/../partials/navbar.php'; ?>

<main>
    <h1>Create New User</h1>

    <?php if ($errors): ?>
        <div class="msg error">
            <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
        </div>
    <?php elseif ($success): ?>
        <div class="msg success">
            <?= htmlspecialchars($success) ?>
        </div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <label for="username">Username</label>
        <input type="text" name="username" id="username" required>

        <label for="password">Password</label>
        <input type="password" name="password" id="password" required>

        <label for="role">Role</label>
        <select name="role" id="role" required>
            <option value="">Select role</option>
            <option value="Admin">Admin</option>
            <option value="Manager">Manager</option>
            <option value="Staff">Staff</option>
        </select>

        <label for="pass_code">Pass Code (optional)</label>
        <input type="text" name="pass_code" id="pass_code">

        <button type="submit" class="btn">Create User</button>
    </form>
</main>

</body>
</html>