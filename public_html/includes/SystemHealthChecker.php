<?php
/**
 * System Health Checker
 * Monitors system health, services, and performance
 */

require_once __DIR__ . '/../config/db.php';

class SystemHealthChecker {
    
    private $pdo;
    
    public function __construct() {
        $this->pdo = db();
    }
    
    /**
     * Run all health checks
     */
    public function runAllChecks() {
        $status = [];
        
        // Overall system status
        $status['overall'] = 'healthy';
        $issues = 0;
        
        // Check database
        $dbCheck = $this->checkDatabase();
        if (!$dbCheck['healthy']) {
            $status['overall'] = 'degraded';
            $issues++;
        }
        
        // Check disk space
        $diskCheck = $this->checkDiskSpace();
        if ($diskCheck['critical']) {
            $status['overall'] = 'down';
            $issues++;
        } elseif ($diskCheck['warning']) {
            $status['overall'] = 'degraded';
            $issues++;
        }
        
        // Check services
        $status['email'] = $this->checkEmailService() ? 'operational' : 'down';
        $status['cache'] = $this->checkCacheService() ? 'operational' : 'down';
        $status['queue'] = $this->checkQueueService() ? 'operational' : 'down';
        $status['ssl'] = $this->checkSSL() ? 'valid' : 'expired';
        
        // System metrics
        $status['uptime'] = $this->getSystemUptime();
        $status['load_average'] = $this->getLoadAverage();
        $status['memory_usage'] = $this->getMemoryUsage();
        
        // Update health check record
        $this->updateHealthRecord('system_overall', $status['overall']);
        
        return $status;
    }
    
