<?php
// /public_html/admin/dashboard.php — minimal, old-nav, schema-safe
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 1) DB/Auth bootstrap
require_once __DIR__ . '/_auth_bootstrap.php'; // must create $pdo or exit
// Load auth helper if present
$auth_includes = [
    __DIR__ . '/../config/auth.php',
    __DIR__ . '/_auth.php',
];
foreach ($auth_includes as $inc) {
    if (file_exists($inc)) { require_once $inc; break; }
}
// Enforce admin auth if function exists
if (function_exists('admin_require_auth')) {
    admin_require_auth();
}

// 2) Include the ORIGINAL header (old nav) — no injected UI
$header_paths = [
    __DIR__ . '/../views/admin/_header.php',
];
$header_included = false;
foreach ($header_paths as $hp) {
    if (file_exists($hp)) { include $hp; $header_included = true; break; }
}
if (!$header_included) {
    echo '<!-- WARNING: views/admin/_header.php not found. Old nav will not render. -->';
}

// 3) Simple KPIs — schema-safe (products.name only)
function qone(PDO $pdo, string $sql, array $args = [], $default = 0) {
    $st = $pdo->prepare($sql);
    $st->execute($args);
    $row = $st->fetch(PDO::FETCH_NUM);
    return $row ? ($row[0] ?? $default) : $default;
}

try {
    $total_products   = qone($pdo, "SELECT COUNT(*) FROM products");
    $total_categories = qone($pdo, "SELECT COUNT(*) FROM categories");
    $total_orders     = qone($pdo, "SELECT COUNT(*) FROM orders");
    $total_tables     = qone($pdo, "SELECT COUNT(*) FROM tables");

    $today_sales   = qone($pdo, "SELECT COALESCE(SUM(total_price),0) FROM orders WHERE DATE(created_at)=CURDATE()");
    $today_tickets = qone($pdo, "SELECT COUNT(*) FROM orders WHERE DATE(created_at)=CURDATE()");

    // Top products today — use products.name only (avoid name_en/name_ar)
    $sqlTop = "
        SELECT p.id, p.name AS product_name, SUM(oi.quantity) AS qty
        FROM order_items oi
        JOIN orders o   ON o.id = oi.order_id
        JOIN products p ON p.id = oi.product_id
        WHERE DATE(o.created_at) = CURDATE()
        GROUP BY p.id, product_name
        ORDER BY qty DESC
        LIMIT 10";
    $top_products = $pdo->query($sqlTop)->fetchAll();

    // Categories — prefer generated columns, fallback to JSON
    $cats = [];
    try {
        $cats = $pdo->query("
            SELECT id,
                   COALESCE(cat_name_en, JSON_UNQUOTE(JSON_EXTRACT(name_i18n,'$.en'))) AS name_en,
                   COALESCE(cat_name_ar, JSON_UNQUOTE(JSON_EXTRACT(name_i18n,'$.ar'))) AS name_ar
            FROM categories
            ORDER BY sort_order, id")->fetchAll();
    } catch (Throwable $e) {
        $cats = $pdo->query("
            SELECT id,
                   JSON_UNQUOTE(JSON_EXTRACT(name_i18n,'$.en')) AS name_en,
                   JSON_UNQUOTE(JSON_EXTRACT(name_i18n,'$.ar')) AS name_ar
            FROM categories
            ORDER BY id")->fetchAll();
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Dashboard query failed</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Admin Dashboard</title>
<meta name="viewport" content="width=device-width,initial-scale=1" />
</head>
<body>

<div style="padding:16px;">
  <h1>Dashboard</h1>
  <div class="kpis" style="display:grid;grid-template-columns:repeat(4,minmax(150px,1fr));gap:12px;">
    <div><div>Total Products</div><strong><?= (int)$total_products ?></strong></div>
    <div><div>Total Categories</div><strong><?= (int)$total_categories ?></strong></div>
    <div><div>Total Orders</div><strong><?= (int)$total_orders ?></strong></div>
    <div><div>Total Tables</div><strong><?= (int)$total_tables ?></strong></div>
  </div>

  <div class="kpis" style="display:grid;grid-template-columns:repeat(2,minmax(150px,1fr));gap:12px;margin-top:12px;">
    <div><div>Today's Sales</div><strong><?= number_format((float)$today_sales, 2) ?></strong></div>
    <div><div>Today's Tickets</div><strong><?= (int)$today_tickets ?></strong></div>
  </div>

  <h2 style="margin-top:18px;">Top Products Today</h2>
  <table border="1" cellpadding="6" cellspacing="0">
    <thead><tr><th>#</th><th>Name</th><th>Qty</th></tr></thead>
    <tbody>
    <?php if (empty($top_products)): ?>
      <tr><td colspan="3"><em>No sales today.</em></td></tr>
    <?php else: foreach ($top_products as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td><?= htmlspecialchars($r['product_name'] ?: '—') ?></td>
        <td><?= (int)$r['qty'] ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table>

  <h2 style="margin-top:18px;">Categories</h2>
  <table border="1" cellpadding="6" cellspacing="0">
    <thead><tr><th>ID</th><th>Name (EN)</th><th>Name (AR)</th></tr></thead>
    <tbody>
    <?php foreach ($cats as $c): ?>
      <tr>
        <td><?= (int)$c['id'] ?></td>
        <td><?= htmlspecialchars($c['name_en'] ?? '') ?></td>
        <td dir="rtl"><?= htmlspecialchars($c['name_ar'] ?? '') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

</body>
</html>
