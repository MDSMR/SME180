## 1. /public_html/pos/api/shifts/open.php
<?php
/**
 * SME 180 POS - Shift Open API
 * Path: /public_html/pos/api/shifts/open.php
 * 
 * Opens a new shift for the day
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

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Check for existing open shift
    $stmt = $pdo->prepare("
        SELECT id, shift_number 
        FROM pos_shifts 
        WHERE tenant_id = :tenant_id 
        AND branch_id = :branch_id 
        AND status = 'open'
        LIMIT 1
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    
    $existingShift = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($existingShift) {
        json_response([
            'success' => false, 
            'error' => 'A shift is already open',
            'shift_id' => $existingShift['id']
        ], 400);
    }
    
    // Generate shift number
    $shiftDate = date('Y-m-d');
    $shiftNumber = 'SHIFT-' . date('Ymd') . '-' . str_pad((string)mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    // Create new shift
    $stmt = $pdo->prepare("
        INSERT INTO pos_shifts (
            tenant_id, branch_id, shift_number, shift_date,
            started_at, started_by, status, created_at
        ) VALUES (
            :tenant_id, :branch_id, :shift_number, :shift_date,
            NOW(), :started_by, 'open', NOW()
        )
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'shift_number' => $shiftNumber,
        'shift_date' => $shiftDate,
        'started_by' => $userId
    ]);
    
    $shiftId = (int)$pdo->lastInsertId();
    
    // Store in session
    $_SESSION['shift_id'] = $shiftId;
    $_SESSION['shift_number'] = $shiftNumber;
    
    $pdo->commit();
    
    json_response([
        'success' => true,
        'shift' => [
            'id' => $shiftId,
            'shift_number' => $shiftNumber,
            'shift_date' => $shiftDate,
            'started_at' => date('Y-m-d H:i:s'),
            'started_by' => $userId
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Shift open error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Failed to open shift'], 500);
}

function json_response($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}
