<?php
/**
 * SME 180 POS - Void Order API
 * Path: /public_html/pos/api/order/void_order.php
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    die('{"success":false,"error":"Invalid request body"}');
}

if (!isset($input['order_id'])) {
    die('{"success":false,"error":"Order ID is required"}');
}

$orderId = (int)$input['order_id'];
$reason = $input['reason'] ?? 'No reason provided';
$managerPin = $input['manager_pin'] ?? '';

try {
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
        $userRole = $_SESSION['role'] ?? 'cashier';
        
        if (!in_array($userRole, ['admin', 'manager'])) {
            if (!$managerPin) {
                die('{"success":false,"error":"Manager approval required","requires_approval":true}');
            }
            
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
                die('{"success":false,"error":"Invalid manager PIN"}');
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
        die('{"success":false,"error":"Order not found"}');
    }
    
    if ($order['status'] === 'voided') {
        die('{"success":false,"error":"Order is already voided"}');
    }
    
    if ($order['payment_status'] === 'paid') {
        die('{"success":false,"error":"Cannot void paid orders. Please refund instead."}');
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
        try {
            $stmt->execute(['table_id' => $order['table_id']]);
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
    
    $pdo->commit();
    
    echo json_encode([
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
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Void order error: ' . $e->getMessage());
    die('{"success":false,"error":"Database error"}');
}
?>