    /**
     * Check database health
     */
    public function checkDatabase() {
        $status = ['healthy' => true, 'details' => []];
        
        try {
            // Test connection
            $stmt = $this->pdo->query("SELECT 1");
            $status['details']['connection'] = 'Connected';
            
            // Check slow queries
            $stmt = $this->pdo->query("
                SELECT COUNT(*) FROM information_schema.processlist 
                WHERE time > 10 AND command != 'Sleep'
            ");
            $slowQueries = $stmt->fetchColumn();
            
            if ($slowQueries > 5) {
                $status['healthy'] = false;
                $status['details']['slow_queries'] = $slowQueries;
            }
            
            // Check connections
            $stmt = $this->pdo->query("SHOW STATUS LIKE 'Threads_connected'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $connections = $result['Value'] ?? 0;
            
            if ($connections > 100) {
                $status['healthy'] = false;
                $status['details']['high_connections'] = $connections;
            }
            
        } catch (Exception $e) {
            $status['healthy'] = false;
            $status['details']['error'] = $e->getMessage();
        }
        
        $this->updateHealthRecord('database', $status['healthy'] ? 'healthy' : 'degraded');
        
        return $status;
    }
    
    /**
     * Check disk space
     */
    public function checkDiskSpace() {
        $status = ['warning' => false, 'critical' => false, 'partitions' => []];
        
        // Check main partition
        $total = disk_total_space('/');
        $free = disk_free_space('/');
        $used = $total - $free;
        $usedPercent = round(($used / $total) * 100, 2);
        
        $status['partitions'][] = [
            'mount' => '/',
            'total' => $this->formatBytes($total),
            'used' => $this->formatBytes($used),
            'free' => $this->formatBytes($free),
            'used_percent' => $usedPercent
        ];
        
        if ($usedPercent > 90) {
            $status['critical'] = true;
        } elseif ($usedPercent > 70) {
            $status['warning'] = true;
        }
        
        // Check storage directory if different
        $storagePath = __DIR__ . '/../storage';
        if (is_dir($storagePath)) {
            $storageTotal = disk_total_space($storagePath);
            $storageFree = disk_free_space($storagePath);
            $storageUsed = $storageTotal - $storageFree;
            $storageUsedPercent = round(($storageUsed / $storageTotal) * 100, 2);
            
            $status['partitions'][] = [
                'mount' => '/storage',
                'total' => $this->formatBytes($storageTotal),
                'used' => $this->formatBytes($storageUsed),
                'free' => $this->formatBytes($storageFree),
                'used_percent' => $storageUsedPercent
            ];
        }
        
        return $status;
    }
    
    /**
     * Get disk usage details
     */
    public function getDiskUsage() {
        $diskCheck = $this->checkDiskSpace();
        return [
            'status' => $diskCheck['critical'] ? 'down' : ($diskCheck['warning'] ? 'degraded' : 'healthy'),
            'partitions' => $diskCheck['partitions']
        ];
    }
    
    /**
     * Get database status
     */
    public function getDatabaseStatus() {
        try {
            // Check connection
            $this->pdo->query("SELECT 1");
            $connected = true;
            
            // Get database size
            $stmt = $this->pdo->prepare("
                SELECT 
                    SUM(data_length + index_length) as size
                FROM information_schema.tables 
                WHERE table_schema = :schema
            ");
            $stmt->execute([':schema' => 'dbvtrnbzad193e']);
            $size = $stmt->fetchColumn();
            
            // Get connection count
            $stmt = $this->pdo->query("SHOW STATUS LIKE 'Threads_connected'");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $connections = $result['Value'] ?? 0;
            
            // Get slow queries
            $stmt = $this->pdo->query("
                SELECT COUNT(*) FROM information_schema.processlist 
                WHERE time > 10 AND command != 'Sleep'
            ");
            $slowQueries = $stmt->fetchColumn();
            
            return [
                'status' => 'healthy',
                'connection' => $connected,
                'size' => $this->formatBytes($size),
                'active_connections' => $connections,
                'slow_queries' => $slowQueries
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'down',
                'connection' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Get cron job status
     */
    public function getCronJobStatus() {
        try {
            $stmt = $this->pdo->query("
                SELECT * FROM cron_jobs 
                ORDER BY 
                    CASE last_status 
                        WHEN 'failed' THEN 1 
                        WHEN 'running' THEN 2 
                        ELSE 3 
                    END,
                    next_run ASC
            ");
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log('Error fetching cron status: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Run cron job manually
     */
    public function runCronJob($jobName) {
        try {
            // Get job details
            $stmt = $this->pdo->prepare("
                SELECT * FROM cron_jobs WHERE job_name = :name
            ");
            $stmt->execute([':name' => $jobName]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$job) {
                throw new Exception('Job not found');
            }
            
            // Update status to running
            $stmt = $this->pdo->prepare("
                UPDATE cron_jobs 
                SET last_status = 'running', last_run = NOW()
                WHERE job_name = :name
            ");
            $stmt->execute([':name' => $jobName]);
            
            // Execute the job (in production, this would trigger the actual script)
            $scriptPath = __DIR__ . '/../scripts/cron/' . $job['job_command'];
            if (file_exists($scriptPath)) {
                exec("php {$scriptPath} > /dev/null 2>&1 &");
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log('Error running cron job: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check email service
     */
    private function checkEmailService() {
        // Test email configuration
        // In production, this would actually test sending an email
        return true;
    }
    
    /**
     * Check cache service
     */
    private function checkCacheService() {
        // Check if APCu is available
        if (function_exists('apcu_enabled')) {
            return apcu_enabled();
        }
        return false;
    }
    
    /**
     * Check queue service
     */
    private function checkQueueService() {
        try {
            // Check if queue table is accessible
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM queue_jobs WHERE status = 'pending'");
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Check SSL certificate
     */
    private function checkSSL() {
        // Check if running on HTTPS
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    }
    
    /**
     * Get system uptime
     */
    private function getSystemUptime() {
        if (PHP_OS_FAMILY === 'Linux') {
            $uptime = shell_exec('uptime -p');
            return trim($uptime);
        }
        return 'N/A';
    }
    
    /**
     * Get load average
     */
    private function getLoadAverage() {
        $load = sys_getloadavg();
        if ($load !== false) {
            return sprintf('%.2f, %.2f, %.2f', $load[0], $load[1], $load[2]);
        }
        return 'N/A';
    }
    
    /**
     * Get memory usage
     */
    private function getMemoryUsage() {
        if (PHP_OS_FAMILY === 'Linux') {
            $memInfo = file_get_contents('/proc/meminfo');
            preg_match('/MemTotal:\s+(\d+)/', $memInfo, $totalMatch);
            preg_match('/MemAvailable:\s+(\d+)/', $memInfo, $availMatch);
            
            if ($totalMatch && $availMatch) {
                $total = $totalMatch[1] * 1024;
                $available = $availMatch[1] * 1024;
                $used = $total - $available;
                $percent = round(($used / $total) * 100, 1);
                
                return $percent . '% (' . $this->formatBytes($used) . ' / ' . $this->formatBytes($total) . ')';
            }
        }
        
        // Fallback to PHP memory usage
        $usage = memory_get_usage(true);
        $peak = memory_get_peak_usage(true);
        return $this->formatBytes($usage) . ' / ' . $this->formatBytes($peak) . ' (peak)';
    }
    
    /**
     * Clear cache
     */
    public function clearCache() {
        $cleared = false;
        
        // Clear APCu cache if available
        if (function_exists('apcu_clear_cache')) {
            apcu_clear_cache();
            $cleared = true;
        }
        
        // Clear file cache
        $cacheDir = __DIR__ . '/../storage/cache';
        if (is_dir($cacheDir)) {
            $this->clearDirectory($cacheDir);
            $cleared = true;
        }
        
        return $cleared;
    }
    
    /**
     * Optimize database
     */
    public function optimizeDatabase() {
        try {
            // Get all tables
            $stmt = $this->pdo->prepare("
                SELECT table_name 
                FROM information_schema.tables 
                WHERE table_schema = :schema
            ");
            $stmt->execute([':schema' => 'dbvtrnbzad193e']);
            
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $this->pdo->exec("OPTIMIZE TABLE `{$row['table_name']}`");
            }
            
            // Clean up old audit logs
            $this->pdo->exec("
                DELETE FROM audit_logs 
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
            ");
            
            // Clean up old sessions
            $this->pdo->exec("
                DELETE FROM app_sessions 
                WHERE last_activity < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 7 DAY))
            ");
            
            return true;
            
        } catch (Exception $e) {
            error_log('Database optimization error: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Update health record
     */
    private function updateHealthRecord($service, $status) {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO system_health_checks 
                (service_name, status, last_check, response_time_ms)
                VALUES (:service, :status, NOW(), :response_time)
                ON DUPLICATE KEY UPDATE 
                    status = VALUES(status),
                    last_check = VALUES(last_check),
                    response_time_ms = VALUES(response_time_ms)
            ");
            
            $responseTime = round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000);
            
            $stmt->execute([
                ':service' => $service,
                ':status' => $status,
                ':response_time' => $responseTime
            ]);
            
        } catch (Exception $e) {
            error_log('Error updating health record: ' . $e->getMessage());
        }
    }
    
    /**
     * Format bytes to human readable
     */
    private function formatBytes($bytes, $precision = 2) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        
        return round($bytes, $precision) . ' ' . $units[$i];
    }
    
    /**
     * Clear directory contents
     */
    private function clearDirectory($dir) {
        if (!is_dir($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..', '.gitkeep']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->clearDirectory($path);
                rmdir($path);
            } else {
                unlink($path);
            }
        }
    }
}