<?php
// /mohamedk10.sg-host.com/public_html/controllers/admin/orders/order_lock.php
// Payment lock management for concurrent access control
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/middleware/auth_login.php';
require_once __DIR__ . '/_helpers.php';

auth_require_login();
use_backend_session();

header('Content-Type: application/json');

$user = $_SESSION['user'] ?? null;
if (!$user) {
    error_response('Unauthorized', 401);
}

$tenantId = (int)$user['tenant_id'];
$userId = (int)$user['id'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$orderId = (int)($_POST['order_id'] ?? $_GET['order_id'] ?? 0);

if ($orderId <= 0) {
    error_response('Invalid order ID');
}

try {
    $pdo = db();
    
    // Verify tenant access
    if (!ensure_tenant_access($pdo, $orderId, $tenantId)) {
        error_response('Order not found or access denied', 404);
    }
    
    switch ($action) {
        case 'acquire':
            // Try to acquire lock
            $timeout = (int)($_POST['timeout'] ?? 120); // Default 2 minutes
            $timeout = min(max($timeout, 30), 600); // Between 30 seconds and 10 minutes
            
            if (acquire_order_lock($pdo, $orderId, $userId, $timeout)) {
                // Log lock acquisition
                log_order_event($pdo, $tenantId, $orderId, 'payment_lock', $userId);
                
                success_response('Lock acquired', [
                    'order_id' => $orderId,
                    'locked_by' => $userId,
                    'timeout_seconds' => $timeout,
                    'expires_at' => time() + $timeout
                ]);
            } else {
                // Get current lock info
                $stmt = $pdo->prepare("
                    SELECT locked_by, locked_at, payment_locked,
                           u.name as locked_by_name
                    FROM orders o
                    LEFT JOIN users u ON u.id = o.locked_by
                    WHERE o.id = :id
                ");
                $stmt->execute([':id' => $orderId]);
                $lock = $stmt->fetch(PDO::FETCH_ASSOC);
                
                error_response('Order is locked by another user', 423, [
                    'locked_by' => $lock['locked_by'],
                    'locked_by_name' => $lock['locked_by_name'],
                    'locked_at' => $lock['locked_at']
                ]);
            }
            break;
            
        case 'release':
            // Release lock (only if owned by current user)
            if (release_order_lock($pdo, $orderId, $userId)) {
                // Log lock release
                log_order_event($pdo, $tenantId, $orderId, 'payment_unlock', $userId);
                
                success_response('Lock released', [
                    'order_id' => $orderId
                ]);
            } else {
                error_response('Could not release lock. You may not own this lock.');
            }
            break;
            
        case 'check':
            // Check lock status
            $stmt = $pdo->prepare("
                SELECT 
                    locked_by, 
                    locked_at, 
                    payment_locked,
                    lock_seq,
                    u.name as locked_by_name,
                    TIMESTAMPDIFF(SECOND, locked_at, NOW()) as locked_seconds
                FROM orders o
                LEFT JOIN users u ON u.id = o.locked_by
                WHERE o.id = :id
            ");
            $stmt->execute([':id' => $orderId]);
            $lock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$lock) {
                error_response('Order not found', 404);
            }
            
            // Check if lock is expired (default 120 seconds timeout)
            $lockTimeout = (int)get_setting($pdo, $tenantId, 'pos.payment_lock_timeout_sec', '120');
            $isExpired = $lock['payment_locked'] && $lock['locked_seconds'] > $lockTimeout;
            
            success_response('', [
                'is_locked' => (bool)$lock['payment_locked'] && !$isExpired,
                'locked_by' => $lock['locked_by'],
                'locked_by_name' => $lock['locked_by_name'],
                'locked_at' => $lock['locked_at'],
                'locked_seconds' => (int)$lock['locked_seconds'],
                'lock_seq' => (int)$lock['lock_seq'],
                'is_expired' => $isExpired,
                'is_mine' => $lock['locked_by'] == $userId
            ]);
            break;
            
        case 'force_release':
            // Force release (managers only)
            $allowedRoles = ['admin', 'manager', 'pos_manager'];
            if (!in_array($user['role_key'] ?? '', $allowedRoles, true)) {
                error_response('Permission denied. Only managers can force release locks.', 403);
            }
            
            // Get current lock owner info for logging
            $stmt = $pdo->prepare("
                SELECT locked_by, u.name as locked_by_name
                FROM orders o
                LEFT JOIN users u ON u.id = o.locked_by
                WHERE o.id = :id AND o.payment_locked = 1
            ");
            $stmt->execute([':id' => $orderId]);
            $prevLock = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Force release
            $stmt = $pdo->prepare("
                UPDATE orders 
                SET locked_by = NULL, 
                    locked_at = NULL, 
                    payment_locked = 0 
                WHERE id = :id
            ");
            $stmt->execute([':id' => $orderId]);
            
            // Log force release
            log_order_event($pdo, $tenantId, $orderId, 'payment_unlock', $userId, [
                'forced' => true,
                'previous_owner' => $prevLock['locked_by'] ?? null,
                'previous_owner_name' => $prevLock['locked_by_name'] ?? null
            ]);
            
            success_response('Lock forcefully released', [
                'order_id' => $orderId,
                'previous_owner' => $prevLock['locked_by'] ?? null
            ]);
            break;
            
        default:
            error_response('Invalid action');
    }
    
} catch (Throwable $e) {
    error_response('Operation failed: ' . $e->getMessage(), 500);
}