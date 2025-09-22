<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$username = htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
$current  = $_SERVER['SCRIPT_NAME'] ?? '';

function nav_active($needles){
  global $current;
  foreach ((array)$needles as $n) {
    if (strpos($current, $n) !== false) return 'active';
  }
  return '';
}
?>
<style>
:root{
  --brand-blue: rgb(0, 123, 255);
  --brand-blue-700: rgb(0, 105, 217);
  --border:#e5e7eb;
  --header-h:64px; /* fallback; JS below sets real height */
}

/* Fixed header */
.app-header{
  position:fixed; top:0; left:0; right:0;
  background:#fff; border-bottom:1px solid var(--border);
  display:flex; align-items:center; gap:12px; padding:10px 12px; z-index:1000;
}

/* Centering: left spacer + right tools; nav sits centered */
.app-left, .app-right{ width:260px; display:flex; align-items:center; }
.app-left{ visibility:hidden; } /* spacer only */
.app-right{ justify-content:flex-end; gap:10px; }

/* Nav row: no scrollbar, wraps when needed, centered */
.app-nav{
  display:flex; gap:8px; flex-wrap:wrap;
  overflow:visible;            /* prevents scrollbars */
  white-space:normal;          /* allow wrapping */
  margin:0 auto; justify-content:center;
}

/* Links (text only; **no icons**) */
.app-link{
  display:inline-flex; align-items:center; padding:8px 12px; border-radius:10px;
  text-decoration:none; background:var(--brand-blue); color:#fff; font:600 13px/1 system-ui;
}
.app-link:hover{ background:var(--brand-blue-700); }
.app-link.active{ box-shadow:0 0 0 2px rgba(0,0,0,.04) inset; }

/* Right area */
.app-user{ padding:6px 10px; border-radius:999px; background:rgba(0,123,255,.12); color:rgb(0,105,217); font:600 12px/1 system-ui; }
.app-signout{ color:var(--brand-blue); text-decoration:none; font:600 12px/1 system-ui; }
.app-signout:hover{ color:var(--brand-blue-700); }
</style>

<header class="app-header" id="appHeader">
  <div class="app-left" aria-hidden="true"></div>

  <nav class="app-nav" aria-label="Primary">
    <!-- POS sits with the rest (centered); text only -->
    <a class="app-link <?= nav_active(['/views/admin/dashboard.php']) ?>" href="/views/admin/dashboard.php" title="POS">POS</a>

    <!-- Dashboard -->
    <a class="app-link <?= nav_active(['/views/admin/dashboard.php']) ?>" href="/views/admin/dashboard.php" title="Dashboard">Dashboard</a>

    <!-- Categories -->
    <a class="app-link <?= nav_active(['/views/admin/menu/categories.php']) ?>" href="/views/admin/menu/categories.php" title="Categories">Categories</a>

    <!-- Items (separate link; ensure items.php exists) -->
    <a class="app-link <?= nav_active(['/views/admin/menu/items.php']) ?>" href="/views/admin/menu/items.php" title="Items">Items</a>

    <a class="app-link <?= nav_active(['/views/tables/map.php']) ?>" href="/views/tables/map.php" title="Tables">Tables</a>
    <a class="app-link <?= nav_active(['/orders/']) ?>" href="/orders/" title="Orders">Orders</a>
    <a class="app-link <?= nav_active(['/views/admin/setup.php']) ?>" href="/views/admin/setup.php" title="Setup">Setup</a>
    <a class="app-link <?= nav_active(['/views/admin/management.php']) ?>" href="/views/admin/management.php" title="Management">Management</a>
  </nav>

  <div class="app-right">
    <?php if ($username !== ''): ?><div class="app-user"><?= $username ?></div><?php endif; ?>
    <a class="app-signout" href="/views/auth/logout.php" title="Sign out">Sign out</a>
  </div>
</header>

<script>
// Keep page content below the nav, even if the header wraps to two lines
(function(){
  function adjustPadding(){
    var h = document.getElementById('appHeader');
    if(!h) return;
    var ph = h.offsetHeight || 64;
    document.documentElement.style.setProperty('--header-h', ph + 'px');
    document.body.style.paddingTop = ph + 'px';
  }
  window.addEventListener('load', adjustPadding);
  window.addEventListener('resize', adjustPadding);
})();
</script>