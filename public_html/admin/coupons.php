<?php
// admin/coupons.php - list cashback coupons and filter
require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../config/db.php';
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Coupons</title>
<style>body{font-family:Arial;padding:12px} table{width:100%;border-collapse:collapse} th,td{border:1px solid #ddd;padding:8px}</style>
</head><body>
<h1>Cashback Coupons</h1>
<table><thead><tr><th>Code</th><th>Customer</th><th>Visit</th><th>Bill</th><th>Cashback</th><th>Expires</th><th>Redeemed</th></tr></thead><tbody>
<?php
$pdo = get_pdo();
$stmt = $pdo->query("SELECT cc.*, c.name as customer_name FROM cashback_coupons cc LEFT JOIN customers c ON c.id = cc.customer_id ORDER BY cc.created_at DESC LIMIT 500");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo '<tr><td>'.htmlspecialchars($r['code']).'</td><td>'.htmlspecialchars($r['customer_name'] ?? 'Unknown').'</td><td>'.intval($r['visit_number']).'</td><td>'.number_format($r['bill_amount'],2).'</td><td>'.number_format($r['cashback_amount'],2).'</td><td>'.htmlspecialchars($r['expires_at']).'</td><td>'.($r['redeemed']? 'Yes':'No').'</td></tr>';
}
?>
</tbody></table>
</body></html>
