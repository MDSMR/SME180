<?php
// /views/admin/rewards/stamp/controllers/export_report.php
// Enhanced CSV export functionality for stamp reports with tab support
declare(strict_types=1);

require_once __DIR__ . '/../_shared/common.php';

if (!$bootstrap_ok) {
    http_response_code(500);
    echo 'Bootstrap failed: ' . $bootstrap_warning;
    exit;
}

if (!($pdo instanceof PDO)) {
    http_response_code(500);
    echo 'Database connection failed';
    exit;
}

/* Get filter parameters */
$programId = (int)($_GET['program_id'] ?? 0);
$branchId = (int)($_GET['branch_id'] ?? 0);
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$exportType = $_GET['export'] ?? 'csv';
$activeTab = $_GET['tab'] ?? 'overview';

/* Validate export type */
if ($exportType !== 'csv') {
    http_response_code(400);
    echo 'Only CSV export is supported';
    exit;
}

/* Generate filename */
$filename = "stamp-{$activeTab}-report-" . date('Y-m-d-His') . '.csv';

/* Set CSV headers */
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

/* Open output stream */
$output = fopen('php://output', 'w');

/* Add BOM for UTF-8 Excel compatibility */
fwrite($output, chr(239) . chr(187) . chr(191));

try {
    // Build base WHERE conditions - FIXED to use correct column names
    $whereConditions = ["ll.tenant_id = ?"];
    $whereParams = [$tenantId];
    
    // Use the correct column name from database schema
    $whereConditions[] = "ll.program_type = 'stamp'";
    
    if ($programId > 0) {
        $whereConditions[] = "ll.program_id = ?";
        $whereParams[] = $programId;
    }
    
    if ($branchId > 0) {
        $whereConditions[] = "ll.branch_id = ?";
        $whereParams[] = $branchId;
    }
    
    $whereConditions[] = "ll.created_at >= ?";
    $whereConditions[] = "ll.created_at <= ?";
    $whereParams[] = $dateFrom . ' 00:00:00';
    $whereParams[] = $dateTo . ' 23:59:59';
    
    $whereClause = implode(' AND ', $whereConditions);

    switch ($activeTab) {
        case 'customers':
            exportCustomerData($pdo, $output, $tenantId, $whereClause, $whereParams, $programId, $dateFrom, $dateTo);
            break;
            
        case 'programs':
            exportProgramData($pdo, $output, $tenantId, $dateFrom, $dateTo);
            break;
            
        case 'transactions':
            exportTransactionData($pdo, $output, $whereClause, $whereParams, $tenantId, $programId);
            break;
            
        case 'analytics':
            exportAnalyticsData($pdo, $output, $tenantId, $dateFrom, $dateTo);
            break;
            
        case 'overview':
        default:
            exportOverviewData($pdo, $output, $whereClause, $whereParams, $tenantId, $dateFrom, $dateTo);
            break;
    }
    
} catch (Throwable $e) {
    /* Handle errors by writing error message to CSV */
    fputcsv($output, ['ERROR', 'Export failed: ' . $e->getMessage()]);
    error_log('Stamp export error: ' . $e->getMessage());
} finally {
    fclose($output);
}

