<?php
/**
 * SME 180 POS - Get Current Shift API
 * Path: /public_html/pos/api/shifts/current.php
 * 
 * Returns information about the current active shift
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
    
    // Get current active shift
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
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
        LEFT JOIN users u ON u.id = s.cashier_id
        LEFT JOIN pos_stations st ON st.id = s.station_id
        WHERE s.tenant_id = :tenant_id
        AND s.branch_id = :branch_id
        AND s.cashier_id = :user_id
        AND s.status = 'open'
        ORDER BY s.opened_at DESC
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
    
    // Calculate shift duration
    $openedAt = strtotime($shift['opened_at']);
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
    
    // Get cash movements
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
    
    // Calculate expected cash
    $expectedCash = (float)$shift['opening_cash'] 
                  + (float)($shift['cash_sales'] ?? 0) 
                  + $cashIn 
                  - $cashOut;
    
    json_response([
        'success' => true,
        'has_shift' => true,
        'shift' => [
            'id' => (int)$shift['id'],
            'shift_number' => $shift['shift_number'],
            'cashier_id' => (int)$shift['cashier_id'],
            'cashier_name' => $shift['cashier_name'],
            'station_id' => (int)$shift['station_id'],
            'station_name' => $shift['station_name'],
            'opened_at' => $shift['opened_at'],
            'opening_cash' => (float)$shift['opening_cash'],
            'status' => $shift['status'],
            'duration' => [
                'hours' => $hours,
                'minutes' => $minutes,
                'formatted' => sprintf('%d:%02d', $hours, $minutes)
            ]
        ],
        'statistics' => [
            'total_orders' => (int)($shift['total_orders'] ?? 0),
            'total_sales' => (float)($shift['total_sales'] ?? 0),
            'cash_sales' => (float)($shift['cash_sales'] ?? 0),
            'card_sales' => (float)($shift['card_sales'] ?? 0),
            'other_sales' => (float)($shift['other_sales'] ?? 0),
            'cash_movements' => [
                'in' => $cashIn,
                'out' => $cashOut
            ],
            'expected_cash' => $expectedCash
        ],
        'recent_orders' => $recentOrders
    ]);
    
} catch (PDOException $e) {
    error_log('Get current shift DB error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    error_log('Get current shift error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
