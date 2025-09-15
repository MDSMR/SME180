<?php
declare(strict_types=1);

/* ---------- Debug toggle ---------- */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { @ini_set('display_errors','1'); @ini_set('display_startup_errors','1'); error_reporting(E_ALL); }

/* ---------- Bootstrap /config/db.php (robust search) ---------- */
$bootstrap_warning=''; $bootstrap_ok=false; $bootstrap_found='';
$bootstrap_tried=[];

function _try_add(&$arr, string $p){ if (!in_array($p, $arr, true)) { $arr[]=$p; } }

// from typical /views/admin/rewards/points/*.php
_try_add($bootstrap_tried, __DIR__ . '/../../../../config/db.php');

// from typical /views/admin/catalog/*.php
_try_add($bootstrap_tried, __DIR__ . '/../../../config/db.php');

// DOCUMENT_ROOT fallback
$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($docRoot !== '') { _try_add($bootstrap_tried, $docRoot . '/config/db.php'); }

// Walk upwards for /config/db.php
$cursor = __DIR__;
for ($i=0; $i<7; $i++) {
  $cursor = dirname($cursor);
  if ($cursor === '' || $cursor === '/' || $cursor === '.' ) break;
  _try_add($bootstrap_tried, $cursor . '/config/db.php');
}

foreach ($bootstrap_tried as $p) { if (is_file($p)) { $bootstrap_found = $p; break; } }
if ($bootstrap_found==='') {
  $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
  $prevHandler = set_error_handler(function($s,$m,$f,$l){ throw new ErrorException($m,0,$s,$f,$l); });
  try {
    require_once $bootstrap_found; // expects db(), use_backend_session()
    if (function_exists('use_backend_session')) { use_backend_session(); }
    $bootstrap_ok = function_exists('db');
    if (!$bootstrap_ok) { $bootstrap_warning = 'Required function db() missing in config/db.php'; }
  } catch (Throwable $e) {
    $bootstrap_warning = 'Bootstrap error: ' . $e->getMessage();
  } finally {
    if ($prevHandler) { set_error_handler($prevHandler); }
  }
}

if (!$bootstrap_ok) {
  http_response_code(200);
  echo '<h1>Admin</h1><div style="color:#c00">Bootstrap failed: ' . htmlspecialchars($bootstrap_warning, ENT_QUOTES) . '</div>';
  exit;
}

/* ---------- Minimal auth (best-effort) ---------- */
if (function_exists('auth_require_login')) {
  auth_require_login();
}
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$user = $_SESSION['user'] ?? null;
if (!$user) { @header('Location: /views/auth/login.php'); exit; }

$tenantId = (int)($user['tenant_id'] ?? 0);
$userId   = (int)($user['id'] ?? 0);

/* ---------- Small helpers ---------- */
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); }
function dt(?string $s): string { if(!$s) return '—'; try{ $d=new DateTime($s); return $d->format('Y-m-d H:i'); } catch(Throwable $e){ return h($s); } }
function money($n): string { if($n===null) return '—'; return number_format((float)$n, 2); }

/* ---------- Safe partial include ---------- */
function include_admin_nav(string $active=''): void {
  $active_var_for_partial = $active;
  $cand = [
    __DIR__ . '/../../../partials/admin_nav.php',             // /views/admin/rewards/* → /views/partials
    __DIR__ . '/../../partials/admin_nav.php',                // /views/admin/* → /views/partials
    (rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/')) . '/views/partials/admin_nav.php',
  ];
  foreach ($cand as $p) {
    if (is_file($p)) { $active = $active_var_for_partial; include $p; return; }
  }
  // no fatal if partial missing
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Rewards · Points · Redemptions</title>
  <link rel="stylesheet" href="/assets/css/bootstrap.min.css">
</head>
<body>
<?php include_admin_nav('rewards'); ?>
<div class="container mt-4">
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="/views/admin/rewards/index.php">Rewards</a></li>
      <li class="breadcrumb-item"><a href="/views/admin/rewards/points/overview.php">Points</a></li>
      <li class="breadcrumb-item active" aria-current="page">Redemptions</li>
    </ol>
  </nav>

  <h1 class="h3 mb-3">Rewards · Points · Redemptions</h1>
  <?php
$pdo = db();
$rows=[];
try{
  $stmt=$pdo->prepare("SELECT id, customer_id, order_id, type, points_delta, reason, created_at FROM loyalty_ledger WHERE tenant_id=:t AND points_delta < 0 ORDER BY id DESC LIMIT 300");
  $stmt->execute([':t'=>$tenantId]); $rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
}catch(Throwable $e){ $rows=[]; }
?>
<div class="card shadow-sm">
  <div class="card-body">
    <div class="table-responsive">
      <table class="table table-sm table-striped align-middle">
        <thead><tr>
          <th>ID</th><th>Customer</th><th>Order</th><th>Type</th><th class="text-end">Redeemed</th><th>Reason</th><th>When</th>
        </tr></thead>
        <tbody>
        <?php if(!$rows): ?>
          <tr><td colspan="7" class="text-muted">No redemptions.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <td><?=h((string)$r['id'])?></td>
            <td><?=h('#'.$r['customer_id'])?></td>
            <td><?=h($r['order_id']!==null? '#'.$r['order_id'] : '—')?></td>
            <td><?=h($r['type'])?></td>
            <td class="text-end"><?=h((string)abs((int)$r['points_delta']))?></td>
            <td><?=h($r['reason'] ?? '')?></td>
            <td><?=dt($r['created_at'] ?? null)?></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

</div>
<script src="/assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>