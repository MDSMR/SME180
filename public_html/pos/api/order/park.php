<?php
/**
 * SME 180 POS - Park Order API
 * Path: /public_html/pos/api/order/park.php
 * 
 * Parks an order for later completion
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function for JSON responses
function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Authentication check
    pos_auth_require_login();
    $user = pos_get_current_user();
    
    if (!$user) {
        json_response(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    // Get tenant and branch from session
    $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
    $branchId = (int)($_SESSION['branch_id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    
    if (!$tenantId || !$branchId || !$userId) {
        json_response(['success' => false, 'error' => 'Invalid session'], 401);
    }
    
    // Parse request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_response(['success' => false, 'error' => 'Invalid request body'], 400);
    }
    
    if (!isset($input['order_id'])) {
        json_response(['success' => false, 'error' => 'Order ID is required'], 400);
    }
    
    $orderId = (int)$input['order_id'];
    $parkReason = $input['reason'] ?? '';
    $parkLabel = $input['label'] ?? null;
    
    // Get database connection
    $pdo = db();
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
        json_response(['success' => false, 'error' => 'Order not found'], 404);
    }
    
    // Check if order can be parked
    if ($order['payment_status'] === 'paid') {
        json_response(['success' => false, 'error' => 'Cannot park paid orders'], 400);
    }
    
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        json_response(['success' => false, 'error' => 'Cannot park ' . $order['status'] . ' orders'], 400);
    }
    
    if ($order['parked'] == 1) {
        json_response(['success' => false, 'error' => 'Order is already parked'], 400);
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
    
    // Create a parking record for history
    $stmt = $pdo->prepare("
        INSERT INTO order_park_history (
            order_id, tenant_id, branch_id, 
            parked_by, park_reason, park_label,
            parked_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id,
            :parked_by, :park_reason, :park_label,
            NOW()
        )
    ");
    
    try {
        $stmt->execute([
            'order_id' => $orderId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'parked_by' => $userId,
            'park_reason' => $parkReason,
            'park_label' => $parkLabel
        ]);
    } catch (PDOException $e) {
        // Table might not exist yet, ignore
    }
    
    $pdo->commit();
    
    json_response([
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
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Park order DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Park order error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
