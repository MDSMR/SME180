<?php
/**
 * SME 180 POS - Park Order API
 * Path: /public_html/pos/api/order/park.php
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');

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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    die('{"success":false,"error":"Invalid request body"}');
}

if (!isset($input['order_id'])) {
    die('{"success":false,"error":"Order ID is required"}');
}

$orderId = (int)$input['order_id'];
$parkReason = $input['reason'] ?? '';
$parkLabel = $input['label'] ?? null;

try {
    $pdo->beginTransaction();
    
    // Get order with lock
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE id = :order_id 
        AND tenant_id = :tenant_id
        AND branch_id = :branch_id
        FOR UPDATE
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        die('{"success":false,"error":"Order not found"}');
    }
    
    // Check if order can be parked
    if ($order['payment_status'] === 'paid') {
        die('{"success":false,"error":"Cannot park paid orders"}');
    }
    
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        die('{"success":false,"error":"Cannot park ' . $order['status'] . ' orders"}');
    }
    
    if ($order['parked'] == 1) {
        die('{"success":false,"error":"Order is already parked"}');
    }
    
    // Generate park label if not provided
    if (!$parkLabel) {
        $parkLabel = sprintf('Parked Order #%s - %s', 
            $order['receipt_reference'],
            date('H:i')
        );
    }
    
    // Park the order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET parked = 1,
            parked_at = NOW(),
            park_label = :park_label,
            status = 'held',
            updated_at = NOW()
        WHERE id = :order_id
    ");
    
    $stmt->execute([
        'park_label' => $parkLabel,
        'order_id' => $orderId
    ]);
    
    // If it's a dine-in order, free up the table
    if ($order['order_type'] === 'dine_in' && $order['table_id']) {
        try {
            $stmt = $pdo->prepare("
                UPDATE dining_tables 
                SET status = 'available',
                    current_order_id = NULL,
                    updated_at = NOW()
                WHERE id = :table_id
                AND tenant_id = :tenant_id
            ");
            $stmt->execute([
                'table_id' => $order['table_id'],
                'tenant_id' => $tenantId
            ]);
        } catch (PDOException $e) {
            // Table might not exist
        }
    }
    
    // Log the parking action
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'parked', :details, NOW()
        )
    ");
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'park_label' => $parkLabel,
            'reason' => $parkReason,
            'table_freed' => $order['table_id'] ? true : false
        ])
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Order parked successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'park_label' => $parkLabel,
            'parked_at' => date('Y-m-d H:i:s'),
            'status' => 'held',
            'table_freed' => $order['table_id'] ? true : false
        ]
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Park order error: ' . $e->getMessage());
    die('{"success":false,"error":"Database error"}');
}
?>