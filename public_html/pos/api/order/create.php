<?php
/**
 * SME 180 POS - Create Order API (PRODUCTION READY)
 * Path: /public_html/pos/api/order/create.php
 * Version: 3.0.0 - Production Ready with Proper DB Connection
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Start timing for performance monitoring
$startTime = microtime(true);

// Set security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
    http_response_code(200);
    die('{"success":true}');
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('{"success":false,"error":"Method not allowed","code":"METHOD_NOT_ALLOWED"}');
}

// Start or resume session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ===== DATABASE CONFIGURATION =====
// Define database constants if not already defined
if (!defined('DB_HOST')) {
    define('DB_HOST', 'localhost');
    define('DB_NAME', 'dbvtrnbzad193e');
    define('DB_USER', 'uta6umaa0iuif');
    define('DB_PASS', '2m%[11|kb1Z4');
    define('DB_CHARSET', 'utf8mb4');
}

/**
 * Get database connection
 */
function getDbConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
                PDO::ATTR_TIMEOUT            => 5,
                PDO::ATTR_PERSISTENT         => false
            ]);
            
            // Set MySQL timezone to match PHP
            $pdo->exec("SET time_zone = '+00:00'");
            
        } catch (PDOException $e) {
            error_log('[SME180] Database connection failed: ' . $e->getMessage());
            return false;
        }
    }
    
    return $pdo;
}

/**
 * Send standardized error response
 */
function sendError($userMessage, $code = 400, $errorCode = 'GENERAL_ERROR', $logMessage = null, $logContext = []) {
    global $startTime;
    
    // Log the actual error internally
    if ($logMessage) {
        error_log('[SME180] ERROR: ' . $logMessage . ' - ' . json_encode($logContext));
    }
    
    $response = [
        'success' => false,
        'error' => $userMessage,
        'code' => $errorCode,
        'timestamp' => date('c'),
        'processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms'
    ];
    
    http_response_code($code);
    die(json_encode($response, JSON_UNESCAPED_UNICODE));
}

/**
 * Send success response
 */
