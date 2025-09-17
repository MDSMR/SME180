<?php
/**
 * SME 180 POS - Get Order Status API
 * Path: /public_html/pos/api/order/status.php
 * 
 * Returns detailed status and information about an order
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
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
    
    if (!$tenantId || !$branchId) {
        json_response(['success' => false, 'error' => 'Invalid session'], 401);
    }
    
    // Get order ID from query parameters
    $orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
    
    if (!$orderId) {
        json_response(['success' => false, 'error' => 'Order ID is required'], 400);
    }
    
    // Get database connection
    $pdo = db();
    
    // Get order details
    $stmt = $pdo->prepare("
        SELECT 
            o.*,
            dt.table_number,
            u.name as cashier_name,
            s.station_name,
            c.name as customer_full_name,
            c.email as customer_email,
            c.loyalty_points
        FROM orders o
        LEFT JOIN dining_tables dt ON dt.id = o.table_id
        LEFT JOIN users u ON u.id = o.cashier_id
        LEFT JOIN pos_stations s ON s.id = o.station_id
        LEFT JOIN customers c ON c.id = o.customer_id
        WHERE o.id = :order_id
        AND o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
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
    
    // Get order items
    $stmt = $pdo->prepare("
        SELECT 
            oi.*,
            p.sku,
            p.category_id,
            pc.name as category_name
        FROM order_items oi
        LEFT JOIN products p ON p.id = oi.product_id
        LEFT JOIN product_categories pc ON pc.id = p.category_id
        WHERE oi.order_id = :order_id
        ORDER BY oi.created_at ASC
    ");
    
    $stmt->execute(['order_id' => $orderId]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get modifiers for each item
    foreach ($items as &$item) {
        $stmt = $pdo->prepare("
            SELECT * FROM order_item_modifiers
            WHERE order_item_id = :item_id
        ");
        $stmt->execute(['item_id' => $item['id']]);
        $item['modifiers'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get payment details
    $stmt = $pdo->prepare("
        SELECT 
            op.*,
            u.name as processed_by_name
        FROM order_payments op
        LEFT JOIN users u ON u.id = op.processed_by
        WHERE op.order_id = :order_id
        ORDER BY op.created_at ASC
    ");
    
    $stmt->execute(['order_id' => $orderId]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get order logs
    $stmt = $pdo->prepare("
        SELECT 
            ol.*,
            u.name as user_name
        FROM order_logs ol
        LEFT JOIN users u ON u.id = ol.user_id
        WHERE ol.order_id = :order_id
        ORDER BY ol.created_at DESC
        LIMIT 20
    ");
    
    $stmt->execute(['order_id' => $orderId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get refund details if any
    $refunds = [];
    if ($order['status'] === 'refunded' || $order['refunded_amount'] > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                r.*,
                u.name as refunded_by_name
            FROM order_refunds r
            LEFT JOIN users u ON u.id = r.refunded_by
            WHERE r.order_id = :order_id
        ");
        $stmt->execute(['order_id' => $orderId]);
        $refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Get kitchen status details
    $kitchenStatus = null;
    if ($order['kitchen_status'] !== 'pending') {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_items,
                SUM(CASE WHEN kitchen_status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN kitchen_status = 'preparing' THEN 1 ELSE 0 END) as preparing,
                SUM(CASE WHEN kitchen_status = 'ready' THEN 1 ELSE 0 END) as ready,
                SUM(CASE WHEN kitchen_status = 'served' THEN 1 ELSE 0 END) as served,
                MIN(fired_at) as first_fired,
                MAX(ready_at) as last_ready
            FROM order_items
            WHERE order_id = :order_id
            AND is_voided = 0
        ");
        $stmt->execute(['order_id' => $orderId]);
        $kitchenStatus = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Calculate preparation time
        if ($kitchenStatus['first_fired'] && $kitchenStatus['last_ready']) {
            $prepTime = strtotime($kitchenStatus['last_ready']) - strtotime($kitchenStatus['first_fired']);
            $kitchenStatus['prep_time_minutes'] = round($prepTime / 60);
        }
    }
    
    // Get currency symbol
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'currency_symbol' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $currencySymbol = $stmt->fetchColumn() ?: '$';
    
    // Calculate summary
    $activeItems = array_filter($items, function($item) {
        return !$item['is_voided'];
    });
    
    $voidedItems = array_filter($items, function($item) {
        return $item['is_voided'];
    });
    
    json_response([
        'success' => true,
        'order' => [
            'id' => (int)$order['id'],
            'receipt_reference' => $order['receipt_reference'],
            'order_type' => $order['order_type'],
            'status' => $order['status'],
            'payment_status' => $order['payment_status'],
            'kitchen_status' => $order['kitchen_status'],
            'parked' => (bool)$order['parked'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at']
        ],
        'location' => [
            'table_id' => $order['table_id'],
            'table_number' => $order['table_number'],
            'station_id' => $order['station_id'],
            'station_name' => $order['station_name']
        ],
        'customer' => [
            'id' => $order['customer_id'],
            'name' => $order['customer_name'] ?: $order['customer_full_name'],
            'phone' => $order['customer_phone'],
            'email' => $order['customer_email'],
            'loyalty_points' => $order['loyalty_points']
        ],
        'cashier' => [
            'id' => $order['cashier_id'],
            'name' => $order['cashier_name']
        ],
        'amounts' => [
            'currency' => $currencySymbol,
            'subtotal' => (float)$order['subtotal'],
            'discount_amount' => (float)$order['discount_amount'],
            'tax_amount' => (float)$order['tax_amount'],
            'tip_amount' => (float)$order['tip_amount'],
            'service_charge' => (float)$order['service_charge'],
            'total_amount' => (float)$order['total_amount'],
            'paid_amount' => (float)$order['paid_amount'],
            'refunded_amount' => (float)$order['refunded_amount'],
            'balance_due' => (float)$order['total_amount'] - (float)$order['paid_amount']
        ],
        'items' => [
            'active' => array_values($activeItems),
            'voided' => array_values($voidedItems),
            'total_count' => count($items),
            'active_count' => count($activeItems),
            'voided_count' => count($voidedItems)
        ],
        'payments' => $payments,
        'refunds' => $refunds,
        'kitchen' => $kitchenStatus,
        'logs' => $logs,
        'timestamps' => [
            'created' => $order['created_at'],
            'updated' => $order['updated_at'],
            'fired' => $order['fired_at'],
            'paid' => $order['paid_at'],
            'closed' => $order['closed_at']
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('Get order status DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    error_log('Get order status error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
