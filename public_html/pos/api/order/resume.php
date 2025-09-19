<?php
/**
 * SME 180 POS - Resume Order API
 * Path: /public_html/pos/api/order/resume.php
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    die('{"success":true}');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

try {
    require_once __DIR__ . '/../../../config/db.php';
    $pdo = db();
} catch (Exception $e) {
    die('{"success":false,"error":"Database connection failed"}');
}

$tenantId = (int)($_SESSION['tenant_id'] ?? 1);
$branchId = (int)($_SESSION['branch_id'] ?? 1);
$userId = (int)($_SESSION['user_id'] ?? 1);

// Check if it's a GET request (list parked orders)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                o.id,
                o.receipt_reference,
                o.park_label,
                o.parked_at,
                o.total_amount,
                o.order_type,
                o.customer_name,
                o.table_id,
                COUNT(oi.id) as items_count
            FROM orders o
            LEFT JOIN order_items oi ON oi.order_id = o.id AND oi.is_voided = 0
            WHERE o.tenant_id = :tenant_id
            AND o.branch_id = :branch_id
            AND o.parked = 1
            AND o.status = 'held'
            GROUP BY o.id
            ORDER BY o.parked_at DESC
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId
        ]);
        
        $parkedOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'parked_orders' => $parkedOrders,
            'count' => count($parkedOrders)
        ]);
    } catch (Exception $e) {
        error_log('List parked orders error: ' . $e->getMessage());
        die('{"success":false,"error":"Failed to retrieve parked orders"}');
    }
    exit;
}

// POST request - resume a specific order
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    die('{"success":false,"error":"Invalid request body"}');
}

if (!isset($input['order_id'])) {
    die('{"success":false,"error":"Order ID is required"}');
}

$orderId = (int)$input['order_id'];
$tableId = isset($input['table_id']) ? (int)$input['table_id'] : null;

try {
    $pdo->beginTransaction();
    
    // Get the parked order with lock
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE id = :order_id 
        AND tenant_id = :tenant_id
        AND branch_id = :branch_id
        AND parked = 1
        FOR UPDATE
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        die('{"success":false,"error":"Parked order not found"}');
    }
    
    // Resume the order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET parked = 0,
            parked_at = NULL,
            park_label = NULL,
            status = 'open',
            table_id = :table_id,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    
    $stmt->execute([
        'table_id' => $tableId ?: $order['table_id'],
        'order_id' => $orderId
    ]);
    
    // Update table status if dine-in
    if ($order['order_type'] === 'dine_in' && $tableId) {
        try {
            $stmt = $pdo->prepare("
                UPDATE dining_tables 
                SET status = 'occupied',
                    current_order_id = :order_id,
                    updated_at = NOW()
                WHERE id = :table_id
            ");
            $stmt->execute([
                'order_id' => $orderId,
                'table_id' => $tableId
            ]);
        } catch (PDOException $e) {
            // Table might not exist
        }
    }
    
    // Log the resume action
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'resumed', :details, NOW()
        )
    ");
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'park_label' => $order['park_label'],
            'parked_duration_minutes' => $order['parked_at'] ? 
                round((time() - strtotime($order['parked_at'])) / 60) : 0,
            'table_id' => $tableId
        ])
    ]);
    
    // Get order items for response
    $stmt = $pdo->prepare("
        SELECT * FROM order_items 
        WHERE order_id = :order_id 
        AND is_voided = 0
        ORDER BY created_at ASC
    ");
    $stmt->execute(['order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Order resumed successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'order_type' => $order['order_type'],
            'table_id' => $tableId ?: $order['table_id'],
            'customer_name' => $order['customer_name'],
            'subtotal' => (float)$order['subtotal'],
            'tax_amount' => (float)$order['tax_amount'],
            'service_charge' => (float)$order['service_charge'],
            'total_amount' => (float)$order['total_amount'],
            'status' => 'open',
            'items_count' => count($items),
            'items' => $items
        ]
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Resume order error: ' . $e->getMessage());
    die('{"success":false,"error":"Failed to resume order"}');
}
?>