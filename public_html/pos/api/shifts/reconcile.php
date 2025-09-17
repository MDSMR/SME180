## 4. /public_html/pos/api/shifts/reconcile.php
```php
<?php
/**
 * SME 180 POS - Shift Reconciliation API
 * Path: /public_html/pos/api/shifts/reconcile.php
 * 
 * Reconciles shift totals with actual cash and payments
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

pos_auth_require_login();
$user = pos_get_current_user();

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if (!$tenantId || !$branchId || !$userId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['shift_id'])) {
    json_response(['success' => false, 'error' => 'Shift ID is required'], 400);
}

$shiftId = (int)$input['shift_id'];
$actualCash = (float)($input['actual_cash'] ?? 0);
$actualCard = (float)($input['actual_card'] ?? 0);
$actualOther = (float)($input['actual_other'] ?? 0);
$notes = $input['notes'] ?? '';

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Get shift
    $stmt = $pdo->prepare("
        SELECT * FROM pos_shifts 
        WHERE id = :id 
        AND tenant_id = :tenant_id 
        AND status = 'closed'
        FOR UPDATE
    ");
    $stmt->execute([
        'id' => $shiftId,
        'tenant_id' => $tenantId
    ]);
    
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$shift) {
        json_response(['success' => false, 'error' => 'Shift not found or not closed'], 404);
    }
    
    // Calculate expected amounts
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN op.payment_method = 'cash' THEN op.amount ELSE 0 END) as expected_cash,
            SUM(CASE WHEN op.payment_method = 'card' THEN op.amount ELSE 0 END) as expected_card,
            SUM(CASE WHEN op.payment_method NOT IN ('cash', 'card') THEN op.amount ELSE 0 END) as expected_other
        FROM order_payments op
        JOIN orders o ON o.id = op.order_id
        WHERE o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND op.created_at BETWEEN :started_at AND :ended_at
        AND op.status = 'completed'
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'started_at' => $shift['started_at'],
        'ended_at' => $shift['ended_at']
    ]);
    
    $expected = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $cashVariance = $actualCash - (float)$expected['expected_cash'];
    $cardVariance = $actualCard - (float)$expected['expected_card'];
    $otherVariance = $actualOther - (float)$expected['expected_other'];
    $totalVariance = $cashVariance + $cardVariance + $otherVariance;
    
    // Update shift with reconciliation
    $stmt = $pdo->prepare("
        UPDATE pos_shifts 
        SET status = 'reconciled',
            reconciled_at = NOW(),
            reconciled_by = :user_id,
            actual_cash = :actual_cash,
            actual_card = :actual_card,
            actual_other = :actual_other,
            cash_variance = :cash_variance,
            card_variance = :card_variance,
            other_variance = :other_variance,
            total_variance = :total_variance,
            reconciliation_notes = :notes
        WHERE id = :id
    ");
    $stmt->execute([
        'user_id' => $userId,
        'actual_cash' => $actualCash,
        'actual_card' => $actualCard,
        'actual_other' => $actualOther,
        'cash_variance' => $cashVariance,
        'card_variance' => $cardVariance,
        'other_variance' => $otherVariance,
        'total_variance' => $totalVariance,
        'notes' => $notes,
        'id' => $shiftId
    ]);
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'reconciliation' => [
            'shift_id' => $shiftId,
            'expected' => [
                'cash' => round((float)$expected['expected_cash'], 2),
                'card' => round((float)$expected['expected_card'], 2),
                'other' => round((float)$expected['expected_other'], 2)
            ],
            'actual' => [
                'cash' => round($actualCash, 2),
                'card' => round($actualCard, 2),
                'other' => round($actualOther, 2)
            ],
            'variance' => [
                'cash' => round($cashVariance, 2),
                'card' => round($cardVariance, 2),
                'other' => round($otherVariance, 2),
                'total' => round($totalVariance, 2)
            ]
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Shift reconcile error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to reconcile shift'], 500);
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
