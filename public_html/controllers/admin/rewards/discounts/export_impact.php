<?php
// /controllers/admin/rewards/discounts/export_impact.php
// Export controller for Sales Impact Report - CSV Only
declare(strict_types=1);

// Direct database connection
require_once __DIR__ . '/../../../../config/db.php';

// Basic security check - at minimum verify the request comes from your domain
if (!isset($_SERVER['HTTP_REFERER']) || strpos($_SERVER['HTTP_REFERER'], 'mohamedk10.sg-host.com') === false) {
    http_response_code(403);
    die('Access denied');
}

// Set tenant ID
$tenantId = 1;

// Get parameters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$branchId = $_GET['branch_id'] ?? 'all';

// Get currency
$currency = 'EGP';
try {
    $pdo = db();
    $stmt = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = ? AND `key` = 'currency' LIMIT 1");
    $stmt->execute([$tenantId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && !empty($result['value'])) {
        $currency = $result['value'];
    }
} catch(Exception $e) {
    // Keep default
}

// Fetch report data
$reportData = [];
try {
    $pdo = db();
    
    $sql = "
        SELECT 
            DATE(o.created_at) as period,
            COUNT(DISTINCT o.id) as total_orders,
            COUNT(DISTINCT CASE WHEN o.discount_amount > 0 THEN o.id END) as discounted_orders,
            SUM(o.subtotal_amount) as total_sales,
            SUM(o.discount_amount) as total_discount,
            AVG(o.total_amount) as avg_order_value
        FROM orders o
        WHERE o.tenant_id = :tenant_id
            AND DATE(o.created_at) BETWEEN :date_from AND :date_to
            AND o.is_voided = 0
    ";
    
    $params = [
        ':tenant_id' => $tenantId,
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo
    ];
    
    if ($branchId !== 'all') {
        $sql .= " AND o.branch_id = :branch_id";
        $params[':branch_id'] = $branchId;
    }
    
    $sql .= " GROUP BY period ORDER BY period ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(Exception $e) {
    die('Error generating report: ' . $e->getMessage());
}

// Calculate totals
$totals = [
    'total_orders' => array_sum(array_column($reportData, 'total_orders')),
    'discounted_orders' => array_sum(array_column($reportData, 'discounted_orders')),
    'total_sales' => array_sum(array_column($reportData, 'total_sales')),
    'total_discount' => array_sum(array_column($reportData, 'total_discount'))
];

// Generate CSV output
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="sales_impact_report_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Headers
fputcsv($output, ['Sales Impact Report']);
fputcsv($output, ['Period: ' . $dateFrom . ' to ' . $dateTo]);
fputcsv($output, []);

// Summary metrics
fputcsv($output, ['Summary Metrics']);
fputcsv($output, ['Total Orders', $totals['total_orders']]);
fputcsv($output, ['Orders with Discount', $totals['discounted_orders']]);
fputcsv($output, ['Discount Utilization', number_format(($totals['discounted_orders'] / max(1, $totals['total_orders'])) * 100, 1) . '%']);
fputcsv($output, ['Total Sales', $currency . ' ' . number_format($totals['total_sales'], 2)]);
fputcsv($output, ['Total Discounts Given', $currency . ' ' . number_format($totals['total_discount'], 2)]);
fputcsv($output, ['Discount Impact', number_format(($totals['total_discount'] / max(1, $totals['total_sales'])) * 100, 1) . '%']);
fputcsv($output, []);

// Column headers
fputcsv($output, [
    'Date',
    'Total Orders',
    'Discounted Orders',
    'Discount %',
    'Gross Sales',
    'Discount Amount',
    'Net Sales',
    'Avg Order Value',
    'Impact %'
]);

// Data rows
foreach ($reportData as $row) {
    $discountPercent = $row['total_orders'] > 0 
        ? number_format(($row['discounted_orders'] / $row['total_orders']) * 100, 1) . '%'
        : '0%';
    
    $impactPercent = $row['total_sales'] > 0
        ? number_format(($row['total_discount'] / $row['total_sales']) * 100, 1) . '%'
        : '0%';
        
    fputcsv($output, [
        date('Y-m-d', strtotime($row['period'])),
        $row['total_orders'],
        $row['discounted_orders'],
        $discountPercent,
        $currency . ' ' . number_format($row['total_sales'], 2),
        $currency . ' ' . number_format($row['total_discount'], 2),
        $currency . ' ' . number_format($row['total_sales'] - $row['total_discount'], 2),
        $currency . ' ' . number_format($row['avg_order_value'], 2),
        $impactPercent
    ]);
}

// Totals
fputcsv($output, []);
fputcsv($output, [
    'TOTAL',
    $totals['total_orders'],
    $totals['discounted_orders'],
    number_format(($totals['discounted_orders'] / max(1, $totals['total_orders'])) * 100, 1) . '%',
    $currency . ' ' . number_format($totals['total_sales'], 2),
    $currency . ' ' . number_format($totals['total_discount'], 2),
    $currency . ' ' . number_format($totals['total_sales'] - $totals['total_discount'], 2),
    '',
    number_format(($totals['total_discount'] / max(1, $totals['total_sales'])) * 100, 1) . '%'
]);

fclose($output);
?>