function exportCustomerData($pdo, $output, $tenantId, $whereClause, $whereParams, $programId, $dateFrom, $dateTo) {
    fputcsv($output, ['CUSTOMER REPORTS']);
    fputcsv($output, ['Report Period', $dateFrom . ' to ' . $dateTo]);
    fputcsv($output, []);
    
    // Customer balances
    fputcsv($output, ['CUSTOMER STAMP BALANCES']);
    fputcsv($output, [
        'Customer ID', 'Customer Name', 'Phone', 'Program ID', 'Program Name', 
        'Current Balance', 'Total Transactions', 'Last Activity'
    ]);
    
    $stmt = $pdo->prepare("
        SELECT 
            c.id, c.name, c.phone,
            ll.program_id,
            lp.name as program_name,
            SUM(CASE WHEN ll.direction = 'redeem' THEN -ll.amount ELSE ll.amount END) as balance,
            COUNT(*) as total_transactions,
            MAX(ll.created_at) as last_activity
        FROM loyalty_ledgers ll
        JOIN customers c ON c.id = ll.customer_id AND c.tenant_id = ll.tenant_id
        LEFT JOIN loyalty_programs lp ON lp.id = ll.program_id AND lp.tenant_id = ll.tenant_id
        WHERE {$whereClause}
        GROUP BY c.id, ll.program_id
        HAVING balance > 0
        ORDER BY balance DESC, last_activity DESC
    ");
    $stmt->execute($whereParams);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['name'] ?: 'Customer #' . $row['id'],
            $row['phone'] ?: '',
            $row['program_id'],
            $row['program_name'] ?: '',
            $row['balance'],
            $row['total_transactions'],
            $row['last_activity']
        ]);
    }
    
    fputcsv($output, []);
    
    // Top customers
    fputcsv($output, ['TOP CUSTOMERS BY STAMPS EARNED']);
    fputcsv($output, [
        'Customer ID', 'Customer Name', 'Phone', 'Stamps Earned', 
        'Stamps Redeemed', 'Active Days', 'Programs Participated'
    ]);
    
    $stmt = $pdo->prepare("
        SELECT 
            c.id, c.name, c.phone,
            SUM(CASE WHEN ll.direction = 'earn' THEN ll.amount ELSE 0 END) as stamps_earned,
            SUM(CASE WHEN ll.direction = 'redeem' THEN ll.amount ELSE 0 END) as stamps_redeemed,
            COUNT(DISTINCT DATE(ll.created_at)) as active_days,
            COUNT(DISTINCT ll.program_id) as programs_participated
        FROM loyalty_ledgers ll
        JOIN customers c ON c.id = ll.customer_id AND c.tenant_id = ll.tenant_id
        WHERE {$whereClause} AND ll.direction = 'earn'
        GROUP BY c.id
        ORDER BY stamps_earned DESC
        LIMIT 50
    ");
    $stmt->execute($whereParams);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['name'] ?: 'Customer #' . $row['id'],
            $row['phone'] ?: '',
            $row['stamps_earned'],
            $row['stamps_redeemed'],
            $row['active_days'],
            $row['programs_participated']
        ]);
    }
}

function exportProgramData($pdo, $output, $tenantId, $dateFrom, $dateTo) {
    fputcsv($output, ['PROGRAM PERFORMANCE REPORTS']);
    fputcsv($output, ['Report Period', $dateFrom . ' to ' . $dateTo]);
    fputcsv($output, []);
    
    fputcsv($output, ['PROGRAM SUMMARY']);
    fputcsv($output, [
        'Program ID', 'Program Name', 'Status', 'Stamps Required',
        'Active Customers', 'Stamps Issued', 'Stamps Redeemed', 
        'Redemptions Count', 'Completion Rate %'
    ]);
    
    // FIXED to use correct column name
    $stmt = $pdo->prepare("
        SELECT 
            lp.id, lp.name, lp.stamps_required, lp.status,
            COUNT(DISTINCT ll.customer_id) as active_customers,
            SUM(CASE WHEN ll.direction = 'earn' THEN ll.amount ELSE 0 END) as stamps_issued,
            SUM(CASE WHEN ll.direction = 'redeem' THEN ll.amount ELSE 0 END) as stamps_redeemed,
            COUNT(CASE WHEN ll.direction = 'redeem' THEN 1 END) as redemptions_count
        FROM loyalty_programs lp
        LEFT JOIN loyalty_ledgers ll ON ll.program_id = lp.id AND ll.tenant_id = lp.tenant_id
            AND ll.created_at >= ? AND ll.created_at <= ?
        WHERE lp.tenant_id = ? AND lp.program_type = 'stamp'
        GROUP BY lp.id
        ORDER BY active_customers DESC, stamps_issued DESC
    ");
    $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59', $tenantId]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $stamps_required = (int)$row['stamps_required'];
        $stamps_issued = (int)$row['stamps_issued'];
        $stamps_redeemed = (int)$row['stamps_redeemed'];
        
        $completion_rate = 0;
        if ($stamps_required > 0 && $stamps_issued > 0) {
            $possible_completions = floor($stamps_issued / $stamps_required);
            $actual_completions = floor($stamps_redeemed / $stamps_required);
            $completion_rate = $possible_completions > 0 ? round(($actual_completions / $possible_completions) * 100, 1) : 0;
        }
        
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['status'] ?: 'inactive',
            $stamps_required,
            $row['active_customers'],
            $stamps_issued,
            $stamps_redeemed,
            $row['redemptions_count'],
            $completion_rate
        ]);
    }
}

