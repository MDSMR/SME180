<?php
// /controllers/admin/rewards/discounts/export_usage.php
// Export controller for Usage Report - CSV Only
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
$programId = $_GET['program_id'] ?? 'all';
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
            ds.name as program_name,
            ds.code as program_code,
            ds.type as discount_type,
            ds.value as discount_value,
            ds.is_stackable,
            b.name as branch_name,
            o.order_type,
            DATE(o.created_at) as order_date,
            COUNT(DISTINCT o.id) as usage_count,
            COUNT(DISTINCT o.customer_id) as unique_customers,
            SUM(o.discount_amount) as total_discount,
            AVG(o.discount_amount) as avg_discount
        FROM discount_schemes ds
        LEFT JOIN orders o ON o.tenant_id = ds.tenant_id 
            AND o.discount_amount > 0
            AND DATE(o.created_at) BETWEEN :date_from AND :date_to
            AND o.is_voided = 0
        LEFT JOIN branches b ON o.branch_id = b.id
        WHERE ds.tenant_id = :tenant_id
    ";
    
    $params = [
        ':tenant_id' => $tenantId,
        ':date_from' => $dateFrom,
        ':date_to' => $dateTo
    ];
    
    if ($programId !== 'all') {
        $sql .= " AND ds.id = :program_id";
        $params[':program_id'] = $programId;
    }
    
    if ($branchId !== 'all') {
        $sql .= " AND o.branch_id = :branch_id";
        $params[':branch_id'] = $branchId;
    }
    
    $sql .= " GROUP BY ds.id, b.id, o.order_type, DATE(o.created_at)
              ORDER BY ds.name, order_date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $reportData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(Exception $e) {
    die('Error generating report: ' . $e->getMessage());
}

// Process data for summary
$programTotals = [];
foreach ($reportData as $row) {
    $pName = $row['program_name'];
    if (!isset($programTotals[$pName])) {
        $programTotals[$pName] = [
            'code' => $row['program_code'],
            'type' => $row['discount_type'],
            'value' => $row['discount_value'],
            'stackable' => $row['is_stackable'],
            'usage_count' => 0,
            'total_discount' => 0
        ];
    }
    $programTotals[$pName]['usage_count'] += $row['usage_count'];
    $programTotals[$pName]['total_discount'] += $row['total_discount'];
}

// Generate CSV output
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="discount_usage_report_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// Headers
fputcsv($output, ['Discount Usage Report']);
fputcsv($output, ['Period: ' . $dateFrom . ' to ' . $dateTo]);
fputcsv($output, []);

// Column headers
fputcsv($output, [
    'Program Name',
    'Code',
    'Type',
    'Value',
    'Stackable',
    'Times Used',
    'Total Discount',
    'Average Discount'
]);

// Data rows
foreach ($programTotals as $name => $data) {
    fputcsv($output, [
        $name,
        $data['code'],
        $data['type'],
        $data['type'] === 'percent' ? $data['value'] . '%' : $currency . ' ' . $data['value'],
        $data['stackable'] ? 'Yes' : 'No',
        $data['usage_count'],
        $currency . ' ' . number_format($data['total_discount'], 2),
        $currency . ' ' . number_format($data['usage_count'] > 0 ? $data['total_discount'] / $data['usage_count'] : 0, 2)
    ]);
}

// Totals
fputcsv($output, []);
fputcsv($output, [
    'TOTAL',
    '',
    '',
    '',
    '',
    array_sum(array_column($programTotals, 'usage_count')),
    $currency . ' ' . number_format(array_sum(array_column($programTotals, 'total_discount')), 2),
    ''
]);

fclose($output);
?>