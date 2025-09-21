<?php
/**
 * SME 180 POS - KDS Heartbeat API (PRODUCTION READY)
 * Path: /public_html/pos/api/kds/heartbeat.php
 * Version: 3.0.0 - Final Production Version
 * 
 * Heartbeat for KDS screens to maintain connection and monitor status
 */

declare(strict_types=1);

// Production error handling
error_reporting(0);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../../logs/pos_errors.log');

// Configuration
define('API_KEY', 'sme180_pos_api_key_2024');
define('OLD_ORDER_WARNING_MINUTES', 15);
define('OLD_ORDER_CRITICAL_MINUTES', 20);

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Handle OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');
    http_response_code(204);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('{"success":false,"error":"Method not allowed","code":"METHOD_NOT_ALLOWED"}');
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'dbvtrnbzad193e');
define('DB_USER', 'uta6umaa0iuif');
define('DB_PASS', '2m%[11|kb1Z4');

/**
 * Send JSON response
 */
function json_response($data, $statusCode = 200): void {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Database connection
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        json_response(['success' => false, 'error' => 'Invalid JSON input'], 400);
    }
    
    // Authentication
    $apiKey = $input['api_key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
    $tenantId = null;
    $branchId = null;
    
    if ($apiKey === API_KEY) {
        // API key authentication
        $tenantId = isset($input['tenant_id']) ? (int)$input['tenant_id'] : null;
        $branchId = isset($input['branch_id']) ? (int)$input['branch_id'] : null;
    } else {
        // Session authentication
        session_start();
        $tenantId = $_SESSION['tenant_id'] ?? null;
        $branchId = $_SESSION['branch_id'] ?? null;
    }
    
    if (!$tenantId || !$branchId) {
        json_response(['success' => false, 'error' => 'Authentication required'], 401);
    }
    
    // Get screen info
    $screenCode = $input['screen_code'] ?? '';
    $statusData = $input['status_data'] ?? [];
    
    if (empty($screenCode)) {
        json_response(['success' => false, 'error' => 'Screen code is required'], 400);
    }
    
    // Check if pos_kds_screens table exists
    $tableCheck = $pdo->query("SHOW TABLES LIKE 'pos_kds_screens'")->rowCount();
    
    if ($tableCheck > 0) {
        // Check if columns exist
        $stmt = $pdo->query("SHOW COLUMNS FROM pos_kds_screens");
        $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $hasStatusData = in_array('status_data', $columns);
        $hasIsOnline = in_array('is_online', $columns);
        
        // Build update query based on available columns
        $updateFields = ['last_heartbeat = NOW()'];
        $updateParams = [
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'screen_code' => $screenCode
        ];
        
        if ($hasStatusData) {
            $updateFields[] = 'status_data = :status_data';
            $updateParams['status_data'] = json_encode($statusData);
        }
        
        if ($hasIsOnline) {
            $updateFields[] = 'is_online = 1';
        }
        
        $updateQuery = "UPDATE pos_kds_screens SET " . implode(', ', $updateFields) . 
                      " WHERE tenant_id = :tenant_id AND branch_id = :branch_id AND screen_code = :screen_code";
        
        $stmt = $pdo->prepare($updateQuery);
        $stmt->execute($updateParams);
        
        if ($stmt->rowCount() == 0) {
            // Screen doesn't exist, create it
            try {
                // Build insert query based on available columns
                $insertColumns = ['tenant_id', 'branch_id', 'screen_code', 'screen_name', 'screen_type', 'last_heartbeat', 'is_active', 'created_at'];
                $insertValues = [':tenant_id', ':branch_id', ':screen_code', ':screen_name', ':screen_type', 'NOW()', '1', 'NOW()'];
                $insertParams = [
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId,
                    'screen_code' => $screenCode,
                    'screen_name' => $screenCode . ' Display',
                    'screen_type' => 'kitchen'
                ];
                
                if ($hasStatusData) {
                    $insertColumns[] = 'status_data';
                    $insertValues[] = ':status_data';
                    $insertParams['status_data'] = json_encode($statusData);
                }
                
                if ($hasIsOnline) {
                    $insertColumns[] = 'is_online';
                    $insertValues[] = '1';
                }
                
                $insertQuery = "INSERT INTO pos_kds_screens (" . implode(', ', $insertColumns) . 
                             ") VALUES (" . implode(', ', $insertValues) . ")";
                
                $stmt = $pdo->prepare($insertQuery);
                $stmt->execute($insertParams);
            } catch (Exception $e) {
                // Screen might already exist or table structure is different
                error_log('[SME180 KDS] Failed to create screen: ' . $e->getMessage());
            }
        }
        
        // Mark offline screens (no heartbeat for 1 minute) - only if is_online column exists
        if ($hasIsOnline) {
            $stmt = $pdo->prepare("
                UPDATE pos_kds_screens 
                SET is_online = 0
                WHERE tenant_id = :tenant_id 
                AND branch_id = :branch_id 
                AND last_heartbeat < DATE_SUB(NOW(), INTERVAL 1 MINUTE)
            ");
            $stmt->execute([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId
            ]);
        }
    }
    
    // Get pending orders statistics - FIXED: Use hardcoded values instead of parameters
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(DISTINCT o.id) as pending_orders,
            MIN(COALESCE(o.fired_at, o.created_at)) as oldest_order_time,
            COUNT(DISTINCT CASE 
                WHEN TIMESTAMPDIFF(MINUTE, COALESCE(o.fired_at, o.created_at), NOW()) > " . OLD_ORDER_CRITICAL_MINUTES . "
                THEN o.id 
            END) as critical_orders,
            COUNT(DISTINCT CASE 
                WHEN TIMESTAMPDIFF(MINUTE, COALESCE(o.fired_at, o.created_at), NOW()) > " . OLD_ORDER_WARNING_MINUTES . "
                AND TIMESTAMPDIFF(MINUTE, COALESCE(o.fired_at, o.created_at), NOW()) <= " . OLD_ORDER_CRITICAL_MINUTES . "
                THEN o.id 
            END) as warning_orders
        FROM orders o
        WHERE o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND o.kitchen_status IN ('fired', 'preparing')
        AND o.status NOT IN ('voided', 'refunded')
    ");
    
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $stats = $stmt->fetch();
    
    // Build alerts array
    $alerts = [];
    
    if ($stats['critical_orders'] > 0) {
        $alerts[] = [
            'type' => 'old_order',
            'message' => $stats['critical_orders'] . ' order(s) pending for over ' . OLD_ORDER_CRITICAL_MINUTES . ' minutes',
            'severity' => 'high',
            'count' => $stats['critical_orders']
        ];
        
        // Create notification for critical orders (if notifications table exists)
        try {
            $tableCheck = $pdo->query("SHOW TABLES LIKE 'system_notifications'")->rowCount();
            
            if ($tableCheck > 0) {
                // Check if notification already exists for these orders
                $stmt = $pdo->prepare("
                    SELECT COUNT(*) as count 
                    FROM system_notifications 
                    WHERE tenant_id = :tenant_id 
                    AND branch_id = :branch_id 
                    AND type = 'critical_orders_alert'
                    AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                ");
                $stmt->execute([
                    'tenant_id' => $tenantId,
                    'branch_id' => $branchId
                ]);
                $existing = $stmt->fetch();
                
                if ($existing['count'] == 0) {
                    $stmt = $pdo->prepare("
                        INSERT INTO system_notifications (
                            tenant_id, branch_id, type, title, message,
                            data, created_at
                        ) VALUES (
                            :tenant_id, :branch_id, 'critical_orders_alert', :title, :message,
                            :data, NOW()
                        )
                    ");
                    
                    $stmt->execute([
                        'tenant_id' => $tenantId,
                        'branch_id' => $branchId,
                        'title' => 'Critical: Old Orders Alert',
                        'message' => $stats['critical_orders'] . ' order(s) pending for over ' . OLD_ORDER_CRITICAL_MINUTES . ' minutes',
                        'data' => json_encode([
                            'critical_orders' => $stats['critical_orders'],
                            'oldest_order_time' => $stats['oldest_order_time']
                        ])
                    ]);
                }
            }
        } catch (Exception $e) {
            // Continue without notification if error
            error_log('[SME180 KDS] Notification error: ' . $e->getMessage());
        }
        
    } elseif ($stats['warning_orders'] > 0) {
        $alerts[] = [
            'type' => 'old_order',
            'message' => $stats['warning_orders'] . ' order(s) pending for over ' . OLD_ORDER_WARNING_MINUTES . ' minutes',
            'severity' => 'medium',
            'count' => $stats['warning_orders']
        ];
    }
    
    // Calculate average preparation time for recent orders
    $stmt = $pdo->prepare("
        SELECT 
            AVG(TIMESTAMPDIFF(MINUTE, o.fired_at, o.ready_at)) as avg_prep_time
        FROM orders o
        WHERE o.tenant_id = :tenant_id
        AND o.branch_id = :branch_id
        AND o.ready_at IS NOT NULL
        AND o.fired_at IS NOT NULL
        AND o.ready_at >= DATE_SUB(NOW(), INTERVAL 4 HOUR)
    ");
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $prepTime = $stmt->fetch();
    
    // Get screen status (other screens)
    $screenStatus = [];
    if ($tableCheck > 0) {
        // Build query based on available columns
        $selectColumns = ['screen_code', 'screen_name', 'last_heartbeat'];
        if ($hasIsOnline) {
            $selectColumns[] = 'is_online';
        }
        
        $stmt = $pdo->prepare("
            SELECT " . implode(', ', $selectColumns) . "
            FROM pos_kds_screens
            WHERE tenant_id = :tenant_id
            AND branch_id = :branch_id
            AND screen_code != :current_screen
        ");
        $stmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'current_screen' => $screenCode
        ]);
        $screenStatus = $stmt->fetchAll();
        
        // Add is_online status if column doesn't exist (for backward compatibility)
        if (!$hasIsOnline) {
            foreach ($screenStatus as &$screen) {
                $lastHeartbeat = strtotime($screen['last_heartbeat'] ?? '2000-01-01');
                $screen['is_online'] = (time() - $lastHeartbeat) < 60 ? 1 : 0;
            }
        }
    }
    
    json_response([
        'success' => true,
        'screen_code' => $screenCode,
        'heartbeat_time' => date('Y-m-d H:i:s'),
        'server_time' => date('Y-m-d H:i:s'),
        'stats' => [
            'pending_orders' => (int)$stats['pending_orders'],
            'critical_orders' => (int)$stats['critical_orders'],
            'warning_orders' => (int)$stats['warning_orders'],
            'avg_prep_minutes' => round((float)($prepTime['avg_prep_time'] ?? 0), 1)
        ],
        'alerts' => $alerts,
        'other_screens' => $screenStatus,
        'refresh_interval' => 30000 // 30 seconds
    ]);
    
} catch (PDOException $e) {
    error_log('[SME180 KDS Heartbeat] Database error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Database error'], 500);
} catch (Exception $e) {
    error_log('[SME180 KDS Heartbeat] Error: ' . $e->getMessage());
    json_response(['success' => false, 'error' => 'Service error'], 500);
}
?>