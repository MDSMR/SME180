<?php
// /mohamedk10.sg-host.com/public_html/controllers/admin/orders/order_export.php
// Export orders to CSV with filters
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/middleware/auth_login.php';
require_once __DIR__ . '/_helpers.php';

auth_require_login();
use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /views/auth/login.php');
    exit;
}

$tenantId = (int)$user['tenant_id'];
$userId = (int)$user['id'];

// Get export format (default CSV, future: PDF, Excel)
$format = clean_string($_GET['format'] ?? 'csv');
if (!in_array($format, ['csv', 'json'], true)) {
    $format = 'csv';
}

// Get filters (same as index page)
$branchId = (int)($_GET['branch_id'] ?? $_GET['branch'] ?? 0);
$dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['to'] ?? date('Y-m-d');
$status = clean_string($_GET['status'] ?? '');
$orderType = clean_string($_GET['order_type'] ?? $_GET['type'] ?? '');
$aggregatorId = (int)($_GET['aggregator_id'] ?? $_GET['aggregator'] ?? 0);
$paymentStatus = clean_string($_GET['payment_status'] ?? $_GET['payment'] ?? '');
$q = clean_string($_GET['q'] ?? '');
$includeDeleted = (bool)($_GET['include_deleted'] ?? false);
$includeItems = (bool)($_GET['include_items'] ?? false);

