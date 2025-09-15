<?php
// /pos/index.php — POS dashboard with offline support
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/pos_auth.php';

// Start POS session (support both function names)
if (function_exists('use_pos_session')) {
    use_pos_session();
} elseif (function_exists('pos_session_start')) {
    pos_session_start();
}

// Enforce POS auth
pos_auth_require_login();

// Current user
$user = pos_user();
if (!$user) {
    header('Location: /pos/login.php');
    exit;
}

$username    = htmlspecialchars($user['username'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
$displayName = htmlspecialchars($user['display_name'] ?? ($user['username'] ?? 'User'), ENT_QUOTES, 'UTF-8');
$tenantName  = htmlspecialchars($user['tenant_name'] ?? 'Restaurant', ENT_QUOTES, 'UTF-8');
$tenantId    = (int)($user['tenant_id'] ?? 0);
$branchId    = (int)($user['branch_id'] ?? 0);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= $tenantName ?> · POS System</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg2:#f7f8fa;--card:#ffffffc9;--panel:#ffffffee;--text:#111827;--muted:#6b7280;--primary:#2563eb;--primary-2:#60a5fa;--accent-red:#e11d48;--accent-green:#059669;--accent-orange:#ea580c;--accent-purple:#9333ea;--border:#e5e7eb;--ring:#93c5fd;--shadow-sm:0 6px 18px rgba(0,0,0,.06);--shadow-md:0 16px 40px rgba(0,0,0,.12);--shadow-lg:0 30px 70px rgba(0,0,0,.18)}
*{box-sizing:border-box}html,body{height:100%}
body{margin:0;color:var(--text);font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,Helvetica,Arial;background:radial-gradient(1100px 520px at -10% 0%, #e0e7ff 0%, transparent 60%),radial-gradient(900px 500px at 110% 0%, #dbeafe 0%, transparent 60%),linear-gradient(180deg, var(--bg2), #ffffff);min-height:100vh}
.header{background:var(--card);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid rgba(229,231,235,.65);padding:16px 24px;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--shadow-sm);position:sticky;top:0;z-index:100}
.brand{display:flex;align-items:center;gap:12px}
.brand-logo{font-size:32px;color:var(--accent-red)}
.brand-text{font-size:20px;font-weight:700;color:var(--text)}
.brand-sub{font-size:14px;color:var(--muted);margin-left:8px}
.user-info{display:flex;align-items:center;gap:16px}
.user-avatar{width:40px;height:40px;border-radius:50%;background:var(--primary);color:#fff;display:grid;place-items:center;font-weight:700;font-size:14px}
.user-details{display:flex;flex-direction:column}
.user-name{font-weight:600;color:var(--text);font-size:14px}
.user-role{font-size:12px;color:var(--muted)}
.main{padding:32px 24px;max-width:1400px;margin:0 auto}
.welcome{text-align:center;margin-bottom:48px}
.welcome h1{font-size:36px;margin:0 0 8px;color:var(--text)}
.welcome p{color:var(--muted);font-size:16px;margin:0}
.time-info{font-size:14px;color:var(--muted);margin-top:8px}
.actions-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:24px;margin-top:32px}
.action-card{background:var(--card);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border:1px solid rgba(229,231,235,.65);border-radius:18px;padding:24px;text-align:center;transition:transform .25s,box-shadow .25s;cursor:pointer;text-decoration:none;color:var(--text);box-shadow:var(--shadow-sm);position:relative;overflow:hidden}
.action-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-md)}
.action-card::before{content:'';position:absolute;top:0;left:0;right:0;height:4px;background:var(--primary)}
.action-card.menu::before{background:var(--accent-green)}
.action-card.reports::before{background:var(--accent-orange)}
.action-card.settings::before{background:var(--muted)}
.action-icon{width:64px;height:64px;border-radius:16px;margin:0 auto 16px;display:grid;place-items:center;font-size:28px;font-weight:700;color:#fff}
.action-icon.orders{background:linear-gradient(135deg,var(--primary),var(--primary-2))}
.action-icon.menu{background:linear-gradient(135deg,var(--accent-green),#10b981)}
.action-icon.reports{background:linear-gradient(135deg,var(--accent-orange),#fb923c)}
.action-icon.settings{background:linear-gradient(135deg,var(--muted),#9ca3af)}
.action-title{font-size:18px;font-weight:700;margin:0 0 8px}
.action-desc{font-size:14px;color:var(--muted);margin:0 0 16px;line-height:1.5}
.action-stats{font-size:12px;color:var(--primary);font-weight:600}
.status-bar{display:flex;gap:16px;justify-content:center;margin:24px 0;flex-wrap:wrap}
.status-item{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px 16px;display:flex;align-items:center;gap:8px;font-size:14px}
.status-dot{width:8px;height:8px;border-radius:50%}
.status-online{background:var(--accent-green)}
.status-offline{background:var(--accent-orange)}
.logout-btn{background:var(--accent-red);color:#fff;border:none;border-radius:10px;padding:10px 16px;font-size:14px;font-weight:600;cursor:pointer}

/* Offline mode indicators */
#connection-status{position:fixed;top:10px;right:200px;z-index:9999;font-size:14px;font-weight:600}
#pending-orders-count{position:fixed;top:10px;right:350px;z-index:9999;background:var(--accent-orange);color:#fff;padding:4px 12px;border-radius:20px;font-size:13px;font-weight:600}
.notification{position:fixed;top:20px;right:20px;padding:15px 20px;color:white;border-radius:4px;box-shadow:0 2px 5px rgba(0,0,0,0.2);z-index:10000;animation:slideIn 0.3s ease}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes slideOut{from{transform:translateX(0);opacity:1}to{transform:translateX(100%);opacity:0}}

@media (max-width:768px){.header{padding:12px 16px}.brand-text{font-size:18px}.brand-sub{display:none}.main{padding:24px 16px}.welcome h1{font-size:28px}.actions-grid{grid-template-columns:1fr;gap:16px}}
</style>
</head>
<body>
<div class="header">
  <div class="brand">
    <div class="brand-logo">S</div>
    <div class="brand-text"><?= $tenantName ?> <span class="brand-sub">POS</span></div>
  </div>
  <div class="user-info">
    <div id="connection-status"></div>
    <div id="pending-orders-count" style="display:none;"></div>
    <div class="user-avatar"><?= strtoupper(substr($displayName,0,2)) ?></div>
    <div class="user-details">
      <div class="user-name"><?= $displayName ?></div>
      <div class="user-role"><?= $username ?></div>
    </div>
    <a href="/pos/logout.php" class="logout-btn" style="text-decoration:none;">Logout</a>
  </div>
</div>

<div class="main">
  <div class="welcome">
    <h1>Welcome back, <?= $displayName ?>!</h1>
    <p>Ready to serve your customers? Choose an option below to get started.</p>
    <div class="time-info"><?= date('l, F j, Y') ?> · <?= date('g:i A') ?></div>
  </div>

  <div class="status-bar">
    <div class="status-item" id="system-status"><div class="status-dot status-online"></div><span>System Online</span></div>
    <div class="status-item"><div class="status-dot status-online"></div><span>Printer Ready</span></div>
    <div class="status-item" id="connection-indicator"><div class="status-dot status-online"></div><span>Connected</span></div>
  </div>

  <div class="actions-grid">
    <a href="/pos/order.php" class="action-card orders">
      <div class="action-icon orders">📋</div>
      <div class="action-title">Take Orders</div>
      <div class="action-desc">Process new customer orders and manage existing ones</div>
      <div class="action-stats">3 active tables</div>
    </a>
    <a href="#" class="action-card menu">
      <div class="action-icon menu">🍽️</div>
      <div class="action-title">Menu Management</div>
      <div class="action-desc">View and update menu items, prices, and availability</div>
      <div class="action-stats">124 items available</div>
    </a>
    <a href="#" class="action-card reports">
      <div class="action-icon reports">📊</div>
      <div class="action-title">Reports & Analytics</div>
      <div class="action-desc">View sales reports and performance metrics</div>
      <div class="action-stats">Today: $2,847</div>
    </a>
    <a href="#" class="action-card settings">
      <div class="action-icon settings">⚙️</div>
      <div class="action-title">Settings</div>
      <div class="action-desc">Configure POS settings and preferences</div>
      <div class="action-stats">All systems normal</div>
    </a>
  </div>
</div>

<!-- Offline Handler Script -->
<script>
// Pass PHP data to JavaScript
window.POSConfig = {
    tenantId: <?= $tenantId ?>,
    branchId: <?= $branchId ?>,
    userId: <?= json_encode($user['id'] ?? 0) ?>,
    userName: <?= json_encode($displayName) ?>
};

// Load offline handler
const script = document.createElement('script');
script.src = '/pos/js/offline-handler.js';
script.onload = function() {
    console.log('Offline handler loaded');
    // Initialize on first page load
    if (window.POSOfflineHandler) {
        updateUIFromOfflineStatus();
    }
};
document.body.appendChild(script);

// Update UI based on offline status
function updateUIFromOfflineStatus() {
    if (window.POSOfflineHandler) {
        const status = window.POSOfflineHandler.getSyncStatus();
        
        // Update connection indicator in status bar
        const connIndicator = document.getElementById('connection-indicator');
        if (connIndicator) {
            const dot = connIndicator.querySelector('.status-dot');
            const text = connIndicator.querySelector('span');
            
            if (status.isOnline) {
                dot.className = 'status-dot status-online';
                text.textContent = 'Connected';
            } else {
                dot.className = 'status-dot status-offline';
                text.textContent = 'Offline Mode';
            }
        }
        
        // Update system status
        const sysStatus = document.getElementById('system-status');
        if (sysStatus && !status.isOnline) {
            const text = sysStatus.querySelector('span');
            text.textContent = 'Offline Mode Active';
        }
    }
}

// Periodic status check
setInterval(updateUIFromOfflineStatus, 5000);
</script>

</body>
</html>