<?php
/**
 * SME 180 POS - Fire to Kitchen API
 * Path: /public_html/pos/api/order/fire.php
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
$stationId = (int)($_SESSION['station_id'] ?? 1);

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    die('{"success":false,"error":"Invalid request body"}');
}

if (!isset($input['order_id'])) {
    die('{"success":false,"error":"Order ID is required"}');
}

$orderId = (int)$input['order_id'];
$fireType = $input['fire_type'] ?? 'all'; // all, selected, course
$itemIds = $input['item_ids'] ?? [];
$courseNumber = (int)($input['course_number'] ?? 1);
$isRush = (bool)($input['is_rush'] ?? false);
$notes = $input['notes'] ?? '';

try {
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
        die('{"success":false,"error":"Order not found"}');
    }
    
    // Check if order can be fired
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        die('{"success":false,"error":"Cannot fire ' . $order['status'] . ' orders"}');
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
                AND (kitchen_status = 'pending' OR kitchen_status IS NULL)
            ");
            $stmt->execute(['order_id' => $orderId]);
            $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'selected':
            // Fire specific items
            if (empty($itemIds)) {
                die('{"success":false,"error":"No items selected"}');
            }
            
            $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT * FROM order_items 
                WHERE id IN ($placeholders)
                AND order_id = ?
                AND is_voided = 0
                AND (kitchen_status = 'pending' OR kitchen_status IS NULL)
            ");
            $params = array_merge($itemIds, [$orderId]);
            $stmt->execute($params);
            $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'course':
            // Fire items by course (using a custom field or all items if not implemented)
            $stmt = $pdo->prepare("
                SELECT * FROM order_items 
                WHERE order_id = :order_id 
                AND is_voided = 0
                AND (kitchen_status = 'pending' OR kitchen_status IS NULL)
            ");
            $stmt->execute(['order_id' => $orderId]);
            $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    if (empty($itemsToFire)) {
        die('{"success":false,"error":"No items to fire"}');
    }
    
    // Update items to fired status
    $firedItemIds = array_column($itemsToFire, 'id');
    $placeholders = str_repeat('?,', count($firedItemIds) - 1) . '?';
    
    $kitchenNotes = '';
    if ($isRush) {
        $kitchenNotes .= '[RUSH] ';
    }
    if ($notes) {
        $kitchenNotes .= $notes;
    }
    
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET kitchen_status = 'preparing',
            fired_at = NOW(),
            kitchen_notes = CASE 
                WHEN kitchen_notes IS NULL THEN ?
                ELSE CONCAT(IFNULL(kitchen_notes, ''), ' ', ?)
            END
        WHERE id IN ($placeholders)
    ");
    
    $params = array_merge([$kitchenNotes, $kitchenNotes], $firedItemIds);
    $stmt->execute($params);
    
    // Update order kitchen status
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET kitchen_status = 'sent',
            fired_at = CASE WHEN fired_at IS NULL THEN NOW() ELSE fired_at END,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    
    // Create fire log
    try {
        $stmt = $pdo->prepare("
            INSERT INTO order_fire_logs (
                order_id, tenant_id, branch_id, fired_by,
                station_id, items_fired, fired_at
            ) VALUES (
                :order_id, :tenant_id, :branch_id, :fired_by,
                :station_id, :items_fired, NOW()
            )
        ");
        
        $stmt->execute([
            'order_id' => $orderId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'fired_by' => $userId,
            'station_id' => $stationId,
            'items_fired' => json_encode([
                'item_ids' => $firedItemIds,
                'fire_type' => $fireType,
                'is_rush' => $isRush,
                'notes' => $notes
            ])
        ]);
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    // Log the action
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'fired_to_kitchen', :details, NOW()
        )
    ");
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'fire_type' => $fireType,
            'items_count' => count($firedItemIds),
            'is_rush' => $isRush,
            'items' => array_map(function($item) {
                return [
                    'id' => $item['id'],
                    'name' => $item['product_name'],
                    'quantity' => $item['quantity']
                ];
            }, $itemsToFire)
        ])
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Items sent to kitchen',
        'fired_items' => array_map(function($item) {
            return [
                'id' => $item['id'],
                'product_name' => $item['product_name'],
                'quantity' => $item['quantity'],
                'status' => 'preparing'
            ];
        }, $itemsToFire),
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'kitchen_status' => 'sent',
            'items_fired' => count($firedItemIds)
        ]
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Fire to kitchen error: ' . $e->getMessage());
    die('{"success":false,"error":"Failed to send items to kitchen"}');
}
?>