<?php
/**
 * SME 180 POS - Void Item API
 * Path: /public_html/pos/api/order/void_item.php
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

if (!isset($input['order_id']) || !isset($input['item_id']) || !isset($input['reason'])) {
    die('{"success":false,"error":"Order ID, item ID, and reason are required"}');
}

$orderId = (int)$input['order_id'];
$itemId = (int)$input['item_id'];
$reason = $input['reason'];
$approvalPin = $input['approval_pin'] ?? null;

try {
    $pdo->beginTransaction();
    
    // Fetch item
    $stmt = $pdo->prepare("
        SELECT oi.*, o.status as order_status, o.payment_status
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = :item_id 
        AND oi.order_id = :order_id
        AND o.tenant_id = :tenant_id
        AND oi.is_voided = 0
        FOR UPDATE
    ");
    $stmt->execute([
        'item_id' => $itemId,
        'order_id' => $orderId,
        'tenant_id' => $tenantId
    ]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        die('{"success":false,"error":"Item not found or already voided"}');
    }
    
    if ($item['payment_status'] === 'paid') {
        die('{"success":false,"error":"Cannot void items from paid orders"}');
    }
    
    $approvedBy = $userId;
    
    // Check if item is fired (requires approval)
    if ($item['kitchen_status'] === 'preparing' || $item['kitchen_status'] === 'ready') {
        $userRole = $_SESSION['role'] ?? 'cashier';
        
        if (!in_array($userRole, ['admin', 'manager'])) {
            if (!$approvalPin) {
                $pdo->commit();
                die('{"success":false,"error":"Manager approval required to void fired item","requires_approval":true}');
            }
            
            $stmt = $pdo->prepare("
                SELECT id FROM users 
                WHERE tenant_id = :tenant_id 
                AND pin = :pin 
                AND role IN ('admin', 'manager')
                AND is_active = 1
                LIMIT 1
            ");
            $stmt->execute([
                'tenant_id' => $tenantId,
                'pin' => hash('sha256', $approvalPin)
            ]);
            
            $managerId = $stmt->fetchColumn();
            if (!$managerId) {
                die('{"success":false,"error":"Invalid approval PIN"}');
            }
            $approvedBy = $managerId;
        }
    }
    
    // Void the item
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET is_voided = 1,
            void_reason = :reason,
            voided_at = NOW(),
            voided_by = :approved_by,
            updated_at = NOW()
        WHERE id = :item_id
    ");
    $stmt->execute([
        'reason' => $reason,
        'approved_by' => $approvedBy,
        'item_id' => $itemId
    ]);
    
    // Recalculate order totals
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN is_voided = 0 THEN line_total ELSE 0 END) as subtotal
        FROM order_items 
        WHERE order_id = :order_id
    ");
    $stmt->execute(['order_id' => $orderId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $taxRate = 14; // Default tax rate
    $subtotal = (float)$totals['subtotal'];
    $tax = $subtotal * ($taxRate / 100);
    $newTotal = $subtotal + $tax;
    
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET subtotal = :subtotal,
            tax_amount = :tax,
            total_amount = :total,
            updated_at = NOW()
        WHERE id = :order_id
    ");
    $stmt->execute([
        'subtotal' => $subtotal,
        'tax' => $tax,
        'total' => $newTotal,
        'order_id' => $orderId
    ]);
    
    // Log void event
    $stmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            :order_id, :tenant_id, :branch_id, :user_id,
            'item_voided', :details, NOW()
        )
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId,
        'details' => json_encode([
            'item_id' => $itemId,
            'reason' => $reason,
            'approved_by' => $approvedBy
        ])
    ]);
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'order_id' => $orderId,
        'item_id' => $itemId,
        'voided' => true,
        'approved_by' => $approvedBy,
        'new_total' => round($newTotal, 2)
    ]);
    
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Void item error: ' . $e->getMessage());
    die('{"success":false,"error":"Failed to void item"}');
}
?>