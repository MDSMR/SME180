<?php
/**
 * SME 180 POS - Get Current Shift API (FIXED)
 * Path: /public_html/pos/api/shifts/current.php
 * 
 * Returns information about the current active shift
 * Fixed: Handles missing opening_cash column by extracting from notes
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include required files
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/pos_auth.php';

// Start session if not started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper function for JSON responses
function json_response($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Authentication check
    pos_auth_require_login();
    $user = pos_get_current_user();
    
    if (!$user) {
        json_response(['success' => false, 'error' => 'Not authenticated'], 401);
    }
    
    // Get tenant and branch from session
    $tenantId = (int)($_SESSION['tenant_id'] ?? 0);
    $branchId = (int)($_SESSION['branch_id'] ?? 0);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $stationId = (int)($_SESSION['station_id'] ?? 0);
    
    if (!$tenantId || !$branchId || !$userId) {
        json_response(['success' => false, 'error' => 'Invalid session'], 401);
    }
    
    // Get database connection
    $pdo = db();
    
    // Check if opening_cash column exists
    $checkColumnStmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'pos_shifts' 
        AND COLUMN_NAME = 'opening_cash'
    ");
    $checkColumnStmt->execute();
    $hasOpeningCashColumn = (bool)$checkColumnStmt->fetchColumn();
    
    // Build query based on available columns
    $selectOpeningCash = $hasOpeningCashColumn ? 's.opening_cash' : '0 as opening_cash';
    
    // Get current active shift
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            $selectOpeningCash,
            u.name as cashier_name,
            st.station_name,
            (
                SELECT COUNT(*) FROM orders 
                WHERE shift_id = s.id 
                AND status != 'voided'
            ) as total_orders,
            (
                SELECT SUM(total_amount) FROM orders 
                WHERE shift_id = s.id 
                AND status != 'voided'
                AND payment_status = 'paid'
            ) as total_sales,
            (
                SELECT SUM(amount) FROM order_payments 
                WHERE order_id IN (
                    SELECT id FROM orders WHERE shift_id = s.id
                ) AND payment_method = 'cash'
            ) as cash_sales,
            (
                SELECT SUM(amount) FROM order_payments 
                WHERE order_id IN (
                    SELECT id FROM orders WHERE shift_id = s.id
                ) AND payment_method = 'card'
            ) as card_sales,
            (
                SELECT SUM(amount) FROM order_payments 
                WHERE order_id IN (
                    SELECT id FROM orders WHERE shift_id = s.id
                ) AND payment_method NOT IN ('cash', 'card')
            ) as other_sales
        FROM pos_shifts s
        LEFT JOIN users u ON u.id = s.started_by
        LEFT JOIN pos_stations st ON st.id = s.station_id
        WHERE s.tenant_id = :tenant_id
        AND s.branch_id = :branch_id
        AND s.started_by = :user_id
        AND s.status = 'open'
        ORDER BY s.started_at DESC
        LIMIT 1
    ");
    
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'user_id' => $userId
    ]);
    
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shift) {
        json_response([
            'success' => true,
            'has_shift' => false,
            'message' => 'No active shift found'
        ]);
    }
    
    // Extract opening balance from notes if column doesn't exist
    $openingCash = 0.00;
    if ($hasOpeningCashColumn) {
        $openingCash = (float)($shift['opening_cash'] ?? 0);
    } else {
        // Extract from notes field (format: "Opening Balance: 300.00")
        if (!empty($shift['notes']) && preg_match('/Opening Balance:\s*[\$]?([\d,]+\.?\d*)/', $shift['notes'], $matches)) {
            $openingCash = floatval(str_replace(',', '', $matches[1]));
        }
        // Also check session as fallback
        if ($openingCash == 0 && isset($_SESSION['shift_opening_balance'])) {
            $openingCash = (float)$_SESSION['shift_opening_balance'];
        }
    }
    
    // Calculate shift duration
    $openedAt = strtotime($shift['started_at']);
    $duration = time() - $openedAt;
    $hours = floor($duration / 3600);
    $minutes = floor(($duration % 3600) / 60);
    
    // Get recent transactions
    $stmt = $pdo->prepare("
        SELECT 
            o.id,
            o.receipt_reference,
            o.total_amount,
            o.payment_method,
            o.created_at,
            o.payment_status
        FROM orders o
        WHERE o.shift_id = :shift_id
        ORDER BY o.created_at DESC
        LIMIT 10
    ");
    
    $stmt->execute(['shift_id' => $shift['id']]);
    $recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get cash movements (if table exists)
    $cashIn = 0;
    $cashOut = 0;
    
    try {
        $stmt = $pdo->prepare("
            SELECT 
                type,
                SUM(amount) as total
            FROM cash_movements
            WHERE shift_id = :shift_id
            GROUP BY type
        ");
        
        $stmt->execute(['shift_id' => $shift['id']]);
        $movements = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $cashIn = (float)($movements['cash_in'] ?? 0);
        $cashOut = (float)($movements['cash_out'] ?? 0);
    } catch (PDOException $e) {
        // Table might not exist, ignore
    }
    
    // Calculate expected cash
    $expectedCash = $openingCash
                  + (float)($shift['cash_sales'] ?? 0)
                  + $cashIn
                  - $cashOut;
    
    // Build response
    $response = [
        'success' => true,
        'has_shift' => true,
        'shift' => [
            'id' => (int)$shift['id'],
            'shift_number' => $shift['shift_number'],
            'cashier_id' => (int)$shift['started_by'],
            'cashier_name' => $shift['cashier_name'],
            'station_id' => $stationId,
            'station_name' => $shift['station_name'] ?? 'Station ' . $stationId,
            'opened_at' => $shift['started_at'],
            'opening_cash' => round($openingCash, 2),
            'status' => $shift['status'],
            'duration' => [
                'hours' => $hours,
                'minutes' => $minutes,
                'formatted' => sprintf('%d:%02d', $hours, $minutes)
            ]
        ],
        'statistics' => [
            'total_orders' => (int)($shift['total_orders'] ?? 0),
            'total_sales' => round((float)($shift['total_sales'] ?? 0), 2),
            'cash_sales' => round((float)($shift['cash_sales'] ?? 0), 2),
            'card_sales' => round((float)($shift['card_sales'] ?? 0), 2),
            'other_sales' => round((float)($shift['other_sales'] ?? 0), 2),
            'cash_movements' => [
                'in' => round($cashIn, 2),
                'out' => round($cashOut, 2)
            ],
            'expected_cash' => round($expectedCash, 2)
        ],
        'recent_orders' => array_map(function($order) {
            return [
                'id' => (int)$order['id'],
                'receipt' => $order['receipt_reference'],
                'amount' => round((float)$order['total_amount'], 2),
                'payment_method' => $order['payment_method'] ?? 'cash',
                'status' => $order['payment_status'],
                'created_at' => $order['created_at']
            ];
        }, $recentOrders)
    ];
    
    json_response($response);
    
} catch (PDOException $e) {
    error_log('Get current shift DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    error_log('Get current shift error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
