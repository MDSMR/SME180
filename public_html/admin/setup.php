<?php
// /public_html/admin/setup.php
declare(strict_types=1);

// DEBUG (temporary): uncomment while troubleshooting 500s
/*
ini_set('display_errors','1');
ini_set('display_startup_errors','1');
error_reporting(E_ALL);
*/

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// 100% fixed path to your auth helper:
require_once __DIR__ . '/../config/admin_auth.php';
admin_require_auth(); // stop here if not logged in

$current = 'setup';

// Tabs map (iframe targets live under /views/admin/setup/* or nearby)
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

function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// Header include (you confirmed this exists)
$headerPath = __DIR__ . '/../views/admin/_header.php';
?>
<!doctype html>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Setup</title>
<?php require_once $headerPath; ?>
<style>
  :root{--brand: rgb(0,123,255); --bg:#f7f7fb; --fg:#111}
  *{box-sizing:border-box}
  body{margin:0;font-family:system-ui,Segoe UI,Arial,sans-serif;background:var(--bg);color:var(--fg)}
  main{padding:20px;max-width:1200px;margin:0 auto}
  .tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}
  .tab{display:inline-block;padding:8px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#fff;text-decoration:none;color:#111}
  .tab.active{background:rgb(0,123,255);color:#fff;border-color:rgb(0,123,255)}
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
    <iframe src="<?= h($targetSrc) ?>" title="<?= h($tabLabel) ?>" loading="eager" referrerpolicy="same-origin"></iframe>
  </div>
</main>