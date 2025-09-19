<?php
/**
 * SME 180 POS - Open Shift API
 * Path: /public_html/pos/api/shifts/open.php
 * Version: 5.0.0 - Production Ready
 */

// Error handling - suppress warnings that break JSON
error_reporting(0);
ini_set('display_errors', '0');

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(json_encode(['success' => true]));
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configuration
$configFile = __DIR__ . '/../../../config/db.php';
if (!file_exists($configFile)) {
    exit(json_encode(['success' => false, 'error' => 'Configuration file missing']));
}
require_once $configFile;

// Verify database function exists
if (!function_exists('db')) {
    exit(json_encode(['success' => false, 'error' => 'Database not configured']));
}

// Get session values with defaults
$tenantId = (int)($_SESSION['tenant_id'] ?? 1);
$branchId = (int)($_SESSION['branch_id'] ?? 1);
$userId = (int)($_SESSION['user_id'] ?? 1);
$stationId = (int)($_SESSION['station_id'] ?? 1);
$userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User #' . $userId;

// Parse JSON input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    exit(json_encode(['success' => false, 'error' => 'Invalid JSON input']));
}

// Extract parameters
$openingBalance = isset($input['opening_balance']) ? (float)$input['opening_balance'] : 0.00;
$notes = isset($input['notes']) ? substr(trim($input['notes'] ?? ''), 0, 500) : '';
$registerNumber = isset($input['register_number']) ? trim($input['register_number']) : '';

// Validate opening balance
if ($openingBalance < 0 || $openingBalance > 100000) {
    exit(json_encode([
        'success' => false,
        'error' => 'Opening balance must be between 0 and 100,000'
    ]));
}

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    
    // Check for existing open shift
    $checkStmt = $pdo->prepare("
        SELECT s.*, u.name as started_by_name
        FROM pos_shifts s
        LEFT JOIN users u ON s.started_by = u.id
        WHERE s.tenant_id = ? 
          AND s.branch_id = ? 
          AND s.status = 'open'
        ORDER BY s.started_at DESC
        LIMIT 1
    ");
    $checkStmt->execute([$tenantId, $branchId]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $pdo->rollBack();
        $hoursOpen = round((time() - strtotime($existing['started_at'])) / 3600, 1);
        exit(json_encode([
            'success' => false,
            'error' => 'A shift is already open. Please close it before opening a new one.',
            'code' => 'SHIFT_ALREADY_OPEN',
            'existing_shift' => [
                'id' => (int)$existing['id'],
                'shift_number' => $existing['shift_number'],
                'started_at' => $existing['started_at'],
                'started_by' => $existing['started_by_name'] ?? 'User #' . $existing['started_by'],
                'hours_open' => $hoursOpen
            ]
        ]));
    }
    
    // Generate unique shift number
    $shiftDate = date('Y-m-d');
    $seqStmt = $pdo->prepare("
        SELECT COUNT(*) + 1 FROM pos_shifts 
        WHERE tenant_id = ? AND branch_id = ? AND shift_date = ?
    ");
    $seqStmt->execute([$tenantId, $branchId, $shiftDate]);
    $sequence = $seqStmt->fetchColumn();
    $shiftNumber = sprintf('SHIFT-%s-%02d', date('Ymd'), $sequence);
    
    // Prepare notes with register info
    if ($registerNumber) {
        $notes = "Register: $registerNumber\n" . $notes;
    }
    
    // Insert new shift with all required columns
    $insertStmt = $pdo->prepare("
        INSERT INTO pos_shifts (
            tenant_id, 
            branch_id,
            station_id,
            cashier_id,
            shift_number, 
            shift_date,
            started_at, 
            started_by,
            opening_cash,
            cash_movements_in,
            cash_movements_out,
            total_sales,
            total_refunds,
            total_discounts,
            order_count,
            customer_count,
            status,
            shift_type,
            notes,
            created_at
        ) VALUES (
            ?, ?, ?, ?, ?, ?,
            NOW(), ?, ?, 0, 0, 0, 0, 0, 0, 0,
            'open', 'regular', ?, NOW()
        )
    ");
    
    $insertStmt->execute([
        $tenantId,
        $branchId,
        $stationId,
        $userId,  // cashier_id
        $shiftNumber,
        $shiftDate,
        $userId,  // started_by
        $openingBalance,
        $notes
    ]);
    
    $shiftId = (int)$pdo->lastInsertId();
    
    // Store in session for continuity
    $_SESSION['shift_id'] = $shiftId;
    $_SESSION['shift_number'] = $shiftNumber;
    $_SESSION['shift_opening_balance'] = $openingBalance;
    $_SESSION['shift_started_at'] = date('Y-m-d H:i:s');
    
    // Create audit log entry if table exists
    try {
        $auditStmt = $pdo->prepare("
            INSERT INTO order_logs (
                order_id, tenant_id, branch_id, user_id,
                action, details, created_at
            ) VALUES (
                0, ?, ?, ?, 'shift_opened', ?, NOW()
            )
        ");
        
        $auditDetails = json_encode([
            'shift_id' => $shiftId,
            'shift_number' => $shiftNumber,
            'opening_balance' => $openingBalance,
            'station_id' => $stationId,
            'register' => $registerNumber
        ]);
        
        $auditStmt->execute([$tenantId, $branchId, $userId, $auditDetails]);
    } catch (Exception $e) {
        // Ignore if audit table doesn't exist
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => 'Shift opened successfully',
        'shift' => [
            'id' => $shiftId,
            'shift_number' => $shiftNumber,
            'shift_date' => $shiftDate,
            'opening_balance' => round($openingBalance, 2),
            'started_at' => date('Y-m-d H:i:s'),
            'started_by' => $userName,
            'station_id' => $stationId,
            'status' => 'open'
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log error for debugging (but don't expose details to client)
    error_log('Shift open error: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Failed to open shift. Please try again.'
    ]);
}
?>
