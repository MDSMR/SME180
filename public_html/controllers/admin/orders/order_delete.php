<?php
// /mohamedk10.sg-host.com/public_html/controllers/admin/orders/order_delete.php
// Soft delete order functionality
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

// Check user role - only managers and admins can delete
$allowedRoles = ['admin', 'manager', 'pos_manager', 'director'];
if (!in_array($user['role_key'] ?? '', $allowedRoles, true)) {
    $_SESSION['flash'] = 'Permission denied. Only managers can delete orders.';
    header('Location: /views/admin/orders/index.php');
    exit;
}

// Get order ID
$orderId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$return = $_POST['return'] ?? $_GET['return'] ?? '/views/admin/orders/index.php';

// For POST, validate CSRF
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validate_csrf('csrf_orders')) {
        $_SESSION['flash'] = 'Invalid request. Please try again.';
        header('Location: ' . $return);
        exit;
    }
}

if ($orderId <= 0) {
    $_SESSION['flash'] = 'Invalid order specified.';
    header('Location: ' . $return);
    exit;
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check tenant access and get order details
    $stmt = $pdo->prepare("
        SELECT id, status, payment_status, total_amount, is_deleted
        FROM orders 
        WHERE id = :id AND tenant_id = :t
        LIMIT 1
    ");
    $stmt->execute([':id' => $orderId, ':t' => $tenantId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $_SESSION['flash'] = 'Order not found.';
        header('Location: ' . $return);
        exit;
    }
    
    if ($order['is_deleted']) {
        $_SESSION['flash'] = 'Order is already deleted.';
        header('Location: ' . $return);
        exit;
    }
    
    // Check if order can be deleted (not closed or paid)
    $restrictedStatuses = ['closed', 'served'];
    $restrictedPayments = ['paid', 'partial'];
    
    if (in_array($order['status'], $restrictedStatuses, true)) {
        $_SESSION['flash'] = 'Cannot delete ' . $order['status'] . ' orders. Please void or cancel instead.';
        header('Location: ' . $return);
        exit;
    }
    
    if (in_array($order['payment_status'], $restrictedPayments, true)) {
        $_SESSION['flash'] = 'Cannot delete orders with payments. Please refund first.';
        header('Location: ' . $return);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Soft delete the order
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET is_deleted = 1,
            deleted_at = NOW(),
            deleted_by = :uid,
            updated_at = NOW()
        WHERE id = :id
    ");
    $stmt->execute([':uid' => $userId, ':id' => $orderId]);
    
    // Log the deletion event
    log_order_event($pdo, $tenantId, $orderId, 'delete', $userId, [
        'reason' => $_POST['reason'] ?? 'Manual deletion',
        'previous_status' => $order['status'],
        'total_amount' => $order['total_amount']
    ]);
    
    // If order had a table, free it
    $stmt = $pdo->prepare("
        UPDATE dining_tables dt
        JOIN orders o ON o.table_id = dt.id
        SET dt.status = 'free'
        WHERE o.id = :id
    ");
    $stmt->execute([':id' => $orderId]);
    
    $pdo->commit();
    
    $_SESSION['flash'] = 'Order #' . $orderId . ' has been deleted.';
    header('Location: ' . $return);
    exit;
    
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $_SESSION['flash'] = 'Failed to delete order: ' . $e->getMessage();
    header('Location: ' . $return);
    exit;
}