// Validate date range
$dateFrom = date('Y-m-d', strtotime($dateFrom));
$dateTo = date('Y-m-d', strtotime($dateTo));
if ($dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

// Validate enums
$allowedStatuses = ['', 'open', 'held', 'sent', 'preparing', 'ready', 'served', 'closed', 'voided', 'cancelled', 'refunded'];
$allowedTypes = ['', 'dine_in', 'takeaway', 'delivery', 'pickup', 'online', 'aggregator', 'talabat'];
$allowedPayments = ['', 'unpaid', 'partial', 'paid', 'refunded', 'voided'];

if (!in_array($status, $allowedStatuses, true)) $status = '';
if (!in_array($orderType, $allowedTypes, true)) $orderType = '';
if (!in_array($paymentStatus, $allowedPayments, true)) $paymentStatus = '';

try {
    $pdo = db();
    
    // Build WHERE clause
    $where = ["o.tenant_id = :t", "DATE(o.created_at) BETWEEN :df AND :dt"];
    $params = [':t' => $tenantId, ':df' => $dateFrom, ':dt' => $dateTo];
    
    if (!$includeDeleted) {
        $where[] = "o.is_deleted = 0";
    }
    
    if ($branchId > 0) {
        $where[] = "o.branch_id = :b";
        $params[':b'] = $branchId;
    }
    
    if ($status !== '') {
        $where[] = "o.status = :s";
        $params[':s'] = $status;
    }
    
    if ($orderType !== '') {
        $where[] = "o.order_type = :ot";
        $params[':ot'] = $orderType;
    }
    
    if ($paymentStatus !== '') {
        $where[] = "o.payment_status = :ps";
        $params[':ps'] = $paymentStatus;
    }
    
    if ($aggregatorId > 0) {
        $where[] = "o.aggregator_id = :a";
        $params[':a'] = $aggregatorId;
    }
    
    if ($q !== '') {
        $where[] = "(o.customer_name LIKE :q OR o.id = :qid OR o.receipt_reference LIKE :q2 OR EXISTS(
            SELECT 1 FROM dining_tables dt WHERE dt.id = o.table_id AND dt.table_number LIKE :q3
        ))";
        $params[':q'] = "%$q%";
        $params[':q2'] = "%$q%";
        $params[':q3'] = "%$q%";
        $params[':qid'] = ctype_digit($q) ? (int)$q : -1;
    }
    
    $whereSql = implode(' AND ', $where);
    
    // Main query
    $sql = "
        SELECT
            o.id, o.created_at, o.updated_at, o.closed_at, o.voided_at,
            o.branch_id, b.name AS branch_name,
            o.order_type, o.table_id, dt.table_number,
            o.customer_id, o.customer_name, c.phone AS customer_phone,
            o.guest_count, o.status, o.payment_status, o.payment_method, 
            o.session_id, o.source_channel,
            o.aggregator_id, a.name AS aggregator_name,
            o.receipt_reference, o.external_order_reference, o.order_notes,
            o.subtotal_amount, o.discount_amount, o.tax_percent, o.tax_amount,
            o.service_percent, o.service_amount, o.commission_percent, 
            o.commission_amount, o.commission_total_amount, o.total_amount,
            o.is_voided, o.is_deleted, o.deleted_at,
            u.name AS created_by_name,
            vu.name AS voided_by_name,
            du.name AS deleted_by_name
        FROM orders o
        LEFT JOIN branches b ON b.id = o.branch_id
        LEFT JOIN aggregators a ON a.id = o.aggregator_id
        LEFT JOIN dining_tables dt ON dt.id = o.table_id
        LEFT JOIN customers c ON c.id = o.customer_id
        LEFT JOIN users u ON u.id = o.created_by_user_id
        LEFT JOIN users vu ON vu.id = o.voided_by_user_id
        LEFT JOIN users du ON du.id = o.deleted_by
        WHERE $whereSql
        ORDER BY o.created_at DESC, o.id DESC
        LIMIT 10000
    ";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    
    // Get items if requested
    $itemsByOrder = [];
    if ($includeItems && count($rows) > 0) {
        $orderIds = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        
        $stmt = $pdo->prepare("
            SELECT 
                oi.*,
                p.name_en AS product_name_en,
                p.name_ar AS product_name_ar
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            WHERE oi.order_id IN ($placeholders)
            ORDER BY oi.order_id, oi.id
        ");
        $stmt->execute($orderIds);
        
        while ($item = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $itemsByOrder[$item['order_id']][] = $item;
        }
    }
    
    // Log export event
    log_order_event($pdo, $tenantId, 0, 'export', $userId, [
        'format' => $format,
        'filters' => [
            'date_range' => $dateFrom . ' to ' . $dateTo,
            'branch_id' => $branchId,
            'status' => $status,
            'order_type' => $orderType,
            'rows_exported' => count($rows)
        ]
    ]);
    
    // Output based on format
    if ($format === 'json') {
        // JSON export
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="orders_' . date('Ymd_His') . '.json"');
        
        $output = [
            'export_date' => date('Y-m-d H:i:s'),
            'filters' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'branch_id' => $branchId,
                'status' => $status,
                'order_type' => $orderType,
                'payment_status' => $paymentStatus
            ],
            'orders' => []
        ];
        
        foreach ($rows as $row) {
            $order = $row;
            if ($includeItems && isset($itemsByOrder[$row['id']])) {
                $order['items'] = $itemsByOrder[$row['id']];
            }
            $output['orders'][] = $order;
        }
        
        echo json_encode($output, JSON_PRETTY_PRINT);
        
    } else {
        // CSV export (default)
        $filename = 'orders_export_' . date('Ymd_His') . '.csv';
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // UTF-8 BOM for Excel compatibility
        echo "\xEF\xBB\xBF";
        
        $fp = fopen('php://output', 'w');
        
        // Headers
        $headers = [
            'Order ID', 'Created At', 'Updated At', 'Closed At', 'Voided At',
            'Branch ID', 'Branch Name',
            'Order Type', 'Table ID', 'Table Number',
            'Customer ID', 'Customer Name', 'Customer Phone',
            'Guest Count', 'Status', 'Payment Status', 'Payment Method',
            'POS Session', 'Source Channel',
            'Aggregator ID', 'Aggregator Name',
            'Receipt Reference', 'External Reference', 'Order Notes',
            'Subtotal', 'Discount', 'Tax %', 'Tax Amount',
            'Service %', 'Service Amount', 'Commission %', 'Commission Amount',
            'Commission Total', 'Total',
            'Is Voided', 'Is Deleted', 'Deleted At',
            'Created By', 'Voided By', 'Deleted By'
        ];
        
        if ($includeItems) {
            $headers[] = 'Item Count';
            $headers[] = 'Items Detail';
        }
        
        fputcsv($fp, $headers);
        
        // Data rows
        foreach ($rows as $r) {
            $row = [
                (int)$r['id'],
                (string)$r['created_at'],
                (string)$r['updated_at'],
                (string)($r['closed_at'] ?? ''),
                (string)($r['voided_at'] ?? ''),
                (int)$r['branch_id'],
                (string)($r['branch_name'] ?? ''),
                (string)$r['order_type'],
                (int)($r['table_id'] ?? 0),
                (string)($r['table_number'] ?? ''),
                (int)($r['customer_id'] ?? 0),
                (string)($r['customer_name'] ?? ''),
                (string)($r['customer_phone'] ?? ''),
                (int)($r['guest_count'] ?? 0),
                (string)$r['status'],
                (string)($r['payment_status'] ?? ''),
                (string)($r['payment_method'] ?? ''),
                (string)($r['session_id'] ?? ''),
                (string)($r['source_channel'] ?? 'pos'),
                (int)($r['aggregator_id'] ?? 0),
                (string)($r['aggregator_name'] ?? ''),
                (string)($r['receipt_reference'] ?? ''),
                (string)($r['external_order_reference'] ?? ''),
                (string)($r['order_notes'] ?? ''),
                format_money((float)$r['subtotal_amount']),
                format_money((float)$r['discount_amount']),
                format_money((float)$r['tax_percent'], 2),
                format_money((float)$r['tax_amount']),
                format_money((float)$r['service_percent'], 2),
                format_money((float)$r['service_amount']),
                format_money((float)$r['commission_percent'], 2),
                format_money((float)$r['commission_amount']),
                format_money((float)$r['commission_total_amount']),
                format_money((float)$r['total_amount']),
                (int)($r['is_voided'] ?? 0),
                (int)($r['is_deleted'] ?? 0),
                (string)($r['deleted_at'] ?? ''),
                (string)($r['created_by_name'] ?? ''),
                (string)($r['voided_by_name'] ?? ''),
                (string)($r['deleted_by_name'] ?? '')
            ];
            
            if ($includeItems) {
                $items = $itemsByOrder[$r['id']] ?? [];
                $row[] = count($items);
                
                // Format items as string
                $itemsStr = [];
                foreach ($items as $item) {
                    $itemsStr[] = sprintf(
                        '%s x%d @ %s = %s',
                        $item['product_name'],
                        $item['quantity'],
                        format_money($item['unit_price']),
                        format_money($item['line_subtotal'])
                    );
                }
                $row[] = implode('; ', $itemsStr);
            }
            
            fputcsv($fp, $row);
        }
        
        fclose($fp);
    }
    
    exit;
    
} catch (Throwable $e) {
    $_SESSION['flash'] = 'Export failed: ' . $e->getMessage();
    header('Location: /views/admin/orders/index.php?' . http_build_query($_GET));
    exit;
}