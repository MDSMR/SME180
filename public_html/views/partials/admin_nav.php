<?php
declare(strict_types=1);
/**
 * Admin Sidebar Navigation (safe partial with inline error catcher)
 * Parent must bootstrap session/config and start session.
 * Add ?debug=1 to the page URL to see inline errors (otherwise only logs).
 * 
 * NOTE: This partial leaves the admin-content div OPEN for page content injection.
 * Pages must call the closing partial or manually close the layout.
 */

/* ---------- Mini error catcher (scoped) ---------- */
$__NAV_DEBUG   = (isset($_GET['debug']) && $_GET['debug'] === '1');
$__nav_err     = null;
$__nav_err_bt  = null;
$__prev_eh     = set_error_handler(function($severity, $message, $file, $line) use (&$__nav_err, &$__nav_err_bt) {
  if (!(error_reporting() & $severity)) return false;
  $__nav_err = new ErrorException($message, 0, $severity, $file, $line);
  $__nav_err_bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
  throw $__nav_err;
});

$__nav_last_fatal = null;
register_shutdown_function(function() use ($__NAV_DEBUG, &$__nav_last_fatal) {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    $__nav_last_fatal = $e;
    error_log('[NAV] fatal: '.$e['message'].' in '.$e['file'].':'.$e['line']);
    if ($__NAV_DEBUG) {
      echo '<div style="background:#fff1f2;border:1px solid #fecaca;color:#991b1b;padding:12px;border-radius:8px;margin:12px;">
        <strong>NAV fatal:</strong> '.htmlspecialchars($e['message'],ENT_QUOTES,'UTF-8').'<br>
        <small>'.htmlspecialchars($e['file'],ENT_QUOTES,'UTF-8').' : '.(int)$e['line'].'</small>
      </div>';
    }
  }
});

/* Polyfill */
if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool {
    return $needle !== '' && strpos($haystack, $needle) !== false;
  }
}

/* Escape */
if (!function_exists('__nav_h')) {
  function __nav_h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}

