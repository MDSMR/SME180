<?php
// /mohamedk10.sg-host.com/public_html/controllers/admin/orders/_helpers.php
// Shared helper functions for order controllers
declare(strict_types=1);

/**
 * Check if a database column exists
 */
function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = :table 
            AND COLUMN_NAME = :column
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Validate enum value against allowed list
 */
function validate_enum(string $value, array $allowed, string $default = ''): string {
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * Format money for display (3 decimals for KWD/similar currencies)
 */
function format_money(float $amount, int $decimals = 3): string {
    return number_format($amount, $decimals, '.', '');
}

/**
 * Get tenant setting value
 */
function get_setting(PDO $pdo, int $tenantId, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT `value` FROM settings WHERE tenant_id = :t AND `key` = :k LIMIT 1");
        $stmt->execute([':t' => $tenantId, ':k' => $key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string)$value : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

/**
 * Ensure user has access to tenant's order
 */
function ensure_tenant_access(PDO $pdo, int $orderId, int $tenantId): bool {
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM orders WHERE id = :id AND tenant_id = :t AND is_deleted = 0 LIMIT 1");
        $stmt->execute([':id' => $orderId, ':t' => $tenantId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Calculate order totals based on items and settings (updated for line discounts)
 */
function calculate_order_totals(PDO $pdo, int $orderId, int $tenantId): array {
    // Get items subtotal with line discounts
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(line_subtotal), 0) as items_subtotal,
            COALESCE(SUM(discount_amount), 0) as items_discount_total,
            COALESCE(SUM(line_total), 0) as items_total
        FROM order_items 
        WHERE order_id = :id
    ");
    $stmt->execute([':id' => $orderId]);
    $itemsData = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $itemsSubtotal = (float)($itemsData['items_subtotal'] ?? 0);
    $itemsDiscountTotal = (float)($itemsData['items_discount_total'] ?? 0);
    $itemsTotal = (float)($itemsData['items_total'] ?? 0);
    
    // If line_total is zero, calculate from subtotal - discount
    if ($itemsTotal == 0 && $itemsSubtotal > 0) {
        $itemsTotal = $itemsSubtotal - $itemsDiscountTotal;
    }
    
    // Get order-level discount total from discount rows
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount_applied), 0) FROM order_discounts WHERE order_id = :id");
    $stmt->execute([':id' => $orderId]);
    $orderDiscount = (float)$stmt->fetchColumn();
    
    // Get order info for order type
    $stmt = $pdo->prepare("SELECT order_type, aggregator_id FROM orders WHERE id = :id");
    $stmt->execute([':id' => $orderId]);
    $orderInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Get tax and service percentages based on order type
    $taxPercent = (float)get_setting($pdo, $tenantId, 'tax_percent', '0');
    $servicePercent = 0;
    if ($orderInfo && $orderInfo['order_type'] === 'dine_in') {
        $servicePercent = (float)get_setting($pdo, $tenantId, 'service_percent', '0');
    }
    
    // Calculate base (after all discounts)
    $base = max(0, $itemsTotal - $orderDiscount);
    
    // Calculate tax and service
    $taxAmount = round($base * ($taxPercent / 100), 3);
    $serviceAmount = round($base * ($servicePercent / 100), 3);
    
    // Get commission if aggregator order
    $commissionPercent = 0;
    $commissionAmount = 0;
    if ($orderInfo && $orderInfo['aggregator_id']) {
        $stmt = $pdo->prepare("
            SELECT default_commission_percent 
            FROM aggregators 
            WHERE id = :id AND tenant_id = :t AND is_active = 1
        ");
        $stmt->execute([':id' => $orderInfo['aggregator_id'], ':t' => $tenantId]);
        $commissionPercent = (float)($stmt->fetchColumn() ?: 0);
        $commissionAmount = round(($base + $taxAmount + $serviceAmount) * ($commissionPercent / 100), 3);
    }
    
    // Total
    $total = round($base + $taxAmount + $serviceAmount + $commissionAmount, 3);
    
    return [
        'subtotal_amount' => $itemsSubtotal,
        'discount_amount' => $orderDiscount + $itemsDiscountTotal,
        'tax_percent' => $taxPercent,
        'tax_amount' => $taxAmount,
        'service_percent' => $servicePercent,
        'service_amount' => $serviceAmount,
        'commission_percent' => $commissionPercent,
        'commission_amount' => $commissionAmount,
        'total_amount' => $total,
        'items_discount_total' => $itemsDiscountTotal
    ];
}

/**
 * Log order event for audit trail
 */
function log_order_event(PDO $pdo, int $tenantId, int $orderId, string $eventType, ?int $userId = null, ?array $payload = null): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO order_item_events (tenant_id, order_id, event_type, created_by, payload, created_at)
            VALUES (:t, :o, :e, :u, :p, NOW())
        ");
        $stmt->execute([
            ':t' => $tenantId,
            ':o' => $orderId,
            ':e' => $eventType,
            ':u' => $userId,
            ':p' => $payload ? json_encode($payload) : null
        ]);
    } catch (Throwable $e) {
        error_log('[order_event] Failed to log: ' . $e->getMessage());
    }
}

/**
 * Get available status transitions based on current status
 */
function get_available_transitions(string $currentStatus): array {
    $transitions = [
        'open' => ['held', 'sent', 'preparing', 'closed', 'cancelled', 'voided'],
        'held' => ['open', 'sent', 'cancelled', 'voided'],
        'sent' => ['preparing', 'ready', 'cancelled', 'voided'],
        'preparing' => ['ready', 'served', 'cancelled', 'voided'],
        'ready' => ['served', 'closed', 'voided'],
        'served' => ['closed', 'voided'],
        'closed' => ['refunded', 'voided'],
        'cancelled' => [],
        'voided' => [],
        'refunded' => []
    ];
    
    return $transitions[$currentStatus] ?? [];
}

/**
 * Acquire order lock for payment processing
 */
function acquire_order_lock(PDO $pdo, int $orderId, int $userId, int $timeoutSeconds = 120): bool {
    try {
        $pdo->beginTransaction();
        
        // Check if already locked
        $stmt = $pdo->prepare("
            SELECT locked_by, locked_at, payment_locked 
            FROM orders 
            WHERE id = :id 
            FOR UPDATE
        ");
        $stmt->execute([':id' => $orderId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            $pdo->rollBack();
            return false;
        }
        
        $now = time();
        $lockedAt = $order['locked_at'] ? strtotime($order['locked_at']) : 0;
        $lockExpired = ($now - $lockedAt) > $timeoutSeconds;
        
        // If locked by someone else and not expired, fail
        if ($order['payment_locked'] && $order['locked_by'] != $userId && !$lockExpired) {
            $pdo->rollBack();
            return false;
        }
        
        // Acquire lock
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET locked_by = :uid, 
                locked_at = NOW(), 
                payment_locked = 1,
                lock_seq = lock_seq + 1
            WHERE id = :id
        ");
        $stmt->execute([':uid' => $userId, ':id' => $orderId]);
        
        $pdo->commit();
        return true;
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return false;
    }
}

/**
 * Release order lock
 */
function release_order_lock(PDO $pdo, int $orderId, int $userId): bool {
    try {
        $stmt = $pdo->prepare("
            UPDATE orders 
            SET locked_by = NULL, 
                locked_at = NULL, 
                payment_locked = 0 
            WHERE id = :id 
            AND locked_by = :uid
        ");
        $stmt->execute([':id' => $orderId, ':uid' => $userId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Check if order can be modified
 */
function can_modify_order(array $order): bool {
    $finalStatuses = ['closed', 'cancelled', 'voided', 'refunded'];
    return !in_array($order['status'], $finalStatuses, true);
}

/**
 * Clean string input
 */
function clean_string(?string $value): string {
    return trim((string)$value);
}

/**
 * Parse numeric input (handles currency symbols)
 */
function parse_money(?string $value): float {
    $clean = preg_replace('/[^\d.\-]/', '', (string)$value);
    if ($clean === '' || !is_numeric($clean)) {
        return 0.0;
    }
    return (float)$clean;
}

/**
 * Create CSRF token if not exists
 */
function ensure_csrf_token(string $key = 'csrf_orders'): string {
    if (empty($_SESSION[$key])) {
        $_SESSION[$key] = bin2hex(random_bytes(32));
    }
    return $_SESSION[$key];
}

/**
 * Validate CSRF token
 */
function validate_csrf(string $key = 'csrf_orders'): bool {
    $token = $_POST['csrf'] ?? $_GET['csrf'] ?? '';
    return !empty($_SESSION[$key]) && hash_equals($_SESSION[$key], $token);
}

/**
 * Standard JSON response
 */
function json_response(bool $success, string $message = '', array $data = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data,
        'timestamp' => time()
    ]);
    exit;
}

/**
 * Standard error response
 */
function error_response(string $message, int $httpCode = 400): void {
    json_response(false, $message, [], $httpCode);
}

/**
 * Standard success response
 */
function success_response(string $message = '', array $data = []): void {
    json_response(true, $message, $data, 200);
}

/**
 * Get aggregator commission rate
 */
function get_aggregator_commission(PDO $pdo, int $tenantId, int $aggregatorId): float {
    try {
        $stmt = $pdo->prepare("
            SELECT default_commission_percent 
            FROM aggregators 
            WHERE id = :id AND tenant_id = :t AND is_active = 1
        ");
        $stmt->execute([':id' => $aggregatorId, ':t' => $tenantId]);
        return (float)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0.0;
    }
}