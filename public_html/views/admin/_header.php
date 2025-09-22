<?php
// views/admin/_header.php — Redesigned (Light) Header, One-line nav, no scroll bar
// Theme: light, primary #2596f8
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$current = basename(parse_url($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'], PHP_URL_PATH));
function nav_active($files, $current){
  foreach((array)$files as $f){ if ($current === basename($f)) return ' aria-current="page"'; }
  return '';
}
?>
<!-- Header Start -->
<link rel="stylesheet" href="/assets/css/admin.css?v=2025-08-13" />
<header class="sm-admin-header" role="banner">
  <div class="sm-header-row" style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
    <!-- Left: Logo -->
    <a class="sm-logo" href="/admin/dashboard.php" title="Dashboard" style="flex-shrink:0;">
      <img src="/assets/images/smorll_logo.png" alt="Smorll" />
    </a>

    <!-- Center: Nav (one line, wrapping disabled, auto shrink) -->
    <nav class="sm-nav" role="navigation" aria-label="Main"
         style="display:flex; flex-wrap:nowrap; white-space:nowrap; overflow-x:hidden; flex:1; justify-content:center; gap:0.5rem;">
      <a class="sm-nav-item" href="/admin/dashboard.php"<?= nav_active(['dashboard.php'], $current) ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>
        <span>Dashboard</span>
      </a>
      <a class="sm-nav-item" href="/views/admin/pos.php"<?= nav_active(['pos.php'], $current) ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 7h18v2H3V7zm2 4h14v8H5v-8zm2 2v4h10v-4H7zM8 3h8v2H8V3z"/></svg>
        <span>POS</span>
      </a>
      <a class="sm-nav-item" href="/views/admin/orders.php"<?= nav_active(['orders.php'], $current) ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 4h10l1 2h3v2h-2l-2.6 9.6A3 3 0 0 1 13.5 20H9a3 3 0 0 1-2.9-2.2L3 6H1V4h4l2 8h9.5l2-7H7V4z"/></svg>
        <span>Orders</span>
      </a>
      <a class="sm-nav-item" href="/views/admin/loyalty.php"<?= nav_active(['loyalty.php'], $current) ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 6 4 4 6.5 4 8.04 4 9.54 4.81 10.35 6.09 11.16 4.81 12.66 4 14.2 4 16.7 4 18.7 6 18.7 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
        <span>Loyalty</span>
      </a>
      <a class="sm-nav-item" href="/views/admin/reports.php"<?= nav_active(['reports.php'], $current) ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3h18v2H3V3zm2 4h4v12H5V7zm6 0h4v12h-4V7zm6 0h4v12h-4V7z"/></svg>
        <span>Reports</span>
      </a>
      <a class="sm-nav-item" href="/views/admin/menu/items.php"<?= nav_active(['items.php'], $current) ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v2H4V6zm0 5h16v2H4v-2zm0 5h16v2H4v-2z"/></svg>
        <span>Products</span>
      </a>
      <a class="sm-nav-item" href="/views/admin/menu/categories.php"<?= nav_active(['categories.php'], $current) ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 3H3v7h7V3zm11 0h-7v7h7V3zM10 14H3v7h7v-7zm11 0h-7v7h7v-7z"/></svg>
        <span>Categories</span>
      </a>
      <a class="sm-nav-item" href="/admin/setup.php"<?= nav_active(['setup.php'], $current) ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M19.14 12.94a7.96 7.96 0 0 0 0-1.88l2.03-1.58a.5.5 0 0 0 .12-.64l-1.92-3.32a.5.5 0 0 0-.6-.22l-2.39.96a7.97 7.97 0 0 0-1.63-.95l-.36-2.54A.5.5 0 0 0 13.9 1h-3.8a.5.5 0 0 0-.5.43L9.24 3.97c-.57.22-1.11.5-1.63.84l-2.4-.96a.5.5 0 0 0-.6.22L2.7 7.39a.5.5 0 0 0 .12.64l2.03 1.58c-.05.31-.08.63-.08.96 0 .32.03.64.08.95L2.82 13.1a.5.5 0 0 0-.12.64l1.92 3.32c.13.22.39.31.6.22l2.39-.96c.5.34 1.06.63 1.63.85l.36 2.53c.05.24.26.43.5.43h3.8c.24 0 .45-.19.49-.43l.36-2.54c.57-.22 1.11-.5 1.63-.84l2.39.96c.23.09.48 0 .6-.22l1.92-3.32a.5.5 0 0 0-.12-.64l-2.03-1.58zM12 15.5A3.5 3.5 0 1 1 12 8.5a3.5 3.5 0 0 1 0 7z"/></svg>
        <span>Settings</span>
      </a>
    </nav>

    <!-- Right: Logout -->
    <div class="sm-right" style="flex-shrink:0; display:flex; align-items:center; gap:0.5rem;">
      <?php if (!empty($_SESSION['admin_username'])): ?>
        <span class="sm-welcome">Hi, <?= htmlspecialchars($_SESSION['admin_username']) ?></span>
      <?php endif; ?>
      <a class="sm-logout btn" href="/admin/logout.php" title="Logout">Logout</a>
    </div>
  </div>
</header>
<!-- Header End -->