<?php
/**
 * SME 180 POS - Apply Item Discount API
 * Path: /public_html/pos/api/order/apply_item_discount.php
 * Version: 1.0.0 - Production Ready
 * 
 * Applies discounts to individual order items
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    http_response_code(204);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'error' => 'Method not allowed']));
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
function sendError($message, $code = 400, $errorCode = 'ERROR') {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'code' => $errorCode,
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function sendSuccess($data) {
    echo json_encode(array_merge(
        ['success' => true],
        $data,
        ['timestamp' => date('c')]
    ), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// Load database
require_once __DIR__ . '/../../../config/db.php';

try {
    $pdo = db();
} catch (Exception $e) {
    sendError('Database connection failed', 503, 'DB_ERROR');
}

// Get session values
$tenantId = $_SESSION['tenant_id'] ?? 1;
$branchId = $_SESSION['branch_id'] ?? 1;
$userId = $_SESSION['user_id'] ?? 1;
$userRole = $_SESSION['role'] ?? 'cashier';

// Parse input
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid JSON', 400, 'INVALID_JSON');
}

// Validate required fields
if (!isset($input['order_id']) || !isset($input['item_id'])) {
    sendError('Order ID and Item ID are required', 400, 'MISSING_FIELDS');
}

$orderId = (int)$input['order_id'];
$itemId = (int)$input['item_id'];
$discountType = $input['discount_type'] ?? 'percent';
$discountValue = (float)($input['discount_value'] ?? 0);
$reason = substr($input['reason'] ?? '', 0, 255);
$managerPin = $input['manager_pin'] ?? '';

// Validate discount
if (!in_array($discountType, ['percent', 'amount'])) {
    sendError('Invalid discount type', 400, 'INVALID_TYPE');
}

if ($discountValue < 0) {
    sendError('Discount value cannot be negative', 400, 'INVALID_VALUE');
}

if ($discountType === 'percent' && $discountValue > 100) {
    sendError('Discount cannot exceed 100%', 400, 'INVALID_PERCENT');
}

try {
    // Get settings
    $stmt = $pdo->prepare("
        SELECT `key`, value FROM settings 
        WHERE tenant_id = ? 
        AND `key` IN ('tax_rate', 'pos_max_item_discount_percent', 'currency')
    ");
    $stmt->execute([$tenantId]);
    
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    $taxRate = (float)($settings['tax_rate'] ?? 14);
    $maxItemDiscount = (float)($settings['pos_max_item_discount_percent'] ?? 30);
    $currency = $settings['currency'] ?? 'EGP';
    
    $pdo->beginTransaction();
    
    // Get order item with order details
    $stmt = $pdo->prepare("
        SELECT oi.*, o.status, o.payment_status, o.tenant_id, o.branch_id,
               o.subtotal as order_subtotal, o.tax_amount, o.total_amount,
               o.service_charge, o.discount_amount as order_discount
        FROM order_items oi
        JOIN orders o ON o.id = oi.order_id
        WHERE oi.id = ? AND oi.order_id = ?
        FOR UPDATE
    ");
    $stmt->execute([$itemId, $orderId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        throw new Exception('Item not found');
    }
    
    // Check if order can be modified
    if (in_array($item['status'], ['closed', 'voided', 'refunded', 'paid'])) {
        throw new Exception('Cannot modify ' . $item['status'] . ' orders');
    }
    
    // Check if item is voided
    if ($item['is_voided'] == 1) {
        throw new Exception('Cannot apply discount to voided item');
    }
    
    // Calculate discount
    $originalPrice = (float)$item['unit_price'] * (float)$item['quantity'];
    $discountAmount = 0;
    $effectivePercent = 0;
    
    if ($discountType === 'percent') {
        $discountAmount = $originalPrice * ($discountValue / 100);
        $effectivePercent = $discountValue;
    } else {
        $discountAmount = min($discountValue, $originalPrice);
        $effectivePercent = ($discountAmount / $originalPrice) * 100;
    }
    
    // Check if manager approval needed
    $requiresApproval = false;
    $approvedBy = $userId;
    
    if ($effectivePercent > $maxItemDiscount) {
        $requiresApproval = true;
        
        if (!in_array($userRole, ['admin', 'manager', 'owner'])) {
            if (!$managerPin) {
                $pdo->commit(); // Release locks
                sendError(
                    "Manager approval required for discount over {$maxItemDiscount}%",
                    403,
                    'APPROVAL_REQUIRED',
                    ['max_allowed' => $maxItemDiscount, 'requested' => round($effectivePercent, 2)]
                );
            }
            
            // Validate manager PIN
            $stmt = $pdo->prepare("
                SELECT id FROM users 
                WHERE tenant_id = ? 
                AND pin = ? 
                AND role IN ('admin', 'manager', 'owner')
                AND is_active = 1
            ");
            $stmt->execute([$tenantId, hash('sha256', $managerPin)]);
            
            if (!$stmt->fetch()) {
                throw new Exception('Invalid manager PIN');
            }
            $approvedBy = $stmt->fetchColumn();
        }
    }
    
    // Check if columns exist and add if needed
    $cols = $pdo->query("SHOW COLUMNS FROM order_items")->fetchAll(PDO::FETCH_COLUMN);
    
    if (!in_array('discount_type', $cols)) {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN discount_type VARCHAR(20) DEFAULT NULL");
    }
    if (!in_array('discount_percent', $cols)) {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN discount_percent DECIMAL(5,2) DEFAULT 0");
    }
    if (!in_array('discount_amount', $cols)) {
        $pdo->exec("ALTER TABLE order_items ADD COLUMN discount_amount DECIMAL(10,2) DEFAULT 0");
    }
    
    // Update item
    $newLineTotal = $originalPrice - $discountAmount;
    
    $stmt = $pdo->prepare("
        UPDATE order_items 
        SET discount_type = ?,
            discount_percent = ?,
            discount_amount = ?,
            line_total = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $discountType,
        $effectivePercent,
        $discountAmount,
        $newLineTotal,
        $itemId
    ]);
    
    // Recalculate order totals
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN is_voided = 0 THEN line_total ELSE 0 END) as new_subtotal,
            SUM(CASE WHEN is_voided = 0 THEN discount_amount ELSE 0 END) as total_item_discounts
        FROM order_items 
        WHERE order_id = ?
    ");
    $stmt->execute([$orderId]);
    $totals = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $newSubtotal = (float)$totals['new_subtotal'];
    $totalItemDiscounts = (float)$totals['total_item_discounts'];
    
    // Apply order-level discount if exists
    $orderDiscount = (float)$item['order_discount'];
    $afterOrderDiscount = $newSubtotal - $orderDiscount;
    
    // Add service charge
    $serviceCharge = (float)$item['service_charge'];
    $taxableAmount = $afterOrderDiscount + $serviceCharge;
    
    // Calculate tax
    $newTaxAmount = $taxableAmount * ($taxRate / 100);
    $newTotal = $taxableAmount + $newTaxAmount;
    
    // Update order totals
    $stmt = $pdo->prepare("
        UPDATE orders 
        SET subtotal = ?,
            tax_amount = ?,
            total_amount = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([
        $newSubtotal,
        $newTaxAmount,
        $newTotal,
        $orderId
    ]);
    
    // Log the discount
    try {
        $stmt = $pdo->prepare("
            INSERT INTO order_logs (
                order_id, tenant_id, branch_id, user_id, action, details, created_at
            ) VALUES (?, ?, ?, ?, 'item_discount', ?, NOW())
        ");
        $stmt->execute([
            $orderId,
            $tenantId,
            $branchId,
            $userId,
            json_encode([
                'item_id' => $itemId,
                'product_name' => $item['product_name'],
                'type' => $discountType,
                'value' => $discountValue,
                'amount' => $discountAmount,
                'percent' => $effectivePercent,
                'reason' => $reason,
                'approved_by' => $approvedBy
            ])
        ]);
    } catch (Exception $e) {
        // Logging is non-critical
    }
    
    $pdo->commit();
    
    sendSuccess([
        'message' => 'Item discount applied successfully',
        'item' => [
            'id' => $itemId,
            'product_name' => $item['product_name'],
            'original_price' => round($originalPrice, 2),
            'discount_amount' => round($discountAmount, 2),
            'discount_percent' => round($effectivePercent, 2),
            'new_line_total' => round($newLineTotal, 2)
        ],
        'order' => [
            'id' => $orderId,
            'new_subtotal' => round($newSubtotal, 2),
            'total_item_discounts' => round($totalItemDiscounts, 2),
            'order_discount' => round($orderDiscount, 2),
            'tax_amount' => round($newTaxAmount, 2),
            'total_amount' => round($newTotal, 2),
            'currency' => $currency
        ],
        'approval' => $requiresApproval ? [
            'required' => true,
            'approved_by' => $approvedBy
        ] : null
    ]);
    
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("[SME180] Item discount failed: " . $e->getMessage());
    sendError($e->getMessage(), 500, 'DISCOUNT_FAILED');
}
?>