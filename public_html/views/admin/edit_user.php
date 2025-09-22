<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth_check.php';
$pdo = get_pdo();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { http_response_code(400); exit('Invalid user id'); }

$userStmt = $pdo->prepare("SELECT id, username, email, role_id FROM users WHERE id = ?");
$userStmt->execute([$id]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) { http_response_code(404); exit('User not found'); }

$roles = $pdo->query("SELECT id, role_name FROM roles ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $role_id  = (int)($_POST['role_id'] ?? 0);

    if ($username === '' || $email === '' || $role_id <= 0) {
        $error = 'All fields are required.';
    } else {
        $upd = $pdo->prepare("UPDATE users SET username=?, email=?, role_id=? WHERE id=?");
        $upd->execute([$username, $email, $role_id, $id]);
        header('Location: /views/admin/user_management.php'); exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><title>Edit User</title>
  <style>
    body{font-family:Arial,sans-serif;margin:0}
    label{display:block;margin:10px 16px 4px}
    input,select{margin:0 16px 8px;padding:10px;width:320px;max-width:90%}
    .btn{margin:10px 16px;padding:8px 12px;border:1px solid #333;border-radius:8px;text-decoration:none;display:inline-block}
    .err{color:#b00020;margin:8px 16px}
  </style>
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<h1 style="margin:16px">Edit User</h1>
<?php if (!empty($error)): ?><div class="err"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<form method="post">
  <label>Username</label>
  <input name="username" value="<?= htmlspecialchars((string)$user['username']) ?>" required>

  <label>Email</label>
  <input type="email" name="email" value="<?= htmlspecialchars((string)$user['email']) ?>" required>

  <label>Role</label>
  <select name="role_id" required>
    <?php foreach ($roles as $r): ?>
      <option value="<?= (int)$r['id'] ?>" <?= ((int)$user['role_id'] === (int)$r['id']) ? 'selected' : '' ?>>
        <?= htmlspecialchars((string)$r['role_name']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <div>
    <button class="btn" type="submit">Save</button>
    <a class="btn" href="/views/admin/user_management.php">Cancel</a>
  </div>
</form>
</body>
</html>