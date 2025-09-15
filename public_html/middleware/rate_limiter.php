<?php
// /middleware/rate_limiter.php
declare(strict_types=1);

/**
 * Rate Limiting for SiteGround (APCu + MySQL fallback)
 */

class RateLimiter {
    private $pdo;
    private $use_apcu;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->use_apcu = function_exists('apcu_fetch');
        
        // Create table if not exists
        $this->initDatabase();
    }
    
    /**
     * Check if action is allowed
     */
    public function attempt(string $identifier, int $max_attempts = 5, int $window = 300): bool {
        // Try APCu first
        if ($this->use_apcu) {
            $key = 'rate_limit:' . $identifier;
            $attempts = apcu_fetch($key);
            
            if ($attempts === false) {
                apcu_store($key, 1, $window);
                return true;
            }
            
            if ($attempts >= $max_attempts) {
                return false;
            }
            
            apcu_inc($key);
            return true;
        }
        
        // Fallback to MySQL
        return $this->attemptDatabase($identifier, $max_attempts, $window);
    }
    
    /**
     * Database-based rate limiting
     */
    private function attemptDatabase(string $identifier, int $max_attempts, int $window): bool {
        $window_start = date('Y-m-d H:i:s', time() - $window);
        
        // Clean old entries
        $this->pdo->prepare("
            DELETE FROM rate_limits 
            WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ")->execute();
        
        // Check current attempts
        $stmt = $this->pdo->prepare("
            SELECT attempts FROM rate_limits 
            WHERE identifier = ? AND window_start > ?
        ");
        $stmt->execute([$identifier, $window_start]);
        $result = $stmt->fetch();
        
        if (!$result) {
            // First attempt
            $stmt = $this->pdo->prepare("
                INSERT INTO rate_limits (identifier, attempts, window_start) 
                VALUES (?, 1, NOW())
            ");
            $stmt->execute([$identifier]);
            return true;
        }
        
        if ($result['attempts'] >= $max_attempts) {
            return false;
        }
        
        // Increment attempts
        $stmt = $this->pdo->prepare("
            UPDATE rate_limits 
            SET attempts = attempts + 1 
            WHERE identifier = ?
        ");
        $stmt->execute([$identifier]);
        
        return true;
    }
    
    /**
     * Clear limits for identifier
     */
    public function clear(string $identifier): void {
        if ($this->use_apcu) {
            apcu_delete('rate_limit:' . $identifier);
        }
        
        $stmt = $this->pdo->prepare("DELETE FROM rate_limits WHERE identifier = ?");
        $stmt->execute([$identifier]);
    }
    
    /**
     * Get remaining attempts
     */
    public function remaining(string $identifier, int $max_attempts = 5): int {
        if ($this->use_apcu) {
            $key = 'rate_limit:' . $identifier;
            $attempts = apcu_fetch($key);
            
            if ($attempts === false) {
                return $max_attempts;
            }
            
            return max(0, $max_attempts - $attempts);
        }
        
        // Database check
        $stmt = $this->pdo->prepare("
            SELECT attempts FROM rate_limits 
            WHERE identifier = ? 
            AND window_start > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ");
        $stmt->execute([$identifier]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return $max_attempts;
        }
        
        return max(0, $max_attempts - $result['attempts']);
    }
    
    /**
     * Initialize database table
     */
    private function initDatabase(): void {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
                identifier VARCHAR(255) PRIMARY KEY,
                attempts INT DEFAULT 1,
                window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_window (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

/**
 * Login rate limiting
 */
class LoginRateLimiter {
    private $limiter;
    private $pdo;
    
    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
        $this->limiter = new RateLimiter($pdo);
    }
    
    /**
     * Check login attempt
     */
    public function checkLogin(string $username, string $ip): array {
        $user_key = 'login:user:' . $username;
        $ip_key = 'login:ip:' . $ip;
        
        // Check both username and IP
        $user_allowed = $this->limiter->attempt($user_key, 5, 300); // 5 attempts per 5 min
        $ip_allowed = $this->limiter->attempt($ip_key, 10, 300); // 10 attempts per IP per 5 min
        
        if (!$user_allowed || !$ip_allowed) {
            $remaining_user = $this->limiter->remaining($user_key, 5);
            $remaining_ip = $this->limiter->remaining($ip_key, 10);
            $remaining = min($remaining_user, $remaining_ip);
            
            // Log excessive attempts
            if ($remaining === 0) {
                $this->logFailure($username, $ip, 'rate_limited');
            }
            
            return [
                'allowed' => false,
                'remaining' => $remaining,
                'retry_after' => 300,
                'show_captcha' => true
            ];
        }
        
        return [
            'allowed' => true,
            'remaining' => min(
                $this->limiter->remaining($user_key, 5),
                $this->limiter->remaining($ip_key, 10)
            ),
            'show_captcha' => false
        ];
    }
    
    /**
     * Clear limits on successful login
     */
    public function clearOnSuccess(string $username, string $ip): void {
        $this->limiter->clear('login:user:' . $username);
        $this->limiter->clear('login:ip:' . $ip);
    }
    
    /**
     * Log login failure
     */
    private function logFailure(string $username, string $ip, string $reason): void {
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO login_failures (
                    username, ip_address, reason, 
                    user_agent, created_at
                ) VALUES (?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $username,
                $ip,
                $reason,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log('Failed to log login failure: ' . $e->getMessage());
        }
    }
}

/**
 * API rate limiting
 */
class APIRateLimiter {
    private $limiter;
    
    public function __construct(PDO $pdo) {
        $this->limiter = new RateLimiter($pdo);
    }
    
    /**
     * Check API rate limit
     */
    public function check(string $api_key, string $endpoint): array {
        $key = 'api:' . $api_key . ':' . $endpoint;
        
        // Different limits per endpoint
        $limits = [
            'orders' => ['max' => 100, 'window' => 60],    // 100 per minute
            'products' => ['max' => 50, 'window' => 60],    // 50 per minute
            'reports' => ['max' => 10, 'window' => 300],    // 10 per 5 minutes
            'default' => ['max' => 30, 'window' => 60]      // 30 per minute default
        ];
        
        $limit = $limits[$endpoint] ?? $limits['default'];
        
        $allowed = $this->limiter->attempt($key, $limit['max'], $limit['window']);
        $remaining = $this->limiter->remaining($key, $limit['max']);
        
        return [
            'allowed' => $allowed,
            'limit' => $limit['max'],
            'remaining' => $remaining,
            'reset' => time() + $limit['window']
        ];
    }
}