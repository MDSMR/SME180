<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/auth_check.php';
$pdo = get_pdo();

$stmt = $pdo->query("
    SELECT u.id, u.username, u.email, u.disabled_at,
           COALESCE(r.role_name,'Unknown') AS role
    FROM users u
    LEFT JOIN roles r ON r.id = u.role_id
    ORDER BY u.id ASC
");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><title>User Management</title>
  <style>
    body{font-family:Arial,sans-serif;margin:0}
    table{border-collapse:collapse;width:100%;margin:16px}
    th,td{border:1px solid #ddd;padding:8px}
    th{background:#f7f7f7}
    .btn{padding:6px 10px;border:1px solid #333;border-radius:8px;text-decoration:none;margin-right:6px;display:inline-block}
  </style>
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<h1 style="margin:16px">User Management</h1>

<table>
  <thead>
    <tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Status</th><th>Actions</th></tr>
  </thead>
  <tbody>
  <?php if ($users): foreach ($users as $u): ?>
    <tr>
      <td><?= htmlspecialchars((string)$u['id']) ?></td>
      <td><?= htmlspecialchars((string)$u['username']) ?></td>
      <td><?= htmlspecialchars((string)$u['email']) ?></td>
      <td><?= htmlspecialchars((string)$u['role']) ?></td>
      <td><?= $u['disabled_at'] ? 'Disabled' : 'Active' ?></td>
      <td>
        <a class="btn" href="edit_user.php?id=<?= (int)$u['id'] ?>">Edit</a>
        <?php if ($u['disabled_at']): ?>
          <a class="btn" href="toggle_user_status.php?id=<?= (int)$u['id'] ?>&enable=1">Enable</a>
        <?php else: ?>
          <a class="btn" href="toggle_user_status.php?id=<?= (int)$u['id'] ?>&disable=1">Disable</a>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; else: ?>
    <tr><td colspan="6">No users found.</td></tr>
  <?php endif; ?>
  </tbody>
</table>

<p style="margin:16px"><a class="btn" href="add_user.php">Add New User</a></p>
</body>
</html>