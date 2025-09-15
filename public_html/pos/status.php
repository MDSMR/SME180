<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/pos_auth.php';
pos_auth_require_login();

// pos/status.php - Professional system status and health check
declare(strict_types=1);

// Basic security - only show if debug parameter is present
if (!isset($_GET['debug']) || $_GET['debug'] !== 'status') {
  http_response_code(404);
  exit('Not Found');
}

// Get comprehensive system status
$pos_status = get_pos_status();
$db_status = $pos_status['database']['status'];
$db_health = $pos_status['database']['health'];

// Extract key information
$dbConnected = $db_status['status'] === 'connected';
$dbError = $db_status['error'];

$sessionActive = $pos_status['session']['active'];
$sessionError = $sessionActive ? null : 'Session not active';

$user = $pos_status['authentication']['user_info'];
$authStatus = $user ? 'authenticated' : 'not_authenticated';

// System info
$systemInfo = $pos_status['system'];
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>POS System Status</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg2:#f7f8fa;
  --text:#111827;
  --muted:#6b7280;
  --primary:#2563eb;
  --accent-green:#059669;
  --accent-red:#e11d48;
  --accent-orange:#ea580c;
  --border:#e5e7eb;
  --shadow-sm:0 6px 18px rgba(0,0,0,.06);
}

*{box-sizing:border-box}
body{
  margin:0;
  color:var(--text);
  font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,Helvetica,Arial;
  background:var(--bg2);
  padding:24px;
}

.container{
  max-width:1000px;
  margin:0 auto;
}

.header{
  text-align:center;
  margin-bottom:32px;
}

.title{
  font-size:32px;
  font-weight:700;
  margin:0 0 8px;
}

.subtitle{
  color:var(--muted);
  margin:0;
}

.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(300px, 1fr));
  gap:24px;
  margin-bottom:32px;
}

.card{
  background:white;
  border:1px solid var(--border);
  border-radius:12px;
  padding:24px;
  box-shadow:var(--shadow-sm);
}

.card-header{
  display:flex;
  align-items:center;
  gap:12px;
  margin-bottom:16px;
}

.status-icon{
  width:40px;
  height:40px;
  border-radius:8px;
  display:grid;
  place-items:center;
  font-size:18px;
  color:white;
  font-weight:700;
}

.status-ok{ background:var(--accent-green); }
.status-error{ background:var(--accent-red); }
.status-warning{ background:var(--accent-orange); }
.status-unknown{ background:var(--muted); }

.card-title{
  font-size:18px;
  font-weight:600;
  margin:0;
}

.status-text{
  font-size:14px;
  font-weight:500;
  margin:0;
}

.status-ok-text{ color:var(--accent-green); }
.status-error-text{ color:var(--accent-red); }
.status-warning-text{ color:var(--accent-orange); }

.detail-list{
  list-style:none;
  margin:0;
  padding:0;
}

.detail-list li{
  display:flex;
  justify-content:space-between;
  padding:8px 0;
  border-bottom:1px solid #f3f4f6;
}

.detail-list li:last-child{
  border-bottom:none;
}

.detail-label{
  color:var(--muted);
  font-size:14px;
}

.detail-value{
  font-size:14px;
  font-weight:500;
  text-align:right;
}

.error-detail{
  background:#fef2f2;
  border:1px solid #fecaca;
  border-radius:8px;
  padding:12px;
  margin-top:12px;
  font-size:13px;
  color:#7f1d1d;
}

.system-info{
  background:#f8fafc;
  border-radius:8px;
  padding:16px;
  margin-top:24px;
}

.info-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(200px, 1fr));
  gap:16px;
}

.refresh-btn{
  background:var(--primary);
  color:white;
  border:none;
  border-radius:8px;
  padding:12px 20px;
  cursor:pointer;
  font-size:14px;
  font-weight:600;
  transition:filter .15s ease;
}

.refresh-btn:hover{
  filter:brightness(1.1);
}
</style>
</head>
<body>

