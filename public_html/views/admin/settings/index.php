<?php declare(strict_types=1);

// Load configuration and authentication
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';
auth_require_login();

// /views/admin/settings/index.php
// Settings Dashboard - Overview of all configuration areas

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

$user = $_SESSION['user'] ?? null;
if (!$user){ header('Location:/views/auth/login.php'); exit; }
$tenantId = (int)$user['tenant_id'];
$roleKey = (string)($user['role_key']??'');

function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
function has_permission(PDO $pdo, string $roleKey, string $perm): bool {
  static $cache = [];
  $k = $roleKey.'|'.$perm;
  if (array_key_exists($k,$cache)) return $cache[$k];
  $st=$pdo->prepare("SELECT is_allowed FROM pos_role_permissions WHERE role_key=:rk AND permission_key=:pk LIMIT 1");
  $st->execute([':rk'=>$roleKey, ':pk'=>$perm]); $row=$st->fetch(PDO::FETCH_ASSOC);
  return $cache[$k] = (bool)($row['is_allowed']??0);
}

$pdo = function_exists('db') ? db() : null;
$canView = $pdo ? has_permission($pdo, $roleKey, 'settings.view') : true;
$canEdit = $pdo ? has_permission($pdo, $roleKey, 'settings.edit') : true;
if (!$canView) { http_response_code(403); exit('Forbidden'); }

// Get basic stats for dashboard cards
$stats = [];
if ($pdo) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) as count FROM branches WHERE tenant_id = :t AND is_active = 1");
        $st->execute([':t' => $tenantId]);
        $stats['branches'] = $st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) as count FROM tax_rates WHERE tenant_id = :t AND is_active = 1");
        $st->execute([':t' => $tenantId]);
        $stats['tax_rates'] = $st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) as count FROM payment_methods WHERE tenant_id = :t AND is_active = 1");
        $st->execute([':t' => $tenantId]);
        $stats['payment_methods'] = $st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE tenant_id = :t AND disabled_at IS NULL");
        $st->execute([':t' => $tenantId]);
        $stats['users'] = $st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) as count FROM printers WHERE tenant_id = :t AND is_active = 1");
        $st->execute([':t' => $tenantId]);
        $stats['printers'] = $st->fetchColumn();

        $st = $pdo->prepare("SELECT COUNT(*) as count FROM roles WHERE tenant_id = :t");
        $st->execute([':t' => $tenantId]);
        $stats['roles'] = $st->fetchColumn();
    } catch (Exception $e) {
        error_log("Settings dashboard stats error: " . $e->getMessage());
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Settings • System Configuration</title>
<style>
:root{
  --bg-primary: #fafbfc;
  --bg-secondary: #f4f6f8;
  --card-bg: #ffffff;
  --text-primary: #1a202c;
  --text-secondary: #4a5568;
  --text-muted: #718096;
  --primary: #4299e1;
  --primary-dark: #2b6cb0;
  --primary-light: #bee3f8;
  --primary-lighter: #ebf8ff;
  --accent: #38b2ac;
  --accent-light: #b2f5ea;
  --border: #e2e8f0;
  --border-light: #f1f5f9;
  --border-dark: #cbd5e0;
  --hover: #f7fafc;
  --success: #48bb78;
  --warning: #ed8936;
  --danger: #f56565;
  --purple: #9f7aea;
  --yellow: #ecc94b;
  --shadow-sm: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
  --shadow-md: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06);
  --shadow-lg: 0 10px 15px -3px rgba(0,0,0,0.1), 0 4px 6px -2px rgba(0,0,0,0.05);
  --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  --transition-fast: all 0.15s cubic-bezier(0.4, 0, 0.2, 1);
}

