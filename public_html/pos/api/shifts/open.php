<?php
/**
 * SME 180 POS - Open Shift API (Production Ready)
 * Path: /public_html/pos/api/shifts/open.php
 * Version: 3.0.0 - Production
 * 
 * Compatible with your existing table structure
 * Full production features including logging and validation
 */

// Production error handling
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Performance tracking
$startTime = microtime(true);

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

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
$configFile = __DIR__ . '/../../../config/db.php';
if (!file_exists($configFile)) {
    error_log('[SME180 Shift] Critical: Config file not found');
    http_response_code(503);
    die('{"success":false,"error":"Service unavailable","code":"CONFIG_ERROR"}');
}
require_once $configFile;

/**
 * Log events for monitoring
 */
function logShiftEvent($level, $message, $context = []) {
    $logEntry = [
        'timestamp' => date('c'),
        'level' => $level,
        'component' => 'shift_open',
        'message' => $message,
        'context' => $context
    ];
    error_log('[SME180 Shift] ' . json_encode($logEntry));
}

/**
 * Send response with timing
 */
function sendResponse($data, $statusCode = 200) {
    global $startTime;
    $data['processing_time'] = round((microtime(true) - $startTime) * 1000, 2) . 'ms';
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// Get session values with defaults
$tenantId = (int)($_SESSION['tenant_id'] ?? 1);
$branchId = (int)($_SESSION['branch_id'] ?? 1);
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['pos_user_id'] ?? 1);
$stationId = (int)($_SESSION['station_id'] ?? 1);
$userName = $_SESSION['user_name'] ?? 'User #' . $userId;

// Log if using defaults
if (!isset($_SESSION['tenant_id'])) {
    logShiftEvent('WARNING', 'No tenant_id in session, using default', ['default' => $tenantId]);
}

// Parse and validate input
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

// Extract parameters with validation
$openingBalance = isset($input['opening_balance']) ? floatval($input['opening_balance']) : 0.00;
$notes = isset($input['notes']) ? substr(trim(strip_tags($input['notes'] ?? '')), 0, 1000) : '';
$registerNumber = isset($input['register_number']) ? substr(trim($input['register_number'] ?? ''), 0, 50) : null;

// Validate opening balance
if ($openingBalance < 0 || $openingBalance > 100000) {
    sendResponse([
        'success' => false,
        'error' => 'Opening balance must be between 0 and 100,000',
        'code' => 'INVALID_BALANCE'
    ], 400);
}

// Add opening balance to notes since column doesn't exist
if ($openingBalance > 0) {
    $balanceNote = "Opening Balance: " . number_format($openingBalance, 2);
    $notes = $balanceNote . ($notes ? "\n" . $notes : "");
}

// Add register number to notes if provided
if ($registerNumber) {
    $notes = "Register: " . $registerNumber . "\n" . $notes;
}

try {
    $pdo = db();
    
    // Set transaction isolation
    $pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
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
        
        logShiftEvent('WARNING', 'Attempted to open shift with existing open shift', [
            'existing_id' => $existing['id'],
            'hours_open' => $hoursOpen
        ]);
        
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
    
    // Insert new shift
    $insertStmt = $pdo->prepare("
        INSERT INTO pos_shifts (
            tenant_id, 
            branch_id, 
            shift_number, 
            shift_date,
            started_at, 
            started_by,
            total_sales,
            total_refunds,
            total_discounts,
            total_tips,
            total_service_charge,
            order_count,
            customer_count,
            status, 
            notes,
            created_at
        ) VALUES (
            :tenant_id,
            :branch_id,
            :shift_number,
            :shift_date,
            NOW(),
            :started_by,
            0.00,
            0.00,
            0.00,
            0.00,
            0.00,
            0,
            0,
            'open',
            :notes,
            NOW()
        )
    ");
    
    $result = $insertStmt->execute([
        ':tenant_id' => $tenantId,
        ':branch_id' => $branchId,
        ':shift_number' => $shiftNumber,
        ':shift_date' => $shiftDate,
        ':started_by' => $userId,
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
    try {
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
            'register_number' => $registerNumber,
            'station_id' => $stationId
        ]);
        
        $auditStmt->execute([
            ':tenant_id' => $tenantId,
            ':branch_id' => $branchId,
            ':user_id' => $userId,
            ':details' => $auditDetails
        ]);
    } catch (Exception $e) {
        logShiftEvent('WARNING', 'Audit log failed', ['error' => $e->getMessage()]);
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Log success
    logShiftEvent('INFO', 'Shift opened successfully', [
        'shift_id' => $shiftId,
        'shift_number' => $shiftNumber,
        'opening_balance' => $openingBalance
    ]);
    
    // Get currency from settings
    $currency = 'EGP';
    try {
        $currStmt = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = ? AND `key` = 'currency_symbol' LIMIT 1");
        $currStmt->execute([$tenantId]);
        $curr = $currStmt->fetchColumn();
        if ($curr) $currency = $curr;
    } catch (Exception $e) {
        // Use default
    }
    
    // Return success
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
            'opening_balance' => $openingBalance,
            'register_number' => $registerNumber,
            'station_id' => $stationId,
            'currency' => $currency,
            'status' => 'open'
        ]
    ], 200);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logShiftEvent('ERROR', 'Failed to open shift', [
        'error' => $e->getMessage(),
        'user_id' => $userId
    ]);
    
    if (strpos($e->getMessage(), 'Duplicate entry') !== false) {
        sendResponse([
            'success' => false,
            'error' => 'Shift number conflict. Please try again.',
            'code' => 'DUPLICATE_SHIFT'
        ], 409);
    } else {
        sendResponse([
            'success' => false,
            'error' => 'Unable to open shift at this time. Please try again.',
            'code' => 'SHIFT_OPEN_FAILED'
        ], 500);
    }
}
?>