function exportTransactionData($pdo, $output, $whereClause, $whereParams, $tenantId, $programId) {
    fputcsv($output, ['TRANSACTION REPORTS']);
    fputcsv($output, []);
    
    // Recent transactions
    fputcsv($output, ['STAMP TRANSACTION LOG']);
    fputcsv($output, [
        'Transaction ID', 'Date', 'Time', 'Customer ID', 'Customer Name', 
        'Program Name', 'Direction', 'Amount', 'Order ID', 'Reason', 'User'
    ]);
    
    $stmt = $pdo->prepare("
        SELECT 
            ll.id, ll.created_at, ll.direction, ll.amount, ll.order_id, ll.reason,
            c.name as customer_name, c.id as customer_id,
            lp.name as program_name,
            u.name as user_name
        FROM loyalty_ledgers ll
        LEFT JOIN customers c ON c.id = ll.customer_id AND c.tenant_id = ll.tenant_id
        LEFT JOIN loyalty_programs lp ON lp.id = ll.program_id AND lp.tenant_id = ll.tenant_id
        LEFT JOIN users u ON u.id = ll.user_id AND u.tenant_id = ll.tenant_id
        WHERE {$whereClause}
        ORDER BY ll.created_at DESC
        LIMIT 1000
    ");
    $stmt->execute($whereParams);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            date('Y-m-d', strtotime($row['created_at'])),
            date('H:i:s', strtotime($row['created_at'])),
            $row['customer_id'],
            $row['customer_name'] ?: 'Customer #' . $row['customer_id'],
            $row['program_name'] ?: '',
            ucfirst($row['direction']),
            $row['amount'],
            $row['order_id'] ? '#' . $row['order_id'] : '',
            $row['reason'] ?: '',
            $row['user_name'] ?: ''
        ]);
    }
    
    fputcsv($output, []);
    
    // Daily activity trends
    fputcsv($output, ['DAILY ACTIVITY TRENDS (Last 30 Days)']);
    fputcsv($output, [
        'Date', 'Stamps Earned', 'Stamps Redeemed', 'Unique Customers', 'Total Transactions'
    ]);
    
    // FIXED to use correct column name
    $stmt = $pdo->prepare("
        SELECT 
            DATE(ll.created_at) as activity_date,
            SUM(CASE WHEN ll.direction = 'earn' THEN ll.amount ELSE 0 END) as stamps_earned,
            SUM(CASE WHEN ll.direction = 'redeem' THEN ll.amount ELSE 0 END) as stamps_redeemed,
            COUNT(DISTINCT ll.customer_id) as unique_customers,
            COUNT(*) as total_transactions
        FROM loyalty_ledgers ll
        WHERE ll.tenant_id = ? AND ll.program_type = 'stamp' 
            AND ll.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        " . ($programId > 0 ? " AND ll.program_id = ?" : "") . "
        GROUP BY DATE(ll.created_at)
        ORDER BY activity_date DESC
    ");
    $dailyParams = [$tenantId];
    if ($programId > 0) $dailyParams[] = $programId;
    $stmt->execute($dailyParams);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['activity_date'],
            $row['stamps_earned'],
            $row['stamps_redeemed'],
            $row['unique_customers'],
            $row['total_transactions']
        ]);
    }
}