<div class="container">
  <div class="header">
    <h1 class="title">POS System Status</h1>
    <p class="subtitle">Real-time system health and diagnostics</p>
    <button class="refresh-btn" onclick="window.location.reload()">🔄 Refresh Status</button>
  </div>
  
  <div class="grid">
    
    <!-- Database Status -->
    <div class="card">
      <div class="card-header">
        <div class="status-icon status-<?= $dbConnected ? 'ok' : 'error' ?>">
          <?= $dbConnected ? '✓' : '✗' ?>
        </div>
        <div>
          <h3 class="card-title">Database Connection</h3>
          <p class="status-text status-<?= $dbConnected ? 'ok' : 'error' ?>-text">
            <?= ucfirst($db_status['status']) ?>
          </p>
        </div>
      </div>
      
       if ($dbConnected): ?>
        <ul class="detail-list">
          <li>
            <span class="detail-label">Database</span>
            <span class="detail-value"><?= htmlspecialchars($db_status['database']) ?></span>
          </li>
          <li>
            <span class="detail-label">MySQL Version</span>
            <span class="detail-value"><?= htmlspecialchars($db_status['mysql_version'] ?? 'Unknown') ?></span>
          </li>
          <li>
            <span class="detail-label">Response Time</span>
            <span class="detail-value">
              <?= $db_health['status'] === 'healthy' ? $db_health['response_time_ms'] . 'ms' : 'N/A' ?>
            </span>
          </li>
          <li>
            <span class="detail-label">Health Status</span>
            <span class="detail-value">
              <?= $db_health['status'] === 'healthy' ? '✅ Healthy' : '⚠️ ' . ucfirst($db_health['status']) ?>
            </span>
          </li>
        </ul>
       else: ?>
        <div class="error-detail">
          <strong>Connection Error:</strong> <?= htmlspecialchars($dbError ?? 'Unknown error') ?>
        </div>
       endif; ?>
    </div>
    
    <!-- Session Status -->
    <div class="card">
      <div class="card-header">
        <div class="status-icon status-<?= $sessionActive ? 'ok' : 'error' ?>">
          <?= $sessionActive ? '✓' : '✗' ?>
        </div>
        <div>
          <h3 class="card-title">POS Session System</h3>
          <p class="status-text status-<?= $sessionActive ? 'ok' : 'error' ?>-text">
            <?= $sessionActive ? 'Active' : 'Inactive' ?>
          </p>
        </div>
      </div>
      
      <ul class="detail-list">
        <li>
          <span class="detail-label">Session ID</span>
          <span class="detail-value"><?= substr($pos_status['session']['id'] ?? 'none', 0, 8) ?>...</span>
        </li>
        <li>
          <span class="detail-label">Session Type</span>
          <span class="detail-value"><?= ucfirst($pos_status['session']['type'] ?? 'unknown') ?></span>
        </li>
        <li>
          <span class="detail-label">Session Name</span>
          <span class="detail-value"><?= htmlspecialchars($pos_status['session']['name'] ?? 'unknown') ?></span>
        </li>
        <li>
          <span class="detail-label">Created</span>
          <span class="detail-value">
            <?= $pos_status['session']['created'] ? date('H:i:s', $pos_status['session']['created']) : 'Unknown' ?>
          </span>
        </li>
      </ul>
      
       if ($sessionError): ?>
        <div class="error-detail">
          <strong>Session Error:</strong> <?= htmlspecialchars($sessionError) ?>
        </div>
       endif; ?>
    </div>
    
    <!-- Authentication Status -->
    <div class="card">
      <div class="card-header">
        <div class="status-icon status-<?= $authStatus === 'authenticated' ? 'ok' : 'warning' ?>">
          <?= $authStatus === 'authenticated' ? '👤' : '🔒' ?>
        </div>
        <div>
          <h3 class="card-title">POS Authentication</h3>
          <p class="status-text status-<?= $authStatus === 'authenticated' ? 'ok' : 'warning' ?>-text">
            <?= $authStatus === 'authenticated' ? 'Signed In' : 'Not Signed In' ?>
          </p>
        </div>
      </div>
      
       if ($user): ?>
        <ul class="detail-list">
          <li>
            <span class="detail-label">User</span>
            <span class="detail-value"><?= htmlspecialchars($user['display_name'] ?? $user['username']) ?></span>
          </li>
          <li>
            <span class="detail-label">Username</span>
            <span class="detail-value"><?= htmlspecialchars($user['username']) ?></span>
          </li>
          <li>
            <span class="detail-label">Tenant</span>
            <span class="detail-value"><?= htmlspecialchars($user['tenant_name'] ?? 'Unknown') ?></span>
          </li>
          <li>
            <span class="detail-label">Login Time</span>
            <span class="detail-value">
              <?= isset($pos_status['authentication']['login_time']) ? 
                     date('H:i:s', $pos_status['authentication']['login_time']) : 'Unknown' ?>
            </span>
          </li>
        </ul>
       else: ?>
        <p class="detail-value">No active POS session</p>
       endif; ?>
    </div>
    
    <!-- System Health -->
    <div class="card">
      <div class="card-header">
        <div class="status-icon status-ok">🔧</div>
        <div>
          <h3 class="card-title">System Health</h3>
          <p class="status-text status-ok-text">Monitoring</p>
        </div>
      </div>
      
      <ul class="detail-list">
        <li>
          <span class="detail-label">Memory Usage</span>
          <span class="detail-value"><?= round($systemInfo['memory_usage'] / 1024 / 1024, 1) ?>MB</span>
        </li>
        <li>
          <span class="detail-label">PHP Version</span>
          <span class="detail-value"><?= $systemInfo['php_version'] ?></span>
        </li>
        <li>
          <span class="detail-label">Session Handler</span>
          <span class="detail-value"><?= $systemInfo['session_save_handler'] ?></span>
        </li>
         if ($dbConnected && !empty($db_health['table_access'])): ?>
          <li>
            <span class="detail-label">Core Tables</span>
            <span class="detail-value">
               
              $accessible = 0;
              $total = count($db_health['table_access']);
              foreach ($db_health['table_access'] as $status) {
                if ($status === 'accessible') $accessible++;
              }
              echo "$accessible/$total accessible";
              ?>
            </span>
          </li>
         endif; ?>
      </ul>
    </div>
    
  </div>
  
  <!-- System Information -->
  <div class="system-info">
    <h3 style="margin:0 0 16px; font-size:18px;">Professional System Architecture</h3>
    <div class="info-grid">
      <div>
        <strong>Database:</strong><br>
        <?= htmlspecialchars($db_status['database']) ?> (<?= $db_status['charset'] ?>)
      </div>
      <div>
        <strong>Session Architecture:</strong><br>
        Backend: smorll_session | POS: pos_session
      </div>
      <div>
        <strong>Current Time:</strong><br>
        <?= $systemInfo['timestamp'] ?>
      </div>
      <div>
        <strong>Response Time:</strong><br>
        <?= $dbConnected && isset($db_health['response_time_ms']) ? 
               $db_health['response_time_ms'] . 'ms' : 'N/A' ?>
      </div>
    </div>
    
     if ($dbConnected && !empty($db_health['table_access'])): ?>
      <h4 style="margin:16px 0 8px; font-size:16px;">Database Table Access</h4>
      <div style="font-family:monospace; font-size:13px; background:#f8f9fa; padding:12px; border-radius:6px;">
         foreach ($db_health['table_access'] as $table => $status): ?>
          <span style="color:<?= $status === 'accessible' ? '#059669' : '#e11d48' ?>">
            <?= $status === 'accessible' ? '✅' : '❌' ?> <?= $table ?>: <?= $status ?><br>
          </span>
         endforeach; ?>
      </div>
     endif; ?>
  </div>
  
  <div style="text-align:center; margin-top:32px; padding-top:24px; border-top:1px solid var(--border);">
    <p style="color:var(--muted); margin:0;">
      <a href="login.php" style="color:var(--primary); text-decoration:none;">← Back to Login</a> |
      <a href="index.php" style="color:var(--primary); text-decoration:none;">Dashboard</a> |
      <span onclick="window.location.reload()" style="cursor:pointer; color:var(--primary);">Refresh</span>
    </p>
  </div>
  
</div>

<script>
// Auto-refresh every 30 seconds
setTimeout(() => {
  window.location.reload();
}, 30000);

// Real-time clock update
setInterval(() => {
  const timeElements = document.querySelectorAll('.detail-value');
  timeElements.forEach(el => {
    if (el.textContent.includes(':')) {
      el.textContent = new Date().toLocaleTimeString();
    }
  });
}, 1000);
</script>

</body>
</html>