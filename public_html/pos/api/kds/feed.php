<?php
/**
 * SME 180 POS - KDS Feed API (PRODUCTION READY)
 * Path: /public_html/pos/api/kds/feed.php
 * Version: 2.0.0 - Production Ready
 * 
 * Returns active kitchen orders for display screens
 */

declare(strict_types=1);

// Production error handling
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Configuration
define('API_KEY', 'sme180_pos_api_key_2024');
define('MAX_ORDERS_LIMIT', 100);
define('DEFAULT_ORDERS_LIMIT', 50);

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    http_response_code(204);
    exit;
}

// Allow GET and POST
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'])) {
    http_response_code(405);
    die('{"success":false,"error":"Method not allowed","code":"METHOD_NOT_ALLOWED"}');
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'dbvtrnbzad193e');
define('DB_USER', 'uta6umaa0iuif');
define('DB_PASS', '2m%[11|kb1Z4');

/**
 * Send JSON response
 */
function json_response($data, $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

try {
    // Database connection
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    // Get input (support both GET and POST)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    } else {
        $input = $_GET;
    }
    
    // Authentication
    $apiKey = $input['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
    $tenantId = null;
    $branchId = null;
    
    if ($apiKey === API_KEY) {
        // API key authentication
        $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : null;
        $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : null;
    } else {
        // Session authentication
        session_start();
        $tenantId = $_SESSION['tenant_id'] ?? null;
        $branchId = $_SESSION['branch_id'] ?? null;
    }
    
    // Validate tenant/branch
    if (!$tenantId || !$branchId) {
        json_response(['success' => false, 'error' => 'Authentication required', 'code' => 'AUTH_REQUIRED'], 401);
    }
    
    // Get filter parameters
    $screenCode = $input['screen_code'] ?? 'KDS_MAIN';
    $statusFilter = $input['status'] ?? 'active';
    $limit = min((int)($input['limit'] ?? DEFAULT_ORDERS_LIMIT), MAX_ORDERS_LIMIT);
    
    // Build query
    $conditions = [
        'o.tenant_id = :tenant_id',
        'o.branch_id = :branch_id',
        'o.status NOT IN ("voided", "refunded")',
        'o.kitchen_status IN ("fired", "preparing", "ready")'
    ];
    
    $params = [
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ];
    
    // Apply status filter
    if ($statusFilter === 'preparing') {
        $conditions[] = 'o.kitchen_status = "preparing"';
    } elseif ($statusFilter === 'ready') {
        $conditions[] = 'o.kitchen_status = "ready"';
    }
    
    $whereClause = implode(' AND ', $conditions);
    
    // Get orders with items
    $sql = "
        SELECT 
            o.id,
            o.receipt_reference,
            o.order_type,
            o.table_id,
            o.customer_name,
            o.kitchen_status,
            o.fired_at,
            o.preparing_at,
            o.ready_at,
            o.notes as kitchen_notes,
            o.created_at,
            dt.table_number,
            TIMESTAMPDIFF(MINUTE, COALESCE(o.fired_at, o.created_at), NOW()) as minutes_elapsed,
            (
                SELECT COUNT(*) 
                FROM order_items 
                WHERE order_id = o.id 
                AND is_voided = 0 
                AND state != 'served'
            ) as pending_items_count
        FROM orders o
        LEFT JOIN dining_tables dt ON dt.id = o.table_id AND dt.tenant_id = o.tenant_id
        WHERE $whereClause
        ORDER BY 
            CASE 
                WHEN o.kitchen_status = 'ready' THEN 1
                WHEN o.kitchen_status = 'preparing' THEN 2
                ELSE 3
            END,
            COALESCE(o.fired_at, o.created_at) ASC
        LIMIT :limit
    ";
    
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue(':' . $key, $value);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $orders = $stmt->fetchAll();
    
    // Get items for each order
    $processedOrders = [];
    foreach ($orders as $order) {
        // Get order items
        $stmt = $pdo->prepare("
            SELECT 
                id,
                product_name,
                quantity,
                state as kitchen_status,
                notes as kitchen_notes,
                fired_at,
                preparing_at,
                ready_at,
                cook_time_minutes
            FROM order_items
            WHERE order_id = :order_id
            AND is_voided = 0
            AND state != 'served'
            ORDER BY id
        ");
        $stmt->execute(['order_id' => $order['id']]);
        $items = $stmt->fetchAll();
        
        // Determine urgency
        $urgency = 'normal';
        $minutesElapsed = (int)$order['minutes_elapsed'];
        if ($minutesElapsed > 20) {
            $urgency = 'urgent';
        } elseif ($minutesElapsed > 15) {
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
            'minutes_elapsed' => $minutesElapsed,
            'urgency' => $urgency,
            'pending_items' => (int)$order['pending_items_count'],
            'items' => $items
        ];
    }
    
    // Get statistics
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as total_orders,
            COUNT(DISTINCT CASE WHEN o.kitchen_status = 'preparing' THEN o.id END) as preparing_orders,
            COUNT(DISTINCT CASE WHEN o.kitchen_status = 'ready' THEN o.id END) as ready_orders,
            AVG(TIMESTAMPDIFF(MINUTE, COALESCE(o.fired_at, o.created_at), NOW())) as avg_wait_time
        FROM orders o
        WHERE o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND o.kitchen_status IN ('fired', 'preparing', 'ready')
        AND COALESCE(o.fired_at, o.created_at) >= DATE_SUB(NOW(), INTERVAL 4 HOUR)
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $stats = $stmt->fetch();
    
    json_response([
        'success' => true,
        'orders' => $processedOrders,
        'statistics' => [
            'total_active' => (int)($stats['total_orders'] ?? 0),
            'preparing' => (int)($stats['preparing_orders'] ?? 0),
            'ready' => (int)($stats['ready_orders'] ?? 0),
            'avg_wait_minutes' => round((float)($stats['avg_wait_time'] ?? 0), 1)
        ],
        'timestamp' => date('Y-m-d H:i:s'),
        'refresh_interval' => 5000
    ]);
    
} catch (PDOException $e) {
    error_log('[SME180 KDS] Database error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error', 'code' => 'DB_ERROR'], 500);
} catch (Exception $e) {
    error_log('[SME180 KDS] Error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Service error', 'code' => 'SERVICE_ERROR'], 500);
}
?>