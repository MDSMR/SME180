## 2. /public_html/pos/api/shifts/close.php
```php
<?php
/**
 * SME 180 POS - Shift Close API
 * Path: /public_html/pos/api/shifts/close.php
 * 
 * Closes the current shift and generates summary
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

pos_auth_require_login();
$user = pos_get_current_user();

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);
$shiftId = (int)($_SESSION['shift_id'] ?? 0);

if (!$tenantId || !$branchId || !$userId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
$notes = $input['notes'] ?? '';

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Get shift data
    if (!$shiftId && isset($input['shift_id'])) {
        $shiftId = (int)$input['shift_id'];
    }
    
    $stmt = $pdo->prepare("
        SELECT * FROM pos_shifts 
        WHERE id = :id 
        AND tenant_id = :tenant_id 
        AND status = 'open'
        FOR UPDATE
    ");
    $stmt->execute([
        'id' => $shiftId,
        'tenant_id' => $tenantId
    ]);
    
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shift) {
        json_response(['success' => false, 'error' => 'No open shift found'], 404);
    }
    
    // Calculate shift totals
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as order_count,
            COUNT(DISTINCT o.customer_id) as customer_count,
            COALESCE(SUM(o.total_amount), 0) as total_sales,
            COALESCE(SUM(o.refunded_amount), 0) as total_refunds,
            COALESCE(SUM(o.discount_amount), 0) as total_discounts,
            COALESCE(SUM(o.tip_amount), 0) as total_tips,
            COALESCE(SUM(o.service_charge_amount), 0) as total_service_charge
        FROM orders o
        WHERE o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND o.created_at BETWEEN :started_at AND NOW()
        AND o.payment_status IN ('paid', 'partial')
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'started_at' => $shift['started_at']
    ]);
    
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check for open cash sessions
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as open_sessions
        FROM cash_sessions 
        WHERE shift_id = :shift_id 
        AND status = 'open'
    ");
    $stmt->execute(['shift_id' => $shiftId]);
    $openSessions = $stmt->fetchColumn();
    
    if ($openSessions > 0) {
        json_response([
            'success' => false, 
            'error' => 'Cannot close shift with open cash sessions',
            'open_sessions' => (int)$openSessions
        ], 400);
    }
    
    // Update shift
    $stmt = $pdo->prepare("
        UPDATE pos_shifts 
        SET ended_at = NOW(),
            ended_by = :ended_by,
            total_sales = :total_sales,
            total_refunds = :total_refunds,
            total_discounts = :total_discounts,
            total_tips = :total_tips,
            total_service_charge = :total_service_charge,
            order_count = :order_count,
            customer_count = :customer_count,
            status = 'closed',
            notes = :notes
        WHERE id = :id
    ");
    $stmt->execute([
        'ended_by' => $userId,
        'total_sales' => $totals['total_sales'],
        'total_refunds' => $totals['total_refunds'],
        'total_discounts' => $totals['total_discounts'],
        'total_tips' => $totals['total_tips'],
        'total_service_charge' => $totals['total_service_charge'],
        'order_count' => $totals['order_count'],
        'customer_count' => $totals['customer_count'],
        'notes' => $notes,
        'id' => $shiftId
    ]);
    
    // Clear session
    unset($_SESSION['shift_id']);
    unset($_SESSION['shift_number']);
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'shift' => [
            'id' => $shiftId,
            'closed_at' => date('Y-m-d H:i:s'),
            'totals' => $totals
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Shift close error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to close shift'], 500);
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