function exportAnalyticsData($pdo, $output, $tenantId, $dateFrom, $dateTo) {
    fputcsv($output, ['ANALYTICS REPORTS']);
    fputcsv($output, ['Report Period', $dateFrom . ' to ' . $dateTo]);
    fputcsv($output, []);
    
    // Popular rewards
    fputcsv($output, ['POPULAR REWARD ITEMS']);
    fputcsv($output, [
        'Product ID', 'Product Name', 'Programs Using', 'Times Redeemed'
    ]);
    
    $stmt = $pdo->prepare("
        SELECT 
            p.id, p.name_en as product_name,
            COUNT(DISTINCT lp.id) as programs_using,
            SUM(CASE WHEN ll.direction = 'redeem' THEN ll.amount ELSE 0 END) as times_redeemed
        FROM products p
        JOIN loyalty_programs lp ON lp.reward_item_id = p.id AND lp.tenant_id = p.tenant_id
        LEFT JOIN loyalty_ledgers ll ON ll.program_id = lp.id AND ll.tenant_id = lp.tenant_id
            AND ll.direction = 'redeem' AND ll.created_at >= ? AND ll.created_at <= ?
        WHERE p.tenant_id = ?
        GROUP BY p.id
        HAVING times_redeemed > 0
        ORDER BY times_redeemed DESC
    ");
    $stmt->execute([$dateFrom . ' 00:00:00', $dateTo . ' 23:59:59', $tenantId]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['id'],
            $row['product_name'],
            $row['programs_using'],
            $row['times_redeemed']
        ]);
    }
    
    fputcsv($output, []);
    
    // Completion time analysis
    fputcsv($output, ['COMPLETION TIME ANALYSIS']);
    fputcsv($output, [
        'Program Name', 'Stamps Required', 'Avg Completion Days', 
        'Min Completion Days', 'Max Completion Days', 'Completed Cards'
    ]);
    
    // FIXED to use correct column name
    $stmt = $pdo->prepare("
        SELECT 
            lp.name as program_name,
            lp.stamps_required,
            AVG(completion_days) as avg_completion_days,
            MIN(completion_days) as min_completion_days,
            MAX(completion_days) as max_completion_days,
            COUNT(*) as completed_cards
        FROM (
            SELECT 
                ll.program_id,
                ll.customer_id,
                DATEDIFF(
                    MIN(CASE WHEN ll.direction = 'redeem' THEN ll.created_at END),
                    MIN(CASE WHEN ll.direction = 'earn' THEN ll.created_at END)
                ) as completion_days
            FROM loyalty_ledgers ll
            WHERE ll.tenant_id = ? AND ll.program_type = 'stamp'
                AND ll.created_at >= ? AND ll.created_at <= ?
            GROUP BY ll.program_id, ll.customer_id
            HAVING MIN(CASE WHEN ll.direction = 'earn' THEN ll.created_at END) IS NOT NULL
                AND MIN(CASE WHEN ll.direction = 'redeem' THEN ll.created_at END) IS NOT NULL
                AND completion_days IS NOT NULL AND completion_days >= 0
        ) completion_stats
        JOIN loyalty_programs lp ON lp.id = completion_stats.program_id AND lp.tenant_id = ?
        GROUP BY completion_stats.program_id
        ORDER BY avg_completion_days ASC
    ");
    $stmt->execute([$tenantId, $dateFrom . ' 00:00:00', $dateTo . ' 23:59:59', $tenantId]);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['program_name'],
            $row['stamps_required'],
            round((float)$row['avg_completion_days'], 1),
            $row['min_completion_days'],
            $row['max_completion_days'],
            $row['completed_cards']
        ]);
    }
}

function exportOverviewData($pdo, $output, $whereClause, $whereParams, $tenantId, $dateFrom, $dateTo) {
    fputcsv($output, ['STAMP REWARDS OVERVIEW REPORT']);
    fputcsv($output, ['Report Period', $dateFrom . ' to ' . $dateTo]);
    fputcsv($output, ['Generated', date('Y-m-d H:i:s')]);
    fputcsv($output, []);
    
    // Overall metrics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT ll.customer_id) as total_customers,
            COUNT(DISTINCT ll.program_id) as active_programs,
            COUNT(*) as total_transactions,
            SUM(CASE WHEN ll.direction = 'earn' THEN ll.amount ELSE 0 END) as stamps_issued,
            SUM(CASE WHEN ll.direction = 'redeem' THEN ll.amount ELSE 0 END) as stamps_redeemed
        FROM loyalty_ledgers ll 
        WHERE {$whereClause}
    ");
    $stmt->execute($whereParams);
    $overview = $stmt->fetch(PDO::FETCH_ASSOC);
    
    fputcsv($output, ['SUMMARY METRICS']);
    fputcsv($output, ['Metric', 'Value']);
    fputcsv($output, ['Active Customers', $overview['total_customers'] ?? 0]);
    fputcsv($output, ['Active Programs', $overview['active_programs'] ?? 0]);
    fputcsv($output, ['Total Transactions', $overview['total_transactions'] ?? 0]);
    fputcsv($output, ['Stamps Issued', $overview['stamps_issued'] ?? 0]);
    fputcsv($output, ['Stamps Redeemed', $overview['stamps_redeemed'] ?? 0]);
    fputcsv($output, ['Net Outstanding Stamps', ($overview['stamps_issued'] ?? 0) - ($overview['stamps_redeemed'] ?? 0)]);
    
    $issued = (int)($overview['stamps_issued'] ?? 0);
    $redeemed = (int)($overview['stamps_redeemed'] ?? 0);
    $redemption_rate = $issued > 0 ? round(($redeemed / $issued) * 100, 2) : 0;
    fputcsv($output, ['Redemption Rate %', $redemption_rate]);
}
?>