<?php
/**
 * SME 180 POS - Void Order API
 * Path: /public_html/pos/api/order/void_order.php
 * 
 * Voids an entire order with manager approval
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
    
    if (!$tenantId || !$branchId || !$userId) {
        json_response(['success' => false, 'error' => 'Invalid session'], 401);
    }
    
    // Parse request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        json_response(['success' => false, 'error' => 'Invalid request body'], 400);
    }
    
    if (!isset($input['order_id'])) {
        json_response(['success' => false, 'error' => 'Order ID is required'], 400);
    }
    
    $orderId = (int)$input['order_id'];
    $reason = $input['reason'] ?? 'No reason provided';
    $managerPin = $input['manager_pin'] ?? '';
    
    // Get database connection
    $pdo = db();
    
    // Check if manager approval is required
    $stmt = $pdo->prepare("
        SELECT value FROM settings 
        WHERE tenant_id = :tenant_id 
        AND `key` = 'pos_require_manager_void' 
        LIMIT 1
    ");
    $stmt->execute(['tenant_id' => $tenantId]);
    $requireManagerApproval = $stmt->fetchColumn() === 'true';
    
    $managerId = $userId;
    
    if ($requireManagerApproval) {
        // Check user role
        $userRole = $_SESSION['role'] ?? $user['role'] ?? '';
        
        if (!in_array($userRole, ['admin', 'manager'])) {
            // Non-manager needs approval
            if (!$managerPin) {
                json_response([
                    'success' => false, 
                    'error' => 'Manager approval required',
                    'requires_approval' => true
                ], 403);
            }
            
            // Validate manager PIN
            $stmt = $pdo->prepare("
                SELECT id, name FROM users 
                WHERE tenant_id = :tenant_id 
                AND pin = :pin 
                AND role IN ('admin', 'manager')
                AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([
                'tenant_id' => $tenantId,
                'pin' => hash('sha256', $managerPin)
            ]);
            
            $manager = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$manager) {
                json_response(['success' => false, 'error' => 'Invalid manager PIN'], 403);
            }
            
            $managerId = $manager['id'];
        }
    }
    
    $pdo->beginTransaction();
    
    // Get order with lock
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
    
    // Check if order can be voided
    if ($order['status'] === 'voided') {
        json_response(['success' => false, 'error' => 'Order is already voided'], 400);
    }
    
    if ($order['payment_status'] === 'paid') {
        json_response([
            'success' => false, 
            'error' => 'Cannot void paid orders. Please refund instead.'
        ], 400);
    }
    
    // Void the order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET status = 'voided',
            voided_at = NOW(),
            voided_by = :voided_by,
            void_reason = :reason,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    
    $stmt->execute([
        'voided_by' => $managerId,
        'reason' => $reason,
        'order_id' => $orderId
    ]);
    
    // Void all order items
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET is_voided = 1,
            voided_at = NOW(),
            voided_by = :voided_by,
            void_reason = 'Order voided'
        WHERE order_id = :order_id
        AND is_voided = 0
    ");
    
    $stmt->execute([
        'voided_by' => $managerId,
        'order_id' => $orderId
    ]);
    
    // Free the table if dine-in
    if ($order['order_type'] === 'dine_in' && $order['table_id']) {
        $stmt = $pdo->prepare("
            UPDATE dining_tables 
            SET status = 'available',
                current_order_id = NULL,
                updated_at = NOW()
            WHERE id = :table_id
        ");
        $stmt->execute(['table_id' => $order['table_id']]);
    }
    
    // Cancel any pending kitchen orders
    if ($order['kitchen_status'] !== 'pending') {
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET kitchen_status = 'cancelled'
            WHERE order_id = :order_id
            AND kitchen_status IN ('pending', 'preparing', 'ready')
        ");
        $stmt->execute(['order_id' => $orderId]);
        
        // Update KDS status
        $stmt = $pdo->prepare("
            UPDATE kds_item_status 
            SET status = 'cancelled',
                updated_at = NOW()
            WHERE order_id = :order_id
        ");
        try {
            $stmt->execute(['order_id' => $orderId]);
        } catch (PDOException $e) {
            // Table might not exist
        }
    }
    
    // Log the void
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'voided', :details, NOW()
        )
    ");
    
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'reason' => $reason,
            'approved_by' => $managerId,
            'total_amount' => $order['total_amount'],
            'items_voided' => true
        ])
    ]);
    
    // Create void record for reporting
    $stmt = $pdo->prepare("
        INSERT INTO order_voids (
            order_id, tenant_id, branch_id,
            voided_by, approved_by, reason,
            original_amount, voided_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id,
            :voided_by, :approved_by, :reason,
            :amount, NOW()
        )
    ");
    
    try {
        $stmt->execute([
            'order_id' => $orderId,
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'voided_by' => $userId,
            'approved_by' => $managerId,
            'reason' => $reason,
            'amount' => $order['total_amount']
        ]);
    } catch (PDOException $e) {
        // Table might not exist
    }
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'message' => 'Order voided successfully',
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'status' => 'voided',
            'voided_at' => date('Y-m-d H:i:s'),
            'voided_by' => $managerId,
            'reason' => $reason,
            'total_amount' => (float)$order['total_amount']
        ]
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Void order DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Void order error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
