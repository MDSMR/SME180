## 3. /public_html/pos/api/shifts/current.php
```php
<?php
/**
 * SME 180 POS - Current Shift API
 * Path: /public_html/pos/api/shifts/current.php
 * 
 * Gets current open shift information
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

pos_auth_require_login();

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);

if (!$tenantId || !$branchId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

try {
    $pdo = db();
    
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            u.name as started_by_name,
            (SELECT COUNT(*) FROM cash_sessions WHERE shift_id = s.id AND status = 'open') as open_sessions
        FROM pos_shifts s
        LEFT JOIN users u ON u.id = s.started_by
        WHERE s.tenant_id = :tenant_id 
        AND s.branch_id = :branch_id 
        AND s.status = 'open'
        ORDER BY s.started_at DESC
        LIMIT 1
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shift) {
        json_response([
            'success' => true,
            'shift' => null,
            'message' => 'No open shift'
        ]);
    }
    
    // Get current stats
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as orders_today,
            COALESCE(SUM(o.total_amount), 0) as sales_today
        FROM orders o
        WHERE o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND o.created_at >= :started_at
        AND o.payment_status IN ('paid', 'partial')
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'started_at' => $shift['started_at']
    ]);
    
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    json_response([
        'success' => true,
        'shift' => [
            'id' => (int)$shift['id'],
            'shift_number' => $shift['shift_number'],
            'shift_date' => $shift['shift_date'],
            'started_at' => $shift['started_at'],
            'started_by' => $shift['started_by_name'],
            'open_sessions' => (int)$shift['open_sessions'],
            'current_stats' => [
                'orders' => (int)$stats['orders_today'],
                'sales' => round((float)$stats['sales_today'], 2)
            ]
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Current shift error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to get shift info'], 500);
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
