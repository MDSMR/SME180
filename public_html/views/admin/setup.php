<?php
// admin/setup.php — Setup landing (tabs + iframe)
declare(strict_types=1);

// Start a session before any redirects/headers
if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/**
 * 1) Try a local bootstrap if you have one.
 *    (Your older file referenced _auth_bootstrap.php)
 */
$bootstrap = __DIR__ . '/_auth_bootstrap.php';
if (file_exists($bootstrap)) {
  require_once $bootstrap;
}

/**
 * 2) Ensure an auth layer is loaded.
 *    We support BOTH of your shared styles:
 *    - auth_check.php that redirects when not logged in (no functions)
 *    - admin_* helpers file that defines admin_require_auth()
 */
if (!function_exists('admin_require_auth')) {
  $candidates = [
    __DIR__ . '/../config/admin_auth.php',     // helpers (admin_login/require_auth/logout)
    __DIR__ . '/../config/auth_admin.php',     // alt name
    __DIR__ . '/../config/auth_helpers.php',   // alt name
    __DIR__ . '/../config/auth_check.php',     // redirect-on-miss (same directory depth)
    __DIR__ . '/../../config/auth_check.php',  // redirect-on-miss (two levels up)
  ];
  foreach ($candidates as $p) {
    if (file_exists($p)) {
      require_once $p;
      if (function_exists('admin_require_auth')) break;
    }
  }
}

/**
 * 3) Gate access:
 *    - If admin_require_auth() exists, call it (it returns void).
 *    - Otherwise, fall back to checking your known session keys and redirect.
 *      (Works with your redirect-only auth_check.php too.)
 */
if (function_exists('admin_require_auth')) {
  admin_require_auth(); // will redirect if not authenticated
} else {
  $authed = !empty($_SESSION['admin_user_id']) || !empty($_SESSION['user_id']);
  if (!$authed) {
    $next = $_SERVER['REQUEST_URI'] ?? '/admin/setup.php';
    header('Location: /views/auth/login.php?next=' . urlencode($next));
    exit;
  }
}

// Page key for header highlighting
$current = 'setup';

// Tabs map: key => [url, label]
$tabs = [
  'printers' => ['/views/admin/menu/printers.php?embed=1',       'Printers'],
  'users'    => ['/views/admin/users.php?embed=1',                'Users'],
  'tax'      => ['/views/admin/setup/tax.php?embed=1',            'Tax'],
  'service'  => ['/views/admin/setup/service_charge.php?embed=1', 'Service Charge'],
  'aggr'     => ['/views/admin/setup/aggregators.php?embed=1',    'Aggregators'],
  'company'  => ['/views/admin/setup/management.php?embed=1',     'Company'],
];

$tabKey = $_GET['tab'] ?? 'printers';
if (!array_key_exists($tabKey, $tabs)) $tabKey = 'printers';
[$targetSrc, $tabLabel] = $tabs[$tabKey];

// Include the shared admin header AFTER auth gate (it outputs markup)
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup</title>
<?php require_once __DIR__ . '/../views/admin/_header.php'; ?>
<style>
  :root{--brand: rgb(0,123,255); --bg:#f7f7fb; --fg:#111; --muted:#666; --card:#fff}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--fg)}
  main{padding:20px;max-width:1200px;margin:0 auto}
  .tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}
  .tab{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;text-decoration:none;color:#111}
  .tab.active{background:var(--brand);color:#fff;border-color:var(--brand)}
  .frame-wrap{background:#fff;border:1px solid #eee;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.05);overflow:hidden}
  iframe{display:block;width:100%;height:75vh;border:0}
</style>
<main>
  <div class="tabs" role="tablist" aria-label="Setup sections">
    <a class="tab <?= $tabKey==='printers'?'active':'' ?>" href="/admin/setup.php?tab=printers">Printers</a>
    <a class="tab <?= $tabKey==='users'?'active':'' ?>"    href="/admin/setup.php?tab=users">Users</a>
    <a class="tab <?= $tabKey==='tax'?'active':'' ?>"      href="/admin/setup.php?tab=tax">Tax</a>
    <a class="tab <?= $tabKey==='service'?'active':'' ?>"  href="/admin/setup.php?tab=service">Service Charge</a>
    <a class="tab <?= $tabKey==='aggr'?'active':'' ?>"     href="/admin/setup.php?tab=aggr">Aggregators</a>
    <a class="tab <?= $tabKey==='company'?'active':'' ?>"  href="/admin/setup.php?tab=company">Company</a>
  </div>
  <div class="frame-wrap">
    <iframe src="<?= htmlspecialchars($targetSrc, ENT_QUOTES, 'UTF-8') ?>"
            title="<?= htmlspecialchars($tabLabel, ENT_QUOTES, 'UTF-8') ?>"
            loading="eager" referrerpolicy="same-origin"></iframe>
  </div>
</main>