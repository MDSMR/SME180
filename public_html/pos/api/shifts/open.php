<?php
/**
 * SME 180 POS - Open Shift API (Production Ready Final)
 * Path: /public_html/pos/api/shifts/open.php
 * Version: 4.0.0 - Production with Fixed Database
 */

// Production error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    die('{"success":true}');
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('{"success":false,"error":"Method not allowed","code":"METHOD_NOT_ALLOWED"}');
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Load configuration
require_once __DIR__ . '/../../../config/db.php';

// Helper function for responses
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// Get session values
$tenantId = (int)($_SESSION['tenant_id'] ?? 1);
$branchId = (int)($_SESSION['branch_id'] ?? 1);
$userId = (int)($_SESSION['user_id'] ?? 1);
$stationId = (int)($_SESSION['station_id'] ?? 1);
$userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User #' . $userId;

// Parse input
$rawInput = file_get_contents('php://input');
$input = [];
if (!empty($rawInput)) {
    $input = json_decode($rawInput, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        sendResponse([
            'success' => false,
            'error' => 'Invalid request format',
            'code' => 'INVALID_JSON'
        ], 400);
    }
}

// Extract parameters
$openingBalance = isset($input['opening_balance']) ? floatval($input['opening_balance']) : 0.00;
$notes = isset($input['notes']) ? substr(trim(strip_tags($input['notes'] ?? '')), 0, 1000) : '';

// Validate opening balance
if ($openingBalance < 0 || $openingBalance > 100000) {
    sendResponse([
        'success' => false,
        'error' => 'Opening balance must be between 0 and 100,000',
        'code' => 'INVALID_BALANCE'
    ], 400);
}

try {
    $pdo = db();
    $pdo->beginTransaction();
    
    // Check for existing open shift
    $checkStmt = $pdo->prepare("
        SELECT 
            s.id, 
            s.shift_number, 
            s.started_at,
            s.started_by,
            u.name as started_by_name
        FROM pos_shifts s
        LEFT JOIN users u ON s.started_by = u.id
        WHERE s.tenant_id = :tenant_id 
            AND s.branch_id = :branch_id 
            AND s.status = 'open'
        ORDER BY s.started_at DESC
        LIMIT 1
    ");
    
    $checkStmt->execute([
        ':tenant_id' => $tenantId,
        ':branch_id' => $branchId
    ]);
    
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        $hoursOpen = round((time() - strtotime($existing['started_at'])) / 3600, 1);
        
        sendResponse([
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
        ], 409);
    }
    
    // Generate unique shift number
    $shiftDate = date('Y-m-d');
    
    // Get sequence for today
    $seqStmt = $pdo->prepare("
        SELECT COUNT(*) + 1 FROM pos_shifts 
        WHERE tenant_id = :tenant_id 
            AND branch_id = :branch_id 
            AND shift_date = :shift_date
    ");
    
    $seqStmt->execute([
        ':tenant_id' => $tenantId,
        ':branch_id' => $branchId,
        ':shift_date' => $shiftDate
    ]);
    
    $sequence = $seqStmt->fetchColumn();
    $shiftNumber = sprintf('SHIFT-%s-%02d', date('Ymd'), $sequence);
    
    // Insert new shift with all proper columns
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
            total_sales,
            total_refunds,
            total_discounts,
            total_tips,
            total_service_charge,
            cash_movements_in,
            cash_movements_out,
            order_count,
            customer_count,
            status,
            shift_type,
            notes,
            created_at
        ) VALUES (
            :tenant_id,
            :branch_id,
            :station_id,
            :cashier_id,
            :shift_number,
            :shift_date,
            NOW(),
            :started_by,
            :opening_cash,
            0.00,
            0.00,
            0.00,
            0.00,
            0.00,
            0.00,
            0.00,
            0,
            0,
            'open',
            'regular',
            :notes,
            NOW()
        )
    ");
    
    $result = $insertStmt->execute([
        ':tenant_id' => $tenantId,
        ':branch_id' => $branchId,
        ':station_id' => $stationId,
        ':cashier_id' => $userId,
        ':shift_number' => $shiftNumber,
        ':shift_date' => $shiftDate,
        ':started_by' => $userId,
        ':opening_cash' => $openingBalance,
        ':notes' => $notes
    ]);
    
    if (!$result) {
        throw new Exception('Failed to create shift record');
    }
    
    $shiftId = (int)$pdo->lastInsertId();
    
    // Store in session
    $_SESSION['shift_id'] = $shiftId;
    $_SESSION['shift_number'] = $shiftNumber;
    $_SESSION['shift_started_at'] = date('Y-m-d H:i:s');
    $_SESSION['shift_opening_balance'] = $openingBalance;
    
    // Create audit log
    $auditStmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            0, :tenant_id, :branch_id, :user_id,
            'shift_opened', :details, NOW()
        )
    ");
    
    $auditDetails = json_encode([
        'shift_id' => $shiftId,
        'shift_number' => $shiftNumber,
        'opening_balance' => $openingBalance,
        'station_id' => $stationId,
        'cashier_id' => $userId
    ]);
    
    $auditStmt->execute([
        ':tenant_id' => $tenantId,
        ':branch_id' => $branchId,
        ':user_id' => $userId,
        ':details' => $auditDetails
    ]);
    
    // Commit transaction
    $pdo->commit();
    
    // Get currency from settings
    $currency = 'EGP';
    try {
        $currStmt = $pdo->prepare("
            SELECT value FROM settings 
            WHERE tenant_id = ? AND `key` = 'currency'
        ");
        $currStmt->execute([$tenantId]);
        $currResult = $currStmt->fetch(PDO::FETCH_ASSOC);
        if ($currResult) {
            $currency = $currResult['value'];
        }
    } catch (Exception $e) {
        // Use default currency
    }
    
    // Return success response
    sendResponse([
        'success' => true,
        'message' => 'Shift opened successfully',
        'shift' => [
            'id' => $shiftId,
            'shift_number' => $shiftNumber,
            'shift_date' => $shiftDate,
            'started_at' => date('Y-m-d H:i:s'),
            'started_by' => [
                'id' => $userId,
                'name' => $userName
            ],
            'station_id' => $stationId,
            'cashier_id' => $userId,
            'opening_balance' => round($openingBalance, 2),
            'currency' => $currency,
            'status' => 'open'
        ]
    ], 200);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log('[SME180 Shift Open] Error: ' . $e->getMessage());
    
    sendResponse([
        'success' => false,
        'error' => 'Failed to open shift. Please try again.',
        'code' => 'OPEN_FAILED'
    ], 500);
}
?>
