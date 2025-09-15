<?php
declare(strict_types=1);

// public_html/views/admin/rewards/index.php

$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* ================= Bootstrap + Session ================= */
$bootstrap_warning = '';
$bootstrap_ok      = false;

// Reach /public_html/config/db.php (three levels up from /views/admin/rewards/)
$bootstrap_path = dirname(__DIR__, 3) . '/config/db.php';
if (!is_file($bootstrap_path)) {
  $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
  $prev = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try {
    require_once $bootstrap_path; // must define db(), use_backend_session()
    if (function_exists('db') && function_exists('use_backend_session')) {
      $bootstrap_ok = true;
    } else {
      $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
    }
  } catch (Throwable $e) {
    $bootstrap_warning = 'Bootstrap error: ' . $e->getMessage();
  } finally {
    if ($prev) { set_error_handler($prev); } else { restore_error_handler(); }
  }
}

if ($bootstrap_ok) {
  try { use_backend_session(); }
  catch (Throwable $e) { $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: ' . $e->getMessage()); }
}

/* ================= Auth ================= */
require_once dirname(__DIR__, 3) . '/middleware/auth_login.php';
auth_require_login();

$user = $_SESSION['user'] ?? null;
if (!$user) { header('Location: /views/auth/login.php'); exit; }

/* ================= Helpers ================= */
function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/* Active tab for navbar */
$active = 'rewards';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Rewards · Smorll POS</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
:root{--bg:#f7f8fa;--card:#fff;--text:#111827;--muted:#6b7280;--primary:#2563eb;--border:#e5e7eb}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);font:14.5px/1.45 system-ui,-apple-system,Segoe UI,Roboto;color:var(--text)}
.container{max-width:1100px;margin:20px auto;padding:0 16px}
.section{background:var(--card);border:1px solid var(--border);border-radius:14px;box-shadow:0 4px 16px rgba(0,0,0,.05);padding:16px;margin-bottom:16px}
.h1{font-size:18px;font-weight:800;margin:0 0 12px}
.grid{display:grid;grid-template-columns:repeat(2,1fr);gap:14px}
@media (max-width:900px){ .grid{grid-template-columns:1fr} }
.card{border:1px solid var(--border);border-radius:14px;padding:16px;background:#fff}
.card h2{margin:0 0 10px;font-size:16px}
.tiles{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
.tile{display:block;border:1px solid var(--border);border-radius:12px;padding:12px;text-decoration:none;color:inherit;background:#fff;transition:filter .15s ease}
.tile:hover{filter:brightness(.98)}
.small{color:var(--muted);font-size:12px}
</style>
</head>
<body>

<?php require __DIR__ . '/../../partials/admin_nav.php'; ?>

<div class="container">
  <?php if ($bootstrap_warning): ?>
    <div class="section small"><?= h($bootstrap_warning) ?></div>
  <?php endif; ?>

  <div class="section">
    <div class="h1">Rewards</div>
    <div class="grid">
      <div class="card">
        <h2>Points</h2>
        <div class="tiles">
          <a class="tile" href="/views/admin/rewards/points/overview.php">Overview</a>
          <a class="tile" href="/views/admin/rewards/points/earn_rules.php">Earn Rules</a>
          <a class="tile" href="/views/admin/rewards/points/redeem_rules.php">Redeem Rules</a>
          <a class="tile" href="/views/admin/rewards/points/ledger.php">Points Ledger</a>
          <a class="tile" href="/views/admin/rewards/points/catalog.php">Rewards Catalog</a>
          <a class="tile" href="/views/admin/rewards/points/redemptions.php">Redemptions</a>
          <a class="tile" href="/views/admin/rewards/points/adjustments.php">Adjustments</a>
          <a class="tile" href="/views/admin/rewards/points/reports.php">Reports</a>
        </div>
      </div>

      <div class="card">
        <h2>Stamp</h2>
        <div class="tiles">
          <a class="tile" href="/views/admin/rewards/stamp/overview.php">Overview</a>
          <a class="tile" href="/views/admin/rewards/stamp/rules.php">Stamp Rules</a>
          <a class="tile" href="/views/admin/rewards/stamp/cards.php">Stamp Cards</a>
          <a class="tile" href="/views/admin/rewards/stamp/issued.php">Issued Rewards</a>
          <a class="tile" href="/views/admin/rewards/stamp/adjustments.php">Adjustments</a>
          <a class="tile" href="/views/admin/rewards/stamp/reports.php">Reports</a>
        </div>
      </div>

      <div class="card">
        <h2>Cashback</h2>
        <div class="tiles">
          <a class="tile" href="/views/admin/rewards/cashback/overview.php">Overview</a>
          <a class="tile" href="/views/admin/rewards/cashback/rules.php">Cashback Rules</a>
          <a class="tile" href="/views/admin/rewards/cashback/ledger.php">Cashback Ledger</a>
          <a class="tile" href="/views/admin/rewards/cashback/wallets.php">Wallet Balances</a>
          <a class="tile" href="/views/admin/rewards/cashback/adjustments.php">Adjustments</a>
          <a class="tile" href="/views/admin/rewards/cashback/reports.php">Reports</a>
        </div>
      </div>

      <div class="card">
        <h2>Common</h2>
        <div class="tiles">
          <a class="tile" href="/views/admin/rewards/common/members.php">Members</a>
          <a class="tile" href="/views/admin/rewards/common/member_view.php">Member Details</a>
          <a class="tile" href="/views/admin/rewards/common/tiers.php">Tiers</a>
          <a class="tile" href="/views/admin/rewards/common/campaigns.php">Campaigns</a>
          <a class="tile" href="/views/admin/rewards/common/coupons.php">Coupons / Vouchers</a>
          <a class="tile" href="/views/admin/rewards/common/expiration.php">Expiration Policies</a>
          <a class="tile" href="/views/admin/rewards/common/integrations.php">Integrations</a>
          <a class="tile" href="/views/admin/rewards/common/reports.php">Global Reports</a>
          <a class="tile" href="/views/admin/rewards/common/settings.php">Settings</a>
        </div>
      </div>
    </div>
  </div>

  <?php if ($DEBUG): ?>
    <div class="section small">Debug: Bootstrap <?= $bootstrap_ok ? 'OK' : 'WARN' ?><?= $bootstrap_warning ? (' — ' . h($bootstrap_warning)) : '' ?><br>Path: <?= h($bootstrap_path) ?></div>
  <?php endif; ?>
</div>
</body>
</html>
