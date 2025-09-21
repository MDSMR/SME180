<?php
/**
 * SME 180 POS - KDS Update Item Status API (PRODUCTION READY)
 * Path: /public_html/pos/api/kds/update_item_status.php
 * Version: 2.0.0 - Production Ready
 * 
 * Updates the status of individual items in the kitchen
 */

declare(strict_types=1);

// Production error handling
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Configuration
define('API_KEY', 'sme180_pos_api_key_2024');

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    http_response_code(204);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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
function json_response($data, $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
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
    
    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_response(['success' => false, 'error' => 'Invalid JSON input'], 400);
    }
    
    // Authentication
    $apiKey = $input['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
    $tenantId = null;
    $branchId = null;
    $userId = null;
    
    if ($apiKey === API_KEY) {
        // API key authentication
        $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : null;
        $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : null;
        $userId = isset($input['user_id']) ? (int)$input['user_id'] : 1;
    } else {
        // Session authentication
        session_start();
        $tenantId = $_SESSION['tenant_id'] ?? null;
        $branchId = $_SESSION['branch_id'] ?? null;
        $userId = $_SESSION['user_id'] ?? 1;
    }
    
    if (!$tenantId || !$branchId) {
        json_response(['success' => false, 'error' => 'Authentication required'], 401);
    }
    
    // Validate required fields
    if (!isset($input['item_id']) || !isset($input['status'])) {
        json_response(['success' => false, 'error' => 'Item ID and status are required'], 400);
    }
    
    $itemId = (int)$input['item_id'];
    $newStatus = $input['status'];
    $notes = $input['notes'] ?? '';
    
    // Validate status
    $validStatuses = ['fired', 'preparing', 'ready', 'served'];
    if (!in_array($newStatus, $validStatuses)) {
        json_response(['success' => false, 'error' => 'Invalid status. Must be: fired, preparing, ready, or served'], 400);
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Get item details with order info
    $stmt = $pdo->prepare("
        SELECT 
            oi.*,
            o.tenant_id,
            o.branch_id,
            o.kitchen_status as order_kitchen_status,
            o.status as order_status
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = :item_id
        FOR UPDATE
    ");
    $stmt->execute(['item_id' => $itemId]);
    $item = $stmt->fetch();
    
    if (!$item) {
        $pdo->rollBack();
        json_response(['success' => false, 'error' => 'Item not found'], 404);
    }
    
    // Verify tenant/branch
    if ($item['tenant_id'] != $tenantId || $item['branch_id'] != $branchId) {
        $pdo->rollBack();
        json_response(['success' => false, 'error' => 'Unauthorized'], 403);
    }
    
    // Check if item can be updated
    if ($item['is_voided']) {
        $pdo->rollBack();
        json_response(['success' => false, 'error' => 'Cannot update voided item'], 400);
    }
    
    if (in_array($item['order_status'], ['voided', 'refunded'])) {
        $pdo->rollBack();
        json_response(['success' => false, 'error' => 'Cannot update items in ' . $item['order_status'] . ' orders'], 400);
    }
    
    // Update item status
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET state = :status,
            kitchen_status_updated_at = NOW(),
            updated_at = NOW()
        WHERE id = :item_id
    ");
    $stmt->execute([
        'status' => $newStatus,
        'item_id' => $itemId
    ]);
    
    // Update specific timestamps based on status
    if ($newStatus === 'preparing' && !$item['preparing_at']) {
        $stmt = $pdo->prepare("UPDATE order_items SET preparing_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $itemId]);
    } elseif ($newStatus === 'ready' && !$item['ready_at']) {
        $stmt = $pdo->prepare("UPDATE order_items SET ready_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $itemId]);
    } elseif ($newStatus === 'served' && !$item['served_at']) {
        $stmt = $pdo->prepare("UPDATE order_items SET served_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $itemId]);
    }
    
    // Log the status change (if table exists)
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_item_events'")->rowCount();
        
        if ($tableCheck > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO order_item_events (
                    tenant_id, order_id, order_item_id, event_type,
                    payload, created_by, created_at
                ) VALUES (
                    :tenant_id, :order_id, :item_id, 'kitchen_status',
                    :payload, :user_id, NOW()
                )
            ");
            $stmt->execute([
                'tenant_id' => $tenantId,
                'order_id' => $item['order_id'],
                'item_id' => $itemId,
                'payload' => json_encode([
                    'from' => $item['state'],
                    'to' => $newStatus,
                    'notes' => $notes
                ]),
                'user_id' => $userId
            ]);
        }
    } catch (Exception $e) {
        // Continue without logging if table doesn't exist
    }
    
    // Check if all items in order have same status
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_items,
            SUM(CASE WHEN state = :status THEN 1 ELSE 0 END) as status_count,
            SUM(CASE WHEN is_voided = 1 THEN 1 ELSE 0 END) as voided_items
        FROM order_items
        WHERE order_id = :order_id
    ");
    $stmt->execute([
        'status' => $newStatus,
        'order_id' => $item['order_id']
    ]);
    $counts = $stmt->fetch();
    
    // If all non-voided items have same status, update order
    $activeItems = $counts['total_items'] - $counts['voided_items'];
    if ($activeItems > 0 && $counts['status_count'] == $activeItems) {
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET kitchen_status = :kitchen_status,
                updated_at = NOW()
            WHERE id = :order_id
        ");
        $stmt->execute([
            'kitchen_status' => $newStatus,
            'order_id' => $item['order_id']
        ]);
        
        // Update order timestamps
        if ($newStatus === 'ready') {
            $stmt = $pdo->prepare("UPDATE orders SET ready_at = COALESCE(ready_at, NOW()) WHERE id = :id");
            $stmt->execute(['id' => $item['order_id']]);
        } elseif ($newStatus === 'served') {
            $stmt = $pdo->prepare("UPDATE orders SET served_at = COALESCE(served_at, NOW()) WHERE id = :id");
            $stmt->execute(['id' => $item['order_id']]);
        }
    }
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'message' => 'Item status updated successfully',
        'item_id' => $itemId,
        'status' => $newStatus,
        'order_id' => $item['order_id']
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[SME180 KDS] Database error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[SME180 KDS] Error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to update item status'], 500);
}
?>