<?php
/**
 * SME 180 POS - Complete Shift API (Close + Reconcile in One Step)
 * Path: /public_html/pos/api/shifts/complete.php
 * Version: 3.0.0 - Production Ready
 * 
 * This single endpoint replaces both close.php and reconcile.php
 * Following modern POS standards (Square, Toast, Clover)
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
$userName = $_SESSION['user_name'] ?? $_SESSION['username'] ?? 'User #' . $userId;
$sessionShiftId = $_SESSION['shift_id'] ?? null;

// Parse JSON input
$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    exit(json_encode(['success' => false, 'error' => 'Invalid JSON input']));
}

// Extract parameters
$shiftId = isset($input['shift_id']) ? (int)$input['shift_id'] : $sessionShiftId;
$actualCash = isset($input['actual_cash']) ? (float)$input['actual_cash'] : null;
$actualCard = isset($input['actual_card']) ? (float)$input['actual_card'] : null;
$actualOther = isset($input['actual_other']) ? (float)$input['actual_other'] : 0.00;
$notes = isset($input['notes']) ? substr(trim($input['notes'] ?? ''), 0, 1000) : '';

// Optional denomination breakdown
$denominations = isset($input['denominations']) ? $input['denominations'] : null;

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();
    
    // Find the open shift
    if ($shiftId) {
        $stmt = $pdo->prepare("
            SELECT * FROM pos_shifts 
            WHERE id = ? 
              AND tenant_id = ? 
              AND branch_id = ? 
              AND status = 'open'
            FOR UPDATE
        ");
        $stmt->execute([$shiftId, $tenantId, $branchId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT * FROM pos_shifts 
            WHERE tenant_id = ? 
              AND branch_id = ? 
              AND status = 'open'
            ORDER BY started_at DESC 
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->execute([$tenantId, $branchId]);
    }
    
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shift) {
        $pdo->rollBack();
        exit(json_encode([
            'success' => false,
            'error' => 'No open shift found to complete',
            'code' => 'NO_OPEN_SHIFT'
        ]));
    }
    
    // Calculate duration
    $startTime = strtotime($shift['started_at']);
    $endTime = time();
    $duration = $endTime - $startTime;
    $hours = round($duration / 3600, 2);
    
    // Get sales data for this shift
    $salesStmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as order_count,
            COUNT(DISTINCT o.customer_id) as customer_count,
            COALESCE(SUM(CASE WHEN o.status NOT IN ('cancelled', 'refunded', 'voided') 
                         THEN o.total_amount ELSE 0 END), 0) as total_sales,
            COALESCE(SUM(CASE WHEN o.status = 'refunded' 
                         THEN o.total_amount ELSE 0 END), 0) as total_refunds,
            COALESCE(SUM(o.discount_amount), 0) as total_discounts,
            COALESCE(SUM(o.tip_amount), 0) as total_tips,
            COALESCE(SUM(o.service_charge_amount), 0) as total_service_charge,
            COALESCE(SUM(o.tax_amount), 0) as total_tax
        FROM orders o
        WHERE o.tenant_id = ?
          AND o.branch_id = ?
          AND o.created_at >= ?
          AND o.created_at <= NOW()
    ");
    $salesStmt->execute([$tenantId, $branchId, $shift['started_at']]);
    $sales = $salesStmt->fetch(PDO::FETCH_ASSOC);
    
    // Get payment method breakdown (if order_payments table exists)
    $cashSales = 0;
    $cardSales = 0;
    $otherSales = 0;
    
    try {
        $paymentStmt = $pdo->prepare("
            SELECT 
                payment_method,
                SUM(amount) as total
            FROM order_payments op
            JOIN orders o ON o.id = op.order_id
            WHERE o.tenant_id = ?
              AND o.branch_id = ?
              AND o.created_at >= ?
              AND op.status = 'completed'
            GROUP BY payment_method
        ");
        $paymentStmt->execute([$tenantId, $branchId, $shift['started_at']]);
        
        while ($payment = $paymentStmt->fetch(PDO::FETCH_ASSOC)) {
            switch ($payment['payment_method']) {
                case 'cash':
                    $cashSales = (float)$payment['total'];
                    break;
                case 'card':
                    $cardSales = (float)$payment['total'];
                    break;
                default:
                    $otherSales += (float)$payment['total'];
            }
        }
    } catch (Exception $e) {
        // If no payments table, assume all sales are cash
        $cashSales = (float)$sales['total_sales'];
    }
    
    // Get opening balance
    $openingCash = 0.00;
    if (isset($shift['opening_cash']) && $shift['opening_cash'] !== null) {
        $openingCash = (float)$shift['opening_cash'];
    } elseif (!empty($shift['notes'])) {
        // Try to extract from notes if column doesn't exist
        if (preg_match('/Opening Balance:\s*[\$]?([\d,]+\.?\d*)/', $shift['notes'], $matches)) {
            $openingCash = (float)str_replace(',', '', $matches[1]);
        }
    }
    
    // Get cash movements if any
    $cashMovementsIn = (float)($shift['cash_movements_in'] ?? 0);
    $cashMovementsOut = (float)($shift['cash_movements_out'] ?? 0);
    
    // Calculate expected amounts
    $expectedCash = $openingCash + $cashSales + $cashMovementsIn - $cashMovementsOut - (float)$sales['total_refunds'];
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
    
    // Check for significant variance (configurable threshold)
    $varianceThreshold = 10.00;
    $hasSignificantVariance = abs($totalVariance) > $varianceThreshold;
    
    // Build reconciliation notes
    $reconciliationNotes = "=== SHIFT COMPLETION REPORT ===\n";
    $reconciliationNotes .= "Shift: {$shift['shift_number']}\n";
    $reconciliationNotes .= "Date: " . date('Y-m-d') . "\n";
    $reconciliationNotes .= "Closed by: $userName\n";
    $reconciliationNotes .= "Duration: " . sprintf('%.1f hours', $hours) . "\n\n";
    
    $reconciliationNotes .= "=== SALES SUMMARY ===\n";
    $reconciliationNotes .= "Total Orders: {$sales['order_count']}\n";
    $reconciliationNotes .= "Total Sales: $" . number_format($sales['total_sales'], 2) . "\n";
    $reconciliationNotes .= "Refunds: $" . number_format($sales['total_refunds'], 2) . "\n";
    $reconciliationNotes .= "Discounts: $" . number_format($sales['total_discounts'], 2) . "\n\n";
    
    $reconciliationNotes .= "=== CASH RECONCILIATION ===\n";
    $reconciliationNotes .= "Opening Balance: $" . number_format($openingCash, 2) . "\n";
    $reconciliationNotes .= "Expected Cash: $" . number_format($expectedCash, 2) . "\n";
    $reconciliationNotes .= "Actual Cash: $" . number_format($actualCash, 2) . "\n";
    $reconciliationNotes .= "Cash Variance: $" . number_format($cashVariance, 2) . "\n";
    $reconciliationNotes .= "Total Variance: $" . number_format($totalVariance, 2) . "\n";
    
    if ($hasSignificantVariance) {
        $reconciliationNotes .= "\n⚠️ SIGNIFICANT VARIANCE DETECTED\n";
    }
    
    if ($notes) {
        $reconciliationNotes .= "\nCashier Notes: " . $notes . "\n";
    }
    
    // Process denomination breakdown if provided
    if ($denominations && is_array($denominations)) {
        $reconciliationNotes .= "\n=== DENOMINATION BREAKDOWN ===\n";
        foreach ($denominations as $denom => $count) {
            if ($count > 0) {
                $value = (float)$denom * (int)$count;
                $reconciliationNotes .= "$" . $denom . " x " . $count . " = $" . number_format($value, 2) . "\n";
            }
        }
    }
    
    // Update shift record - close and reconcile in one step
    $updateStmt = $pdo->prepare("
        UPDATE pos_shifts SET
            ended_at = NOW(),
            ended_by = ?,
            reconciled_at = NOW(),
            reconciled_by = ?,
            status = 'reconciled',
            total_sales = ?,
            total_refunds = ?,
            total_discounts = ?,
            total_tips = ?,
            total_service_charge = ?,
            actual_cash = ?,
            actual_card = ?,
            actual_other = ?,
            cash_variance = ?,
            card_variance = ?,
            other_variance = ?,
            total_variance = ?,
            order_count = ?,
            customer_count = ?,
            reconciliation_notes = ?
        WHERE id = ?
    ");
    
    $updateStmt->execute([
        $userId,                        // ended_by
        $userId,                        // reconciled_by
        $sales['total_sales'],
        $sales['total_refunds'],
        $sales['total_discounts'],
        $sales['total_tips'] ?? 0,
        $sales['total_service_charge'] ?? 0,
        $actualCash,
        $actualCard,
        $actualOther,
        $cashVariance,
        $cardVariance,
        $otherVariance,
        $totalVariance,
        $sales['order_count'],
        $sales['customer_count'],
        $reconciliationNotes,
        $shift['id']
    ]);
    
    // Create audit log entry if table exists
    try {
        $auditStmt = $pdo->prepare("
            INSERT INTO order_logs (
                order_id, tenant_id, branch_id, user_id,
                action, details, created_at
            ) VALUES (
                0, ?, ?, ?, 'shift_completed', ?, NOW()
            )
        ");
        
        $auditDetails = json_encode([
            'shift_id' => $shift['id'],
            'shift_number' => $shift['shift_number'],
            'duration_hours' => $hours,
            'total_sales' => $sales['total_sales'],
            'total_variance' => $totalVariance,
            'has_significant_variance' => $hasSignificantVariance
        ]);
        
        $auditStmt->execute([$tenantId, $branchId, $userId, $auditDetails]);
    } catch (Exception $e) {
        // Ignore if audit table doesn't exist
    }
    
    // Clear session data
    unset($_SESSION['shift_id']);
    unset($_SESSION['shift_number']);
    unset($_SESSION['shift_opening_balance']);
    unset($_SESSION['shift_started_at']);
    
    // Commit transaction
    $pdo->commit();
    
    // Return comprehensive response
    echo json_encode([
        'success' => true,
        'message' => $hasSignificantVariance ? 
            'Shift completed with variance. Manager review required.' : 
            'Shift completed successfully',
        'shift' => [
            'id' => (int)$shift['id'],
            'shift_number' => $shift['shift_number'],
            'shift_date' => $shift['shift_date'],
            'duration_hours' => $hours,
            'closed_by' => $userName,
            'status' => 'reconciled'
        ],
        'summary' => [
            'opening_balance' => round($openingCash, 2),
            'total_sales' => round((float)$sales['total_sales'], 2),
            'total_refunds' => round((float)$sales['total_refunds'], 2),
            'total_discounts' => round((float)$sales['total_discounts'], 2),
            'total_tips' => round((float)$sales['total_tips'], 2),
            'total_tax' => round((float)$sales['total_tax'], 2),
            'order_count' => (int)$sales['order_count'],
            'customer_count' => (int)$sales['customer_count']
        ],
        'reconciliation' => [
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
                'status' => $hasSignificantVariance ? 'warning' : 'ok',
                'requires_review' => $hasSignificantVariance
            ]
        ]
    ]);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    // Log error for debugging (but don't expose details to client)
    error_log('Shift complete error: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Failed to complete shift. Please try again.'
    ]);
}
?>
