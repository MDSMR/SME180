<?php
/**
 * SME 180 POS - KDS Feed API
 * Path: /public_html/pos/api/kds/feed.php
 * 
 * Returns active kitchen orders for display screens
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
    
    // Get filter parameters
    $screenId = isset($_GET['screen_id']) ? (int)$_GET['screen_id'] : null;
    $statusFilter = $_GET['status'] ?? 'active'; // active, preparing, ready
    $stationFilter = isset($_GET['station_id']) ? (int)$_GET['station_id'] : null;
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    
    // Get database connection
    $pdo = db();
    
    // Build query based on filters
    $conditions = [
        'o.tenant_id = :tenant_id',
        'o.branch_id = :branch_id',
        'o.status NOT IN ("voided", "refunded")',
        'o.kitchen_status IN ("sent", "preparing", "ready")'
    ];
    
    $params = [
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ];
    
    if ($statusFilter === 'preparing') {
        $conditions[] = 'o.kitchen_status = "preparing"';
    } elseif ($statusFilter === 'ready') {
        $conditions[] = 'o.kitchen_status = "ready"';
    }
    
    if ($stationFilter) {
        $conditions[] = 'o.station_id = :station_id';
        $params['station_id'] = $stationFilter;
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    // Get orders with their items
    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            o.receipt_reference,
            o.order_type,
            o.table_id,
            o.customer_name,
            o.kitchen_status,
            o.fired_at,
            o.kitchen_notes,
            o.created_at,
            dt.table_number,
            s.station_name,
            TIMESTAMPDIFF(MINUTE, o.fired_at, NOW()) as minutes_elapsed,
            (
                SELECT COUNT(*) 
                FROM order_items 
                WHERE order_id = o.id 
                AND is_voided = 0 
                AND kitchen_status != 'served'
            ) as pending_items_count,
            (
                SELECT GROUP_CONCAT(
                    JSON_OBJECT(
                        'id', oi.id,
                        'product_name', oi.product_name,
                        'quantity', oi.quantity,
                        'kitchen_status', oi.kitchen_status,
                        'kitchen_notes', oi.kitchen_notes,
                        'fired_at', oi.fired_at,
                        'started_at', oi.started_at,
                        'ready_at', oi.ready_at,
                        'cook_time_minutes', oi.cook_time_minutes,
                        'modifiers', (
                            SELECT GROUP_CONCAT(
                                JSON_OBJECT(
                                    'name', modifier_name,
                                    'quantity', quantity
                                )
                            )
                            FROM order_item_modifiers
                            WHERE order_item_id = oi.id
                        )
                    )
                )
                FROM order_items oi
                WHERE oi.order_id = o.id
                AND oi.is_voided = 0
                AND oi.kitchen_status != 'served'
            ) as items_json
        FROM orders o
        LEFT JOIN dining_tables dt ON dt.id = o.table_id
        LEFT JOIN pos_stations s ON s.id = o.station_id
        WHERE $whereClause
        ORDER BY 
            CASE 
                WHEN o.kitchen_status = 'ready' THEN 1
                WHEN o.kitchen_status = 'preparing' THEN 2
                ELSE 3
            END,
            o.fired_at ASC
        LIMIT :limit
    ");
    
    $params['limit'] = $limit;
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process orders and parse items
    $processedOrders = [];
    foreach ($orders as $order) {
        $items = [];
        if ($order['items_json']) {
            $itemsArray = explode('},{', trim($order['items_json'], '{}'));
            foreach ($itemsArray as $itemJson) {
                if (!empty($itemJson)) {
                    $items[] = json_decode('{' . $itemJson . '}', true);
                }
            }
        }
        
        // Determine display urgency
        $urgency = 'normal';
        if ($order['minutes_elapsed'] > 20) {
            $urgency = 'urgent';
        } elseif ($order['minutes_elapsed'] > 15) {
            $urgency = 'warning';
        }
        
        $processedOrders[] = [
            'id' => (int)$order['id'],
            'receipt_reference' => $order['receipt_reference'],
            'order_type' => $order['order_type'],
            'table_number' => $order['table_number'],
            'customer_name' => $order['customer_name'],
            'kitchen_status' => $order['kitchen_status'],
            'fired_at' => $order['fired_at'],
            'minutes_elapsed' => (int)$order['minutes_elapsed'],
            'urgency' => $urgency,
            'station_name' => $order['station_name'],
            'pending_items' => (int)$order['pending_items_count'],
            'items' => $items
        ];
    }
    
    // Get summary statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as total_orders,
            COUNT(DISTINCT CASE WHEN o.kitchen_status = 'preparing' THEN o.id END) as preparing_orders,
            COUNT(DISTINCT CASE WHEN o.kitchen_status = 'ready' THEN o.id END) as ready_orders,
            AVG(TIMESTAMPDIFF(MINUTE, o.fired_at, NOW())) as avg_wait_time
        FROM orders o
        WHERE o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND o.kitchen_status IN ('sent', 'preparing', 'ready')
        AND o.fired_at >= DATE_SUB(NOW(), INTERVAL 4 HOUR)
    ");
    
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    json_response([
        'success' => true,
        'orders' => $processedOrders,
        'statistics' => [
            'total_active' => (int)$stats['total_orders'],
            'preparing' => (int)$stats['preparing_orders'],
            'ready' => (int)$stats['ready_orders'],
            'avg_wait_minutes' => round((float)$stats['avg_wait_time'], 1)
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'refresh_interval' => 5000 // Suggested refresh interval in ms
    ]);
    
} catch (PDOException $e) {
    error_log('KDS feed DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    error_log('KDS feed error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
