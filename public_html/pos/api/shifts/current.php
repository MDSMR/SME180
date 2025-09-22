<?php
/**
 * SME 180 POS - Get Current Shift API
 * Path: /public_html/pos/api/shifts/current.php
 * Version: 3.0.0 - Production Ready
 */

// Error handling - suppress warnings that break JSON
error_reporting(0);
ini_set('display_errors', '0');

// Set JSON header immediately
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(json_encode(['success' => true]));
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

try {
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get current active shift
    $stmt = $pdo->prepare("
        SELECT 
            s.*,
            u.name as cashier_name,
            ps.station_name,
            COALESCE((
                SELECT COUNT(*) FROM orders 
                WHERE shift_id = s.id 
                  AND status NOT IN ('voided', 'cancelled')
            ), 0) as total_orders,
            COALESCE((
                SELECT SUM(total_amount) FROM orders 
                WHERE shift_id = s.id 
                  AND status NOT IN ('voided', 'cancelled', 'refunded')
            ), 0) as total_sales,
            COALESCE((
                SELECT SUM(amount) FROM order_payments op
                JOIN orders o ON o.id = op.order_id
                WHERE o.shift_id = s.id 
                  AND op.payment_method = 'cash'
                  AND op.status = 'completed'
            ), 0) as cash_sales,
            COALESCE((
                SELECT SUM(amount) FROM order_payments op
                JOIN orders o ON o.id = op.order_id
                WHERE o.shift_id = s.id 
                  AND op.payment_method = 'card'
                  AND op.status = 'completed'
            ), 0) as card_sales,
            COALESCE((
                SELECT SUM(amount) FROM order_payments op
                JOIN orders o ON o.id = op.order_id
                WHERE o.shift_id = s.id 
                  AND op.payment_method NOT IN ('cash', 'card')
                  AND op.status = 'completed'
            ), 0) as other_sales
        FROM pos_shifts s
        LEFT JOIN users u ON u.id = s.started_by
        LEFT JOIN pos_stations ps ON ps.id = s.station_id
        WHERE s.tenant_id = ?
          AND s.branch_id = ?
          AND s.status = 'open'
        ORDER BY s.started_at DESC
        LIMIT 1
    ");
    
    $stmt->execute([$tenantId, $branchId]);
    $shift = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$shift) {
        // No active shift
        echo json_encode([
            'success' => true,
            'has_shift' => false,
            'has_open_shift' => false,
            'message' => 'No active shift found'
        ]);
        exit;
    }
    
    // Calculate duration
    $startTime = strtotime($shift['started_at']);
    $currentTime = time();
    $duration = $currentTime - $startTime;
    $hours = floor($duration / 3600);
    $minutes = floor(($duration % 3600) / 60);
    $durationFormatted = sprintf('%dh %dm', $hours, $minutes);
    
    // Extract opening cash (handle both column and notes)
    $openingCash = 0.00;
    if (isset($shift['opening_cash']) && $shift['opening_cash'] !== null) {
        $openingCash = (float)$shift['opening_cash'];
    } elseif (!empty($shift['notes'])) {
        // Try to extract from notes if column doesn't exist or is empty
        if (preg_match('/Opening Balance:\s*[\$]?([\d,]+\.?\d*)/', $shift['notes'], $matches)) {
            $openingCash = (float)str_replace(',', '', $matches[1]);
        }
    }
    
    // Get cash movements if table exists
    $cashIn = 0;
    $cashOut = 0;
    try {
        $movementStmt = $pdo->prepare("
            SELECT 
                type,
                SUM(amount) as total
            FROM cash_movements
            WHERE shift_id = ?
            GROUP BY type
        ");
        $movementStmt->execute([$shift['id']]);
        $movements = $movementStmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $cashIn = (float)($movements['cash_in'] ?? 0);
        $cashOut = (float)($movements['cash_out'] ?? 0);
    } catch (Exception $e) {
        // Table might not exist, ignore
    }
    
    // Calculate expected cash in drawer
    $cashSales = (float)($shift['cash_sales'] ?? 0);
    $expectedCash = $openingCash + $cashSales + $cashIn - $cashOut;
    
    // Get recent orders
    $recentOrdersStmt = $pdo->prepare("
        SELECT 
            id,
            receipt_reference,
            total_amount,
            payment_method,
            payment_status,
            created_at
        FROM orders 
        WHERE shift_id = ?
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $recentOrdersStmt->execute([$shift['id']]);
    $recentOrders = $recentOrdersStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Build response
    echo json_encode([
        'success' => true,
        'has_shift' => true,
        'has_open_shift' => true,
        'shift' => [
            'id' => (int)$shift['id'],
            'shift_number' => $shift['shift_number'],
            'shift_date' => $shift['shift_date'],
            'cashier_id' => (int)($shift['cashier_id'] ?? $shift['started_by']),
            'cashier_name' => $shift['cashier_name'] ?? 'Unknown',
            'station_id' => (int)($shift['station_id'] ?? $stationId),
            'station_name' => $shift['station_name'] ?? 'Station ' . $stationId,
            'started_at' => $shift['started_at'],
            'opening_cash' => round($openingCash, 2),
            'status' => $shift['status'],
            'duration' => $durationFormatted,
            'duration_raw' => [
                'hours' => $hours,
                'minutes' => $minutes,
                'seconds' => $duration
            ],
            'current_sales' => [
                'orders_count' => (int)$shift['total_orders'],
                'total_sales' => round((float)$shift['total_sales'], 2),
                'cash_sales' => round((float)$shift['cash_sales'], 2),
                'card_sales' => round((float)$shift['card_sales'], 2),
                'other_sales' => round((float)$shift['other_sales'], 2)
            ],
            'cash_movements' => [
                'in' => round($cashIn, 2),
                'out' => round($cashOut, 2)
            ],
            'expected_drawer' => round($expectedCash, 2)
        ],
        'recent_orders' => array_map(function($order) {
            return [
                'id' => (int)$order['id'],
                'receipt' => $order['receipt_reference'],
                'amount' => round((float)$order['total_amount'], 2),
                'payment' => $order['payment_method'] ?? 'cash',
                'status' => $order['payment_status'],
                'time' => $order['created_at']
            ];
        }, $recentOrders)
    ]);
    
} catch (Exception $e) {
    // Log error for debugging (but don't expose details to client)
    error_log('Get current shift error: ' . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'error' => 'Failed to get shift status'
    ]);
}
?>