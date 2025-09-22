<?php
require_once __DIR__ . '/../../config/auth_check.php';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"><title>Setup</title>
  <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>
<div class="container" style="max-width:980px;margin:16px auto;padding:0 16px">
  <h1 class="page-title">Setup</h1>
  <p class="subtitle">Configure Tax, Service Charge, and Aggregators.</p>

  <div class="row" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px">
    <a class="card" href="/views/admin/setup/tax.php" style="padding:16px;text-decoration:none;color:inherit">
      <strong>Tax</strong>
      <div class="help">VAT/GST rates and rules</div>
    </a>
    <a class="card" href="/views/admin/setup/service_charge.php" style="padding:16px;text-decoration:none;color:inherit">
      <strong>Service Charge</strong>
      <div class="help">Default service fee settings</div>
    </a>
    <a class="card" href="/views/admin/setup/aggregators.php" style="padding:16px;text-decoration:none;color:inherit">
      <strong>Aggregators</strong>
      <div class="help">Delivery integrations</div>
    </a>
  </div>
</div>
</body>
</html>