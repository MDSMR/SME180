<?php
// /public_html/views/admin/reports.php — Reports index (unified design)
// Hardened bootstrap + auth + resilient navbar include
declare(strict_types=1);

// Debug via ?debug=1
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) {
  @ini_set('display_errors','1');
  @ini_set('display_startup_errors','1');
  error_reporting(E_ALL);
}

/* ---------- Bootstrap /config/db.php (robust) ---------- */
$bootstrap_ok = false;
$bootstrap_warning = '';
$bootstrap_found = '';
$bootstrap_tried = [];

$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');

$configCandidates = array_values(array_unique(array_filter([
  __DIR__ . '/../../config/db.php',                 // from /views/admin → up 2 → /config/db.php
  $docRoot ? ($docRoot . '/config/db.php') : '',    // absolute via docroot
])));

foreach ($configCandidates as $p) {
  if (!$p) continue;
  $bootstrap_tried[] = $p;
  if (is_file($p)) {
    $prev = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
    try {
      require_once $p; // expects db(), use_backend_session()
      if (function_exists('db') && function_exists('use_backend_session')) {
        $bootstrap_ok = true;
        $bootstrap_found = $p;
        break;
      } else {
        $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
      }
    } catch (Throwable $e) {
      $bootstrap_warning = 'Bootstrap error: ' . $e->getMessage();
    } finally {
      if ($prev) { set_error_handler($prev); } else { restore_error_handler(); }
    }
  }
}
if (!$bootstrap_ok && !$bootstrap_warning) {
  $bootstrap_warning = 'Configuration file not found: /config/db.php';
}
if ($bootstrap_ok) {
  try { use_backend_session(); }
  catch (Throwable $e) { $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: ' . $e->getMessage()); }
}

/* ---------- Auth middleware ---------- */
// From /views/admin → up 2 → /middleware/auth_login.php
$auth_ok = false;
$auth_file = '';
$authCandidates = array_values(array_unique(array_filter([
  __DIR__ . '/../../middleware/auth_login.php',
  $docRoot ? ($docRoot . '/middleware/auth_login.php') : '',
])));
foreach ($authCandidates as $ap) {
  if ($ap && is_file($ap)) {
    $auth_file = $ap;
    require_once $ap;
    if (function_exists('auth_require_login')) { $auth_ok = true; }
    break;
  }
}
if ($auth_ok) {
  auth_require_login();
} else {
  http_response_code(500);
  echo "<!doctype html><meta charset='utf-8'>
  <div style='margin:24px;padding:12px;border:1px solid #fecaca;background:#fef2f2;border-radius:10px;color:#7f1d1d;font-family:system-ui'>
    <strong>Auth middleware not found or invalid</strong><br>
    Expected at: <code>/middleware/auth_login.php</code><br>
  </div>";
  exit;
}

/* ---------- Helpers ---------- */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

/* ---------- Current user ---------- */
$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Reports · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{
  --bg-primary:#fafbfc; --bg-secondary:#f4f6f8; --card-bg:#ffffff;
  --text-primary:#1a202c; --text-secondary:#4a5568; --text-muted:#718096;
  --primary:#4299e1; --primary-dark:#2b6cb0; --primary-light:#bee3f8; --primary-lighter:#ebf8ff;
  --border:#e2e8f0; --border-light:#f1f5f9; --hover:#f7fafc;
  --shadow-sm:0 1px 3px rgba(0,0,0,.08),0 1px 2px rgba(0,0,0,.06);
  --shadow-lg:0 10px 15px -3px rgba(0,0,0,.1),0 4px 6px -2px rgba(0,0,0,.05);
  --transition:all .2s cubic-bezier(.4,0,.2,1);
}
*{box-sizing:border-box;}
html{scroll-padding-top:120px;scroll-behavior:smooth;}
body{
  margin:0;background:linear-gradient(135deg,#fff 0%,#fafbff 50%,#f8fafc 100%);
  font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;
  font-size:14px;line-height:1.6;color:var(--text-primary);min-height:100vh;
}
.container{max-width:1400px;margin:0 auto;padding:28px 24px;}
.card{background:var(--card-bg);border:1px solid var(--border);border-radius:20px;box-shadow:var(--shadow-sm);padding:24px;position:relative;overflow:hidden;}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;background:linear-gradient(90deg,transparent,var(--primary-light),transparent);opacity:.6;}
.header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;}
.header h1{margin:0;font-size:22px;font-weight:800;letter-spacing:-.4px;color:var(--text-primary);}
.header small{color:var(--text-secondary);font-weight:600;}
.grid{display:grid;gap:24px;}
.grid-3{grid-template-columns:repeat(auto-fit,minmax(320px,1fr));}
.tile{display:block;text-decoration:none;color:var(--text-primary);border:1px solid var(--border);border-radius:20px;padding:24px;background:var(--card-bg);transition:var(--transition);cursor:pointer;position:relative;overflow:hidden;}
.tile::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.4),transparent);transition:var(--transition);}
.tile:hover::before{left:100%;}
.tile:hover{transform:translateY(-6px) scale(1.02);box-shadow:var(--shadow-lg);border-color:var(--primary);background:linear-gradient(135deg,#fff 0%,#f0f8ff 50%,#e6f3ff 100%);}
.t-row{display:flex;align-items:center;gap:16px;position:relative;z-index:1;}
.t-icon{width:48px;height:48px;border-radius:16px;display:flex;align-items:center;justify-content:center;font-size:22px;box-shadow:var(--shadow-sm);transition:var(--transition);}
.tile:hover .t-icon{transform:scale(1.1);}
.t-title{font-weight:700;font-size:17px;margin:0;letter-spacing:-.5px;}
.t-desc{color:var(--text-secondary);font-size:13px;margin-top:4px;font-weight:500;}
.tile.sales   .t-icon{background:linear-gradient(135deg,#dbeafe 0%,#bfdbfe 50%,#93c5fd 100%);color:#1e40af;}
.tile.orders  .t-icon{background:linear-gradient(135deg,#e9d5ff 0%,#c4b5fd 50%,#a78bfa 100%);color:#5b21b6;}
.tile.stock   .t-icon{background:linear-gradient(135deg,#fef3c7 0%,#fde68a 50%,#fcd34d 100%);color:#b45309;}
.tile.staff   .t-icon{background:linear-gradient(135deg,#e0e7ff 0%,#c7d2fe 50%,#a5b4fc 100%);color:#3730a3;}
.tile.loyalty .t-icon{background:linear-gradient(135deg,#fee2e2 0%,#fecaca 50%,#fca5a5 100%);color:#9f1239;}
.notice{background:#fff7ed;border:1px solid #ffedd5;color:#7c2d12;padding:10px;border-radius:12px;margin:10px 0;box-shadow:var(--shadow-sm);}
@media (max-width:1024px){.container{padding:20px 16px}.card{padding:20px}.grid-3{grid-template-columns:repeat(auto-fit,minmax(280px,1fr));}}
@media (max-width:768px){.container{padding:16px 12px}.card{padding:16px}.grid-3{grid-template-columns:1fr}.t-icon{width:40px;height:40px;font-size:18px}.t-title{font-size:15px}.t-desc{font-size:12px}}
@media print{.tile{break-inside:avoid}}
</style>
</head>
<body>

<?php
  // Highlight “Reports” in the navbar
  $active = 'reports';

  // Navbar include: from /views/admin → ../partials/admin_nav.php (1 level up)
  $navCandidates = array_values(array_unique(array_filter([
    __DIR__ . '/../partials/admin_nav.php',
    $docRoot ? ($docRoot . '/views/partials/admin_nav.php') : '',
  ])));
  $navIncluded = false;
  foreach ($navCandidates as $np) {
    if ($np && is_file($np)) { require $np; $navIncluded = true; break; }
  }
  if (!$navIncluded) {
    echo "<div class='notice'>Navigation file not found. Looked in:<br><code>"
       . h(implode(' | ', $navCandidates)) . "</code></div>";
  }
?>

<div class="container">
  <?php if ($bootstrap_warning): ?>
    <div class="notice"><?= h($bootstrap_warning) ?></div>
  <?php endif; ?>

  <div class="header">
    <h1>Reports</h1>
    <small>Choose a report category</small>
  </div>

  <div class="card">
    <div class="grid grid-3" role="list">
      <a class="tile sales" href="/views/admin/reports/sales.php" role="listitem" aria-label="Open Sales reports">
        <div class="t-row">
          <div class="t-icon" aria-hidden="true">📈</div>
          <div>
            <div class="t-title">Sales</div>
            <div class="t-desc">Revenue by period, item, category, channel, and user.</div>
          </div>
        </div>
      </a>

      <a class="tile orders" href="/views/admin/reports/orders.php" role="listitem" aria-label="Open Orders reports">
        <div class="t-row">
          <div class="t-icon" aria-hidden="true">🧾</div>
          <div>
            <div class="t-title">Orders</div>
            <div class="t-desc">Order volume, channels, cancellations, and averages.</div>
          </div>
        </div>
      </a>

      <a class="tile stock" href="/views/admin/reports/inventory.php" role="listitem" aria-label="Open Inventory reports">
        <div class="t-row">
          <div class="t-icon" aria-hidden="true">📦</div>
          <div>
            <div class="t-title">Inventory</div>
            <div class="t-desc">Stock movement, waste analysis, and cost of goods.</div>
          </div>
        </div>
      </a>

      <a class="tile staff" href="/views/admin/reports/staff.php" role="listitem" aria-label="Open Staff reports">
        <div class="t-row">
          <div class="t-icon" aria-hidden="true">👥</div>
          <div>
            <div class="t-title">Staff</div>
            <div class="t-desc">Performance by staff member and role.</div>
          </div>
        </div>
      </a>

      <a class="tile loyalty" href="/views/admin/reports/loyalty.php" role="listitem" aria-label="Open Loyalty reports">
        <div class="t-row">
          <div class="t-icon" aria-hidden="true">💖</div>
          <div>
            <div class="t-title">Loyalty</div>
            <div class="t-desc">Enrollments, redemptions, and repeat rate.</div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <?php if ($DEBUG): ?>
    <div style="background:linear-gradient(135deg,#eef2ff,#e0e7ff);border:1px solid var(--primary-light);color:var(--primary-dark);padding:16px 20px;border-radius:12px;margin:24px 0;font-size:13px;font-family:monospace;box-shadow:var(--shadow-sm);">
      <strong>🔍 Debug Info:</strong>
      <div>User: <code><?= h((string)($user['id'] ?? '')) ?></code></div>
      <div>Bootstrap: <?= $bootstrap_ok ? 'OK' : 'WARN' ?><?= $bootstrap_warning ? (' — '.h($bootstrap_warning)) : '' ?></div>
      <div>Config tried: <code><?= h(implode(' | ', $bootstrap_tried)) ?></code></div>
      <div>db.php used: <code><?= h($bootstrap_found) ?></code></div>
      <div>Auth file: <code><?= h($auth_file) ?></code></div>
      <div>Nav candidates: <code><?= h(implode(' | ', $navCandidates)) ?></code></div>
    </div>
  <?php endif; ?>
</div>

</body>
</html>