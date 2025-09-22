<?php
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

// Use existing auth if opened directly; skip when embedded
$embedded = !empty($_GET['embed']);
if (!$embedded) {
  $authCandidates = [
    __DIR__ . '/../../../config/admin_auth.php',
    __DIR__ . '/../../config/auth_check.php',
  ];
  foreach ($authCandidates as $p) { if (file_exists($p)) { require_once $p; break; } }
  if (function_exists('admin_require_auth')) { admin_require_auth(); }
}
?>
<?php if (!$embedded): ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><title>Management</title>
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<?php include __DIR__ . '/../../_nav.php'; ?>
<div class="container" style="max-width:980px;margin:16px auto;padding:0 16px">
<?php endif; ?>

<main style="padding:10px;max-width:980px;margin:0 auto;font-family:system-ui">
  <!-- Heading removed -->
  <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
    <a class="card" href="/views/admin/menu/printers.php?embed=1" style="padding:16px;text-decoration:none;color:inherit">
      <strong>Printers</strong>
      <div class="help">Kitchen & receipt printers</div>
    </a>
    <a class="card" href="/views/admin/users.php?embed=1" style="padding:16px;text-decoration:none;color:inherit">
      <strong>Users</strong>
      <div class="help">Accounts & roles</div>
    </a>
    <a class="card" href="/views/admin/company.php?embed=1" style="padding:16px;text-decoration:none;color:inherit">
      <strong>Company</strong>
      <div class="help">Profile & identity</div>
    </a>
  </div>
</main>

<?php if (!$embedded): ?>
</div>
</body>
</html>
<?php endif; ?>