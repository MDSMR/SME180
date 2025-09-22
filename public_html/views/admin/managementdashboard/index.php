<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Includes
require __DIR__ . '/../../../config/db.php';
require __DIR__ . '/includes/auth.php';         // Role/session check
require __DIR__ . '/includes/header.php';       // Shared layout

// Fetch users
$stmt = $pdo->prepare("SELECT id, username, email, role, status FROM users ORDER BY id DESC");
$stmt->execute();
$users = $stmt->fetchAll();
?>

<h2>User Management</h2>
<a href="create_user.php">➕ Create New User</a>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Username</th>
      <th>Email</th>
      <th>Role</th>
      <th>Status</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($users as $user): ?>
    <tr>
      <td><?= $user['id'] ?></td>
      <td><?= htmlspecialchars($user['username']) ?></td>
      <td><?= htmlspecialchars($user['email']) ?></td>
      <td><?= $user['role'] ?></td>
      <td>
        <span class="<?= $user['status'] === 'active' ? 'badge-active' : 'badge-disabled' ?>">
          <?= ucfirst($user['status']) ?>
        </span>
      </td>
      <td>
        <a href="edit_user.php?id=<?= $user['id'] ?>">✏️ Edit</a>
        <form method="POST" action="toggle_user.php" style="display:inline;">
          <input type="hidden" name="id" value="<?= $user['id'] ?>">
          <button type="submit" onclick="return confirm('Toggle user status?')">
            <?= $user['status'] === 'active' ? '🚫 Disable' : '✅ Enable' ?>
          </button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</body>
</html>