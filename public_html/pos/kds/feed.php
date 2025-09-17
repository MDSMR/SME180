## 1. /public_html/pos/api/kds/feed.php
```php
<?php
/**
 * SME 180 POS - KDS Feed API
 * Path: /public_html/pos/api/kds/feed.php
 * 
 * Provides real-time feed of orders for kitchen display screens
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

pos_auth_require_login();

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$screenCode = $_GET['screen_code'] ?? '';

if (!$tenantId || !$branchId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

if (empty($screenCode)) {
    json_response(['success' => false, 'error' => 'Screen code is required'], 400);
}

try {
    $pdo = db();
    
    // Get screen configuration
    $stmt = $pdo->prepare("
        SELECT * FROM pos_kds_screens 
        WHERE tenant_id = :tenant_id 
        AND branch_id = :branch_id 
        AND screen_code = :screen_code
        AND is_active = 1
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'screen_code' => $screenCode
    ]);
    
    $screen = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$screen) {
        json_response(['success' => false, 'error' => 'Screen not found or inactive'], 404);
    }
    
    // Update heartbeat
    $stmt = $pdo->prepare("
        UPDATE pos_kds_screens 
        SET last_heartbeat = NOW() 
        WHERE id = :id
    ");
    $stmt->execute(['id' => $screen['id']]);
    
    // Get category filter if configured
    $categories = json_decode($screen['categories'] ?? '[]', true);
    
    // Build query for orders
    $sql = "
        SELECT DISTINCT
            o.id as order_id,
            o.receipt_reference,
            o.order_type,
            o.table_id,
            o.customer_name,
            o.kitchen_status,
            o.fired_at,
            o.ready_at,
            o.created_at,
            TIMESTAMPDIFF(MINUTE, o.fired_at, NOW()) as minutes_elapsed,
            dt.table_number,
            u.name as server_name
        FROM orders o
        LEFT JOIN dining_tables dt ON dt.id = o.table_id
        LEFT JOIN users u ON u.id = o.created_by_user_id
        JOIN order_items oi ON oi.order_id = o.id
        LEFT JOIN products p ON p.id = oi.product_id
        WHERE o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND o.kitchen_status IN ('fired', 'preparing', 'ready')
        AND o.status NOT IN ('voided', 'refunded', 'closed')
        AND oi.fire_status = 'fired'
        AND oi.is_voided = 0
    ";
    
    $params = [
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ];
    
    // Add category filter if configured
    if (!empty($categories)) {
        $placeholders = array_map(function($i) { return ':cat' . $i; }, range(0, count($categories) - 1));
        $sql .= " AND p.category_id IN (" . implode(',', $placeholders) . ")";
        foreach ($categories as $i => $catId) {
            $params['cat' . $i] = $catId;
        }
    }
    
    // Add screen type specific filtering
    if ($screen['screen_type'] === 'bar') {
        // Bar screens only see beverage items
        $sql .= " AND p.category_id IN (SELECT id FROM categories WHERE name LIKE '%beverage%' OR name LIKE '%drink%')";
    }
    
    $sql .= " ORDER BY 
        CASE o.kitchen_status 
            WHEN 'ready' THEN 3
            WHEN 'preparing' THEN 1
            WHEN 'fired' THEN 2
        END,
        o.fired_at ASC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get items for each order
    $ordersWithItems = [];
    foreach ($orders as $order) {
        $stmt = $pdo->prepare("
            SELECT 
                oi.id as item_id,
                oi.product_id,
                oi.product_name,
                oi.quantity,
                oi.notes,
                oi.kitchen_notes,
                oi.fire_status,
                oi.state as item_status,
                oi.fire_time,
                p.category_id,
                c.name as category_name,
                (SELECT GROUP_CONCAT(CONCAT(oiv.variation_group, ': ', oiv.variation_value) SEPARATOR ', ')
                 FROM order_item_variations oiv
                 WHERE oiv.order_item_id = oi.id) as variations
            FROM order_items oi
            LEFT JOIN products p ON p.id = oi.product_id
            LEFT JOIN categories c ON c.id = p.category_id
            WHERE oi.order_id = :order_id
            AND oi.fire_status = 'fired'
            AND oi.is_voided = 0
        ");
        
        if (!empty($categories)) {
            $itemSql = " AND p.category_id IN (" . implode(',', $placeholders) . ")";
            $stmt = $pdo->prepare(str_replace("AND oi.is_voided = 0", "AND oi.is_voided = 0" . $itemSql, $stmt->queryString));
            foreach ($categories as $i => $catId) {
                $params['cat' . $i] = $catId;
            }
        }
        
        $stmt->execute(array_merge(['order_id' => $order['order_id']], 
                                  array_intersect_key($params, array_flip(array_filter(array_keys($params), 
                                                      function($k) { return strpos($k, 'cat') === 0; })))));
        
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($items)) {
            $order['items'] = $items;
            $order['item_count'] = count($items);
            
            // Determine display color based on time
            $minutesElapsed = (int)$order['minutes_elapsed'];
            if ($minutesElapsed < 10) {
                $order['display_color'] = 'green';
            } elseif ($minutesElapsed < 15) {
                $order['display_color'] = 'yellow';
            } elseif ($minutesElapsed < 20) {
                $order['display_color'] = 'orange';
            } else {
                $order['display_color'] = 'red';
            }
            
            $ordersWithItems[] = $order;
        }
    }
    
    // Get summary stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT CASE WHEN kitchen_status = 'fired' THEN id END) as fired_count,
            COUNT(DISTINCT CASE WHEN kitchen_status = 'preparing' THEN id END) as preparing_count,
            COUNT(DISTINCT CASE WHEN kitchen_status = 'ready' THEN id END) as ready_count
        FROM orders
        WHERE tenant_id = :tenant_id
        AND branch_id = :branch_id
        AND kitchen_status IN ('fired', 'preparing', 'ready')
        AND status NOT IN ('voided', 'refunded', 'closed')
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    json_response([
        'success' => true,
        'screen' => [
            'id' => (int)$screen['id'],
            'code' => $screen['screen_code'],
            'name' => $screen['screen_name'],
            'type' => $screen['screen_type']
        ],
        'stats' => [
            'fired' => (int)$stats['fired_count'],
            'preparing' => (int)$stats['preparing_count'],
            'ready' => (int)$stats['ready_count'],
            'total' => count($ordersWithItems)
        ],
        'orders' => $ordersWithItems,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log('KDS feed error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to get KDS feed'], 500);
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
