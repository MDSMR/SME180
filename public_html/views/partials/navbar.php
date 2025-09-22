<?php
declare(strict_types=1);
/**
 * Shared navbar partial.
 * Usage: include __DIR__ . '/../partials/navbar.php';
 * Expects $_SESSION['username'] and $_SESSION['role'] to be set after login.
 */
$navUser = htmlspecialchars($_SESSION['username'] ?? 'User', ENT_QUOTES, 'UTF-8');
$navRole = htmlspecialchars($_SESSION['role'] ?? 'Guest', ENT_QUOTES, 'UTF-8');
?>
<header>
  <style>
    header { background:#fff; border-bottom:1px solid #e6e8eb; padding:10px 14px; display:flex; justify-content:space-between; align-items:center; }
    nav a { margin-right:12px; color: rgb(0, 123, 255); text-decoration:none; font-weight:600; }
    nav a:hover { color: rgb(0, 105, 217); }
    .pill { background: rgba(0,123,255,.10); color: rgb(0,105,217); padding:4px 8px; border-radius:999px; font-size:12px; margin-left:8px; }
    .right { color:#444; font-size:14px; }
  </style>
  <div>
    <strong>Admin Panel</strong>
    <span class="pill"><?= $navRole ?></span>
  </div>
  <nav>
    <a href="/views/admin/dashboard.php">Dashboard</a>
    <?php if ($navRole === 'Admin'): ?>
      <a href="/views/admin/user_management.php">User Management</a>
    <?php endif; ?>
    <a href="/views/auth/logout.php">Logout</a>
  </nav>
  <div class="right">Hi, <?= $navUser ?></div>
</header>