/* ---------- Resolve links/state ---------- */
try {
  $__user = (isset($_SESSION['user']) && is_array($_SESSION['user'])) ? $_SESSION['user'] : [
    'name'      => 'User',
    'username'  => isset($_SESSION['username']) ? $_SESSION['username'] : 'user',
    'role'      => 'staff',
    'role_key'  => 'staff',
    'tenant_id' => isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : 0,
    'id'        => isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0,
  ];

  $__active = (isset($active) && is_string($active)) ? $active : '';

  $script = (string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? ''));
  $uri    = (string)($_SERVER['REQUEST_URI'] ?? $script);

  // Enhanced active state detection based on actual URLs
  $__isCatalog   = in_array($__active, ['products','categories','modifiers'], true);
  $__isStockflow = in_array($__active, ['stockflow_create','stockflow_view'], true);
  $__isOrders    = in_array($__active, ['orders_create','orders_view'], true);
  $__isRewards   = in_array($__active, ['rewards_points_view','rewards_stamps_view','rewards_cashback_view','rewards_discounts_view'], true);
  $__isCustomers = in_array($__active, ['customers_view','customers_rewards'], true);
  $__isReports   = in_array($__active, ['reports_sales','reports_orders','reports_stockflow','reports_rewards','reports_tables'], true);
  $__isSetup     = in_array($__active, ['settings_general','settings_finance','settings_users','settings_hardware','settings_tables'], true);

  // Auto-detection based on URL patterns
  if (!$__isCatalog   && (str_contains($script, '/views/admin/catalog/') || str_contains($uri, '/views/admin/catalog/'))) $__isCatalog = true;
  if (!$__isStockflow && (str_contains($script, '/views/admin/stockflow/') || str_contains($uri, '/views/admin/stockflow/'))) $__isStockflow = true;
  if (!$__isOrders    && (str_contains($script, '/views/admin/orders/') || str_contains($uri, '/views/admin/orders/'))) $__isOrders = true;
  if (!$__isRewards   && (str_contains($script, '/views/admin/rewards/') || str_contains($uri, '/views/admin/rewards/'))) $__isRewards = true;
  if (!$__isCustomers && (str_contains($script, '/views/admin/customers/') || str_contains($uri, '/views/admin/customers/'))) $__isCustomers = true;
  if (!$__isReports   && (str_contains($script, '/views/admin/reports/') || str_contains($uri, '/views/admin/reports/'))) $__isReports = true;
  if (!$__isSetup     && (str_contains($script, '/views/admin/settings/') || str_contains($uri, '/views/admin/settings/'))) $__isSetup = true;

  $__docroot    = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
  $__publicRoot = realpath(__DIR__ . '/../../..'); if ($__publicRoot === false) $__publicRoot = '';

  $fsExists = function(string $webPath) use ($__docroot, $__publicRoot): bool {
    $w = '/' . ltrim($webPath, '/');
    $fs1 = ($__docroot !== '' ? $__docroot . $w : '');
    $fs2 = ($__publicRoot !== '' ? rtrim($__publicRoot, '/') . $w : '');
    if ($fs1 !== '' && (is_file($fs1) || (is_dir($fs1) && is_file($fs1.'/index.php')))) return true;
    if ($fs2 !== '' && (is_file($fs2) || (is_dir($fs2) && is_file($fs2.'/index.php')))) return true;
    return false;
  };

  $href = function(array $candidates) use ($fsExists): string {
    foreach ($candidates as $path) {
      if ($path === '#' || $path === '') continue;
      if ($fsExists($path)) return $path;
    }
    return $candidates[0] ?? '#';
  };

  /* Exact URLs based on your actual structure */
  $__links = [
    'dashboard' => [
      '/views/admin/dashboard.php',
      '/admin/dashboard'
    ],
    
    // Catalog section
    'products' => [
      '/views/admin/catalog/products.php'
    ],
    'categories' => [
      '/views/admin/catalog/categories.php'
    ],
    'modifiers' => [
      '/views/admin/catalog/modifiers.php'
    ],
    
    // Stockflow section  
    'stockflow_create' => [
      '/views/admin/stockflow/transfer.php'
    ],
    'stockflow_view' => [
      '/views/admin/stockflow/index.php'
    ],
    
    // Orders section
    'orders_create' => [
      '/views/admin/orders/create.php'
    ],
    'orders_view' => [
      '/views/admin/orders/index.php'
    ],
    
    // Rewards section - Points
    'rewards_points_view' => [
      '/views/admin/rewards/points/index.php'
    ],
    'rewards_points_create' => [
      '/views/admin/rewards/points/create.php', // Will be developed
      '#'
    ],
    
    // Rewards section - Stamps
    'rewards_stamps_view' => [
      '/views/admin/rewards/stamp/index.php'
    ],
    'rewards_stamps_create' => [
      '/views/admin/rewards/stamp/create.php', // Will be developed
      '#'
    ],
    
    // Rewards section - Cashback
    'rewards_cashback_view' => [
      '/views/admin/rewards/cashback/index.php'
    ],
    'rewards_cashback_create' => [
      '/views/admin/rewards/cashback/create.php', // Will be developed
      '#'
    ],
    
    // Rewards section - Discounts
    'rewards_discounts_view' => [
      '/views/admin/rewards/discounts/index.php'
    ],
    'rewards_discounts_create' => [
      '/views/admin/rewards/discounts/create.php', // Will be developed
      '#'
    ],
    
    // Customers section (top-level)
    'customers_view' => [
      '/views/admin/customers/index.php',
      '#'
    ],
    'customers_rewards' => [
      '/views/admin/customers/rewards.php'
    ],
    
    // Reports section
    'reports_sales' => [
      '/views/admin/reports/sales.php', // Will be developed
      '#'
    ],
    'reports_orders' => [
      '/views/admin/reports/orders.php', // Will be developed
      '#'
    ],
    'reports_stockflow' => [
      '/views/admin/reports/stockflow.php', // Will be developed
      '#'
    ],
    'reports_rewards' => [
      '/views/admin/reports/rewards.php', // Will be developed
      '#'
    ],
    'reports_tables' => [
      '/views/admin/reports/tables.php', // Will be developed
      '#'
    ],
    
    // Setup section - INCLUDING TABLES (ordered as requested)
    'settings_general' => [
      '/views/admin/settings/general.php'
    ],
    'settings_tables' => [
      '/views/admin/settings/tables/index.php'
    ],
    'settings_finance' => [
      '/views/admin/settings/finance.php'
    ],
    'settings_users' => [
      '/views/admin/settings/users.php'
    ],
    'settings_hardware' => [
      '/views/admin/settings/printers.php'
    ],
    
    'logout' => [
    '/views/auth/logout.php',
    '/logout.php',
    '/admin/logout',
    ],
  ];
  $L = [];
  foreach ($__links as $key => $cands) { $L[$key] = $href($cands); }

  $__logoCandidates = [
    '/assets/brand/smorll.svg','/assets/brand/logo.svg','/assets/brand/smorll.png','/assets/brand/logo.png','/assets/brand/logo.webp',
    '/assets/images/smorll.svg','/assets/images/logo.svg','/assets/images/smorll.png','/assets/images/logo.png','/assets/images/logo.webp',
    '/assets/img/smorll.svg','/assets/img/logo.svg','/assets/img/smorll.png','/assets/img/logo.png','/assets/img/logo.webp',
    '/assets/logo.svg','/assets/logo.png','/assets/logo.webp',
    '/images/smorll.svg','/images/logo.svg','/images/smorll.png','/images/logo.png','/images/logo.webp',
  ];
  $__logoUrl = null;
  foreach ($__logoCandidates as $p) { if ($fsExists($p)) { $__logoUrl = $p; break; } }

  // Calculate user initials for avatar
  $userName = $__user['name'] ?? $__user['username'] ?? 'User';
  $initials = '';
  $nameParts = explode(' ', trim($userName));
  if (count($nameParts) >= 2) {
      $initials = strtoupper(substr($nameParts[0], 0, 1) . substr($nameParts[1], 0, 1));
  } else {
      $initials = strtoupper(substr($userName, 0, 2));
  }

} catch (Throwable $e) {
  $__nav_err = $e;
  error_log('[NAV] error: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());
} finally {
  if ($__prev_eh) { set_error_handler($__prev_eh); } else { restore_error_handler(); }
}
?>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

<style>
:root{
  /* Microsoft 365 Color Palette - matching your catalog page */
  --bg-primary:#faf9f8; --bg-secondary:#f3f2f1; --card-bg:#ffffff;
  --text-primary:#323130; --text-secondary:#605e5c; --text-tertiary:#8a8886;
  --primary:#0078d4; --primary-hover:#106ebe; --primary-light:#deecf9; --primary-lighter:#f3f9fd;
  --border:#edebe9; --border-light:#f8f6f4; --hover:#f3f2f1;
  --shadow-sm:0 1px 2px rgba(0,0,0,.04), 0 1px 1px rgba(0,0,0,.06);
  --shadow-md:0 4px 8px rgba(0,0,0,.04), 0 1px 3px rgba(0,0,0,.06);
  --shadow-lg:0 8px 16px rgba(0,0,0,.06), 0 2px 4px rgba(0,0,0,.08);
  --transition:all .1s cubic-bezier(.1,.9,.2,1);
  --radius:4px; --radius-lg:8px;
  
  /* Header-specific colors */
  --header-bg:var(--primary);
  --header-text:#ffffff;
}

/* Layout */
.admin-layout{ display:flex; min-height:100vh; }
.admin-sidebar{ 
  width:280px; 
  background:var(--card-bg); 
  color:var(--text-primary); 
  position:fixed; 
  left:0; 
  top:0; 
  height:100vh; 
  z-index:100; 
  transition:transform 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
  overflow-y:auto;
  scrollbar-width:thin;
  scrollbar-color:var(--border) transparent;
  border-right:1px solid var(--border);
  box-shadow:var(--shadow-sm);
}
.admin-sidebar::-webkit-scrollbar{ width:6px; }
.admin-sidebar::-webkit-scrollbar-track{ background:transparent; }
.admin-sidebar::-webkit-scrollbar-thumb{ background:var(--border); border-radius:3px; }
.admin-sidebar::-webkit-scrollbar-thumb:hover{ background:var(--text-tertiary); }

.admin-sidebar.collapsed{ transform:translateX(-280px); }
.admin-content{ 
  flex:1; 
  margin-left:280px; 
  transition:margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
  min-height:100vh;
  background:var(--bg-primary);
  display:flex;
  flex-direction:column;
}
.admin-content.expanded{ margin-left:0; }

/* Page content wrapper */
.page-content{
  flex:1;
  padding-bottom:80px; /* Space for footer */
}

/* Top Header - Updated with Microsoft Light Blue */
.admin-header{ 
  position:sticky; 
  top:0; 
  z-index:90; 
  background:var(--header-bg); 
  color:var(--header-text);
  border-bottom:1px solid var(--border); 
  box-shadow:var(--shadow-sm); 
  padding:0 24px;
  height:64px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  box-sizing:border-box;
}

/* Sidebar Toggle */
.sidebar-toggle{ 
  background:none; 
  border:none; 
  color:white; 
  cursor:pointer; 
  padding:8px; 
  border-radius:var(--radius); 
  transition:var(--transition); 
  display:flex; 
  align-items:center; 
  justify-content:center;
}
.sidebar-toggle:hover{ 
  background:rgba(255,255,255,0.15); 
  color:white; 
}
.sidebar-toggle svg{ width:16px; height:16px; }

/* Brand - Fixed alignment with header */
.sidebar-brand{ 
  padding:19px 20px 18px 20px; 
  border-bottom:1px solid var(--border); 
  display:flex; 
  align-items:center; 
  gap:12px;
  height:64px;
  box-sizing:border-box;
  background:var(--header-bg);
}
.brand-logo{ 
  display:flex; 
  align-items:center; 
  color:var(--text-primary); 
  font-family:'Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,Roboto,'Helvetica Neue',sans-serif; 
  font-size:22px; 
  font-weight:600; 
  user-select:none; 
  flex:1;
}
.brand-img{ height:32px; width:auto; display:block; }
.brand-text{ margin-left:8px; display:inline-block; }

/* Navigation */
.sidebar-nav{ padding:8px 0; flex:1; }
.nav-section{ margin-bottom:4px; }

.nav-item{ margin-bottom:4px; }
.nav-link{ 
  display:flex; 
  align-items:center; 
  padding:8px 20px; 
  color:var(--text-secondary); 
  text-decoration:none; 
  font-family:'Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,Roboto,'Helvetica Neue',sans-serif; 
  font-size:15px; 
  font-weight:400; 
  transition:var(--transition); 
  position:relative;
  border:none;
  background:none;
  width:100%;
  cursor:pointer;
  text-align:left;
  border-left:3px solid transparent;
}
.nav-link:hover{ 
  background:var(--hover); 
  color:var(--text-primary); 
}
.nav-link.active{ 
  background:var(--primary-lighter); 
  color:var(--primary); 
  font-weight:600;
  border-left-color:var(--primary);
}

.nav-icon{ 
  width:16px; 
  height:16px; 
  margin-right:12px; 
  flex-shrink:0;
}

/* Section Headers */
.nav-header{ 
  display:flex; 
  align-items:center; 
  justify-content:space-between;
  padding:8px 20px; 
  color:var(--text-secondary); 
  font-family:'Segoe UI',system-ui,-apple-system,BlinkMacSystemFont,Roboto,'Helvetica Neue',sans-serif; 
  font-size:15px; 
  font-weight:400; 
  cursor:pointer;
  transition:var(--transition);
  background:none;
  border:none;
  width:100%;
  text-align:left;
  margin:0 0 4px 0;
}
.nav-header:hover{ 
  color:var(--text-primary); 
  background:var(--hover);
}
.nav-header.expanded{ color:var(--primary); }

.nav-chevron{ 
  width:12px; 
  height:12px; 
  transition:transform 0.2s ease; 
  stroke-width:2;
}
.nav-header.expanded .nav-chevron{ transform:rotate(90deg); }

/* Sub-navigation */
.nav-subnav{ 
  max-height:0; 
  overflow:hidden; 
  transition:max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  margin:0 12px 8px 12px;
}
.nav-subnav.expanded{ 
  max-height:500px; 
  padding:4px 0;
}
.nav-sublink{ 
  display:flex; 
  align-items:center; 
  padding:6px 16px 6px 40px; 
  color:var(--text-secondary); 
  text-decoration:none; 
  font-size:14px; 
  font-weight:400; 
  transition:var(--transition);
  border-radius:var(--radius);
  margin:0 6px;
  position:relative;
  border-left:3px solid transparent;
}
.nav-sublink:hover{ 
  background:#e3f2fd; 
  color:var(--primary); 
}
.nav-sublink.active{ 
  background:var(--primary-lighter); 
  color:var(--primary); 
  font-weight:600;
  border-left-color:var(--primary);
}
.nav-sublink.disabled{
  color:var(--text-tertiary);
  cursor:not-allowed;
  font-style:italic;
}
.nav-sublink.disabled:hover{
  background:none;
  color:var(--text-tertiary);
}

/* Coming Soon badge */
.coming-soon{
  font-size:9px;
  background:var(--border);
  color:var(--text-tertiary);
  padding:2px 6px;
  border-radius:10px;
  margin-left:auto;
  font-weight:500;
  text-transform:uppercase;
  letter-spacing:0.5px;
}

/* Header User Info - Updated for new header background */
.header-user{ 
  display:flex; 
  align-items:center; 
  gap:8px; 
  color:white; 
  font-size:13px; 
  font-weight:400;
  cursor:pointer;
  padding:6px 12px;
  border-radius:var(--radius);
  transition:var(--transition);
  position:relative;
}
.header-user:hover{
  background:rgba(255,255,255,0.15);
  color:white;
}
.header-user.active{
  background:rgba(255,255,255,0.25);
}
.header-user-avatar{ 
  width:24px; 
  height:24px; 
  border-radius:50%; 
  background:rgba(255,255,255,0.2); 
  color:white; 
  display:flex; 
  align-items:center; 
  justify-content:center; 
  font-weight:600; 
  font-size:11px; 
  border:1px solid rgba(255,255,255,0.3);
}
.header-user-chevron{
  width:12px;
  height:12px;
  color:rgba(255,255,255,0.8);
  transition:transform 0.2s ease;
  margin-left:4px;
}
.header-user.active .header-user-chevron{
  transform:rotate(180deg);
}

/* Header User Dropdown */
.header-user-dropdown{
  position:absolute;
  top:calc(100% + 6px);
  right:0;
  background:var(--card-bg);
  border:1px solid var(--border);
  border-radius:var(--radius-lg);
  box-shadow:var(--shadow-lg);
  min-width:120px;
  z-index:1000;
  display:none;
  overflow:hidden;
}
.header-user-dropdown.show{
  display:block;
  animation:headerDropdownFadeIn 0.15s ease-out;
}
@keyframes headerDropdownFadeIn{
  from{opacity:0; transform:translateY(-4px);}
  to{opacity:1; transform:translateY(0);}
}
.header-dropdown-item{
  display:flex;
  align-items:center;
  gap:8px;
  width:100%;
  padding:8px 12px;
  font-size:13px;
  font-weight:400;
  color:var(--text-secondary);
  text-decoration:none;
  border:none;
  background:none;
  text-align:left;
  cursor:pointer;
  transition:var(--transition);
}
.header-dropdown-item:hover{
  background:var(--hover);
  color:var(--text-primary);
}
.header-dropdown-item.danger{
  color:#d13438;
}
.header-dropdown-item.danger:hover{
  background:#fdf2f2;
  color:#b91c1c;
}
.header-dropdown-icon{
  width:14px;
  height:14px;
  flex-shrink:0;
}

/* Admin Footer */
.admin-footer{
  background: #ffffff;
  border-top: 1px solid #edebe9;
  padding: 16px 24px;
  margin-top: auto;
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
  font-size: 12px;
  color: #8a8886;
  position: relative;
  clear: both;
}

.admin-footer-container{
  max-width: 1400px;
  margin: 0 auto;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 16px;
}

.admin-footer-copyright{
  color: #605e5c;
}

.admin-footer-links{
  display: flex;
  gap: 16px;
  align-items: center;
  font-size: 11px;
}

.admin-footer-link{
  color: #8a8886;
  text-decoration: none;
  transition: color 0.2s;
}

.admin-footer-link:hover{
  color: #0078d4;
}

.admin-footer-separator{
  color: #d2d0ce;
}

.admin-footer-version{
  color: #a8a6a4;
}

/* Mobile Overlay */
.sidebar-overlay{ 
  position:fixed; 
  top:0; 
  left:0; 
  right:0; 
  bottom:0; 
  background:rgba(0,0,0,0.3); 
  z-index:99; 
  display:none; 
  opacity:0; 
  transition:opacity 0.3s ease;
}
.sidebar-overlay.show{ 
  display:block; 
  opacity:1; 
}

/* Responsive */
@media (max-width: 768px) {
  .admin-sidebar{ 
    transform:translateX(-280px); 
  }
  .admin-sidebar.mobile-open{ 
    transform:translateX(0); 
  }
  .admin-content{ 
    margin-left:0; 
  }
  .admin-header{ 
    padding:0 16px; 
  }
  .sidebar-brand{ 
    padding:20px; 
    height:64px;
  }
  .nav-link{ 
    padding:12px 20px; 
  }
  .nav-header{ 
    padding:12px 20px; 
  }
}

@media (max-width: 640px) {
  .admin-header{ 
    height:60px; 
    padding:0 12px; 
  }
  .sidebar-brand{
    height:60px;
    padding:18px 20px 17px 20px;
  }
  .header-user{ 
    gap:8px; 
    font-size:13px; 
  }
  .header-user-avatar{ 
    width:28px; 
    height:28px; 
    font-size:11px; 
  }
  .admin-footer{
    text-align: center;
  }
  .admin-footer-container{
    flex-direction: column;
    gap: 8px !important;
  }
  .admin-footer-links{
    font-size: 10px !important;
  }
}
</style>

<?php if ($__NAV_DEBUG && ($__nav_err || $__nav_last_fatal)): ?>
  <div style="background:#fff7ed;border:1px solid #fed7aa;color:#7c2d12;padding:12px;border-radius:8px;margin:12px;">
    <strong>NAV debug:</strong>
    <?php if ($__nav_err): ?>
      <?= __nav_h($__nav_err->getMessage()) ?><br>
      <small><?= __nav_h($__nav_err->getFile()) ?> : <?= (int)$__nav_err->getLine() ?></small>
    <?php elseif ($__nav_last_fatal): ?>
      <?= __nav_h($__nav_last_fatal['message']) ?><br>
      <small><?= __nav_h($__nav_last_fatal['file']) ?> : <?= (int)$__nav_last_fatal['line'] ?></small>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="admin-layout">
  <!-- Sidebar -->
  <aside class="admin-sidebar" id="adminSidebar">
    <div class="sidebar-brand">
      <div class="brand-logo" aria-label="Smorll">
        <span class="brand-text" style="color:white;">Smorll</span>
      </div>
    </div>

    <nav class="sidebar-nav" aria-label="Primary navigation">
      <!-- Dashboard - Only clickable main item -->
      <div class="nav-item">
        <a href="<?= __nav_h($L['dashboard']) ?>" class="nav-link <?= ($__active==='dashboard' ? 'active' : '') ?>">
          <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor">
            <path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/>
          </svg>
          Dashboard
        </a>
      </div>

      <!-- Catalog Section -->
      <div class="nav-section">
        <button class="nav-header <?= ($__isCatalog ? 'expanded' : '') ?>" data-section="catalog">
          <div style="display:flex;align-items:center;">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor">
              <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
            </svg>
            Catalog
          </div>
          <svg class="nav-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
        <div class="nav-subnav <?= ($__isCatalog ? 'expanded' : '') ?>" id="catalog-subnav">
          <a href="<?= __nav_h($L['products']) ?>" class="nav-sublink <?= ($__active==='products' ? 'active' : '') ?>">Products</a>
          <a href="<?= __nav_h($L['categories']) ?>" class="nav-sublink <?= ($__active==='categories' ? 'active' : '') ?>">Categories</a>
          <a href="<?= __nav_h($L['modifiers']) ?>" class="nav-sublink <?= ($__active==='modifiers' ? 'active' : '') ?>">Modifiers</a>
        </div>
      </div>

      <!-- Stockflow Section -->
      <div class="nav-section">
        <button class="nav-header <?= ($__isStockflow ? 'expanded' : '') ?>" data-section="stockflow">
          <div style="display:flex;align-items:center;">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor">
              <path d="M20 6h-2.18c.11-.31.18-.65.18-1a2.996 2.996 0 00-5.5-1.65l-.5.67-.5-.68C10.96 2.54 10.05 2 9 2 7.34 2 6 3.34 6 5c0 .35.07.69.18 1H4c-1.11 0-1.99.89-1.99 2L2 19c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zm-5-2c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zM9 4c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1z"/>
            </svg>
            Stockflow
          </div>
          <svg class="nav-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
        <div class="nav-subnav <?= ($__isStockflow ? 'expanded' : '') ?>" id="stockflow-subnav">
          <a href="<?= __nav_h($L['stockflow_view']) ?>" class="nav-sublink <?= ($__active==='stockflow_view' ? 'active' : '') ?>">View</a>
          <a href="<?= __nav_h($L['stockflow_create']) ?>" class="nav-sublink <?= ($__active==='stockflow_create' ? 'active' : '') ?>">Create</a>
        </div>
      </div>

      <!-- Orders Section -->
      <div class="nav-section">
        <button class="nav-header <?= ($__isOrders ? 'expanded' : '') ?>" data-section="orders">
          <div style="display:flex;align-items:center;">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor">
              <path d="M7 18c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12L8.1 13h7.45c.75 0 1.41-.41 1.75-1.03L21.7 4H5.21l-.94-2H1zm16 16c-1.1 0-2 .9-2 2s.9 2 2 2 2-.9 2-2-.9-2-2-2z"/>
            </svg>
            Orders
          </div>
          <svg class="nav-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
        <div class="nav-subnav <?= ($__isOrders ? 'expanded' : '') ?>" id="orders-subnav">
          <a href="<?= __nav_h($L['orders_view']) ?>" class="nav-sublink <?= ($__active==='orders_view' ? 'active' : '') ?>">View</a>
          <a href="<?= __nav_h($L['orders_create']) ?>" class="nav-sublink <?= ($__active==='orders_create' ? 'active' : '') ?>">Create</a>
        </div>
      </div>

      <!-- Customers Section (Top-level) -->
      <div class="nav-section">
        <button class="nav-header <?= ($__isCustomers ? 'expanded' : '') ?>" data-section="customers">
          <div style="display:flex;align-items:center;">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 12c2.761 0 5-2.239 5-5S14.761 2 12 2 7 4.239 7 7s2.239 5 5 5zm0 2c-4.418 0-8 2.239-8 5v1h16v-1c0-2.761-3.582-5-8-5z"/>
            </svg>
            Customers
          </div>
          <svg class="nav-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
        <div class="nav-subnav <?= ($__isCustomers ? 'expanded' : '') ?>" id="customers-subnav">
          <a href="<?= __nav_h($L['customers_view']) ?>" class="nav-sublink <?= ($__active==='customers_view' ? 'active' : '') ?>">View</a>
          <a href="<?= __nav_h($L['customers_rewards']) ?>" class="nav-sublink <?= ($__active==='customers_rewards' ? 'active' : '') ?>">Rewards</a>
        </div>
      </div>

      <!-- Rewards Section -->
      <div class="nav-section">
        <button class="nav-header <?= ($__isRewards ? 'expanded' : '') ?>" data-section="rewards">
          <div style="display:flex;align-items:center;">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor">
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/>
            </svg>
            Rewards
          </div>
          <svg class="nav-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
        <div class="nav-subnav <?= ($__isRewards ? 'expanded' : '') ?>" id="rewards-subnav">
          <a href="<?= __nav_h($L['rewards_points_view']) ?>" class="nav-sublink <?= ($__active==='rewards_points_view' ? 'active' : '') ?>">Points</a>
          <a href="<?= __nav_h($L['rewards_stamps_view']) ?>" class="nav-sublink <?= ($__active==='rewards_stamps_view' ? 'active' : '') ?>">Stamps</a>
          <a href="<?= __nav_h($L['rewards_cashback_view']) ?>" class="nav-sublink <?= ($__active==='rewards_cashback_view' ? 'active' : '') ?>">Cashback</a>
          <a href="<?= __nav_h($L['rewards_discounts_view']) ?>" class="nav-sublink <?= ($__active==='rewards_discounts_view' ? 'active' : '') ?>">Discounts</a>
        </div>
      </div>

      <!-- Reports Section -->
      <div class="nav-section">
        <button class="nav-header <?= ($__isReports ? 'expanded' : '') ?>" data-section="reports">
          <div style="display:flex;align-items:center;">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor">
              <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z"/>
            </svg>
            Reports
          </div>
          <svg class="nav-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
        <div class="nav-subnav <?= ($__isReports ? 'expanded' : '') ?>" id="reports-subnav">
          <a href="<?= __nav_h($L['reports_sales']) ?>" class="nav-sublink disabled <?= ($__active==='reports_sales' ? 'active' : '') ?>">
            Sales <span class="coming-soon">Soon</span>
          </a>
          <a href="<?= __nav_h($L['reports_orders']) ?>" class="nav-sublink disabled <?= ($__active==='reports_orders' ? 'active' : '') ?>">
            Orders <span class="coming-soon">Soon</span>
          </a>
          <a href="<?= __nav_h($L['reports_stockflow']) ?>" class="nav-sublink disabled <?= ($__active==='reports_stockflow' ? 'active' : '') ?>">
            Stockflow <span class="coming-soon">Soon</span>
          </a>
          <a href="<?= __nav_h($L['reports_rewards']) ?>" class="nav-sublink disabled <?= ($__active==='reports_rewards' ? 'active' : '') ?>">
            Rewards <span class="coming-soon">Soon</span>
          </a>
          <a href="<?= __nav_h($L['reports_tables']) ?>" class="nav-sublink disabled <?= ($__active==='reports_tables' ? 'active' : '') ?>">
            Tables <span class="coming-soon">Soon</span>
          </a>
        </div>
      </div>

      <!-- Setup Section - WITH REORDERED ITEMS -->
      <div class="nav-section">
        <button class="nav-header <?= ($__isSetup ? 'expanded' : '') ?>" data-section="setup">
          <div style="display:flex;align-items:center;">
            <svg class="nav-icon" viewBox="0 0 24 24" fill="currentColor">
              <path d="M19.14 12.94c.04-.3.06-.61.06-.94 0-.32-.02-.64-.07-.94l2.03-1.58c.18-.14.23-.41.12-.61l-1.92-3.32c-.12-.22-.37-.29-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54c-.04-.24-.24-.41-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.09.63-.09.94s.02.64.07.94l-2.03 1.58c-.18.14-.23.41-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58zM12 15.6c-1.98 0-3.6-1.62-3.6-3.6s1.62-3.6 3.6-3.6 3.6 1.62 3.6 3.6-1.62 3.6-3.6 3.6z"/>
            </svg>
            Setup
          </div>
          <svg class="nav-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
          </svg>
        </button>
        <div class="nav-subnav <?= ($__isSetup ? 'expanded' : '') ?>" id="setup-subnav">
          <a href="<?= __nav_h($L['settings_general']) ?>" class="nav-sublink <?= ($__active==='settings_general' ? 'active' : '') ?>">General</a>
          <a href="<?= __nav_h($L['settings_tables']) ?>" class="nav-sublink <?= ($__active==='settings_tables' ? 'active' : '') ?>">Tables</a>
          <a href="<?= __nav_h($L['settings_finance']) ?>" class="nav-sublink <?= ($__active==='settings_finance' ? 'active' : '') ?>">Finance & Payment</a>
          <a href="<?= __nav_h($L['settings_users']) ?>" class="nav-sublink <?= ($__active==='settings_users' ? 'active' : '') ?>">Users & Roles</a>
          <a href="<?= __nav_h($L['settings_hardware']) ?>" class="nav-sublink <?= ($__active==='settings_hardware' ? 'active' : '') ?>">Hardware</a>
        </div>
      </div>
    </nav>
  </aside>

  <!-- Mobile Overlay -->
  <div class="sidebar-overlay" id="sidebarOverlay"></div>

  <!-- Main Content Area -->
  <div class="admin-content" id="adminContent">
    <!-- Top Header -->
    <header class="admin-header">
      <button class="sidebar-toggle" id="sidebarToggle" aria-label="Toggle sidebar">
        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
        </svg>
      </button>

      <div class="header-user" id="headerUser">
        <span><?= __nav_h($__user['username'] ?? 'User') ?></span>
        <div class="header-user-avatar">
          <?= __nav_h($initials) ?>
        </div>
        <svg class="header-user-chevron" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
        </svg>
        
        <div class="header-user-dropdown" id="headerUserDropdown">
          <a href="<?= __nav_h($L['logout']) ?>" class="header-dropdown-item danger">
            <svg class="header-dropdown-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
            </svg>
            Logout
          </a>
        </div>
      </div>
    </header>

    <!-- Page Content Area - OPEN FOR CONTENT INJECTION -->
    <div class="page-content">
    <?php
    // Optional: Add a PHP comment here showing where page content goes
    /* 
     * PAGE CONTENT GOES HERE
     * Individual pages should place their content after including this file
     * Remember to close the page-content div and add footer at the end
     */
    ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const sidebar = document.getElementById('adminSidebar');
  const sidebarToggle = document.getElementById('sidebarToggle');
  const sidebarOverlay = document.getElementById('sidebarOverlay');
  const adminContent = document.getElementById('adminContent');
  const headerUser = document.getElementById('headerUser');
  const headerUserDropdown = document.getElementById('headerUserDropdown');
  
  let isMobile = window.innerWidth <= 768;
  
  // Handle responsive changes
  function handleResize() {
    const wasMobile = isMobile;
    isMobile = window.innerWidth <= 768;
    
    if (wasMobile !== isMobile) {
      // Reset states when switching between mobile/desktop
      sidebar.classList.remove('collapsed', 'mobile-open');
      adminContent.classList.remove('expanded');
      sidebarOverlay.classList.remove('show');
    }
  }
  
  // Sidebar toggle functionality
  function toggleSidebar() {
    if (isMobile) {
      sidebar.classList.toggle('mobile-open');
      sidebarOverlay.classList.toggle('show');
      document.body.style.overflow = sidebar.classList.contains('mobile-open') ? 'hidden' : '';
    } else {
      sidebar.classList.toggle('collapsed');
      adminContent.classList.toggle('expanded');
    }
  }
  
  // Close mobile sidebar
  function closeMobileSidebar() {
    if (isMobile) {
      sidebar.classList.remove('mobile-open');
      sidebarOverlay.classList.remove('show');
      document.body.style.overflow = '';
    }
  }
  
  // Event listeners
  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', toggleSidebar);
  }
  
  if (sidebarOverlay) {
    sidebarOverlay.addEventListener('click', closeMobileSidebar);
  }
  
  // FIXED: Section expansion/collapse - now works for all sections
  document.querySelectorAll('.nav-header[data-section]').forEach(header => {
    header.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      
      const section = this.getAttribute('data-section');
      const subnav = document.getElementById(section + '-subnav');
      
      if (subnav) {
        const isExpanded = this.classList.contains('expanded');
        
        // Toggle current section
        if (isExpanded) {
          // Always allow collapsing, even if it has active children
          this.classList.remove('expanded');
          subnav.classList.remove('expanded');
        } else {
          // Close other sections (but not ones with active children)
          document.querySelectorAll('.nav-header').forEach(h => {
            if (h !== this) {
              const otherSection = h.getAttribute('data-section');
              const otherSubnav = document.getElementById(otherSection + '-subnav');
              // Only auto-close if there's no active child
              if (otherSubnav && !otherSubnav.querySelector('.nav-sublink.active')) {
                h.classList.remove('expanded');
                otherSubnav.classList.remove('expanded');
              }
            }
          });
          
          // Expand current section
          this.classList.add('expanded');
          subnav.classList.add('expanded');
        }
      }
    });
  });

  // Header user dropdown functionality
  if (headerUser && headerUserDropdown) {
    headerUser.addEventListener('click', function(e) {
      e.stopPropagation();
      headerUserDropdown.classList.toggle('show');
      headerUser.classList.toggle('active');
    });
    
    document.addEventListener('click', function() {
      headerUserDropdown.classList.remove('show');
      headerUser.classList.remove('active');
    });
    
    headerUserDropdown.addEventListener('click', function(e) {
      e.stopPropagation();
    });
  }
  
  // Close mobile sidebar on navigation
  document.querySelectorAll('.nav-sublink:not(.disabled)').forEach(link => {
    link.addEventListener('click', closeMobileSidebar);
  });
  
  // Prevent clicks on disabled links
  document.querySelectorAll('.nav-sublink.disabled').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
    });
  });
  
  // Handle window resize
  window.addEventListener('resize', handleResize);
  
  // Initialize
  handleResize();
  
  // Persist sidebar state in localStorage for desktop
  const sidebarState = localStorage.getItem('sidebar-collapsed');
  if (sidebarState === 'true' && !isMobile) {
    sidebar.classList.add('collapsed');
    adminContent.classList.add('expanded');
  }
  
  // Save sidebar state
  if (sidebarToggle) {
    sidebarToggle.addEventListener('click', function() {
      if (!isMobile) {
        localStorage.setItem('sidebar-collapsed', sidebar.classList.contains('collapsed'));
      }
    });
  }
});
</script>

<?php if ($__NAV_DEBUG): ?>
  <div style="background:#eef2ff;border:1px solid #c7d2fe;color:#1e3a8a;padding:10px;border-radius:8px;margin:12px;font:12px/1.4 monospace;">
    <strong>NAV debug:</strong>
    <div>Active value → <code><?= __nav_h($__active) ?></code></div>
    <div>Dashboard condition → <code><?= ($__active==='dashboard') ? 'true' : 'false' ?></code></div>
    <div>Is Catalog → <code><?= $__isCatalog ? 'true' : 'false' ?></code></div>
    <div>Is Rewards → <code><?= $__isRewards ? 'true' : 'false' ?></code></div>
    <div>Is Setup → <code><?= $__isSetup ? 'true' : 'false' ?></code></div>
  </div>
<?php endif; ?>

<!-- LAYOUT REMAINS OPEN FOR PAGE CONTENT INJECTION -->
<!-- Pages must close the page-content div and add footer, or include admin_nav_close.php -->