## 4. /public_html/pos/api/kds/screens/heartbeat.php
<?php
/**
 * SME 180 POS - KDS Screen Heartbeat API
 * Path: /public_html/pos/api/kds/screens/heartbeat.php
 * 
 * Heartbeat for KDS screens to maintain connection
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../../config/db.php';
require_once __DIR__ . '/../../../../middleware/pos_auth.php';

pos_auth_require_login();

$tenantId = (int)($_SESSION['tenant_id'] ?? 0);
$branchId = (int)($_SESSION['branch_id'] ?? 0);

if (!$tenantId || !$branchId) {
    json_response(['success' => false, 'error' => 'Invalid session'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
$screenCode = $input['screen_code'] ?? '';
$statusData = $input['status_data'] ?? [];

if (empty($screenCode)) {
    json_response(['success' => false, 'error' => 'Screen code is required'], 400);
}

try {
    $pdo = db();
    
    // Update heartbeat
    $stmt = $pdo->prepare("
        UPDATE pos_kds_screens 
        SET last_heartbeat = NOW(),
            status_data = :status_data
        WHERE tenant_id = :tenant_id 
        AND branch_id = :branch_id 
        AND screen_code = :screen_code
    ");
    $stmt->execute([
        'status_data' => json_encode($statusData),
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'screen_code' => $screenCode
    ]);
    
    if ($stmt->rowCount() == 0) {
        json_response(['success' => false, 'error' => 'Screen not found'], 404);
    }
    
    // Get pending updates for this screen
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as pending_orders,
            MIN(o.fired_at) as oldest_order_time
        FROM orders o
        JOIN order_items oi ON oi.order_id = o.id
        WHERE o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND o.kitchen_status IN ('fired', 'preparing')
        AND oi.is_voided = 0
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Check for alerts
    $alerts = [];
    
    // Check for old orders (> 20 minutes)
    if ($stats['oldest_order_time']) {
        $oldestTime = strtotime($stats['oldest_order_time']);
        $minutesElapsed = (time() - $oldestTime) / 60;
        
        if ($minutesElapsed > 20) {
            $alerts[] = [
                'type' => 'old_order',
                'message' => 'Orders pending for over 20 minutes',
                'severity' => 'high'
            ];
        } elseif ($minutesElapsed > 15) {
            $alerts[] = [
                'type' => 'old_order',
                'message' => 'Orders pending for over 15 minutes',
                'severity' => 'medium'
            ];
        }
    }
    
    json_response([
        'success' => true,
        'screen_code' => $screenCode,
        'heartbeat_time' => date('Y-m-d H:i:s'),
        'stats' => [
            'pending_orders' => (int)$stats['pending_orders']
        ],
        'alerts' => $alerts
    ]);
    
} catch (Exception $e) {
    error_log('KDS heartbeat error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to update heartbeat'], 500);
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