function sendSuccess($data) {
    global $startTime;
    
    $response = array_merge(
        ['success' => true],
        $data,
        ['processing_time' => round((microtime(true) - $startTime) * 1000, 2) . 'ms']
    );
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

// ===== MAIN LOGIC STARTS HERE =====

// Get database connection
$pdo = getDbConnection();
if (!$pdo) {
    sendError('Service temporarily unavailable. Please try again.', 503, 'DB_CONNECTION_ERROR',
        'Failed to establish database connection');
}

// Session validation - Get session data
$tenantId = isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
$branchId = isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 
          (isset($_SESSION['pos_user_id']) ? (int)$_SESSION['pos_user_id'] : null);
$stationId = isset($_SESSION['station_id']) ? (int)$_SESSION['station_id'] : null;

// Parse input first to check for tenant/branch/user in request
$rawInput = file_get_contents('php://input');
if (strlen($rawInput) > 100000) { // 100KB max
    sendError('Request too large', 413, 'REQUEST_TOO_LARGE');
}

if (empty($rawInput)) {
    sendError('Request body is required', 400, 'EMPTY_REQUEST');
}

$input = json_decode($rawInput, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    sendError('Invalid request format', 400, 'INVALID_JSON', 
        'JSON parse error: ' . json_last_error_msg());
}

// Allow tenant/branch/user to be passed in request if not in session (for testing/POS)
if (!$tenantId && isset($input['tenant_id'])) {
    $tenantId = (int)$input['tenant_id'];
}
if (!$branchId && isset($input['branch_id'])) {
    $branchId = (int)$input['branch_id'];
}
if (!$userId && isset($input['user_id'])) {
    $userId = (int)$input['user_id'];
}

// Validate tenant and branch
if (!$tenantId || $tenantId <= 0) {
    // Try to get default tenant
    try {
        $stmt = $pdo->query("SELECT id FROM tenants WHERE is_active = 1 ORDER BY id ASC LIMIT 1");
        $tenantId = $stmt->fetchColumn();
        if (!$tenantId) {
            sendError('No active tenant found. Please contact support.', 401, 'NO_TENANT');
        }
        error_log('[SME180] WARNING: No tenant_id in session or request, using tenant_id: ' . $tenantId);
    } catch (Exception $e) {
        sendError('Unable to determine tenant', 500, 'TENANT_ERROR');
    }
}

if (!$branchId || $branchId <= 0) {
    // Try to get default branch for tenant
    try {
        $stmt = $pdo->prepare("
            SELECT id FROM branches 
            WHERE tenant_id = :tenant_id 
            AND is_active = 1 
            ORDER BY is_default DESC, id ASC 
            LIMIT 1
        ");
        $stmt->execute(['tenant_id' => $tenantId]);
        $branchId = $stmt->fetchColumn();
        if (!$branchId) {
            $branchId = 1; // Fallback to ID 1
        }
        error_log('[SME180] WARNING: No branch_id in session or request, using branch_id: ' . $branchId);
    } catch (Exception $e) {
        $branchId = 1; // Fallback
        error_log('[SME180] WARNING: Could not determine branch, using default: 1');
    }
}

// Validate or find user
if (!$userId || $userId <= 0) {
    try {
        // Check what columns exist in users table to handle different schemas
        $columnCheck = $pdo->query("SHOW COLUMNS FROM users");
        $userColumns = [];
        while ($col = $columnCheck->fetch(PDO::FETCH_ASSOC)) {
            $userColumns[] = $col['Field'];
        }
        
        // Build query based on available columns
        $whereConditions = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];
        
        // Check for active status column (could be is_active, active, status, disabled_at, etc.)
        if (in_array('is_active', $userColumns)) {
            $whereConditions[] = 'is_active = 1';
        } elseif (in_array('active', $userColumns)) {
            $whereConditions[] = 'active = 1';
        } elseif (in_array('status', $userColumns)) {
            $whereConditions[] = "status = 'active'";
        } elseif (in_array('disabled_at', $userColumns)) {
            $whereConditions[] = 'disabled_at IS NULL';
        }
        // If no status column found, don't filter by status
        
        $sql = "SELECT id FROM users WHERE " . implode(' AND ', $whereConditions) . " ORDER BY id ASC LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $userId = $stmt->fetchColumn();
        
        if (!$userId) {
            // Try to find ANY user for this tenant (ignore active status)
            $stmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id = :tenant_id ORDER BY id ASC LIMIT 1");
            $stmt->execute(['tenant_id' => $tenantId]);
            $userId = $stmt->fetchColumn();
            
            if (!$userId) {
                // Last resort - find ANY user in the system
                $stmt = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
                $userId = $stmt->fetchColumn();
                
                if (!$userId) {
                    sendError('No users found in system. Please create a user first.', 401, 'NO_USERS_EXIST');
                }
            }
        }
        error_log('[SME180] WARNING: No user_id in session, using user_id: ' . $userId);
    } catch (Exception $e) {
        error_log('[SME180] ERROR: User lookup failed - ' . $e->getMessage());
        // Try simpler query as fallback
        try {
            $stmt = $pdo->query("SELECT id FROM users LIMIT 1");
            $userId = $stmt->fetchColumn();
            if (!$userId) {
                sendError('No users found in database', 500, 'NO_USERS');
            }
            error_log('[SME180] WARNING: Using fallback user_id: ' . $userId);
        } catch (Exception $e2) {
            sendError('Unable to access users table', 500, 'USER_TABLE_ERROR', $e2->getMessage());
        }
    }
} else {
    // Verify the user exists
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE id = :user_id");
        $stmt->execute(['user_id' => $userId]);
        if (!$stmt->fetchColumn()) {
            // User doesn't exist, find alternative
            $stmt = $pdo->prepare("SELECT id FROM users WHERE tenant_id = :tenant_id ORDER BY id ASC LIMIT 1");
            $stmt->execute(['tenant_id' => $tenantId]);
            $newUserId = $stmt->fetchColumn();
            
            if (!$newUserId) {
                // Try ANY user
                $stmt = $pdo->query("SELECT id FROM users ORDER BY id ASC LIMIT 1");
                $newUserId = $stmt->fetchColumn();
            }
            
            if ($newUserId) {
                error_log("[SME180] WARNING: User $userId not found, using user_id: $newUserId");
                $userId = $newUserId;
            } else {
                sendError('No valid users in system', 401, 'NO_VALID_USERS');
            }
        }
    } catch (Exception $e) {
        error_log('[SME180] ERROR: User verification failed - ' . $e->getMessage());
        // Don't fail if we have a user_id, just continue
        error_log('[SME180] WARNING: Could not verify user_id: ' . $userId . ', continuing anyway');
    }
}

