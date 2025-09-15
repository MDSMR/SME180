#!/usr/bin/php
<?php
/**
 * Queue Worker Process
 * Run: php /path/to/scripts/queue_worker.php [queue-name]
 */

// Bootstrap application
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/../includes/Queue.php';

use App\Queue\Queue;

// Configuration
$config = [
    'sleep' => 3,           // Sleep seconds when no jobs
    'timeout' => 60,        // Job reservation timeout
    'max_jobs' => 1000,     // Max jobs before restart
    'max_memory' => 128,    // Max memory in MB
];

// Parse command line arguments
$queueName = $argv[1] ?? 'default';
$daemon = in_array('--daemon', $argv);

// Initialize
$jobsProcessed = 0;
$shouldStop = false;

// Signal handlers for graceful shutdown
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function() use (&$shouldStop) {
        $shouldStop = true;
        echo "Received SIGTERM, shutting down gracefully...\n";
    });
    pcntl_signal(SIGINT, function() use (&$shouldStop) {
        $shouldStop = true;
        echo "Received SIGINT, shutting down gracefully...\n";
    });
}

// Log start
echo "[" . date('Y-m-d H:i:s') . "] Queue worker started for queue: $queueName\n";

// Main worker loop
while (!$shouldStop) {
    try {
        // Check memory usage
        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        if ($memoryUsage > $config['max_memory']) {
            echo "Memory limit exceeded ({$memoryUsage}MB), restarting...\n";
            break;
        }
        
        // Check job limit
        if ($jobsProcessed >= $config['max_jobs']) {
            echo "Job limit reached ({$jobsProcessed}), restarting...\n";
            break;
        }
        
        // Get database connection
        $db = getDbConnection();
        $queue = new Queue($db);
        
        // Pop next job
        $job = $queue->pop($queueName, $config['timeout']);
        
        if ($job) {
            $jobId = $job->getId();
            $payload = $job->getPayload();
            
            echo "[" . date('Y-m-d H:i:s') . "] Processing job {$jobId}: {$payload['job']}\n";
            
            try {
                // Process based on job type
                processJob($payload['job'], $payload['data']);
                
                // Delete successful job
                $job->delete();
                $jobsProcessed++;
                
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} completed successfully\n";
                
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} failed: " . $e->getMessage() . "\n";
                $job->fail($e);
            }
        } else {
            // No jobs available, sleep
            if (!$daemon) {
                break; // Exit if not running as daemon
            }
            sleep($config['sleep']);
        }
        
        // Process signals if available
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
    } catch (Exception $e) {
        echo "[" . date('Y-m-d H:i:s') . "] Worker error: " . $e->getMessage() . "\n";
        sleep($config['sleep']);
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Queue worker stopped. Processed {$jobsProcessed} jobs.\n";

/**
 * Process a job based on its type
 */
function processJob($jobType, $data) {
    switch ($jobType) {
        case 'send_email':
            sendEmail($data);
            break;
            
        case 'process_order':
            processOrder($data);
            break;
            
        case 'update_metrics':
            updateMetrics($data);
            break;
            
        case 'generate_report':
            generateReport($data);
            break;
            
        case 'sync_inventory':
            syncInventory($data);
            break;
            
        case 'cleanup_sessions':
            cleanupSessions($data);
            break;
            
        case 'calculate_commissions':
            calculateCommissions($data);
            break;
            
        default:
            throw new Exception("Unknown job type: {$jobType}");
    }
}

/**
 * Job Handlers
 */
function sendEmail($data) {
    // Implement email sending logic
    $to = $data['to'] ?? '';
    $subject = $data['subject'] ?? '';
    $body = $data['body'] ?? '';
    
    if (!$to || !$subject) {
        throw new Exception("Invalid email data");
    }
    
    // Use your email service here
    // mail($to, $subject, $body);
    
    echo "  -> Email sent to {$to}\n";
}

function processOrder($data) {
    $orderId = $data['order_id'] ?? null;
    $tenantId = $data['tenant_id'] ?? null;
    
    if (!$orderId || !$tenantId) {
        throw new Exception("Invalid order data");
    }
    
    $db = getDbConnection();
    
    // Process loyalty points
    $stmt = $db->prepare("
        SELECT o.*, c.rewards_enrolled 
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        WHERE o.id = ? AND o.tenant_id = ?
    ");
    $stmt->execute([$orderId, $tenantId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order && $order['rewards_enrolled']) {
        // Award loyalty points logic here
        echo "  -> Processed loyalty for order {$orderId}\n";
    }
    
    // Update order metrics
    updateOrderMetrics($tenantId, $order['branch_id'], $order);
}

function updateMetrics($data) {
    $tenantId = $data['tenant_id'] ?? null;
    $branchId = $data['branch_id'] ?? null;
    $date = $data['date'] ?? date('Y-m-d');
    
    if (!$tenantId || !$branchId) {
        throw new Exception("Invalid metrics data");
    }
    
    $db = getDbConnection();
    
    // Calculate daily metrics
    $stmt = $db->prepare("
        INSERT INTO metrics_daily (tenant_id, branch_id, metric_date, total_orders, total_revenue, avg_order_value, total_customers, total_items_sold)
        SELECT 
            tenant_id,
            branch_id,
            DATE(created_at) as metric_date,
            COUNT(*) as total_orders,
            SUM(total_amount) as total_revenue,
            AVG(total_amount) as avg_order_value,
            COUNT(DISTINCT customer_id) as total_customers,
            (SELECT SUM(quantity) FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE tenant_id = ? AND branch_id = ? AND DATE(created_at) = ?))
        FROM orders
        WHERE tenant_id = ? AND branch_id = ? AND DATE(created_at) = ?
        AND status = 'closed' AND payment_status = 'paid'
        GROUP BY tenant_id, branch_id, DATE(created_at)
        ON DUPLICATE KEY UPDATE
            total_orders = VALUES(total_orders),
            total_revenue = VALUES(total_revenue),
            avg_order_value = VALUES(avg_order_value),
            total_customers = VALUES(total_customers),
            total_items_sold = VALUES(total_items_sold),
            updated_at = NOW()
    ");
    
    $stmt->execute([$tenantId, $branchId, $date, $tenantId, $branchId, $date]);
    
    echo "  -> Updated metrics for tenant {$tenantId}, branch {$branchId}, date {$date}\n";
}

function generateReport($data) {
    $reportType = $data['type'] ?? '';
    $tenantId = $data['tenant_id'] ?? null;
    $params = $data['params'] ?? [];
    
    echo "  -> Generating {$reportType} report for tenant {$tenantId}\n";
    
    // Implement report generation logic
    // Save to storage/reports/ directory
}

function syncInventory($data) {
    $tenantId = $data['tenant_id'] ?? null;
    $branchId = $data['branch_id'] ?? null;
    
    if (!$tenantId || !$branchId) {
        throw new Exception("Invalid inventory sync data");
    }
    
    echo "  -> Syncing inventory for tenant {$tenantId}, branch {$branchId}\n";
    
    // Implement inventory sync logic
}

function cleanupSessions($data) {
    $db = getDbConnection();
    
    // Clean old sessions
    $stmt = $db->prepare("
        DELETE FROM app_sessions 
        WHERE last_activity < UNIX_TIMESTAMP() - (24 * 60 * 60 * 7)
    ");
    $deleted = $stmt->execute();
    
    echo "  -> Cleaned up old sessions\n";
}

function calculateCommissions($data) {
    $orderId = $data['order_id'] ?? null;
    
    if (!$orderId) {
        throw new Exception("Invalid commission data");
    }
    
    $db = getDbConnection();
    
    // Calculate aggregator commissions
    $stmt = $db->prepare("
        SELECT o.*, a.default_commission_percent 
        FROM orders o
        LEFT JOIN aggregators a ON o.aggregator_id = a.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($order && $order['aggregator_id']) {
        $commission = $order['subtotal_amount'] * ($order['default_commission_percent'] / 100);
        
        $stmt = $db->prepare("
            UPDATE orders 
            SET commission_amount = ?, 
                commission_percent = ?
            WHERE id = ?
        ");
        $stmt->execute([$commission, $order['default_commission_percent'], $orderId]);
        
        echo "  -> Calculated commission for order {$orderId}: {$commission}\n";
    }
}

function updateOrderMetrics($tenantId, $branchId, $order) {
    $db = getDbConnection();
    
    // Update hourly metrics
    $hour = date('H', strtotime($order['created_at']));
    $dateTime = date('Y-m-d H:00:00', strtotime($order['created_at']));
    
    $stmt = $db->prepare("
        INSERT INTO metrics_hourly (tenant_id, branch_id, metric_datetime, hour, orders_count, revenue, customers_count)
        VALUES (?, ?, ?, ?, 1, ?, 1)
        ON DUPLICATE KEY UPDATE
            orders_count = orders_count + 1,
            revenue = revenue + VALUES(revenue),
            customers_count = customers_count + 1
    ");
    
    $stmt->execute([$tenantId, $branchId, $dateTime, $hour, $order['total_amount']]);
}

/**
 * Get database connection
 */
function getDbConnection() {
    static $db = null;
    
    if ($db === null) {
        try {
            $db = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
                DB_USER,
                DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage() . "\n");
        }
    }
    
    return $db;
}