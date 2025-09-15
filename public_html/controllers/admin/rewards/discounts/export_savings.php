<?php
// /controllers/admin/rewards/discounts/export_savings.php
// Export controller for Customer Savings Report - CSV Only
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
            c.name as customer_name,
            c.phone,
            c.email,
            c.classification,
            COUNT(DISTINCT o.id) as total_orders,
            COUNT(DISTINCT CASE WHEN o.discount_amount > 0 THEN o.id END) as discounted_orders,
            COALESCE(SUM(o.discount_amount), 0) as total_savings,
            COALESCE(AVG(CASE WHEN o.discount_amount > 0 THEN o.discount_amount END), 0) as avg_savings,
            MIN(CASE WHEN o.discount_amount > 0 THEN o.created_at END) as first_discount_date,
            MAX(CASE WHEN o.discount_amount > 0 THEN o.created_at END) as last_discount_date
        FROM customers c
        LEFT JOIN orders o ON c.id = o.customer_id 
            AND o.tenant_id = :tenant_id
            AND DATE(o.created_at) BETWEEN :date_from AND :date_to
            AND o.is_voided = 0
    ";
    
    if ($branchId !== 'all') {
        $sql .= " AND o.branch_id = :branch_id";
    }
    
    $sql .= " WHERE c.tenant_id = :tenant_id
              GROUP BY c.id
              HAVING total_savings > 0
              ORDER BY total_savings DESC";
    
    $params = [
        ':tenant_id' => $tenantId,
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo
    ];
    
    if ($branchId !== 'all') {
        $params[':branch_id'] = $branchId;
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(Exception $e) {
    die('Error generating report: ' . $e->getMessage());
}

// Generate CSV output
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="customer_savings_report_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Headers
fputcsv($output, ['Customer Savings Report']);
fputcsv($output, ['Period: ' . $dateFrom . ' to ' . $dateTo]);
fputcsv($output, []);

// Column headers
fputcsv($output, [
    'Customer Name',
    'Phone',
    'Email',
    'Classification',
    'Total Orders',
    'Discounted Orders',
    'Discount %',
    'Total Savings',
    'Average Savings',
    'First Discount',
    'Last Discount'
]);

// Data rows
foreach ($reportData as $row) {
    $discountPercent = $row['total_orders'] > 0 
        ? number_format(($row['discounted_orders'] / $row['total_orders']) * 100, 1) . '%'
        : '0%';
        
    fputcsv($output, [
        $row['customer_name'],
        $row['phone'] ?: 'N/A',
        $row['email'] ?: 'N/A',
        ucfirst($row['classification'] ?: 'Regular'),
        $row['total_orders'],
        $row['discounted_orders'],
        $discountPercent,
        $currency . ' ' . number_format($row['total_savings'], 2),
        $currency . ' ' . number_format($row['avg_savings'], 2),
        $row['first_discount_date'] ? date('Y-m-d', strtotime($row['first_discount_date'])) : 'N/A',
        $row['last_discount_date'] ? date('Y-m-d', strtotime($row['last_discount_date'])) : 'N/A'
    ]);
}

// Totals
fputcsv($output, []);
fputcsv($output, [
    'TOTAL (' . count($reportData) . ' customers)',
    '',
    '',
    '',
    array_sum(array_column($reportData, 'total_orders')),
    array_sum(array_column($reportData, 'discounted_orders')),
    '',
    $currency . ' ' . number_format(array_sum(array_column($reportData, 'total_savings')), 2),
    '',
    '',
    ''
]);

fclose($output);
?>