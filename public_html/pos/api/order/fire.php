<?php
/**
 * SME 180 POS - Fire to Kitchen API
 * Path: /public_html/pos/api/order/fire.php
 * 
 * Sends order items to kitchen for preparation
 * Supports selective firing, course management, and rush orders
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

// Authentication check
pos_auth_require_login();
$user = pos_get_current_user();

// Get tenant and branch from session
$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$stationId = (int)($_SESSION['station_id'] ?? 0);

if (!$tenantId || !$branchId || !$userId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

// Parse request
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    json_response(['success' => false, 'error' => 'Invalid request body'], 400);
}

// Validate required fields
if (!isset($input['order_id'])) {
    json_response(['success' => false, 'error' => 'Order ID is required'], 400);
}

$orderId = (int)$input['order_id'];
$fireType = $input['fire_type'] ?? 'all'; // all, selected, course
$itemIds = $input['item_ids'] ?? [];
$courseNumber = (int)($input['course_number'] ?? 1);
$isRush = (bool)($input['is_rush'] ?? false);
$notes = $input['notes'] ?? '';

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Fetch and lock order
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
    
    // Check if order can be fired
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        json_response(['success' => false, 'error' => 'Cannot fire ' . $order['status'] . ' orders'], 400);
    }
    
    // Determine which items to fire
    $itemsToFire = [];
    
    switch ($fireType) {
        case 'all':
            // Fire all unfired items
            $stmt = $pdo->prepare("
                SELECT * FROM order_items 
                WHERE order_id = :order_id 
                AND is_voided = 0
                AND fire_status = 'pending'
            ");
            $stmt->execute(['order_id' => $orderId]);
            $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'selected':
            // Fire specific items
            if (empty($itemIds)) {
                json_response(['success' => false, 'error' => 'No items selected'], 400);
            }
            
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT * FROM order_items 
                WHERE order_id = ? 
                AND id IN ($placeholders)
                AND is_voided = 0
                AND fire_status = 'pending'
            ");
            $params = array_merge([$orderId], $itemIds);
            $stmt->execute($params);
            $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'course':
            // Fire items by course
            $stmt = $pdo->prepare("
                SELECT * FROM order_items 
                WHERE order_id = :order_id 
                AND course_number = :course
                AND is_voided = 0
                AND fire_status = 'pending'
            ");
            $stmt->execute([
                'order_id' => $orderId,
                'course' => $courseNumber
            ]);
            $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        default:
            json_response(['success' => false, 'error' => 'Invalid fire type'], 400);
    }
    
    if (empty($itemsToFire)) {
        json_response(['success' => false, 'error' => 'No items to fire'], 400);
    }
    
    // Get printer configuration for categories
    $printerMapping = [];
    $stmt = $pdo->prepare("
        SELECT DISTINCT p.category_id, pc.printer_id
        FROM order_items oi
        JOIN products p ON p.id = oi.product_id
        LEFT JOIN printer_categories pc ON pc.category_id = p.category_id
        WHERE oi.order_id = :order_id
        AND pc.tenant_id = :tenant_id
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId
    ]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $printerMapping[$row['category_id']] = $row['printer_id'];
    }
    
    // Fire the items
    $firedItems = [];
    $fireTime = date('Y-m-d H:i:s');
    
    $updateStmt = $pdo->prepare("
        UPDATE order_items 
        SET fire_status = 'fired',
            state = 'fired',
            fire_time = :fire_time,
            fired_at = :fired_at,
            updated_at = NOW()
        WHERE id = :item_id
    ");
    
    foreach ($itemsToFire as $item) {
        $updateStmt->execute([
            'fire_time' => $fireTime,
            'fired_at' => $fireTime,
            'item_id' => $item['id']
        ]);
        
        $firedItems[] = [
            'id' => $item['id'],
            'product_id' => $item['product_id'],
            'product_name' => $item['product_name'],
            'quantity' => $item['quantity']
        ];
        
        // Queue for printing if printer configured
        if (isset($printerMapping[$item['category_id']])) {
            queueKitchenPrint($pdo, $item, $order, $printerMapping[$item['category_id']], $tenantId, $branchId);
        }
    }
    
    // Update order kitchen status
    $newKitchenStatus = 'fired';
    if ($isRush) {
        $newKitchenStatus = 'preparing'; // Rush orders go straight to preparing
    }
    
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET kitchen_status = :kitchen_status,
            fired_at = COALESCE(fired_at, :fired_at),
            status = CASE 
                WHEN status = 'open' THEN 'sent'
                ELSE status
            END,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'kitchen_status' => $newKitchenStatus,
        'fired_at' => $fireTime,
        'order_id' => $orderId
    ]);
    
    // Log fire event
    $stmt = $pdo->prepare("
        INSERT INTO order_fire_logs (
            tenant_id, branch_id, order_id, fire_type,
            items_fired, station_id, fired_by, fired_at, notes
        ) VALUES (
            :tenant_id, :branch_id, :order_id, :fire_type,
            :items_fired, :station_id, :fired_by, NOW(), :notes
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'order_id' => $orderId,
        'fire_type' => $isRush ? 'rush' : 'initial',
        'items_fired' => json_encode(array_column($firedItems, 'id')),
        'station_id' => $stationId,
        'fired_by' => $userId,
        'notes' => $notes
    ]);
    
    // Send to KDS if configured
    sendToKDS($pdo, $orderId, $firedItems, $tenantId, $branchId);
    
    $pdo->commit();
    
    // Check if all items are now fired
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as pending_count
        FROM order_items 
        WHERE order_id = :order_id 
        AND is_voided = 0
        AND fire_status = 'pending'
    ");
    $stmt->execute(['order_id' => $orderId]);
    $pendingCount = $stmt->fetch(PDO::FETCH_ASSOC)['pending_count'];
    
    $response = [
        'success' => true,
        'order_id' => $orderId,
        'fired' => [
            'count' => count($firedItems),
            'items' => $firedItems,
            'time' => $fireTime
        ],
        'remaining' => [
            'pending_items' => (int)$pendingCount,
            'all_fired' => $pendingCount == 0
        ],
        'kitchen_status' => $newKitchenStatus
    ];
    
    json_response($response);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('Fire to kitchen error: ' . $e->getMessage());
    json_response([
        'success' => false,
        'error' => 'Failed to fire items',
        'details' => $e->getMessage()
    ], 500);
}

/**
 * Queue kitchen print job
 */
