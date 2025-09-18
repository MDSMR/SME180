<?php
/**
 * SME 180 POS - Complete Shift API (Enhanced Version)
 * Path: /public_html/pos/api/shifts/complete.php
 * Version: 2.0.0
 * 
 * Captures all closing information including date, session, terminal, etc.
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
    error_log('[SME180 Complete] Critical: Config file not found');
    http_response_code(503);
    die('{"success":false,"error":"Service unavailable","code":"CONFIG_ERROR"}');
}
require_once $configFile;

/**
 * Log events
 */
function logEvent($level, $message, $context = []) {
    $logEntry = [
        'timestamp' => date('c'),
        'level' => $level,
        'component' => 'shift_complete',
        'message' => $message,
        'context' => $context
    ];
    error_log('[SME180 Complete] ' . json_encode($logEntry));
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

// ===== CAPTURE ALL SESSION AND ENVIRONMENT DATA =====

// Session information
$sessionId = session_id();
$tenantId = (int)($_SESSION['tenant_id'] ?? 1);
$branchId = (int)($_SESSION['branch_id'] ?? 1);
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['pos_user_id'] ?? 1);
$userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User #' . $userId;
$stationId = (int)($_SESSION['station_id'] ?? 0);
$terminalId = $_SESSION['terminal_id'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

// Shift information from session
$shiftIdFromSession = $_SESSION['shift_id'] ?? null;
$shiftNumberFromSession = $_SESSION['shift_number'] ?? null;
$shiftStartedAt = $_SESSION['shift_started_at'] ?? null;
$sessionOpeningBalance = (float)($_SESSION['shift_opening_balance'] ?? 0);

// Closing date/time information
$closingDate = date('Y-m-d');
$closingTime = date('H:i:s');
$closingDateTime = date('Y-m-d H:i:s');
$closingTimestamp = time();
$timezone = date_default_timezone_get();

// Client information
$clientIP = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
$clientInfo = [
    'ip' => $clientIP,
    'user_agent' => $userAgent,
    'session_id' => $sessionId,
    'terminal_id' => $terminalId
];

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
$notes = isset($input['notes']) ? substr(trim(strip_tags($input['notes'] ?? '')), 0, 1000) : '';

// Denominations breakdown (optional)
$cashDenominations = $input['denominations'] ?? null;
$totalBills = 0;
$totalCoins = 0;

if ($cashDenominations) {
    // Process denomination counts
    // Format: {"100": 5, "50": 10, "20": 15, ...}
    $denominationDetails = [];
    foreach ($cashDenominations as $denom => $count) {
        $value = floatval($denom) * intval($count);
        $denominationDetails[] = [
            'denomination' => $denom,
            'count' => $count,
            'total' => $value
        ];
        
        // Separate bills and coins (assuming denominations < 1 are coins)
        if (floatval($denom) >= 1) {
            $totalBills += $value;
        } else {
            $totalCoins += $value;
        }
    }
}

// Validate amounts if provided
if ($actualCash !== null && ($actualCash < 0 || $actualCash > 1000000)) {
    sendResponse([
        'success' => false,
        'error' => 'Invalid cash amount (0-1,000,000)',
        'code' => 'INVALID_AMOUNT'
    ], 400);
}

try {
    $pdo = db();
    
    // Set transaction isolation
    $pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
    $pdo->beginTransaction();
    
    // Find the current open shift - use session shift_id if available
    if ($shiftIdFromSession && !$shiftId) {
        $shiftId = $shiftIdFromSession;
    }
    
    if ($shiftId) {
        // Use specific shift ID
        $shiftStmt = $pdo->prepare("
            SELECT * FROM pos_shifts 
            WHERE id = :id
                AND tenant_id = :tenant_id 
                AND branch_id = :branch_id
                AND status = 'open'
            FOR UPDATE
        ");
        
        $shiftStmt->execute([
            ':id' => $shiftId,
            ':tenant_id' => $tenantId,
            ':branch_id' => $branchId
        ]);
    } else {
        // Find any open shift for this branch
        $shiftStmt = $pdo->prepare("
            SELECT * FROM pos_shifts 
            WHERE tenant_id = :tenant_id 
                AND branch_id = :branch_id
                AND status = 'open'
            ORDER BY started_at DESC
            LIMIT 1
            FOR UPDATE
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
            'error' => 'No open shift found. Please open a shift first.',
            'code' => 'NO_OPEN_SHIFT',
            'debug' => [
                'session_shift_id' => $shiftIdFromSession,
                'provided_shift_id' => $shiftId
            ]
        ], 404);
    }
    
    // Calculate shift duration
    $startTime = strtotime($shift['started_at']);
    $endTime = $closingTimestamp;
    $duration = $endTime - $startTime;
    $hours = round($duration / 3600, 2);
    $durationFormatted = sprintf('%d hours %d minutes', 
        floor($hours), 
        round(($hours - floor($hours)) * 60)
    );
    
    // Get all orders during this shift with time range
    $ordersStmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as order_count,
            COUNT(DISTINCT o.customer_id) as customer_count,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded') THEN o.total_amount ELSE 0 END), 0) as total_sales,
            COALESCE(SUM(CASE WHEN o.status = 'refunded' THEN o.total_amount ELSE 0 END), 0) as total_refunds,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded') THEN o.discount_amount ELSE 0 END), 0) as total_discounts,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded') THEN o.subtotal ELSE 0 END), 0) as net_sales,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded') THEN o.tax_amount ELSE 0 END), 0) as total_tax,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded') THEN o.tip_amount ELSE 0 END), 0) as total_tips,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded') THEN o.service_charge_amount ELSE 0 END), 0) as total_service_charge,
            MIN(o.created_at) as first_order_time,
            MAX(o.created_at) as last_order_time,
            COUNT(DISTINCT CASE WHEN o.status = 'cancelled' THEN o.id END) as cancelled_count,
            COUNT(DISTINCT CASE WHEN o.status = 'refunded' THEN o.id END) as refunded_count
        FROM orders o
        WHERE o.tenant_id = :tenant_id
            AND o.branch_id = :branch_id
            AND o.created_at >= :shift_start
            AND o.created_at <= :closing_time
    ");
    
    $ordersStmt->execute([
        ':tenant_id' => $tenantId,
        ':branch_id' => $branchId,
        ':shift_start' => $shift['started_at'],
        ':closing_time' => $closingDateTime
    ]);
    
    $sales = $ordersStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get payment method breakdown
    $paymentBreakdownStmt = $pdo->prepare("
        SELECT 
            payment_method,
            SUM(amount) as total,
            COUNT(*) as transaction_count
        FROM order_payments
        WHERE order_id IN (
            SELECT id FROM orders 
            WHERE tenant_id = :tenant_id
                AND branch_id = :branch_id
                AND created_at >= :shift_start
                AND created_at <= :closing_time
                AND status NOT IN ('cancelled', 'refunded')
        )
        AND status = 'completed'
        GROUP BY payment_method
    ");
    
    $paymentBreakdownStmt->execute([
        ':tenant_id' => $tenantId,
        ':branch_id' => $branchId,
        ':shift_start' => $shift['started_at'],
        ':closing_time' => $closingDateTime
    ]);
    
    $paymentBreakdown = [];
    $cashSales = 0;
    $cardSales = 0;
    $otherSales = 0;
    
    while ($payment = $paymentBreakdownStmt->fetch(PDO::FETCH_ASSOC)) {
        $amount = floatval($payment['total']);
        $paymentBreakdown[$payment['payment_method']] = [
            'amount' => $amount,
            'count' => (int)$payment['transaction_count']
        ];
        
        switch ($payment['payment_method']) {
            case 'cash':
                $cashSales = $amount;
                break;
            case 'card':
                $cardSales = $amount;
                break;
            default:
                $otherSales += $amount;
        }
    }
    
    // If no payment records, assume all sales are cash
    if (empty($paymentBreakdown) && $sales['total_sales'] > 0) {
        $cashSales = floatval($sales['total_sales']);
        $paymentBreakdown['cash'] = [
            'amount' => $cashSales,
            'count' => (int)$sales['order_count']
        ];
    }
    
    // Extract opening balance
    $openingBalance = $sessionOpeningBalance;
    if ($openingBalance == 0 && preg_match('/Opening Balance:\s*[\$]?([\d,]+\.?\d*)/', $shift['notes'], $matches)) {
        $openingBalance = floatval(str_replace(',', '', $matches[1]));
    }
    
    // Calculate expected amounts
    $expectedCash = $openingBalance + $cashSales - floatval($sales['total_refunds']);
    $expectedCard = $cardSales;
    $expectedOther = $otherSales;
    
    // Use provided actual amounts or default to expected
    if ($actualCash === null) $actualCash = $expectedCash;
    if ($actualCard === null) $actualCard = $expectedCard;
    if ($actualOther === null) $actualOther = $expectedOther;
    
    // Calculate variances
    $cashVariance = $actualCash - $expectedCash;
    $cardVariance = $actualCard - $expectedCard;
    $otherVariance = $actualOther - $expectedOther;
    $totalVariance = $cashVariance + $cardVariance + $otherVariance;
    
    // Check for significant variance
    $varianceThreshold = 10.00;
    $significantVariance = abs($totalVariance) > $varianceThreshold;
    
    // Build comprehensive closing notes
    $closingNotes = [
        "=== SHIFT CLOSING REPORT ===",
        "Shift: " . $shift['shift_number'],
        "Date: " . $closingDate,
        "Closed at: " . $closingTime . " (" . $timezone . ")",
        "Duration: " . $durationFormatted,
        "Closed by: " . $userName . " (ID: " . $userId . ")",
        "Terminal: " . $terminalId,
        "Station ID: " . $stationId,
        "Session: " . substr($sessionId, 0, 8) . "...",
        "",
        "=== SALES SUMMARY ===",
        "Total Orders: " . $sales['order_count'],
        "Customers Served: " . $sales['customer_count'],
        "Gross Sales: $" . number_format($sales['total_sales'], 2),
        "Refunds: $" . number_format($sales['total_refunds'], 2),
        "Discounts: $" . number_format($sales['total_discounts'], 2),
        "Net Sales: $" . number_format($sales['net_sales'], 2),
        "",
        "=== CASH RECONCILIATION ===",
        "Opening Balance: $" . number_format($openingBalance, 2),
        "Cash Sales: $" . number_format($cashSales, 2),
        "Expected Cash: $" . number_format($expectedCash, 2),
        "Actual Cash: $" . number_format($actualCash, 2),
        "Cash Variance: $" . number_format($cashVariance, 2)
    ];
    
    if ($cashDenominations) {
        $closingNotes[] = "";
        $closingNotes[] = "=== DENOMINATION BREAKDOWN ===";
        foreach ($denominationDetails as $denom) {
            $closingNotes[] = sprintf("$%s x %d = $%s", 
                $denom['denomination'], 
                $denom['count'], 
                number_format($denom['total'], 2)
            );
        }
        $closingNotes[] = "Total Bills: $" . number_format($totalBills, 2);
        $closingNotes[] = "Total Coins: $" . number_format($totalCoins, 2);
    }
    
    if ($significantVariance) {
        $closingNotes[] = "";
        $closingNotes[] = "⚠️ SIGNIFICANT VARIANCE DETECTED: $" . number_format($totalVariance, 2);
    }
    
    if ($notes) {
        $closingNotes[] = "";
        $closingNotes[] = "=== CASHIER NOTES ===";
        $closingNotes[] = $notes;
    }
    
    $closingNotes[] = "";
    $closingNotes[] = "=== SYSTEM INFO ===";
    $closingNotes[] = "IP: " . $clientIP;
    $closingNotes[] = "Processed in: " . round((microtime(true) - $GLOBALS['startTime']) * 1000, 2) . "ms";
    
    $finalNotes = implode("\n", $closingNotes);
    
    // Update shift with all closing information
    $updateStmt = $pdo->prepare("
        UPDATE pos_shifts SET
            ended_at = :ended_at,
            ended_by = :ended_by,
            reconciled_at = :reconciled_at,
            reconciled_by = :reconciled_by,
            status = 'reconciled',
            total_sales = :total_sales,
            total_refunds = :total_refunds,
            total_discounts = :total_discounts,
            total_tips = :total_tips,
            total_service_charge = :total_service_charge,
            actual_cash = :actual_cash,
            actual_card = :actual_card,
            actual_other = :actual_other,
            cash_variance = :cash_variance,
            card_variance = :card_variance,
            other_variance = :other_variance,
            total_variance = :total_variance,
            order_count = :order_count,
            customer_count = :customer_count,
            reconciliation_notes = :notes
        WHERE id = :id
    ");
    
    $updateStmt->execute([
        ':ended_at' => $closingDateTime,
        ':ended_by' => $userId,
        ':reconciled_at' => $closingDateTime,
        ':reconciled_by' => $userId,
        ':total_sales' => $sales['total_sales'],
        ':total_refunds' => $sales['total_refunds'],
        ':total_discounts' => $sales['total_discounts'],
        ':total_tips' => $sales['total_tips'] ?? 0,
        ':total_service_charge' => $sales['total_service_charge'] ?? 0,
        ':actual_cash' => $actualCash,
        ':actual_card' => $actualCard,
        ':actual_other' => $actualOther,
        ':cash_variance' => $cashVariance,
        ':card_variance' => $cardVariance,
        ':other_variance' => $otherVariance,
        ':total_variance' => $totalVariance,
        ':order_count' => $sales['order_count'],
        ':customer_count' => $sales['customer_count'],
        ':notes' => $finalNotes,
        ':id' => $shift['id']
    ]);
    
    // Create comprehensive audit log
    $auditStmt = $pdo->prepare("
        INSERT INTO order_logs (
            order_id, tenant_id, branch_id, user_id,
            action, details, created_at
        ) VALUES (
            0, :tenant_id, :branch_id, :user_id,
            'shift_completed', :details, NOW()
        )
    ");
    
    $auditDetails = json_encode([
        'shift_id' => $shift['id'],
        'shift_number' => $shift['shift_number'],
        'closing_date' => $closingDate,
        'closing_time' => $closingTime,
        'session_id' => $sessionId,
        'terminal_id' => $terminalId,
        'station_id' => $stationId,
        'duration_hours' => $hours,
        'opening_balance' => $openingBalance,
        'expected' => [
            'cash' => $expectedCash,
            'card' => $expectedCard,
            'other' => $expectedOther
        ],
        'actual' => [
            'cash' => $actualCash,
            'card' => $actualCard,
            'other' => $actualOther
        ],
        'variance' => [
            'cash' => $cashVariance,
            'card' => $cardVariance,
            'other' => $otherVariance,
            'total' => $totalVariance
        ],
        'sales' => [
            'total' => $sales['total_sales'],
            'orders' => $sales['order_count'],
            'refunds' => $sales['total_refunds'],
            'first_order' => $sales['first_order_time'],
            'last_order' => $sales['last_order_time']
        ],
        'payment_breakdown' => $paymentBreakdown,
        'denominations' => $denominationDetails ?? null,
        'significant_variance' => $significantVariance,
        'client_info' => $clientInfo
    ], JSON_UNESCAPED_UNICODE);
    
    $auditStmt->execute([
        ':tenant_id' => $tenantId,
        ':branch_id' => $branchId,
        ':user_id' => $userId,
        ':details' => $auditDetails
    ]);
    
    // Clear session data
    unset($_SESSION['shift_id']);
    unset($_SESSION['shift_number']);
    unset($_SESSION['shift_started_at']);
    unset($_SESSION['shift_opening_balance']);
    
    // Commit transaction
    $pdo->commit();
    
    // Log successful completion
    logEvent('INFO', 'Shift completed successfully', [
        'shift_id' => $shift['id'],
        'shift_number' => $shift['shift_number'],
        'total_sales' => $sales['total_sales'],
        'total_variance' => $totalVariance,
        'duration_hours' => $hours,
        'closed_by' => $userId,
        'closing_time' => $closingDateTime
    ]);
    
    // Return comprehensive response
    sendResponse([
        'success' => true,
        'message' => $significantVariance ? 
            'Shift completed with variance. Manager review required.' : 
            'Shift completed successfully',
        'shift' => [
            'id' => (int)$shift['id'],
            'shift_number' => $shift['shift_number'],
            'shift_date' => $shift['shift_date'],
            'opened_at' => $shift['started_at'],
            'closed_at' => $closingDateTime,
            'duration' => [
                'hours' => $hours,
                'formatted' => $durationFormatted
            ],
            'closed_by' => [
                'id' => $userId,
                'name' => $userName
            ],
            'terminal' => $terminalId,
            'station_id' => $stationId,
            'session_id' => substr($sessionId, 0, 8) . '...',
            'status' => 'reconciled'
        ],
        'timing' => [
            'closing_date' => $closingDate,
            'closing_time' => $closingTime,
            'timezone' => $timezone,
            'timestamp' => $closingTimestamp,
            'first_order' => $sales['first_order_time'],
            'last_order' => $sales['last_order_time']
        ],
        'financials' => [
            'opening_balance' => round($openingBalance, 2),
            'expected' => [
                'cash' => round($expectedCash, 2),
                'card' => round($expectedCard, 2),
                'other' => round($expectedOther, 2),
                'total' => round($expectedCash + $expectedCard + $expectedOther, 2)
            ],
            'actual' => [
                'cash' => round($actualCash, 2),
                'card' => round($actualCard, 2),
                'other' => round($actualOther, 2),
                'total' => round($actualCash + $actualCard + $actualOther, 2)
            ],
            'variance' => [
                'cash' => round($cashVariance, 2),
                'card' => round($cardVariance, 2),
                'other' => round($otherVariance, 2),
                'total' => round($totalVariance, 2),
                'status' => $significantVariance ? 'warning' : 'ok',
                'percentage' => ($expectedCash + $expectedCard + $expectedOther) > 0 ? 
                    round(($totalVariance / ($expectedCash + $expectedCard + $expectedOther)) * 100, 2) : 0,
                'requires_review' => $significantVariance
            ]
        ],
        'sales_summary' => [
            'order_count' => (int)$sales['order_count'],
            'customer_count' => (int)$sales['customer_count'],
            'cancelled_count' => (int)$sales['cancelled_count'],
            'refunded_count' => (int)$sales['refunded_count'],
            'total_sales' => round(floatval($sales['total_sales']), 2),
            'total_refunds' => round(floatval($sales['total_refunds']), 2),
            'total_discounts' => round(floatval($sales['total_discounts']), 2),
            'total_tips' => round(floatval($sales['total_tips']), 2),
            'total_service_charge' => round(floatval($sales['total_service_charge']), 2),
            'net_sales' => round(floatval($sales['net_sales']), 2),
            'total_tax' => round(floatval($sales['total_tax']), 2),
            'average_order' => $sales['order_count'] > 0 ? 
                round(floatval($sales['total_sales']) / $sales['order_count'], 2) : 0
        ],
        'payment_breakdown' => $paymentBreakdown,
        'denominations' => $denominationDetails ?? null,
        'report_url' => '/pos/reports/shift/' . $shift['id'] . '/summary',
        'print_receipt' => true
    ], 200);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    logEvent('ERROR', 'Failed to complete shift', [
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'user_id' => $userId,
        'shift_id' => $shift['id'] ?? null
    ]);
    
    sendResponse([
        'success' => false,
        'error' => 'Unable to complete shift. Please try again.',
        'code' => 'COMPLETE_FAILED',
        'debug' => [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ], 500);
}
?>