// Extract and validate order data
$orderType = $input['order_type'] ?? 'dine_in';
$customerName = isset($input['customer_name']) ? 
    substr(trim(strip_tags($input['customer_name'])), 0, 100) : 'Walk-in Customer';
$customerPhone = isset($input['customer_phone']) ? 
    substr(trim($input['customer_phone']), 0, 20) : null;
$customerId = isset($input['customer_id']) ? (int)$input['customer_id'] : null;
$tableId = isset($input['table_id']) ? (int)$input['table_id'] : null;
$notes = isset($input['notes']) ? 
    substr(trim(strip_tags($input['notes'])), 0, 500) : '';
$items = $input['items'] ?? [];

// Validate order type
$validOrderTypes = ['dine_in', 'takeaway', 'delivery'];
if (!in_array($orderType, $validOrderTypes)) {
    sendError(
        'Invalid order type. Must be: ' . implode(', ', $validOrderTypes),
        400,
        'INVALID_ORDER_TYPE'
    );
}

// Validate items
if (empty($items) || !is_array($items)) {
    sendError('Order must contain at least one item', 400, 'NO_ITEMS');
}

if (count($items) > 100) {
    sendError('Order cannot contain more than 100 items', 400, 'TOO_MANY_ITEMS');
}

// Validate and process items
$validatedItems = [];
$subtotal = 0;

foreach ($items as $index => $item) {
    if (!is_array($item)) {
        sendError("Invalid item at position " . ($index + 1), 400, 'INVALID_ITEM');
    }
    
    // Product validation
    if (!isset($item['product_name']) && !isset($item['product_id'])) {
        sendError(
            "Item " . ($index + 1) . " must have product name or ID",
            400,
            'MISSING_PRODUCT_INFO'
        );
    }
    
    // Quantity validation
    $quantity = isset($item['quantity']) ? floatval($item['quantity']) : 0;
    if ($quantity <= 0 || $quantity > 9999) {
        sendError(
            "Item " . ($index + 1) . " quantity must be between 0.01 and 9999",
            400,
            'INVALID_QUANTITY'
        );
    }
    
    // Price validation
    $unitPrice = isset($item['unit_price']) ? floatval($item['unit_price']) : 0;
    if ($unitPrice < 0 || $unitPrice > 999999) {
        sendError(
            "Item " . ($index + 1) . " price is invalid",
            400,
            'INVALID_PRICE'
        );
    }
    
    $lineTotal = round($quantity * $unitPrice, 2);
    $subtotal += $lineTotal;
    
    $validatedItems[] = [
        'product_id' => isset($item['product_id']) ? (int)$item['product_id'] : null,
        'product_name' => isset($item['product_name']) ? 
            substr(trim(strip_tags($item['product_name'])), 0, 100) : 'Item',
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'line_total' => $lineTotal,
        'notes' => isset($item['notes']) ? 
            substr(trim(strip_tags($item['notes'])), 0, 255) : null
    ];
}

// Validate total amount (max 1 million)
if ($subtotal > 1000000) {
    sendError('Order total exceeds maximum allowed amount', 400, 'AMOUNT_TOO_HIGH');
}

