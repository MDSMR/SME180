<?php
/**
 * SME 180 POS - Fire to Kitchen API
 * Path: /public_html/pos/api/order/fire.php
 * 
 * Sends order items to kitchen for preparation
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
    
    // Get database connection
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
                AND kitchen_status = 'pending'
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
                WHERE id IN ($placeholders)
                AND order_id = ?
                AND is_voided = 0
                AND kitchen_status = 'pending'
            ");
            $params = array_merge($itemIds, [$orderId]);
            $stmt->execute($params);
            $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'course':
            // Fire items by course
            $stmt = $pdo->prepare("
                SELECT * FROM order_items 
                WHERE order_id = :order_id 
                AND is_voided = 0
                AND kitchen_status = 'pending'
                AND course_number = :course
            ");
            $stmt->execute([
                'order_id' => $orderId,
                'course' => $courseNumber
            ]);
            $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    if (empty($itemsToFire)) {
        json_response(['success' => false, 'error' => 'No items to fire'], 400);
    }
    
    // Update items to fired status
    $firedItemIds = array_column($itemsToFire, 'id');
    $placeholders = str_repeat('?,', count($firedItemIds) - 1) . '?';
    
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET kitchen_status = 'preparing',
            fired_at = NOW(),
            kitchen_notes = CONCAT(IFNULL(kitchen_notes, ''), ?)
        WHERE id IN ($placeholders)
    ");
    
    $kitchenNotes = '';
    if ($isRush) {
        $kitchenNotes .= '[RUSH] ';
    }
    if ($notes) {
        $kitchenNotes .= $notes;
    }
    
    $params = array_merge([$kitchenNotes], $firedItemIds);
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
    
    // Create KDS item status entries
    foreach ($itemsToFire as $item) {
        $stmt = $pdo->prepare("
            INSERT INTO kds_item_status (
                order_id, order_item_id, tenant_id, branch_id,
                status, started_at, updated_by, created_at
            ) VALUES (
                :order_id, :item_id, :tenant_id, :branch_id,
                'preparing', NOW(), :user_id, NOW()
            )
            ON DUPLICATE KEY UPDATE
                status = 'preparing',
                started_at = NOW(),
                updated_by = :user_id2,
                updated_at = NOW()
        ");
        
        $stmt->execute([
            'order_id' => $orderId,
            'item_id' => $item['id'],
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'user_id' => $userId,
            'user_id2' => $userId
        ]);
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
    
    json_response([
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
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Fire to kitchen DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Fire to kitchen error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
