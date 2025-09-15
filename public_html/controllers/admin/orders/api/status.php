<?php
// /mohamedk10.sg-host.com/public_html/controllers/admin/orders/api/status.php
// API endpoint for getting available status transitions and updating status
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/config/db.php';
require_once dirname(__DIR__, 4) . '/middleware/auth_login.php';
require_once __DIR__ . '/../_helpers.php';

auth_require_login();
use_backend_session();

header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user) {
    error_response('Unauthorized', 401);
}

$tenantId = (int)$user['tenant_id'];
$userId = (int)$user['id'];
$method = $_SERVER['REQUEST_METHOD'];

try {
    $pdo = db();
    
    if ($method === 'GET') {
        // Get available transitions for an order
        $orderId = (int)($_GET['order_id'] ?? 0);
        
        if ($orderId <= 0) {
            // Return all possible statuses and their transitions
            $allStatuses = [
                'open' => ['label' => 'Open', 'color' => 'warning'],
                'held' => ['label' => 'Held', 'color' => 'info'],
                'sent' => ['label' => 'Sent to Kitchen', 'color' => 'info'],
                'preparing' => ['label' => 'Preparing', 'color' => 'warning'],
                'ready' => ['label' => 'Ready', 'color' => 'success'],
                'served' => ['label' => 'Served', 'color' => 'success'],
                'closed' => ['label' => 'Closed', 'color' => 'success'],
                'cancelled' => ['label' => 'Cancelled', 'color' => 'danger'],
                'voided' => ['label' => 'Voided', 'color' => 'danger'],
                'refunded' => ['label' => 'Refunded', 'color' => 'danger']
            ];
            
            $transitions = [];
            foreach ($allStatuses as $status => $info) {
                $transitions[$status] = get_available_transitions($status);
            }
            
            success_response('', [
                'statuses' => $allStatuses,
                'transitions' => $transitions
            ]);
        }
        
        // Get specific order's current status and available transitions
        if (!ensure_tenant_access($pdo, $orderId, $tenantId)) {
            error_response('Order not found or access denied', 404);
        }
        
        $stmt = $pdo->prepare("
            SELECT status, payment_status, order_type, locked_by, payment_locked
            FROM orders 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $availableTransitions = get_available_transitions($order['status']);
        
        // Check if order is locked
        $isLocked = $order['payment_locked'] && $order['locked_by'] != $userId;
        
        success_response('', [
            'current_status' => $order['status'],
            'payment_status' => $order['payment_status'],
            'available_transitions' => $availableTransitions,
            'is_locked' => $isLocked,
            'locked_by' => $order['locked_by']
        ]);
        
    } elseif ($method === 'POST') {
        // Update order status
        $orderId = (int)($_POST['order_id'] ?? 0);
        $newStatus = clean_string($_POST['status'] ?? '');
        
        if ($orderId <= 0 || $newStatus === '') {
            error_response('Invalid parameters');
        }
        
        if (!ensure_tenant_access($pdo, $orderId, $tenantId)) {
            error_response('Order not found or access denied', 404);
        }
        
        // Get current order state
        $stmt = $pdo->prepare("
            SELECT status, payment_status, payment_locked, locked_by
            FROM orders 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Check if locked by another user
        if ($order['payment_locked'] && $order['locked_by'] != $userId) {
            error_response('Order is locked by another user', 423);
        }
        
        // Validate transition
        $availableTransitions = get_available_transitions($order['status']);
        if (!in_array($newStatus, $availableTransitions, true)) {
            error_response('Invalid status transition from ' . $order['status'] . ' to ' . $newStatus);
        }
        
        $pdo->beginTransaction();
        
        // Update status
        $sets = ["status = :status", "updated_at = NOW()"];
        $params = [':status' => $newStatus, ':id' => $orderId];
        
        // Handle is_voided flag
        if (column_exists($pdo, 'orders', 'is_voided')) {
            $sets[] = "is_voided = :is_voided";
            $params[':is_voided'] = ($newStatus === 'voided') ? 1 : 0;
        }
        
        // Handle payment status changes
        if ($newStatus === 'voided' || $newStatus === 'refunded') {
            $sets[] = "payment_status = 'voided'";
        }
        
        $sql = "UPDATE orders SET " . implode(', ', $sets) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        // Update lifecycle timestamps
        if ($newStatus === 'closed' && column_exists($pdo, 'orders', 'closed_at')) {
            $stmt = $pdo->prepare("UPDATE orders SET closed_at = COALESCE(closed_at, NOW()) WHERE id = :id");
            $stmt->execute([':id' => $orderId]);
        }
        
        if ($newStatus === 'voided' && column_exists($pdo, 'orders', 'voided_at')) {
            $sql = "UPDATE orders SET voided_at = COALESCE(voided_at, NOW())";
            $params = [':id' => $orderId];
            
            if (column_exists($pdo, 'orders', 'voided_by_user_id')) {
                $sql .= ", voided_by_user_id = COALESCE(voided_by_user_id, :uid)";
                $params[':uid'] = $userId;
            }
            
            $sql .= " WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
        }
        
        // Log event
        log_order_event($pdo, $tenantId, $orderId, 'status_change', $userId, [
            'from' => $order['status'],
            'to' => $newStatus
        ]);
        
        // Handle hooks for closed status
        if ($newStatus === 'closed' && $order['status'] !== 'closed') {
            // Rewards hook
            $rewards_path = dirname(__DIR__, 3) . '/rewards/engine/_apply_on_closed.php';
            if (is_file($rewards_path)) {
                require_once $rewards_path;
                if (function_exists('rewards_apply_on_order_closed')) {
                    try {
                        rewards_apply_on_order_closed($pdo, $tenantId, $orderId);
                    } catch (Throwable $e) {
                        error_log('[rewards] Failed for order ' . $orderId . ': ' . $e->getMessage());
                    }
                }
            }
            
            // Stockflow hook
            $stockflow_path = dirname(__DIR__, 3) . '/stockflow/engine/apply_order_deductions.php';
            if (is_file($stockflow_path)) {
                require_once $stockflow_path;
                if (function_exists('stockflow_apply_on_order_close')) {
                    try {
                        stockflow_apply_on_order_close($pdo, $tenantId, $orderId, $userId);
                    } catch (Throwable $e) {
                        error_log('[stockflow] Failed for order ' . $orderId . ': ' . $e->getMessage());
                    }
                }
            }
        }
        
        $pdo->commit();
        
        success_response('Status updated successfully', [
            'order_id' => $orderId,
            'new_status' => $newStatus,
            'available_transitions' => get_available_transitions($newStatus)
        ]);
        
    } else {
        error_response('Method not allowed', 405);
    }
    
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_response('Operation failed: ' . $e->getMessage(), 500);
}