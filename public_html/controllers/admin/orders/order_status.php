<?php
// /mohamedk10.sg-host.com/public_html/controllers/admin/orders/order_status.php
// Lightweight endpoint for changing order status
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/middleware/auth_login.php';
require_once __DIR__ . '/_helpers.php';

auth_require_login();
use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) {
    header('Location: /views/auth/login.php');
    exit;
}

$tenantId = (int)$user['tenant_id'];
$userId = (int)$user['id'];

// Accept both GET and POST
$orderId = (int)($_REQUEST['id'] ?? $_REQUEST['order_id'] ?? 0);
$newStatus = clean_string($_REQUEST['status'] ?? '');
$return = $_REQUEST['return'] ?? '/views/admin/orders/index.php';

// Validate input
if ($orderId <= 0 || $newStatus === '') {
    $_SESSION['flash'] = 'Invalid request parameters.';
    header('Location: ' . $return);
    exit;
}

$allowedStatuses = ['open', 'held', 'sent', 'preparing', 'ready', 'served', 'closed', 'voided', 'cancelled', 'refunded'];
if (!in_array($newStatus, $allowedStatuses, true)) {
    $_SESSION['flash'] = 'Invalid status: ' . $newStatus;
    header('Location: ' . $return);
    exit;
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Load order and verify access
    $stmt = $pdo->prepare("
        SELECT id, tenant_id, status, payment_status, order_type, aggregator_id
        FROM orders 
        WHERE id = :id AND is_deleted = 0
        LIMIT 1
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order || (int)$order['tenant_id'] !== $tenantId) {
        $_SESSION['flash'] = 'Order not found or access denied.';
        header('Location: ' . $return);
        exit;
    }
    
    $previousStatus = $order['status'];
    
    // Check if status change is valid
    if ($previousStatus === $newStatus) {
        $_SESSION['flash'] = 'Status unchanged.';
        header('Location: ' . $return);
        exit;
    }
    
    // Validate transition
    $availableTransitions = get_available_transitions($previousStatus);
    if (!in_array($newStatus, $availableTransitions, true)) {
        $_SESSION['flash'] = 'Invalid status transition from ' . $previousStatus . ' to ' . $newStatus;
        header('Location: ' . $return);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Build update query
    $sets = ["status = :status", "updated_at = NOW()"];
    $params = [':status' => $newStatus, ':id' => $orderId];
    
    // Handle is_voided flag
    if (column_exists($pdo, 'orders', 'is_voided')) {
        $sets[] = "is_voided = :is_voided";
        $params[':is_voided'] = ($newStatus === 'voided') ? 1 : 0;
    }
    
    // Update payment status for certain transitions
    if ($newStatus === 'voided' || $newStatus === 'refunded') {
        $sets[] = "payment_status = 'voided'";
    }
    
    $sql = "UPDATE orders SET " . implode(', ', $sets) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Handle lifecycle timestamps
    if ($newStatus === 'closed' && column_exists($pdo, 'orders', 'closed_at')) {
        $stmt = $pdo->prepare("UPDATE orders SET closed_at = COALESCE(closed_at, NOW()) WHERE id = :id");
        $stmt->execute([':id' => $orderId]);
    }
    
    if ($newStatus === 'voided') {
        if (column_exists($pdo, 'orders', 'voided_at')) {
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
    }
    
    // Log the status change
    log_order_event($pdo, $tenantId, $orderId, 'status_change', $userId, [
        'from' => $previousStatus,
        'to' => $newStatus
    ]);
    
    $flashMessage = 'Order status updated to ' . ucfirst($newStatus) . '.';
    
    // Handle transition to closed
    if ($newStatus === 'closed' && $previousStatus !== 'closed') {
        // Rewards integration
        $rewards_path = dirname(__DIR__, 2) . '/rewards/engine/_apply_on_closed.php';
        if (is_file($rewards_path)) {
            require_once $rewards_path;
            if (function_exists('rewards_apply_on_order_closed')) {
                try {
                    $result = rewards_apply_on_order_closed($pdo, $tenantId, $orderId);
                    if (!empty($result['notes'])) {
                        $flashMessage .= ' Rewards: ' . implode('; ', $result['notes']);
                    }
                } catch (Throwable $e) {
                    error_log('[rewards] Failed for order ' . $orderId . ': ' . $e->getMessage());
                }
            }
        }
        
        // Stockflow integration
        $stockflow_path = dirname(__DIR__, 2) . '/stockflow/engine/apply_order_deductions.php';
        if (is_file($stockflow_path)) {
            require_once $stockflow_path;
            if (function_exists('stockflow_apply_on_order_close')) {
                try {
                    $result = stockflow_apply_on_order_close($pdo, $tenantId, $orderId, $userId);
                    if (!empty($result['notes'])) {
                        $flashMessage .= ' Stock: ' . implode('; ', $result['notes']);
                    }
                } catch (Throwable $e) {
                    error_log('[stockflow] Failed for order ' . $orderId . ': ' . $e->getMessage());
                }
            }
        }
    }
    
    // Handle transition to sent (fire to kitchen)
    if ($newStatus === 'sent' && $previousStatus !== 'sent') {
        // Auto-fire held items
        $stmt = $pdo->prepare("
            UPDATE order_items 
            SET state = 'fired', 
                fired_at = NOW(), 
                updated_at = NOW()
            WHERE order_id = :id 
            AND state = 'held'
        ");
        $stmt->execute([':id' => $orderId]);
        $firedCount = $stmt->rowCount();
        
        if ($firedCount > 0) {
            $flashMessage .= ' ' . $firedCount . ' items sent to kitchen.';
            log_order_event($pdo, $tenantId, $orderId, 'fire', $userId, [
                'auto_fire' => true,
                'count' => $firedCount
            ]);
        }
    }
    
    $pdo->commit();
    
    $_SESSION['flash'] = $flashMessage;
    
    // Return JSON for AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $flashMessage,
            'new_status' => $newStatus,
            'available_transitions' => get_available_transitions($newStatus)
        ]);
        exit;
    }
    
    header('Location: ' . $return);
    exit;
    
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $error = 'Status update failed: ' . $e->getMessage();
    $_SESSION['flash'] = $error;
    
    // Return JSON error for AJAX
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => $error
        ]);
        exit;
    }
    
    header('Location: ' . $return);
    exit;
}