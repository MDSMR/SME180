<?php
/**
 * SME 180 POS - Fire to Kitchen API
 * Path: /public_html/pos/api/order/fire.php
 * Version: 2.0.0 - Production Ready
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(200);
    die('{"success":true}');
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('{"success":false,"error":"Method not allowed","code":"METHOD_NOT_ALLOWED"}');
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
function logEvent($level, $message, $context = []) {
    $logEntry = [
        'timestamp' => date('c'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'request_id' => $_SERVER['REQUEST_TIME_FLOAT'] ?? null
    ];
    error_log('[SME180] ' . json_encode($logEntry));
}

function sendError($message, $code = 400, $errorCode = 'GENERAL_ERROR', $additionalData = []) {
    http_response_code($code);
    $response = array_merge(
        [
            'success' => false,
            'error' => $message,
            'code' => $errorCode,
            'timestamp' => date('c')
        ],
        $additionalData
    );
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendSuccess($data) {
    echo json_encode(array_merge(
        ['success' => true],
        $data,
        ['timestamp' => date('c')]
    ), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function validateItemIds($itemIds) {
    if (!is_array($itemIds)) {
        return false;
    }
    
    foreach ($itemIds as $id) {
        if (!is_numeric($id) || $id <= 0 || $id > PHP_INT_MAX) {
            return false;
        }
    }
    
    return true;
}

// Load configuration
try {
    require_once __DIR__ . '/../../../config/db.php';
    $pdo = db();
} catch (Exception $e) {
    logEvent('ERROR', 'Database connection failed', ['error' => $e->getMessage()]);
    sendError('Database connection failed', 503, 'DB_CONNECTION_ERROR');
}

// Session validation
$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
$branchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$stationId = isset($_SESSION['station_id']) ? (int)$_SESSION['station_id'] : 1;

// Use defaults with warning
if (!$tenantId) {
    $tenantId = 1;
    logEvent('WARNING', 'No tenant_id in session, using default', ['session_id' => session_id()]);
}
if (!$branchId) {
    $branchId = 1;
    logEvent('WARNING', 'No branch_id in session, using default', ['session_id' => session_id()]);
}
if (!$userId) {
    $userId = 1;
    logEvent('WARNING', 'No user_id in session, using default', ['session_id' => session_id()]);
}

// Parse and validate input
$rawInput = file_get_contents('php://input');
if (strlen($rawInput) > 50000) { // 50KB max
    sendError('Request too large', 413, 'REQUEST_TOO_LARGE');
}

if (empty($rawInput)) {
    sendError('Request body is required', 400, 'EMPTY_REQUEST');
}

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid JSON format', 400, 'INVALID_JSON');
}

// Validate required fields
if (!isset($input['order_id'])) {
    sendError('Order ID is required', 400, 'MISSING_ORDER_ID');
}

$orderId = filter_var($input['order_id'], FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
]);

if ($orderId === false) {
    sendError('Invalid order ID format', 400, 'INVALID_ORDER_ID');
}

// Validate fire type
$validFireTypes = ['all', 'selected', 'course'];
$fireType = isset($input['fire_type']) ? $input['fire_type'] : 'all';

if (!in_array($fireType, $validFireTypes)) {
    sendError(
        'Invalid fire type. Must be: ' . implode(', ', $validFireTypes),
        400,
        'INVALID_FIRE_TYPE'
    );
}

// Validate item IDs if fire_type is 'selected'
$itemIds = [];
if ($fireType === 'selected') {
    if (!isset($input['item_ids']) || !is_array($input['item_ids']) || empty($input['item_ids'])) {
        sendError('Item IDs are required for selected fire type', 400, 'MISSING_ITEM_IDS');
    }
    
    if (!validateItemIds($input['item_ids'])) {
        sendError('Invalid item IDs format', 400, 'INVALID_ITEM_IDS');
    }
    
    $itemIds = array_map('intval', $input['item_ids']);
    
    // Limit number of items
    if (count($itemIds) > 100) {
        sendError('Cannot fire more than 100 items at once', 400, 'TOO_MANY_ITEMS');
    }
}

// Validate optional fields
$courseNumber = isset($input['course_number']) ? 
    filter_var($input['course_number'], FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 10]
    ]) : 1;

if ($courseNumber === false) {
    $courseNumber = 1;
}

$isRush = isset($input['is_rush']) ? (bool)$input['is_rush'] : false;
$notes = isset($input['notes']) ? 
    substr(trim(strip_tags($input['notes'])), 0, 500) : '';

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
        $pdo->rollBack();
        sendError('Order not found', 404, 'ORDER_NOT_FOUND');
    }
    
    // Check if order can be fired
    if (in_array($order['status'], ['closed', 'voided', 'refunded'])) {
        $pdo->rollBack();
        sendError(
            'Cannot fire items from ' . $order['status'] . ' orders',
            409,
            'INVALID_ORDER_STATUS'
        );
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
                AND (kitchen_status = 'pending' OR kitchen_status IS NULL OR kitchen_status = '')
            ");
            $stmt->execute(['order_id' => $orderId]);
            $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'selected':
            // Fire specific items
            if (!empty($itemIds)) {
                $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
                $stmt = $pdo->prepare("
                    SELECT * FROM order_items 
                    WHERE id IN ($placeholders)
                    AND order_id = ?
                    AND is_voided = 0
                    AND (kitchen_status = 'pending' OR kitchen_status IS NULL OR kitchen_status = '')
                ");
                $params = array_merge($itemIds, [$orderId]);
                $stmt->execute($params);
                $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Verify all requested items were found
                if (count($itemsToFire) !== count($itemIds)) {
                    $foundIds = array_column($itemsToFire, 'id');
                    $notFound = array_diff($itemIds, $foundIds);
                    
                    $pdo->rollBack();
                    sendError(
                        'Some items were not found or already fired',
                        404,
                        'ITEMS_NOT_FOUND',
                        ['not_found_ids' => $notFound]
                    );
                }
            }
            break;
            
        case 'course':
            // Fire items by course (using course field if exists)
            $courseColumn = false;
            try {
                $checkCol = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'course'");
                $courseColumn = ($checkCol->rowCount() > 0);
            } catch (Exception $e) {
                $courseColumn = false;
            }
            
            if ($courseColumn) {
                $stmt = $pdo->prepare("
                    SELECT * FROM order_items 
                    WHERE order_id = :order_id 
                    AND is_voided = 0
                    AND course = :course
                    AND (kitchen_status = 'pending' OR kitchen_status IS NULL OR kitchen_status = '')
                ");
                $stmt->execute([
                    'order_id' => $orderId,
                    'course' => $courseNumber
                ]);
            } else {
                // If no course column, fire all unfired items
                $stmt = $pdo->prepare("
                    SELECT * FROM order_items 
                    WHERE order_id = :order_id 
                    AND is_voided = 0
                    AND (kitchen_status = 'pending' OR kitchen_status IS NULL OR kitchen_status = '')
                ");
                $stmt->execute(['order_id' => $orderId]);
            }
            $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    if (empty($itemsToFire)) {
        $pdo->rollBack();
        logEvent('INFO', 'No items to fire', [
            'order_id' => $orderId,
            'fire_type' => $fireType
        ]);
        sendError('No items available to fire', 404, 'NO_ITEMS_TO_FIRE');
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
    
    // Check if kitchen_notes column exists
    $hasKitchenNotes = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'kitchen_notes'");
        $hasKitchenNotes = ($checkCol->rowCount() > 0);
    } catch (Exception $e) {
        $hasKitchenNotes = false;
    }
    
    // Check if fired_at column exists
    $hasFiredAt = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM order_items LIKE 'fired_at'");
        $hasFiredAt = ($checkCol->rowCount() > 0);
    } catch (Exception $e) {
        $hasFiredAt = false;
    }
    
    // Build update query based on available columns
    $updateFields = ["kitchen_status = 'preparing'"];
    $updateParams = [];
    
    if ($hasFiredAt) {
        $updateFields[] = "fired_at = NOW()";
    }
    
    if ($hasKitchenNotes && !empty($kitchenNotes)) {
        $updateFields[] = "kitchen_notes = CASE 
            WHEN kitchen_notes IS NULL THEN ?
            ELSE CONCAT(IFNULL(kitchen_notes, ''), ' ', ?)
        END";
        $updateParams[] = $kitchenNotes;
        $updateParams[] = $kitchenNotes;
    }
    
    $updateFields[] = "updated_at = NOW()";
    
    $updateQuery = "UPDATE order_items SET " . implode(", ", $updateFields) . " WHERE id IN ($placeholders)";
    
    $stmt = $pdo->prepare($updateQuery);
    $params = array_merge($updateParams, $firedItemIds);
    $stmt->execute($params);
    
    // Update order kitchen status
    $orderUpdateFields = ["kitchen_status = 'sent'", "updated_at = NOW()"];
    
    // Check if fired_at column exists in orders table
    $hasOrderFiredAt = false;
    try {
        $checkCol = $pdo->query("SHOW COLUMNS FROM orders LIKE 'fired_at'");
        $hasOrderFiredAt = ($checkCol->rowCount() > 0);
    } catch (Exception $e) {
        $hasOrderFiredAt = false;
    }
    
    if ($hasOrderFiredAt) {
        $orderUpdateFields[] = "fired_at = CASE WHEN fired_at IS NULL THEN NOW() ELSE fired_at END";
    }
    
    $stmt = $pdo->prepare("UPDATE orders SET " . implode(", ", $orderUpdateFields) . " WHERE id = :order_id");
    $stmt->execute(['order_id' => $orderId]);
    
    // Create fire log (check if table exists)
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
        // Table might not exist - log but continue
        logEvent('INFO', 'order_fire_logs table not found', ['error' => $e->getMessage()]);
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
    
    $logDetails = [
        'fire_type' => $fireType,
        'items_count' => count($firedItemIds),
        'is_rush' => $isRush,
        'course' => $courseNumber,
        'notes' => $notes,
        'station_id' => $stationId,
        'items' => array_map(function($item) {
            return [
                'id' => $item['id'],
                'name' => $item['product_name'],
                'quantity' => $item['quantity']
            ];
        }, $itemsToFire),
        'ip' => $_SERVER['REMOTE_ADDR'] ?? null
    ];
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode($logDetails)
    ]);
    
    $pdo->commit();
    
    // Log successful fire
    logEvent('INFO', 'Items fired to kitchen', [
        'order_id' => $orderId,
        'receipt' => $order['receipt_reference'],
        'items_count' => count($firedItemIds),
        'fire_type' => $fireType,
        'is_rush' => $isRush
    ]);
    
    sendSuccess([
        'message' => 'Items sent to kitchen successfully',
        'fired_items' => array_map(function($item) {
            return [
                'id' => (int)$item['id'],
                'product_name' => $item['product_name'],
                'quantity' => (float)$item['quantity'],
                'status' => 'preparing'
            ];
        }, $itemsToFire),
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'kitchen_status' => 'sent',
            'items_fired' => count($firedItemIds),
            'is_rush' => $isRush
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Fire to kitchen failed', [
        'order_id' => $orderId,
        'fire_type' => $fireType,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    
    sendError('Failed to send items to kitchen', 500, 'FIRE_FAILED');
}
?>