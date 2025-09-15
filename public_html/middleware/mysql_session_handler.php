<?php
// /middleware/mysql_session_handler.php
declare(strict_types=1);

/**
 * MySQL Session Handler for Multi-tenant SaaS
 * Compatible with SiteGround hosting
 */

class MySQLSessionHandler implements SessionHandlerInterface {
    private $pdo;
    private $table = 'app_sessions';
    private $lifetime;
    
    public function __construct(PDO $pdo, int $lifetime = 1800) {
        $this->pdo = $pdo;
        $this->lifetime = $lifetime;
    }
    
    public function open($path, $name): bool {
        return true;
    }
    
    public function close(): bool {
        return true;
    }
    
    public function read($session_id): string {
        $stmt = $this->pdo->prepare("
            SELECT data FROM {$this->table} 
            WHERE id = ? AND last_activity > ?
        ");
        
        $expiry = time() - $this->lifetime;
        $stmt->execute([$session_id, $expiry]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result ? $result['data'] : '';
    }
    
    public function write($session_id, $data): bool {
        // Extract tenant and user info from session data
        $session_data = $this->decode_session($data);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO {$this->table} (
                id, tenant_id, user_id, branch_id, data, 
                last_activity, ip_address, user_agent, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            ) ON DUPLICATE KEY UPDATE
                tenant_id = VALUES(tenant_id),
                user_id = VALUES(user_id),
                branch_id = VALUES(branch_id),
                data = VALUES(data),
                last_activity = VALUES(last_activity),
                ip_address = VALUES(ip_address),
                user_agent = VALUES(user_agent)
        ");
        
        return $stmt->execute([
            $session_id,
            $session_data['tenant_id'] ?? null,
            $session_data['user_id'] ?? null,
            $session_data['branch_id'] ?? null,
            $data,
            time(),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
    
    public function destroy($session_id): bool {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE id = ?");
        return $stmt->execute([$session_id]);
    }
    
    public function gc($max_lifetime): int|false {
        $expiry = time() - $max_lifetime;
        $stmt = $this->pdo->prepare("DELETE FROM {$this->table} WHERE last_activity < ?");
        $stmt->execute([$expiry]);
        return $stmt->rowCount();
    }
    
    /**
     * Decode session data to extract values
     */
    private function decode_session($data): array {
        $result = [];
        $offset = 0;
        
        while ($offset < strlen($data)) {
            if (!strstr(substr($data, $offset), '|')) {
                break;
            }
            
            $pos = strpos($data, '|', $offset);
            $num = $pos - $offset;
            $varname = substr($data, $offset, $num);
            $offset += $num + 1;
            
            $dataItem = unserialize(substr($data, $offset));
            $result[$varname] = $dataItem;
            $offset += strlen(serialize($dataItem));
        }
        
        return $result;
    }
    
    /**
     * Clean up expired sessions for a specific tenant
     */
    public function cleanupTenant(int $tenant_id): int {
        $expiry = time() - $this->lifetime;
        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->table} 
            WHERE tenant_id = ? AND last_activity < ?
        ");
        $stmt->execute([$tenant_id, $expiry]);
        return $stmt->rowCount();
    }
    
    /**
     * Get active session count for tenant
     */
    public function getActiveSessions(int $tenant_id): int {
        $expiry = time() - $this->lifetime;
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM {$this->table} 
            WHERE tenant_id = ? AND last_activity > ?
        ");
        $stmt->execute([$tenant_id, $expiry]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Force logout a specific user
     */
    public function destroyUserSessions(int $user_id): int {
        $stmt = $this->pdo->prepare("
            DELETE FROM {$this->table} WHERE user_id = ?
        ");
        $stmt->execute([$user_id]);
        return $stmt->rowCount();
    }
}

/**
 * Initialize session handler
 */
function init_session_handler(): void {
    $pdo = db();
    
    // Create session table if not exists
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_sessions (
            id VARCHAR(128) PRIMARY KEY,
            tenant_id INT NULL,
            user_id INT NULL,
            branch_id INT NULL,
            data TEXT,
            last_activity INT UNSIGNED,
            ip_address VARCHAR(45),
            user_agent VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity (last_activity),
            INDEX idx_user (user_id),
            INDEX idx_tenant (tenant_id, last_activity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    // Set handler
    $handler = new MySQLSessionHandler($pdo, 1800); // 30 min timeout
    session_set_save_handler($handler, true);
    
    // Session configuration
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        ini_set('session.cookie_secure', '1');
    }
    
    // Start session
    session_start();
    
    // Regenerate session ID on privilege changes
    if (isset($_SESSION['regenerate'])) {
        unset($_SESSION['regenerate']);
        session_regenerate_id(true);
    }
}