<?php
/**
 * SME 180 POS - KDS Update Order Status API (PRODUCTION READY)
 * Path: /public_html/pos/api/kds/update_order_status.php
 * Version: 2.0.0 - Production Ready
 * 
 * Updates the kitchen status of an entire order
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
    if (!isset($input['order_id']) || !isset($input['status'])) {
        json_response(['success' => false, 'error' => 'Order ID and status are required'], 400);
    }
    
    $orderId = (int)$input['order_id'];
    $newStatus = $input['status'];
    $notes = $input['notes'] ?? '';
    
    // Validate status
    $validStatuses = ['fired', 'preparing', 'ready', 'served', 'cancelled'];
    if (!in_array($newStatus, $validStatuses)) {
        json_response(['success' => false, 'error' => 'Invalid status. Must be: fired, preparing, ready, served, or cancelled'], 400);
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Get and lock order
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
    $order = $stmt->fetch();
    
    if (!$order) {
        $pdo->rollBack();
        json_response(['success' => false, 'error' => 'Order not found'], 404);
    }
    
    // Check if order can be updated
    if (in_array($order['status'], ['voided', 'refunded'])) {
        $pdo->rollBack();
        json_response(['success' => false, 'error' => 'Cannot update ' . $order['status'] . ' orders'], 400);
    }
    
    // Update order kitchen status
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET kitchen_status = :kitchen_status,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'kitchen_status' => $newStatus,
        'order_id' => $orderId
    ]);
    
    // Update timestamps based on status
    if ($newStatus === 'fired' && !$order['fired_at']) {
        $stmt = $pdo->prepare("UPDATE orders SET fired_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
    } elseif ($newStatus === 'preparing' && !$order['preparing_at']) {
        $stmt = $pdo->prepare("UPDATE orders SET preparing_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
    } elseif ($newStatus === 'ready' && !$order['ready_at']) {
        $stmt = $pdo->prepare("UPDATE orders SET ready_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
    } elseif ($newStatus === 'served') {
        // Update order status based on payment
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET status = CASE 
                    WHEN payment_status = 'paid' THEN 'closed'
                    ELSE 'served'
                END,
                served_at = COALESCE(served_at, NOW())
            WHERE id = :id
        ");
        $stmt->execute(['id' => $orderId]);
    }
    
    // Update all non-voided items if not cancelling
    if ($newStatus !== 'cancelled') {
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET state = :status,
                kitchen_status_updated_at = NOW(),
                updated_at = NOW()
            WHERE order_id = :order_id
            AND is_voided = 0
        ");
        $stmt->execute([
            'status' => $newStatus,
            'order_id' => $orderId
        ]);
        
        // Update item timestamps
        if ($newStatus === 'preparing') {
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET preparing_at = COALESCE(preparing_at, NOW()) 
                WHERE order_id = :id AND is_voided = 0
            ");
            $stmt->execute(['id' => $orderId]);
        } elseif ($newStatus === 'ready') {
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET ready_at = COALESCE(ready_at, NOW()) 
                WHERE order_id = :id AND is_voided = 0
            ");
            $stmt->execute(['id' => $orderId]);
        } elseif ($newStatus === 'served') {
            $stmt = $pdo->prepare("
                UPDATE order_items 
                SET served_at = COALESCE(served_at, NOW()) 
                WHERE order_id = :id AND is_voided = 0
            ");
            $stmt->execute(['id' => $orderId]);
        }
    } else {
        // If cancelling, update items to cancelled state
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET state = 'voided',
                is_voided = 1,
                voided_at = NOW(),
                voided_by = :user_id,
                void_reason = 'Order cancelled from kitchen'
            WHERE order_id = :order_id
            AND is_voided = 0
        ");
        $stmt->execute([
            'user_id' => $userId,
            'order_id' => $orderId
        ]);
    }
    
    // Log status change (if table exists)
    try {
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_item_events'")->rowCount();
        
        if ($tableCheck > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO order_item_events (
                    tenant_id, order_id, event_type,
                    payload, created_by, created_at
                ) VALUES (
                    :tenant_id, :order_id, 'kitchen_status',
                    :payload, :user_id, NOW()
                )
            ");
            $stmt->execute([
                'tenant_id' => $tenantId,
                'order_id' => $orderId,
                'payload' => json_encode([
                    'from' => $order['kitchen_status'],
                    'to' => $newStatus,
                    'notes' => $notes
                ]),
                'user_id' => $userId
            ]);
        }
    } catch (Exception $e) {
        // Continue without logging
    }
    
    // Create notification for ready orders (if notifications table exists)
    if ($newStatus === 'ready') {
        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'system_notifications'")->rowCount();
            
            if ($tableCheck > 0) {
                $stmt = $pdo->prepare("
                    INSERT INTO system_notifications (
                        tenant_id, branch_id, type, title, message,
                        data, created_at
                    ) VALUES (
                        :tenant_id, :branch_id, 'order_ready', :title, :message,
                        :data, NOW()
                    )
                ");
                
                $stmt->execute([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'title' => 'Order Ready',
                    'message' => 'Order #' . ($order['receipt_reference'] ?? $orderId) . ' is ready',
                    'data' => json_encode(['order_id' => $orderId])
                ]);
            }
        } catch (Exception $e) {
            // Continue without notification
        }
    }
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'message' => 'Order status updated successfully',
        'order_id' => $orderId,
        'kitchen_status' => $newStatus,
        'receipt_reference' => $order['receipt_reference'] ?? null
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
    json_response(['success' => false, 'error' => 'Failed to update order status'], 500);
}
?>