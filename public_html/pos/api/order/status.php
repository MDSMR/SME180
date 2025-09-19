<?php
/**
 * SME 180 POS - Get Order Status API
 * Path: /public_html/pos/api/order/status.php
 * 
 * Returns the current status and details of an order
 */

// MUST set JSON header first - before ANY output
header('Content-Type: application/json; charset=utf-8');

// Disable ALL error output to prevent HTML
@error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit('{"success":true}');
}

// Direct database connection - no includes to avoid any output
try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=dbvtrnbzad193e;charset=utf8mb4',
        'uta6umaa0iuif',
        '2m%[11|kb1Z4',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    exit('{"success":false,"error":"Database connection failed"}');
}

// Get order ID from request
$orderId = 0;

// Check GET parameter
if (isset($_GET['order_id'])) {
    $orderId = (int)$_GET['order_id'];
}

// Check POST body if no GET param
if (!$orderId && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = @file_get_contents('php://input');
    if ($raw) {
        $input = @json_decode($raw, true);
        if (isset($input['order_id'])) {
            $orderId = (int)$input['order_id'];
        }
    }
}

// For testing - get latest order if no ID provided
if (!$orderId) {
    try {
        $stmt = $pdo->query("SELECT id FROM orders WHERE tenant_id = 1 ORDER BY id DESC LIMIT 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $orderId = (int)$result['id'];
        }
    } catch (Exception $e) {
        exit('{"success":false,"error":"No order ID provided and no orders found"}');
    }
}

if (!$orderId) {
    exit('{"success":false,"error":"Order ID is required"}');
}

try {
    // Get order details
    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            o.receipt_reference,
            o.order_type,
            o.table_id,
            o.customer_name,
            o.subtotal,
            o.tax_amount,
            o.discount_amount,
            o.tip_amount,
            o.service_charge,
            o.total_amount,
            o.status,
            o.payment_status,
            COALESCE(o.kitchen_status, 'pending') as kitchen_status,
            o.parked,
            o.park_label,
            o.created_at,
            dt.table_number
        FROM orders o
        LEFT JOIN dining_tables dt ON dt.id = o.table_id
        WHERE o.id = ?
    ");
    
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        exit('{"success":false,"error":"Order not found"}');
    }
    
    // Get order items
    $stmt = $pdo->prepare("
        SELECT 
            id,
            product_id,
            product_name,
            quantity,
            unit_price,
            line_total,
            is_voided,
            COALESCE(kitchen_status, state, 'pending') as status
        FROM order_items 
        WHERE order_id = ?
        ORDER BY id
    ");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build response
    $response = [
        'success' => true,
        'order' => [
            'id' => (int)$order['id'],
            'receipt_reference' => $order['receipt_reference'],
            'order_type' => $order['order_type'],
            'table_number' => $order['table_number'],
            'customer_name' => $order['customer_name'],
            'amounts' => [
                'subtotal' => (float)$order['subtotal'],
                'tax' => (float)$order['tax_amount'],
                'discount' => (float)$order['discount_amount'],
                'tip' => (float)$order['tip_amount'],
                'service_charge' => (float)$order['service_charge'],
                'total' => (float)$order['total_amount']
            ],
            'status' => $order['status'],
            'payment_status' => $order['payment_status'],
            'kitchen_status' => $order['kitchen_status'],
            'is_parked' => (bool)$order['parked'],
            'park_label' => $order['park_label'],
            'created_at' => $order['created_at']
        ],
        'items' => array_map(function($item) {
            return [
                'id' => (int)$item['id'],
                'product_id' => (int)$item['product_id'],
                'name' => $item['product_name'],
                'quantity' => (int)$item['quantity'],
                'unit_price' => (float)$item['unit_price'],
                'line_total' => (float)$item['line_total'],
                'is_voided' => (bool)$item['is_voided'],
                'status' => $item['status']
            ];
        }, $items),
        'summary' => [
            'total_items' => count($items),
            'voided_items' => count(array_filter($items, function($i) { return $i['is_voided']; }))
        ]
    ];
    
    echo json_encode($response);
    
} catch (Exception $e) {
    $error = [
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ];
    echo json_encode($error);
}
?>