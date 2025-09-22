<?php
require_once __DIR__ . '/../../config/auth_check.php'; // adjust if needed
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><title>Management</title>
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="container" style="max-width:980px;margin:16px auto;padding:0 16px">
  <h1 class="page-title">Management</h1>

  <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
    <a class="card" href="/views/admin/menu/printers.php" style="padding:16px;text-decoration:none;color:inherit">
      <strong>Printers</strong><div class="help">Kitchen & receipt printers</div>
    </a>
    <a class="card" href="/views/admin/user_management.php" style="padding:16px;text-decoration:none;color:inherit">
      <strong>Users</strong><div class="help">Accounts & roles</div>
    </a>
    <a class="card" href="/views/admin/company.php" style="padding:16px;text-decoration:none;color:inherit">
      <strong>Company</strong><div class="help">Profile & identity</div>
    </a>
  </div>
</div>
</body>
</html>