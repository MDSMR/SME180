<?php
/**
 * SME 180 POS - Close Shift API (Production Ready)
 * Path: /public_html/pos/api/shifts/close.php
 * Version: 3.0.0 - Production
 * 
 * Compatible with your table structure
 * Complete reconciliation and reporting
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
 * Log events
 */
function logShiftEvent($level, $message, $context = []) {
    $logEntry = [
        'timestamp' => date('c'),
        'level' => $level,
        'component' => 'shift_close',
        'message' => $message,
        'context' => $context
    ];
    error_log('[SME180 Shift] ' . json_encode($logEntry));
}

/**
 * Send response
 */
function sendResponse($data, $statusCode = 200) {
    global $startTime;
    $data['processing_time'] = round((microtime(true) - $startTime) * 1000, 2) . 'ms';
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// Get session values
$tenantId = (int)($_SESSION['tenant_id'] ?? 1);
$branchId = (int)($_SESSION['branch_id'] ?? 1);
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['pos_user_id'] ?? 1);
$userName = $_SESSION['user_name'] ?? 'User #' . $userId;

// Get opening balance from session if stored
$sessionOpeningBalance = $_SESSION['shift_opening_balance'] ?? 0;

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
$shiftId = isset($input['shift_id']) ? (int)$input['shift_id'] : null;
$actualCash = isset($input['actual_cash']) ? floatval($input['actual_cash']) : null;
$actualCard = isset($input['actual_card']) ? floatval($input['actual_card']) : null;
$actualOther = isset($input['actual_other']) ? floatval($input['actual_other']) : null;
$reconciliationNotes = isset($input['notes']) ? substr(trim(strip_tags($input['notes'] ?? '')), 0, 1000) : '';
$forceClose = isset($input['force_close']) ? (bool)$input['force_close'] : false;

