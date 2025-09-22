<?php
require_once __DIR__ . '/../config/auth_check.php';
require_once __DIR__ . '/../config/db.php';

$pdo  = get_pdo();
$code = trim($_GET['code'] ?? '');
if ($code === '') { http_response_code(400); exit('Missing code'); }

$stmt = $pdo->prepare("SELECT code, visit_number, bill_amount, cashback_amount, created_at, expires_at FROM cashback_coupons WHERE code=? LIMIT 1");
$stmt->execute([$code]);
$c = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$c) { http_response_code(404); exit('Coupon not found'); }
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><title>Cashback Coupon</title>
<style>
  body{font-family:Arial,sans-serif;font-size:14px;margin:0;padding:12px}
  .center{text-align:center}
  hr{border:0;border-top:1px dashed #000;margin:8px 0}
  .big{font-size:18px;font-weight:bold}
  .row{display:flex;justify-content:space-between;margin:4px 0}
  @media print { @page{ size:80mm auto; margin:3mm; } button{ display:none; } }
</style>
</head>
<body>
  <div class="center big">SMORLL POS</div>
  <div class="center">Cashback Coupon</div>
  <hr>
  <div class="row"><div>Code</div><div><strong><?= htmlspecialchars($c['code']) ?></strong></div></div>
  <div class="row"><div>Visit</div><div><?= (int)$c['visit_number'] ?></div></div>
  <div class="row"><div>Bill Amount</div><div><?= number_format((float)$c['bill_amount'],2) ?></div></div>
  <div class="row"><div>Discount Amount</div><div><?= number_format((float)$c['cashback_amount'],2) ?></div></div>
  <hr>
  <div>Issued: <?= htmlspecialchars($c['created_at']) ?></div>
  <?php if (!empty($c['expires_at'])): ?><div>Expires: <?= htmlspecialchars($c['expires_at']) ?></div><?php endif; ?>
  <hr>
  <div class="center">Present this code on your next visit.</div>
  <div class="center" style="margin-top:8px"><button onclick="window.print()">Print</button></div>
</body>
</html>