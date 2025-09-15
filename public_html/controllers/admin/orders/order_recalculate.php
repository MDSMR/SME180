<?php
// /mohamedk10.sg-host.com/public_html/controllers/admin/orders/order_recalculate.php
// Recalculate order totals based on current items and settings
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

// Accept both POST and GET for flexibility
$orderId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$return = $_POST['return'] ?? $_GET['return'] ?? '/views/admin/orders/view.php?id=' . $orderId;

if ($orderId <= 0) {
    $_SESSION['flash'] = 'Invalid order specified.';
    header('Location: /views/admin/orders/index.php');
    exit;
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Verify tenant access
    if (!ensure_tenant_access($pdo, $orderId, $tenantId)) {
        $_SESSION['flash'] = 'Order not found or access denied.';
        header('Location: /views/admin/orders/index.php');
        exit;
    }
    
    // Load order details
    $stmt = $pdo->prepare("
        SELECT 
            o.*,
            b.name as branch_name
        FROM orders o
        LEFT JOIN branches b ON b.id = o.branch_id
        WHERE o.id = :id
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        $_SESSION['flash'] = 'Order not found.';
        header('Location: /views/admin/orders/index.php');
        exit;
    }
    
    // Check if order can be modified
    if (!can_modify_order($order)) {
        $_SESSION['flash'] = 'Cannot recalculate totals for ' . $order['status'] . ' orders.';
        header('Location: ' . $return);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Calculate totals using helper function
    $totals = calculate_order_totals($pdo, $orderId, $tenantId);
    
    // Update order with new totals
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET subtotal_amount = :sub,
            discount_amount = :disc,
            tax_percent = :tax_p,
            tax_amount = :tax_a,
            service_percent = :svc_p,
            service_amount = :svc_a,
            commission_percent = :comm_p,
            commission_amount = :comm_a,
            commission_total_amount = :comm_a,
            total_amount = :total,
            updated_at = NOW()
        WHERE id = :id
    ");
    
    $stmt->execute([
        ':sub' => $totals['subtotal_amount'],
        ':disc' => $totals['discount_amount'],
        ':tax_p' => $totals['tax_percent'],
        ':tax_a' => $totals['tax_amount'],
        ':svc_p' => $totals['service_percent'],
        ':svc_a' => $totals['service_amount'],
        ':comm_p' => $totals['commission_percent'],
        ':comm_a' => $totals['commission_amount'],
        ':total' => $totals['total_amount'],
        ':id' => $orderId
    ]);
    
    // Log the recalculation event
    log_order_event($pdo, $tenantId, $orderId, 'recalculate', $userId, [
        'old_total' => $order['total_amount'],
        'new_total' => $totals['total_amount'],
        'difference' => round($totals['total_amount'] - (float)$order['total_amount'], 3)
    ]);
    
    $pdo->commit();
    
    // Prepare success message with details
    $message = sprintf(
        'Totals recalculated. Subtotal: %s, Tax: %s, Service: %s, Total: %s',
        format_money($totals['subtotal_amount']),
        format_money($totals['tax_amount']),
        format_money($totals['service_amount']),
        format_money($totals['total_amount'])
    );
    
    $_SESSION['flash'] = $message;
    
    // Return JSON for AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'totals' => $totals
        ]);
        exit;
    }
    
    // Regular redirect
    header('Location: ' . $return . '&msg=' . urlencode($message));
    exit;
    
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $error = 'Recalculation failed: ' . $e->getMessage();
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
    
    header('Location: ' . $return . '&err=' . urlencode($error));
    exit;
}