try {
    $pdo = db();
    
    // Set transaction isolation
    $pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
    $pdo->beginTransaction();
    
    // Find the open shift
    if ($shiftId) {
        $shiftStmt = $pdo->prepare("
            SELECT * FROM pos_shifts 
            WHERE id = :id 
                AND tenant_id = :tenant_id 
                AND branch_id = :branch_id
                AND status = 'open'
        ");
        $shiftStmt->execute([
            ':id' => $shiftId,
            ':tenant_id' => $tenantId,
            ':branch_id' => $branchId
        ]);
    } else {
        $shiftStmt = $pdo->prepare("
            SELECT * FROM pos_shifts 
            WHERE tenant_id = :tenant_id 
                AND branch_id = :branch_id
                AND status = 'open'
            ORDER BY started_at DESC
            LIMIT 1
        ");
        $shiftStmt->execute([
            ':tenant_id' => $tenantId,
            ':branch_id' => $branchId
        ]);
    }
    
    $shift = $shiftStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shift) {
        sendResponse([
            'success' => false,
            'error' => 'No open shift found to close',
            'code' => 'NO_OPEN_SHIFT'
        ], 404);
    }
    
    // Calculate shift duration
    $duration = time() - strtotime($shift['started_at']);
    $hours = round($duration / 3600, 2);
    
    // Calculate sales data from orders
    $salesStmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as order_count,
            COUNT(DISTINCT o.customer_id) as customer_count,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded') THEN o.total_amount ELSE 0 END), 0) as total_sales,
            COALESCE(SUM(CASE WHEN o.status = 'refunded' THEN o.total_amount ELSE 0 END), 0) as total_refunds,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded') THEN o.discount_amount ELSE 0 END), 0) as total_discounts,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded') THEN o.subtotal ELSE 0 END), 0) as net_sales,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded') THEN o.tax_amount ELSE 0 END), 0) as total_tax
        FROM orders o
        WHERE o.tenant_id = :tenant_id
            AND o.branch_id = :branch_id
            AND o.created_at >= :shift_start
            AND o.created_at <= NOW()
    ");
    
    $salesStmt->execute([
        ':tenant_id' => $tenantId,
        ':branch_id' => $branchId,
        ':shift_start' => $shift['started_at']
    ]);
    
    $sales = $salesStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get payment breakdown if order_payments table exists
    $cashSales = 0;
    $cardSales = 0;
    $otherSales = 0;
    
    try {
        $paymentStmt = $pdo->prepare("
            SELECT 
                payment_method,
                SUM(amount) as total
            FROM order_payments
            WHERE order_id IN (
                SELECT id FROM orders 
                WHERE tenant_id = :tenant_id
                    AND branch_id = :branch_id
                    AND created_at >= :shift_start
                    AND status NOT IN ('cancelled', 'refunded')
            )
            AND status = 'completed'
            GROUP BY payment_method
        ");
        
        $paymentStmt->execute([
            ':tenant_id' => $tenantId,
            ':branch_id' => $branchId,
            ':shift_start' => $shift['started_at']
        ]);
        
        while ($payment = $paymentStmt->fetch(PDO::FETCH_ASSOC)) {
            switch ($payment['payment_method']) {
                case 'cash':
                    $cashSales = floatval($payment['total']);
                    break;
                case 'card':
                    $cardSales = floatval($payment['total']);
                    break;
                default:
                    $otherSales += floatval($payment['total']);
            }
        }
    } catch (Exception $e) {
        // If no payments table, assume all sales are cash
        $cashSales = floatval($sales['total_sales']);
        logShiftEvent('INFO', 'No payment breakdown available, assuming all cash');
    }
    
    // Extract opening balance from notes if it was stored there
    $openingBalance = $sessionOpeningBalance;
    if (preg_match('/Opening Balance:\s*[\$]?([\d,]+\.?\d*)/', $shift['notes'], $matches)) {
        $openingBalance = floatval(str_replace(',', '', $matches[1]));
    }
    
    // Calculate expected vs actual
    $expectedCash = $openingBalance + $cashSales - floatval($sales['total_refunds']);
    
    // Set actual values if not provided
    if ($actualCash === null) $actualCash = $expectedCash;
    if ($actualCard === null) $actualCard = $cardSales;
    if ($actualOther === null) $actualOther = $otherSales;
    
    // Calculate variances
    $cashVariance = $actualCash - $expectedCash;
    $cardVariance = $actualCard - $cardSales;
    $otherVariance = $actualOther - $otherSales;
    $totalVariance = $cashVariance + $cardVariance + $otherVariance;
    
    // Update shift record
    $updateStmt = $pdo->prepare("
        UPDATE pos_shifts SET
            ended_at = NOW(),
            ended_by = :ended_by,
            total_sales = :total_sales,
            total_refunds = :total_refunds,
            total_discounts = :total_discounts,
            actual_cash = :actual_cash,
            actual_card = :actual_card,
            actual_other = :actual_other,
            cash_variance = :cash_variance,
            card_variance = :card_variance,
            other_variance = :other_variance,
            total_variance = :total_variance,
            order_count = :order_count,
            customer_count = :customer_count,
            reconciliation_notes = :reconciliation_notes,
            status = 'closed'
        WHERE id = :id
    ");
    
    $updateStmt->execute([
        ':ended_by' => $userId,
        ':total_sales' => $sales['total_sales'],
        ':total_refunds' => $sales['total_refunds'],
        ':total_discounts' => $sales['total_discounts'],
        ':actual_cash' => $actualCash,
        ':actual_card' => $actualCard,
        ':actual_other' => $actualOther,
        ':cash_variance' => $cashVariance,
        ':card_variance' => $cardVariance,
        ':other_variance' => $otherVariance,
        ':total_variance' => $totalVariance,
        ':order_count' => $sales['order_count'],
        ':customer_count' => $sales['customer_count'],
        ':reconciliation_notes' => $reconciliationNotes,
        ':id' => $shift['id']
    ]);
    
    // Create audit log
    try {
        $auditStmt = $pdo->prepare("
            INSERT INTO order_logs (
                order_id, tenant_id, branch_id, user_id,
                action, details, created_at
            ) VALUES (
                0, :tenant_id, :branch_id, :user_id,
                'shift_closed', :details, NOW()
            )
        ");
        
        $auditDetails = json_encode([
            'shift_id' => $shift['id'],
            'shift_number' => $shift['shift_number'],
            'duration_hours' => $hours,
            'opening_balance' => $openingBalance,
            'total_sales' => $sales['total_sales'],
            'actual_cash' => $actualCash,
            'cash_variance' => $cashVariance,
            'total_variance' => $totalVariance,
            'orders' => $sales['order_count']
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
    
    // Clear session
    unset($_SESSION['shift_id']);
    unset($_SESSION['shift_number']);
    unset($_SESSION['shift_started_at']);
    unset($_SESSION['shift_opening_balance']);
    
    // Commit transaction
    $pdo->commit();
    
    // Log success
    logShiftEvent('INFO', 'Shift closed successfully', [
        'shift_id' => $shift['id'],
        'total_sales' => $sales['total_sales'],
        'variance' => $totalVariance
    ]);
    
    // Return comprehensive response
    sendResponse([
        'success' => true,
        'message' => 'Shift closed successfully',
        'shift' => [
            'id' => (int)$shift['id'],
            'shift_number' => $shift['shift_number'],
            'shift_date' => $shift['shift_date'],
            'started_at' => $shift['started_at'],
            'ended_at' => date('Y-m-d H:i:s'),
            'duration' => [
                'hours' => $hours,
                'formatted' => sprintf('%d hours %d minutes', floor($hours), round(($hours - floor($hours)) * 60))
            ],
            'closed_by' => [
                'id' => $userId,
                'name' => $userName
            ]
        ],
        'financials' => [
            'opening_balance' => $openingBalance,
            'expected_cash' => $expectedCash,
            'actual_cash' => $actualCash,
            'cash_variance' => $cashVariance,
            'card_sales' => $cardSales,
            'actual_card' => $actualCard,
            'card_variance' => $cardVariance,
            'other_sales' => $otherSales,
            'actual_other' => $actualOther,
            'other_variance' => $otherVariance,
            'total_variance' => $totalVariance,
            'variance_status' => abs($totalVariance) > 10 ? 'warning' : 'ok'
        ],
        'sales' => [
            'order_count' => (int)$sales['order_count'],
            'customer_count' => (int)$sales['customer_count'],
            'total_sales' => floatval($sales['total_sales']),
            'total_refunds' => floatval($sales['total_refunds']),
            'total_discounts' => floatval($sales['total_discounts']),
            'net_sales' => floatval($sales['net_sales']),
            'total_tax' => floatval($sales['total_tax']),
            'average_order' => $sales['order_count'] > 0 ? 
                round(floatval($sales['total_sales']) / $sales['order_count'], 2) : 0
        ]
    ], 200);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logShiftEvent('ERROR', 'Failed to close shift', [
        'error' => $e->getMessage(),
        'shift_id' => $shiftId
    ]);
    
    sendResponse([
        'success' => false,
        'error' => 'Unable to close shift. Please try again.',
        'code' => 'CLOSE_FAILED'
    ], 500);
}
?>