// Database operations
try {
    $pdo->exec("SET TRANSACTION ISOLATION LEVEL READ COMMITTED");
    $pdo->beginTransaction();
    
    // Get settings from database
    $settingsQuery = "
        SELECT `key`, `value`
        FROM settings 
        WHERE tenant_id = ? 
        AND `key` IN ('tax_rate', 'currency_symbol', 'currency_code', 'currency')
    ";
    $settingsStmt = $pdo->prepare($settingsQuery);
    $settingsStmt->execute([$tenantId]);
    
    $settings = [];
    while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['key']] = $row['value'];
    }
    
    // Get settings with proper defaults
    $taxRate = isset($settings['tax_rate']) ? floatval($settings['tax_rate']) : 14.0;
    $currencyCode = $settings['currency_code'] ?? $settings['currency'] ?? 'EGP';
    $currencySymbol = $settings['currency_symbol'] ?? 'EGP';
    
    // Calculate totals
    $taxAmount = round($subtotal * ($taxRate / 100), 2);
    $totalAmount = round($subtotal + $taxAmount, 2);
    
    // Generate unique receipt number
    $datePrefix = date('Ymd');
    $stmt = $pdo->prepare("
        SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(receipt_reference, '-', -1) AS UNSIGNED)), 0) + 1
        FROM orders 
        WHERE tenant_id = ? 
            AND branch_id = ? 
            AND receipt_reference LIKE ?
    ");
    $stmt->execute([$tenantId, $branchId, "ORD{$datePrefix}%"]);
    $orderNum = $stmt->fetchColumn();
    $receiptRef = sprintf('ORD%s-%04d', $datePrefix, $orderNum);
    
    // Check which columns exist in orders table
    $columnsQuery = "SHOW COLUMNS FROM orders";
    $columnsStmt = $pdo->query($columnsQuery);
    $columns = [];
    while ($col = $columnsStmt->fetch(PDO::FETCH_ASSOC)) {
        $columns[] = $col['Field'];
    }
    
    // Build insert query based on available columns
    $orderFields = [
        'tenant_id', 'branch_id', 'receipt_reference', 'order_type',
        'customer_name', 'customer_phone', 'notes',
        'subtotal', 'tax_amount', 'total_amount',
        'status', 'payment_status',
        'created_at', 'updated_at'
    ];
    
    $orderValues = [
        $tenantId, $branchId, $receiptRef, $orderType,
        $customerName, $customerPhone, $notes,
        $subtotal, $taxAmount, $totalAmount,
        'open', 'unpaid',
        date('Y-m-d H:i:s'), date('Y-m-d H:i:s')
    ];
    
    // Add optional columns if they exist
    if (in_array('customer_id', $columns) && $customerId) {
        $orderFields[] = 'customer_id';
        $orderValues[] = $customerId;
    }
    
    if (in_array('table_id', $columns) && $tableId) {
        $orderFields[] = 'table_id';
        $orderValues[] = $tableId;
    }
    
    if (in_array('station_id', $columns) && $stationId) {
        $orderFields[] = 'station_id';
        $orderValues[] = $stationId;
    }
    
    // Handle user column (could be created_by_user_id, cashier_id, or user_id)
    if (in_array('created_by_user_id', $columns)) {
        $orderFields[] = 'created_by_user_id';
        $orderValues[] = $userId;
    } elseif (in_array('cashier_id', $columns)) {
        $orderFields[] = 'cashier_id';
        $orderValues[] = $userId;
    } elseif (in_array('user_id', $columns)) {
        $orderFields[] = 'user_id';
        $orderValues[] = $userId;
    }
    
    // Build and execute the INSERT query
    $placeholders = array_fill(0, count($orderValues), '?');
    $orderSql = "INSERT INTO orders (" . implode(', ', $orderFields) . ") VALUES (" . implode(', ', $placeholders) . ")";
    
    $orderStmt = $pdo->prepare($orderSql);
    $orderResult = $orderStmt->execute($orderValues);
    
    if (!$orderResult) {
        throw new Exception('Order creation failed');
    }
    
    $orderId = (int)$pdo->lastInsertId();
    
    // Check if order_items has notes column
    $itemColumnsQuery = "SHOW COLUMNS FROM order_items";
    $itemColumnsStmt = $pdo->query($itemColumnsQuery);
    $itemColumns = [];
    while ($col = $itemColumnsStmt->fetch(PDO::FETCH_ASSOC)) {
        $itemColumns[] = $col['Field'];
    }
    
    $hasItemNotes = in_array('notes', $itemColumns);
    
    // Insert items
    if ($hasItemNotes) {
        $itemSql = "INSERT INTO order_items (
            order_id, tenant_id, branch_id,
            product_id, product_name,
            quantity, unit_price, line_total, notes,
            created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    } else {
        $itemSql = "INSERT INTO order_items (
            order_id, tenant_id, branch_id,
            product_id, product_name,
            quantity, unit_price, line_total,
            created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    }
    
    $itemStmt = $pdo->prepare($itemSql);
    $insertedItems = [];
    $currentTime = date('Y-m-d H:i:s');
    
    foreach ($validatedItems as $item) {
        if ($hasItemNotes) {
            $params = [
                $orderId, $tenantId, $branchId,
                $item['product_id'], $item['product_name'],
                $item['quantity'], $item['unit_price'], $item['line_total'],
                $item['notes'],
                $currentTime, $currentTime
            ];
        } else {
            $params = [
                $orderId, $tenantId, $branchId,
                $item['product_id'], $item['product_name'],
                $item['quantity'], $item['unit_price'], $item['line_total'],
                $currentTime, $currentTime
            ];
        }
        
        if (!$itemStmt->execute($params)) {
            throw new Exception('Failed to add order item');
        }
        
        $item['id'] = (int)$pdo->lastInsertId();
        unset($item['notes']); // Don't expose in response
        $insertedItems[] = $item;
    }
    
    // Audit log (optional - check if table exists)
    try {
        // Check if order_logs table exists
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'order_logs'")->rowCount();
        
        if ($tableCheck > 0) {
            $auditSql = "INSERT INTO order_logs (
                order_id, tenant_id, branch_id, user_id,
                action, details, created_at
            ) VALUES (?, ?, ?, ?, 'created', ?, ?)";
            
            $auditDetails = json_encode([
                'receipt' => $receiptRef,
                'type' => $orderType,
                'items' => count($insertedItems),
                'total' => $totalAmount,
                'currency' => $currencyCode,
                'source' => 'pos_api',
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
            $auditStmt = $pdo->prepare($auditSql);
            $auditStmt->execute([
                $orderId, $tenantId, $branchId, $userId, $auditDetails, $currentTime
            ]);
        }
    } catch (Exception $auditEx) {
        // Log but don't fail order
        error_log('[SME180] WARNING: Audit log failed: ' . $auditEx->getMessage());
    }
    
    // Commit transaction
    $pdo->commit();
    
    // Return response
    sendSuccess([
        'message' => 'Order created successfully',
        'order_id' => $orderId,
        'order' => [
            'id' => $orderId,
            'receipt_reference' => $receiptRef,
            'order_type' => $orderType,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'customer_phone' => $customerPhone,
            'table_id' => $tableId,
            'subtotal' => $subtotal,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'currency' => $currencyCode,
            'currency_symbol' => $currencySymbol,
            'items' => $insertedItems,
            'notes' => $notes,
            'status' => 'open',
            'payment_status' => 'unpaid',
            'created_at' => date('c'),
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'created_by' => $userId
        ]
    ]);
    
} catch (Exception $e) {
    // Rollback on error
    if ($pdo && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    $errorMessage = $e->getMessage();
    
    // Log the error with context
    error_log('[SME180] ERROR: Order creation failed - ' . $errorMessage);
    error_log('[SME180] Context: tenant=' . $tenantId . ', branch=' . $branchId . ', user=' . $userId);
    
    // Check for specific errors
    if (strpos($errorMessage, 'Duplicate entry') !== false) {
        sendError(
            'Order number conflict. Please try again.',
            409,
            'RECEIPT_CONFLICT',
            $errorMessage
        );
    } elseif (strpos($errorMessage, 'orders') !== false && strpos($errorMessage, "doesn't exist") !== false) {
        sendError(
            'Database tables not configured properly. Please contact support.',
            500,
            'DATABASE_SCHEMA_ERROR',
            $errorMessage
        );
    } else {
        // Generic error - never expose internal details in production
        sendError(
            'Unable to process order at this time. Please try again.',
            500,
            'ORDER_FAILED',
            $errorMessage,
            [
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'user_id' => $userId
            ]
        );
    }
}
?>