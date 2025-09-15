<?php
// /mohamedk10.sg-host.com/public_html/controllers/admin/orders/order_refund.php
// Handle order refunds and voids with loyalty/cashback revocation
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

// Input parameters
$orderId = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$mode = clean_string($_POST['mode'] ?? $_GET['mode'] ?? 'refund'); // 'refund' or 'void'
$reason = clean_string($_POST['reason'] ?? '');
$return = $_POST['return'] ?? $_GET['return'] ?? '/views/admin/orders/index.php';

// Validate mode
if (!in_array($mode, ['refund', 'void'], true)) {
    $_SESSION['flash'] = 'Invalid operation mode.';
    header('Location: ' . $return);
    exit;
}

// For POST requests, validate CSRF
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
    
    // Load order and verify access
    $stmt = $pdo->prepare("
        SELECT id, tenant_id, status, payment_status, total_amount, 
               customer_id, aggregator_id, is_deleted
        FROM orders 
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order || (int)$order['tenant_id'] !== $tenantId) {
        $_SESSION['flash'] = 'Order not found or access denied.';
        header('Location: ' . $return);
        exit;
    }
    
    if ($order['is_deleted']) {
        $_SESSION['flash'] = 'Cannot refund/void deleted orders.';
        header('Location: ' . $return);
        exit;
    }
    
    // Check current status
    if (in_array($order['status'], ['voided', 'refunded'], true)) {
        $_SESSION['flash'] = 'Order is already ' . $order['status'] . '.';
        header('Location: ' . $return);
        exit;
    }
    
    // For refunds, order should be closed/paid
    if ($mode === 'refund' && $order['payment_status'] !== 'paid') {
        $_SESSION['flash'] = 'Can only refund paid orders.';
        header('Location: ' . $return);
        exit;
    }
    
    $pdo->beginTransaction();
    
    // Update order status and payment status
    $newStatus = ($mode === 'refund') ? 'refunded' : 'voided';
    $sets = [
        "status = :status",
        "payment_status = 'voided'",
        "updated_at = NOW()"
    ];
    $params = [':status' => $newStatus, ':id' => $orderId];
    
    // Handle is_voided flag
    if (column_exists($pdo, 'orders', 'is_voided')) {
        $sets[] = "is_voided = 1";
    }
    
    $sql = "UPDATE orders SET " . implode(', ', $sets) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    // Update lifecycle timestamps
    if ($mode === 'void' && column_exists($pdo, 'orders', 'voided_at')) {
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
    
    // Revoke any cashback/loyalty rewards earned from this order
    $revokedAmount = 0;
    $revokedVouchers = [];
    
    try {
        // Find cashback entries for this order
        $stmt = $pdo->prepare("
            SELECT id, voucher_id, cash_delta, points_delta
            FROM loyalty_ledger
            WHERE tenant_id = :t 
            AND order_id = :o 
            AND type IN ('cashback_earn', 'points_earn')
        ");
        $stmt->execute([':t' => $tenantId, ':o' => $orderId]);
        $earnRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        foreach ($earnRows as $earn) {
            $voucherId = (int)($earn['voucher_id'] ?? 0);
            $cashDelta = (float)($earn['cash_delta'] ?? 0);
            $pointsDelta = (int)($earn['points_delta'] ?? 0);
            
            // Void associated voucher
            if ($voucherId > 0) {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE vouchers
                        SET status = 'void', 
                            uses_remaining = 0, 
                            updated_at = NOW()
                        WHERE tenant_id = :t 
                        AND id = :vid
                        LIMIT 1
                    ");
                    $stmt->execute([':t' => $tenantId, ':vid' => $voucherId]);
                    
                    if ($stmt->rowCount() > 0) {
                        $revokedVouchers[] = $voucherId;
                        $revokedAmount += $cashDelta;
                    }
                } catch (Throwable $e) {
                    // Continue even if voucher update fails
                    error_log('[refund] Failed to void voucher ' . $voucherId . ': ' . $e->getMessage());
                }
            }
            
            // Insert reversal ledger entry
            try {
                $reverseType = ($earn['type'] === 'cashback_earn') ? 'cashback_revoke' : 'points_revoke';
                $stmt = $pdo->prepare("
                    INSERT INTO loyalty_ledger (
                        tenant_id, program_id, customer_id, order_id, 
                        type, cash_delta, points_delta, voucher_id, 
                        reason, created_at
                    )
                    SELECT 
                        tenant_id, program_id, customer_id, order_id,
                        :type, :cash, :points, voucher_id,
                        :reason, NOW()
                    FROM loyalty_ledger
                    WHERE id = :srcid
                    LIMIT 1
                ");
                $stmt->execute([
                    ':type' => $reverseType,
                    ':cash' => -abs($cashDelta),
                    ':points' => -abs($pointsDelta),
                    ':reason' => $mode . '_order',
                    ':srcid' => $earn['id']
                ]);
            } catch (Throwable $e) {
                // Log but continue
                error_log('[refund] Failed to create reversal entry: ' . $e->getMessage());
            }
        }
        
        // Update customer points balance if applicable
        if ($order['customer_id'] && count($earnRows) > 0) {
            try {
                $stmt = $pdo->prepare("
                    UPDATE customers c
                    SET points_balance = GREATEST(0, points_balance - (
                        SELECT COALESCE(SUM(points_delta), 0)
                        FROM loyalty_ledger
                        WHERE customer_id = c.id 
                        AND order_id = :oid
                        AND type IN ('cashback_earn', 'points_earn')
                    ))
                    WHERE id = :cid
                ");
                $stmt->execute([':oid' => $orderId, ':cid' => $order['customer_id']]);
            } catch (Throwable $e) {
                // Non-critical, continue
                error_log('[refund] Failed to update customer points: ' . $e->getMessage());
            }
        }
    } catch (Throwable $e) {
        // Loyalty tables might not exist, continue without error
        error_log('[refund] Loyalty processing skipped: ' . $e->getMessage());
    }
    
    // Return stock to inventory if applicable
    if ($mode === 'refund' || $mode === 'void') {
        try {
            // Get all items from the order
            $stmt = $pdo->prepare("
                SELECT oi.*, p.is_inventory_tracked
                FROM order_items oi
                JOIN products p ON p.id = oi.product_id
                WHERE oi.order_id = :id
                AND p.is_inventory_tracked = 1
            ");
            $stmt->execute([':id' => $orderId]);
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($items as $item) {
                // Return stock
                $stmt = $pdo->prepare("
                    UPDATE stockflow_stock_levels
                    SET current_stock = current_stock + :qty,
                        last_movement_at = NOW(),
                        updated_at = NOW()
                    WHERE tenant_id = :t
                    AND branch_id = :b
                    AND product_id = :p
                ");
                $stmt->execute([
                    ':qty' => $item['quantity'],
                    ':t' => $tenantId,
                    ':b' => $order['branch_id'] ?? 1,
                    ':p' => $item['product_id']
                ]);
                
                // Log stock movement
                $stmt = $pdo->prepare("
                    INSERT INTO stockflow_stock_movements (
                        tenant_id, branch_id, product_id, movement_type,
                        quantity, reference_type, reference_id, notes,
                        created_by_user_id, movement_date, created_at
                    ) VALUES (
                        :t, :b, :p, 'return_in',
                        :qty, 'order', :ref, :notes,
                        :uid, NOW(), NOW()
                    )
                ");
                $stmt->execute([
                    ':t' => $tenantId,
                    ':b' => $order['branch_id'] ?? 1,
                    ':p' => $item['product_id'],
                    ':qty' => $item['quantity'],
                    ':ref' => $orderId,
                    ':notes' => ucfirst($mode) . ' order #' . $orderId,
                    ':uid' => $userId
                ]);
            }
        } catch (Throwable $e) {
            // Stock tables might not exist, continue
            error_log('[refund] Stock return skipped: ' . $e->getMessage());
        }
    }
    
    // Log the refund/void event
    log_order_event($pdo, $tenantId, $orderId, $mode, $userId, [
        'reason' => $reason ?: 'Manual ' . $mode,
        'previous_status' => $order['status'],
        'previous_payment' => $order['payment_status'],
        'total_amount' => $order['total_amount'],
        'revoked_vouchers' => $revokedVouchers,
        'revoked_amount' => $revokedAmount
    ]);
    
    $pdo->commit();
    
    // Build success message
    $message = 'Order #' . $orderId . ' has been ' . $newStatus . '.';
    if ($revokedAmount > 0) {
        $message .= ' Revoked cashback: ' . format_money($revokedAmount);
    }
    if (count($revokedVouchers) > 0) {
        $message .= ' (' . count($revokedVouchers) . ' voucher(s) voided)';
    }
    
    $_SESSION['flash'] = $message;
    
    // Return JSON for AJAX requests
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
        strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'message' => $message,
            'order_id' => $orderId,
            'new_status' => $newStatus,
            'revoked_amount' => $revokedAmount,
            'revoked_vouchers' => $revokedVouchers
        ]);
        exit;
    }
    
    header('Location: ' . $return);
    exit;
    
} catch (Throwable $e) {
    if (!empty($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $error = ucfirst($mode) . ' failed: ' . $e->getMessage();
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