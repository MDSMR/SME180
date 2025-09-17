<?php
/**
 * SME 180 POS - Resume Order API
 * Path: /public_html/pos/api/order/resume.php
 * 
 * Resumes a parked order or returns list of parked orders
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
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
    
    // Get database connection
    $pdo = db();
    
    // Check if it's a GET request (list parked orders)
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
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
                dt.table_number,
                COUNT(oi.id) as items_count,
                u.name as parked_by_name
            FROM orders o
            LEFT JOIN dining_tables dt ON dt.id = o.table_id
            LEFT JOIN users u ON u.id = o.cashier_id
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
        
        json_response([
            'success' => true,
            'parked_orders' => $parkedOrders,
            'count' => count($parkedOrders)
        ]);
    }
    
    // POST request - resume a specific order
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_response(['success' => false, 'error' => 'Invalid request body'], 400);
    }
    
    if (!isset($input['order_id'])) {
        json_response(['success' => false, 'error' => 'Order ID is required'], 400);
    }
    
    $orderId = (int)$input['order_id'];
    $tableId = isset($input['table_id']) ? (int)$input['table_id'] : null;
    
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
        json_response(['success' => false, 'error' => 'Parked order not found'], 404);
    }
    
    // If table is provided for dine-in order, check availability
    if ($order['order_type'] === 'dine_in' && $tableId) {
        $stmt = $pdo->prepare("
            SELECT id, status FROM dining_tables 
            WHERE id = :table_id 
            AND tenant_id = :tenant_id
            FOR UPDATE
        ");
        $stmt->execute([
            'table_id' => $tableId,
            'tenant_id' => $tenantId
        ]);
        $table = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$table) {
            json_response(['success' => false, 'error' => 'Table not found'], 404);
        }
        
        // Check if table is available
        if ($table['status'] !== 'available') {
            $stmt = $pdo->prepare("
                SELECT receipt_reference FROM orders 
                WHERE table_id = :table_id 
                AND status NOT IN ('closed', 'voided', 'refunded')
                AND payment_status != 'paid'
                AND parked = 0
                LIMIT 1
            ");
            $stmt->execute(['table_id' => $tableId]);
            $activeOrder = $stmt->fetchColumn();
            
            if ($activeOrder) {
                json_response([
                    'success' => false, 
                    'error' => 'Table is occupied',
                    'active_order' => $activeOrder
                ], 400);
            }
        }
    } elseif ($order['order_type'] === 'dine_in' && !$tableId) {
        // Use original table if available
        $tableId = $order['table_id'];
        if ($tableId) {
            $stmt = $pdo->prepare("
                SELECT id FROM orders 
                WHERE table_id = :table_id 
                AND status NOT IN ('closed', 'voided', 'refunded')
                AND payment_status != 'paid'
                AND parked = 0
                AND id != :order_id
                LIMIT 1
            ");
            $stmt->execute([
                'table_id' => $tableId,
                'order_id' => $orderId
            ]);
            
            if ($stmt->fetchColumn()) {
                // Original table is occupied, require new table
                json_response([
                    'success' => false, 
                    'error' => 'Original table is occupied. Please select a new table.',
                    'requires_table' => true
                ], 400);
            }
        }
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
    
    json_response([
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
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Resume order DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Resume order error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