function queueKitchenPrint($pdo, $item, $order, $printerId, $tenantId, $branchId) {
    try {
        // Build print content
        $content = "=== KITCHEN ORDER ===\n";
        $content .= "Order #: " . $order['receipt_reference'] . "\n";
        $content .= "Table: " . ($order['table_id'] ? 'Table ' . $order['table_id'] : 'Takeaway') . "\n";
        $content .= "Time: " . date('H:i') . "\n";
        $content .= "--------------------\n";
        $content .= $item['quantity'] . "x " . $item['product_name'] . "\n";
        
        if ($item['notes']) {
            $content .= "Notes: " . $item['notes'] . "\n";
        }
        if ($item['kitchen_notes']) {
            $content .= "Kitchen: " . $item['kitchen_notes'] . "\n";
        }
        
        // Get variations
        $stmt = $pdo->prepare("
            SELECT variation_group, variation_value 
            FROM order_item_variations 
            WHERE order_item_id = :item_id
        ");
        $stmt->execute(['item_id' => $item['id']]);
        $variations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($variations as $var) {
            $content .= "  - " . $var['variation_group'] . ": " . $var['variation_value'] . "\n";
        }
        
        $content .= "====================\n";
        
        // Insert print job
        $stmt = $pdo->prepare("
            INSERT INTO pos_print_queue (
                tenant_id, branch_id, printer_id, job_type,
                reference_type, reference_id, content, format,
                priority, status, queued_at
            ) VALUES (
                :tenant_id, :branch_id, :printer_id, 'kitchen',
                'order_item', :item_id, :content, 'text',
                5, 'pending', NOW()
            )
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'printer_id' => $printerId,
            'item_id' => $item['id'],
            'content' => $content
        ]);
        
    } catch (Exception $e) {
        // Printing errors should not fail the fire operation
        error_log('Kitchen print error: ' . $e->getMessage());
    }
}

/**
 * Send items to Kitchen Display System
 */
function sendToKDS($pdo, $orderId, $items, $tenantId, $branchId) {
    try {
        // Check if KDS is configured
        $stmt = $pdo->prepare("
            SELECT id, screen_code 
            FROM pos_kds_screens 
            WHERE tenant_id = :tenant_id 
            AND branch_id = :branch_id
            AND is_active = 1
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId
        ]);
        $kdsScreens = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($kdsScreens)) {
            return; // No KDS configured
        }
        
        // KDS notification would be sent here via WebSocket or polling
        // This is a placeholder for the actual KDS integration
        
    } catch (Exception $e) {
        // KDS errors should not fail the fire operation
        error_log('KDS error: ' . $e->getMessage());
    }
}

/**
 * Send JSON response
 */
function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
?>
