<?php
/**
 * SME 180 POS - Fire to Kitchen API (Production Ready)
 * Path: /public_html/pos/api/order/fire.php
 * Version: 5.0.0 - Production Ready with Variations
 */

declare(strict_types=1);

// Production settings
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Configuration
define('API_TEST_MODE', true); // CHANGE TO false IN PRODUCTION
define('MAX_REQUEST_SIZE', 50000); // 50KB

// Performance monitoring
$startTime = microtime(true);

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

// Only POST allowed
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode([
        'success' => false,
        'error' => 'Method not allowed. Use POST.',
        'code' => 'METHOD_NOT_ALLOWED'
    ]));
}

/**
 * Send error response
 */
function sendError(string $message, int $code = 400, string $errorCode = 'ERROR'): void {
    global $startTime;
    
    error_log("[SME180] $errorCode: $message");
    
    http_response_code($code);
    die(json_encode([
        'success' => false,
        'error' => $message,
        'code' => $errorCode,
        'timestamp' => date('c'),
        'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * Send success response
 */
function sendSuccess(array $data): void {
    global $startTime;
    
    echo json_encode(array_merge(
        ['success' => true],
        $data,
        [
            'timestamp' => date('c'),
            'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
        ]
    ), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// Parse and validate input
$rawInput = file_get_contents('php://input');
if (strlen($rawInput) > MAX_REQUEST_SIZE) {
    sendError('Request too large', 413, 'REQUEST_TOO_LARGE');
}

if (empty($rawInput)) {
    sendError('Request body is required', 400, 'EMPTY_REQUEST');
}

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid JSON: ' . json_last_error_msg(), 400, 'INVALID_JSON');
}

// Load database configuration
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/auth_login.php';

try {
    $pdo = db();
    if (!$pdo) {
        throw new Exception('Database connection not available');
    }
} catch (Exception $e) {
    sendError('Service temporarily unavailable', 503, 'DATABASE_ERROR');
}

// Authentication with test mode support
use_backend_session();
$authenticated = false;
$tenantId = null;
$branchId = null;
$userId = null;

// Check session authentication
$user = auth_user();
if ($user) {
    $authenticated = true;
    $tenantId = auth_get_tenant_id();
    $branchId = auth_get_branch_id();
    $userId = (int)($user['id'] ?? 0);
}

// Test mode fallback
if (!$authenticated && API_TEST_MODE) {
    $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : 1;
    $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : 1;
    $userId = isset($input['user_id']) ? (int)$input['user_id'] : 1;
    error_log("[SME180] WARNING: Using test mode authentication");
} elseif (!$authenticated) {
    sendError('Authentication required', 401, 'UNAUTHORIZED');
}

// Get order ID
$orderId = isset($input['order_id']) ? (int)$input['order_id'] : 0;

// If no order ID, get most recent active order
if (!$orderId) {
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM orders 
            WHERE tenant_id = :tenant_id 
            AND status NOT IN ('cancelled', 'voided', 'completed')
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        $orderId = (int)$stmt->fetchColumn();
        
        if (!$orderId) {
            sendError('No active orders found', 404, 'NO_ORDERS');
        }
    } catch (PDOException $e) {
        sendError('Failed to find order', 500, 'ORDER_LOOKUP_FAILED');
    }
}

// Validate fire type
$fireType = $input['fire_type'] ?? 'all';
$validFireTypes = ['all', 'selected', 'course'];
if (!in_array($fireType, $validFireTypes, true)) {
    sendError('Invalid fire type. Must be: ' . implode(', ', $validFireTypes), 400, 'INVALID_FIRE_TYPE');
}

// Get item IDs for selected fire
$itemIds = [];
if ($fireType === 'selected') {
    if (!isset($input['item_ids']) || !is_array($input['item_ids'])) {
        sendError('item_ids array required for selected fire type', 400, 'MISSING_ITEM_IDS');
    }
    $itemIds = array_filter(array_map('intval', $input['item_ids']), function($id) {
        return $id > 0;
    });
    if (empty($itemIds)) {
        sendError('No valid item IDs provided', 400, 'INVALID_ITEM_IDS');
    }
}

// Get other parameters
$isRush = (bool)($input['is_rush'] ?? false);
$notes = isset($input['notes']) ? substr(trim(strip_tags($input['notes'])), 0, 500) : '';
$courseNumber = isset($input['course_number']) ? (int)$input['course_number'] : 1;

// Process item variations (NEW)
$itemVariations = [];
if (isset($input['item_variations']) && is_array($input['item_variations'])) {
    foreach ($input['item_variations'] as $itemId => $variations) {
        $itemId = (int)$itemId;
        if ($itemId > 0 && is_array($variations)) {
            $clean = array_filter(array_map(function($v) {
                return is_string($v) ? substr(trim(strip_tags($v)), 0, 100) : '';
            }, $variations));
            if (!empty($clean)) {
                $itemVariations[$itemId] = $clean;
            }
        }
    }
}

// Process item comments (NEW)
$itemComments = [];
if (isset($input['item_comments']) && is_array($input['item_comments'])) {
    foreach ($input['item_comments'] as $itemId => $comment) {
        $itemId = (int)$itemId;
        if ($itemId > 0 && is_string($comment)) {
            $clean = substr(trim(strip_tags($comment)), 0, 255);
            if ($clean) {
                $itemComments[$itemId] = $clean;
            }
        }
    }
}

// Main processing
try {
    $pdo->beginTransaction();
    
    // Get and lock order
    $stmt = $pdo->prepare("
        SELECT * FROM orders 
        WHERE id = :order_id 
        AND tenant_id = :tenant_id
        FOR UPDATE
    ");
    $stmt->execute([
        'order_id' => $orderId,
        'tenant_id' => $tenantId
    ]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception("Order not found");
    }
    
    // Verify branch access (skip in test mode)
    if ($order['branch_id'] != $branchId && !API_TEST_MODE) {
        throw new Exception("Order belongs to different branch");
    }
    
    // Check order status
    if (in_array($order['status'], ['cancelled', 'voided', 'completed', 'refunded'], true)) {
        throw new Exception("Cannot fire items from {$order['status']} order");
    }
    
    // Check what columns exist in order_items
    $cols = $pdo->query("SHOW COLUMNS FROM order_items")->fetchAll(PDO::FETCH_ASSOC);
    $columnNames = array_column($cols, 'Field');
    
    $hasKitchenStatus = in_array('kitchen_status', $columnNames);
    $hasState = in_array('state', $columnNames);
    $hasFiredAt = in_array('fired_at', $columnNames);
    $hasIsVoided = in_array('is_voided', $columnNames);
    $hasKitchenNotes = in_array('kitchen_notes', $columnNames);
    $hasCourse = in_array('course', $columnNames);
    
    // Build query to get items to fire
    $itemQuery = "SELECT * FROM order_items WHERE order_id = :order_id";
    $itemParams = ['order_id' => $orderId];
    
    // Add void filter if column exists
    if ($hasIsVoided) {
        $itemQuery .= " AND (is_voided = 0 OR is_voided IS NULL)";
    }
    
    // Add status filter
    if ($hasKitchenStatus) {
        $itemQuery .= " AND (kitchen_status = 'pending' OR kitchen_status IS NULL OR kitchen_status = '')";
    } elseif ($hasState) {
        $itemQuery .= " AND (state = 'pending' OR state IS NULL OR state = '')";
    }
    
    // Handle fire type
    if ($fireType === 'selected' && !empty($itemIds)) {
        $placeholders = str_repeat('?,', count($itemIds) - 1) . '?';
        $itemQuery .= " AND id IN ($placeholders)";
        foreach ($itemIds as $id) {
            $itemParams[] = $id;
        }
    } elseif ($fireType === 'course' && $hasCourse) {
        $itemQuery .= " AND course = :course";
        $itemParams['course'] = $courseNumber;
    }
    
    $stmt = $pdo->prepare($itemQuery);
    $stmt->execute($itemParams);
    $itemsToFire = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($itemsToFire)) {
        throw new Exception("No items available to fire");
    }
    
    // Update each item
    $firedItems = [];
    foreach ($itemsToFire as $item) {
        $itemId = (int)$item['id'];
        $updateFields = ["updated_at = NOW()"];
        $updateParams = ['item_id' => $itemId];
        
        // Set kitchen status
        if ($hasKitchenStatus) {
            $updateFields[] = "kitchen_status = 'preparing'";
        } elseif ($hasState) {
            $updateFields[] = "state = 'preparing'";
        }
        
        // Set fired timestamp
        if ($hasFiredAt) {
            $updateFields[] = "fired_at = NOW()";
        }
        
        // Build kitchen notes with variations and comments
        if ($hasKitchenNotes) {
            $noteParts = [];
            
            if ($isRush) {
                $noteParts[] = '[RUSH]';
            }
            
            if (isset($itemVariations[$itemId])) {
                $noteParts[] = 'Mods: ' . implode(', ', $itemVariations[$itemId]);
            }
            
            if (isset($itemComments[$itemId])) {
                $noteParts[] = $itemComments[$itemId];
            }
            
            if (!empty($noteParts)) {
                $newNote = implode(' | ', $noteParts);
                $updateFields[] = "kitchen_notes = CASE 
                    WHEN kitchen_notes IS NULL OR kitchen_notes = '' THEN :note
                    ELSE CONCAT(kitchen_notes, ' | ', :note2)
                END";
                $updateParams['note'] = $newNote;
                $updateParams['note2'] = $newNote;
            }
        }
        
        // Execute update
        $updateSql = "UPDATE order_items SET " . implode(", ", $updateFields) . " WHERE id = :item_id";
        $stmt = $pdo->prepare($updateSql);
        $stmt->execute($updateParams);
        
        // Track fired item
        $firedItems[] = [
            'id' => $itemId,
            'product_name' => $item['product_name'],
            'quantity' => (float)$item['quantity'],
            'status' => 'preparing',
            'variations' => $itemVariations[$itemId] ?? [],
            'comment' => $itemComments[$itemId] ?? null
        ];
    }
    
    // Update order status
    $orderUpdateFields = ["updated_at = NOW()"];
    
    // Check order columns
    $orderCols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_ASSOC);
    $orderColumns = array_column($orderCols, 'Field');
    
    if (in_array('kitchen_status', $orderColumns)) {
        $orderUpdateFields[] = "kitchen_status = 'sent'";
    }
    
    if (in_array('fired_at', $orderColumns)) {
        $orderUpdateFields[] = "fired_at = CASE WHEN fired_at IS NULL THEN NOW() ELSE fired_at END";
    }
    
    $stmt = $pdo->prepare("UPDATE orders SET " . implode(", ", $orderUpdateFields) . " WHERE id = :order_id");
    $stmt->execute(['order_id' => $orderId]);
    
    // Audit log (optional)
    try {
        if ($pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount() > 0) {
            $stmt = $pdo->prepare("
                INSERT INTO order_logs (order_id, tenant_id, branch_id, user_id, action, details, created_at)
                VALUES (:order_id, :tenant_id, :branch_id, :user_id, 'fired_to_kitchen', :details, NOW())
            ");
            $stmt->execute([
                'order_id' => $orderId,
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $userId,
                'details' => json_encode([
                    'fire_type' => $fireType,
                    'items_count' => count($firedItems),
                    'is_rush' => $isRush,
                    'notes' => $notes,
                    'items' => array_map(function($item) {
                        return [
                            'id' => $item['id'],
                            'name' => $item['product_name'],
                            'qty' => $item['quantity'],
                            'variations' => $item['variations'],
                            'comment' => $item['comment']
                        ];
                    }, $firedItems)
                ])
            ]);
        }
    } catch (Exception $e) {
        // Audit logging is non-critical
        error_log("[SME180] Audit log failed: " . $e->getMessage());
    }
    
    $pdo->commit();
    
    // Success response
    sendSuccess([
        'message' => 'Sent ' . count($firedItems) . ' items to kitchen',
        'fired_items' => $firedItems,
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $order['receipt_reference'],
            'kitchen_status' => 'sent',
            'items_fired' => count($firedItems),
            'fire_type' => $fireType,
            'is_rush' => $isRush
        ]
    ]);
    
} catch (Exception $e) {
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("[SME180] Fire to kitchen failed: " . $e->getMessage());
    
    if (strpos($e->getMessage(), 'not found') !== false) {
        sendError('Order not found', 404, 'ORDER_NOT_FOUND');
    } elseif (strpos($e->getMessage(), 'Cannot fire') !== false) {
        sendError($e->getMessage(), 400, 'INVALID_STATUS');
    } elseif (strpos($e->getMessage(), 'different branch') !== false) {
        sendError('Access denied', 403, 'ACCESS_DENIED');
    } elseif (strpos($e->getMessage(), 'No items') !== false) {
        sendError('No items available to fire', 404, 'NO_ITEMS_TO_FIRE');
    } else {
        sendError('Failed to send items to kitchen', 500, 'FIRE_FAILED');
    }
}
?>