*{box-sizing:border-box;}
html{scroll-padding-top:120px;scroll-behavior:smooth;}
body{
  margin:0;
  background: linear-gradient(135deg, #ffffff 0%, #fafbff 50%, #f8fafc 100%);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
  font-size: 14px; 
  line-height: 1.6; 
  color: var(--text-primary); 
  min-height:100vh;
}

.container{
  max-width:1400px;
  margin:0 auto;
  padding:28px 24px;
}

/* Main Card Container */
.main-card{
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 20px;
  box-shadow: var(--shadow-sm);
  padding: 24px;
  position: relative;
  overflow: hidden;
}

.main-card::before{
  content:'';
  position:absolute; 
  top:0; 
  left:0; 
  right:0; 
  height:1px;
  background: linear-gradient(90deg, transparent, var(--primary-light), transparent);
  opacity:.6;
}

/* Grid & Tiles */
.grid{
  display:grid;
  gap:24px;
}

.grid-2{
  grid-template-columns:repeat(auto-fit,minmax(480px,1fr));
}

/* Settings Tiles */
.tile{
  display:block; 
  text-decoration:none; 
  color:var(--text-primary);
  border:1px solid var(--border); 
  border-radius:20px; 
  padding:24px;
  background:var(--card-bg); 
  transition:var(--transition); 
  cursor:pointer;
  position:relative; 
  overflow:hidden;
}

.tile::before{
  content:''; 
  position:absolute; 
  top:0; 
  left:-100%; 
  width:100%; 
  height:100%;
  background:linear-gradient(90deg,transparent,rgba(255,255,255,0.4),transparent);
  transition:var(--transition);
}

.tile:hover::before{ 
  left:100%; 
}

.tile:hover{
  transform:translateY(-6px) scale(1.02); 
  box-shadow:var(--shadow-lg); 
  border-color:var(--primary);
  background: linear-gradient(135deg,#ffffff 0%,#f0f8ff 50%,#e6f3ff 100%);
}

/* Tile Content Layout */
.tile-content{
  display:flex;
  flex-direction:column;
  gap:16px;
  position:relative;
  z-index:1;
}

.tile-header{
  display:flex;
  align-items:center;
  gap:16px;
}

.tile-icon{
  width:56px;
  height:56px;
  border-radius:16px;
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:28px; 
  box-shadow:var(--shadow-sm); 
  transition:var(--transition);
  flex-shrink:0;
}

.tile:hover .tile-icon{ 
  transform:scale(1.1); 
  box-shadow:var(--shadow-md); 
}

.tile-text{
  flex:1;
}

.tile-title{
  font-weight:700;
  font-size:18px;
  margin:0;
  letter-spacing:-.5px;
  color:var(--text-primary);
}

.tile-desc{
  color:var(--text-secondary);
  font-size:13px;
  margin-top:4px;
  font-weight:500;
  line-height:1.5;
}

/* Statistics Section */
.tile-stats{
  display:flex;
  gap:20px;
  padding-top:16px;
  border-top:1px solid var(--border-light);
  flex-wrap:wrap;
}

.stat-item{
  display:flex;
  align-items:center;
  gap:10px;
  flex:1;
  min-width:120px;
}

.stat-badge{
  width:36px;
  height:36px;
  border-radius:10px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:var(--bg-secondary);
  font-weight:600;
  font-size:12px;
  color:var(--text-muted);
  border:1px solid var(--border);
}

.stat-info{
  display:flex;
  flex-direction:column;
}

.stat-value{
  font-size:20px;
  font-weight:700;
  color:var(--text-primary);
  line-height:1;
}

.stat-label{
  font-size:11px;
  color:var(--text-muted);
  margin-top:2px;
  text-transform:uppercase;
  letter-spacing:0.5px;
}

/* Tile color schemes - matching rewards page style */
.tile.general .tile-icon{
  background:linear-gradient(135deg,#dbeafe 0%,#bfdbfe 50%,#93c5fd 100%);
  color:#1e40af;
}

.tile.finance .tile-icon{
  background:linear-gradient(135deg,#dcfce7 0%,#bbf7d0 50%,#86efac 100%);
  color:#065f46;
}

.tile.users .tile-icon{
  background:linear-gradient(135deg,#e9d5ff 0%,#c4b5fd 50%,#a78bfa 100%);
  color:#6b21a8;
}

.tile.printers .tile-icon{
  background:linear-gradient(135deg,#fef3c7 0%,#fde68a 50%,#fcd34d 100%);
  color:#b45309;
}

/* Quick Actions Section */
.quick-actions{
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: 20px;
  box-shadow: var(--shadow-sm);
  padding: 24px;
  margin-top: 24px;
}

.section-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:20px;
}

.section-title{
  font-size:17px;
  font-weight:700;
  color:var(--text-primary);
  letter-spacing:-0.3px;
  margin:0;
}

.actions-grid{
  display:grid;
  grid-template-columns:repeat(auto-fill,minmax(240px,1fr));
  gap:12px;
}

.action-link{
  display:flex;
  align-items:center;
  gap:12px;
  padding:14px 16px;
  border:1px solid var(--border);
  border-radius:12px;
  background:var(--bg-secondary);
  text-decoration:none;
  color:var(--text-primary);
  font-size:13px;
  font-weight:600;
  transition:var(--transition);
  position:relative;
  overflow:hidden;
}

.action-link::before{
  content:'';
  position:absolute;
  top:0;
  left:-100%;
  width:100%;
  height:100%;
  background:linear-gradient(90deg,transparent,rgba(66,153,225,0.1),transparent);
  transition:var(--transition);
}

.action-link:hover::before{
  left:100%;
}

.action-link:hover{
  background:var(--card-bg);
  border-color:var(--primary);
  transform:translateX(4px);
  box-shadow:var(--shadow-sm);
}

.action-icon{
  width:32px;
  height:32px;
  border-radius:8px;
  display:flex;
  align-items:center;
  justify-content:center;
  background:linear-gradient(135deg,#ebf8ff 0%,#bee3f8 50%,#90cdf4 100%);
  color:var(--primary-dark);
  font-size:16px;
  flex-shrink:0;
}

/* Header */
.header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  margin-bottom:24px;
}

.header h1{
  margin:0;
  font-size:24px;
  font-weight:800;
  letter-spacing:-.4px;
  color:var(--text-primary);
}

.header small{
  color:var(--text-secondary);
  font-weight:600;
  font-size:14px;
}

/* Status Badge */
.status-badge{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:6px 12px;
  border-radius:20px;
  font-size:11px;
  font-weight:600;
  text-transform:uppercase;
  letter-spacing:0.5px;
}

.status-badge.active{
  background:var(--primary-lighter);
  color:var(--primary-dark);
  border:1px solid var(--primary-light);
}

.status-badge::before{
  content:'';
  width:6px;
  height:6px;
  border-radius:50%;
  background:currentColor;
  animation:pulse 2s infinite;
}

@keyframes pulse{
  0%,100%{opacity:1;}
  50%{opacity:0.5;}
}

/* Debug Panel */
.debug-panel{
  background:linear-gradient(135deg,#fef3c7,#fde68a);
  border:1px solid #f59e0b;
  color:#78350f;
  padding:16px 20px;
  border-radius:12px;
  margin:24px 0;
  font-size:13px;
  font-family:monospace;
  box-shadow:var(--shadow-sm);
}

.debug-panel strong{
  display:block;
  margin-bottom:8px;
  font-size:14px;
}

/* Responsive */
@media (max-width:1024px){
  .container{padding:20px 16px;}
  .main-card,.quick-actions{padding:20px;}
  .grid-2{grid-template-columns:1fr;}
  .actions-grid{grid-template-columns:repeat(auto-fill,minmax(200px,1fr));}
}

@media (max-width:768px){
  .container{padding:16px 12px;}
  .main-card,.quick-actions{padding:16px;}
  .tile{padding:20px;}
  .tile-icon{width:48px;height:48px;font-size:24px;}
  .tile-title{font-size:16px;}
  .tile-desc{font-size:12px;}
  .tile-stats{gap:16px;}
  .stat-value{font-size:18px;}
  .actions-grid{grid-template-columns:1fr;}
  .header h1{font-size:20px;}
}

@media print{
  .tile{break-inside:avoid;}
  .quick-actions{break-inside:avoid;}
}
</style>
</head>
<body>

<?php
$active = 'settings';
$__nav_paths = [
  __DIR__ . '/../partials/admin_nav.php',
  dirname(__DIR__, 2) . '/partials/admin_nav.php',
  rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/').'/views/admin/partials/admin_nav.php',
];
$__nav_loaded = false;
foreach ($__nav_paths as $__p) {
  if ($__p && is_file($__p)) { require $__p; $__nav_loaded = true; break; }
}
if (!$__nav_loaded) {
  echo '<div style="max-width:1400px;margin:10px auto;padding:12px 16px;border:1px solid var(--warning);background:var(--bg-secondary);color:#744210;border-radius:12px;box-shadow:var(--shadow-sm);">
          <strong>⚠️ Navigation:</strong> Admin navigation component not found.
        </div>';
}
?>

<div class="container">
  <div class="header">
    <h1>Settings</h1>
    <small>System Configuration & Preferences</small>
  </div>

  <div class="main-card">
    <div class="grid grid-2" role="list">
      
      <!-- General Settings -->
      <a class="tile general" href="/views/admin/settings/general.php" role="listitem" aria-label="Open General Settings">
        <div class="tile-content">
          <div class="tile-header">
            <div class="tile-icon" aria-hidden="true">⚙️</div>
            <div class="tile-text">
              <div class="tile-title">General Settings</div>
              <div class="tile-desc">Restaurant profile, branch locations, operating hours, and basic system preferences</div>
            </div>
          </div>
          <div class="tile-stats">
            <div class="stat-item">
              <div class="stat-badge">B</div>
              <div class="stat-info">
                <span class="stat-value"><?= (int)($stats['branches'] ?? 0) ?></span>
                <span class="stat-label">Branches</span>
              </div>
            </div>
            <span class="status-badge active">Active</span>
          </div>
        </div>
      </a>

      <!-- Finance & Payments -->
      <a class="tile finance" href="/views/admin/settings/finance.php" role="listitem" aria-label="Open Finance Settings">
        <div class="tile-content">
          <div class="tile-header">
            <div class="tile-icon" aria-hidden="true">💰</div>
            <div class="tile-text">
              <div class="tile-title">Finance & Payments</div>
              <div class="tile-desc">Tax configuration, payment methods, currency settings, and billing preferences</div>
            </div>
          </div>
          <div class="tile-stats">
            <div class="stat-item">
              <div class="stat-badge">T</div>
              <div class="stat-info">
                <span class="stat-value"><?= (int)($stats['tax_rates'] ?? 0) ?></span>
                <span class="stat-label">Tax Rates</span>
              </div>
            </div>
            <div class="stat-item">
              <div class="stat-badge">P</div>
              <div class="stat-info">
                <span class="stat-value"><?= (int)($stats['payment_methods'] ?? 0) ?></span>
                <span class="stat-label">Methods</span>
              </div>
            </div>
          </div>
        </div>
      </a>

      <!-- Users & Roles -->
      <a class="tile users" href="/views/admin/settings/users.php" role="listitem" aria-label="Open Users & Roles">
        <div class="tile-content">
          <div class="tile-header">
            <div class="tile-icon" aria-hidden="true">👥</div>
            <div class="tile-text">
              <div class="tile-title">Users & Roles</div>
              <div class="tile-desc">User accounts, role permissions, security settings, and access control</div>
            </div>
          </div>
          <div class="tile-stats">
            <div class="stat-item">
              <div class="stat-badge">U</div>
              <div class="stat-info">
                <span class="stat-value"><?= (int)($stats['users'] ?? 0) ?></span>
                <span class="stat-label">Users</span>
              </div>
            </div>
            <div class="stat-item">
              <div class="stat-badge">R</div>
              <div class="stat-info">
                <span class="stat-value"><?= (int)($stats['roles'] ?? 0) ?></span>
                <span class="stat-label">Roles</span>
              </div>
            </div>
          </div>
        </div>
      </a>

      <!-- Printers & Hardware -->
      <a class="tile printers" href="/views/admin/settings/printers.php" role="listitem" aria-label="Open Printers & Hardware">
        <div class="tile-content">
          <div class="tile-header">
            <div class="tile-icon" aria-hidden="true">🖨️</div>
            <div class="tile-text">
              <div class="tile-title">Printers & Hardware</div>
              <div class="tile-desc">Receipt printers, kitchen displays, barcode scanners, and device configuration</div>
            </div>
          </div>
          <div class="tile-stats">
            <div class="stat-item">
              <div class="stat-badge">H</div>
              <div class="stat-info">
                <span class="stat-value"><?= (int)($stats['printers'] ?? 0) ?></span>
                <span class="stat-label">Devices</span>
              </div>
            </div>
            <span class="status-badge active">Online</span>
          </div>
        </div>
      </a>

    </div>
  </div>

  <!-- Quick Actions -->
  <div class="quick-actions">
    <div class="section-header">
      <h3 class="section-title">Quick Actions</h3>
    </div>
    <div class="actions-grid">
      <a href="/views/admin/settings/general.php#profile" class="action-link">
        <div class="action-icon">📍</div>
        <span>Update Restaurant Profile</span>
      </a>
      <a href="/views/admin/settings/finance.php#taxes" class="action-link">
        <div class="action-icon">📊</div>
        <span>Configure Tax Rates</span>
      </a>
      <a href="/views/admin/settings/users.php#add" class="action-link">
        <div class="action-icon">➕</div>
        <span>Add New User</span>
      </a>
      <a href="/views/admin/settings/printers.php#add" class="action-link">
        <div class="action-icon">🔧</div>
        <span>Setup Printer</span>
      </a>
      <a href="/views/admin/settings/finance.php#payment" class="action-link">
        <div class="action-icon">💳</div>
        <span>Payment Methods</span>
      </a>
      <a href="/views/admin/settings/general.php#backup" class="action-link">
        <div class="action-icon">💾</div>
        <span>Backup & Restore</span>
      </a>
      <a href="/views/admin/settings/users.php#permissions" class="action-link">
        <div class="action-icon">🔐</div>
        <span>Role Permissions</span>
      </a>
      <a href="/views/admin/settings/general.php#notifications" class="action-link">
        <div class="action-icon">🔔</div>
        <span>Notifications</span>
      </a>
    </div>
  </div>

  <?php if ($DEBUG): ?>
  <div class="debug-panel">
    <strong>🔍 Debug Information:</strong>
    <div>Tenant ID: <?= $tenantId ?></div>
    <div>User Role: <?= h($roleKey) ?></div>
    <div>Permissions: View=<?= $canView?'✓ Granted':'✗ Denied' ?>, Edit=<?= $canEdit?'✓ Granted':'✗ Denied' ?></div>
    <div>Statistics: <?= json_encode($stats, JSON_PRETTY_PRINT) ?></div>
  </div>
  <?php endif; ?>
</div>

</body>
</html>