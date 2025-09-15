<?php
/**
 * SME 180 SaaS POS System
 * Session Management Configuration
 * Path: /config/session.php
 */
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/db.php';

/**
 * Session Manager for SME 180
 * Handles session initialization and management
 */
class SessionManager {
    
    /**
     * Initialize session with proper configuration
     * @param string $type 'backend' or 'pos'
     */
    public static function initialize(string $type = 'backend'): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        
        $sessionName = ($type === 'pos') ? SESSION_NAME_POS : SESSION_NAME_BACKEND;
        $timeout = ($type === 'pos') ? POS_SESSION_TIMEOUT : SESSION_TIMEOUT;
        
        // Configure session
        ini_set('session.gc_maxlifetime', (string)$timeout);
        
        // Set secure cookie parameters
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        
        session_name($sessionName);
        session_set_cookie_params([
            'lifetime' => $timeout,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        
        // Start session
        session_start();
        
        // Set session type
        $_SESSION['session_type'] = $type;
        
        // Initialize activity tracking
        if (!isset($_SESSION['last_activity'])) {
            $_SESSION['last_activity'] = time();
        }
        
        if (!isset($_SESSION['last_regeneration'])) {
            $_SESSION['last_regeneration'] = time();
        }
        
        // Check timeout
        self::checkTimeout();
        
        // Regenerate session ID periodically
        self::regenerateIfNeeded();
    }
    
    /**
     * Check session timeout
     */
    public static function checkTimeout(): void {
        if (!isset($_SESSION['last_activity'])) {
            return;
        }
        
        $sessionType = $_SESSION['session_type'] ?? 'backend';
        $timeout = ($sessionType === 'pos') ? POS_SESSION_TIMEOUT : SESSION_TIMEOUT;
        
        $inactiveTime = time() - $_SESSION['last_activity'];
        
        if ($inactiveTime > $timeout) {
            self::destroy();
            redirect('/views/auth/login.php?timeout=1');
            exit;
        }
        
        // Update last activity
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Regenerate session ID if needed
     */
    public static function regenerateIfNeeded(): void {
        if (!isset($_SESSION['last_regeneration'])) {
            return;
        }
        
        if (time() - $_SESSION['last_regeneration'] > SESSION_REGENERATE_TIME) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    }
    
    /**
     * Get remaining session time in seconds
     */
    public static function getRemainingTime(): int {
        if (!isset($_SESSION['last_activity'])) {
            return 0;
        }
        
        $sessionType = $_SESSION['session_type'] ?? 'backend';
        $timeout = ($sessionType === 'pos') ? POS_SESSION_TIMEOUT : SESSION_TIMEOUT;
        
        $elapsed = time() - $_SESSION['last_activity'];
        $remaining = $timeout - $elapsed;
        
        return max(0, $remaining);
    }
    
    /**
     * Extend session
     */
    public static function extend(): void {
        $_SESSION['last_activity'] = time();
    }
    
    /**
     * Destroy session
     */
    public static function destroy(): void {
        if (session_status() !== PHP_SESSION_NONE) {
            // Clear session data
            $_SESSION = [];
            
            // Delete session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(), 
                    '', 
                    time() - 42000,
                    $params["path"], 
                    $params["domain"],
                    $params["secure"], 
                    $params["httponly"]
                );
            }
            
            // Destroy session
            session_destroy();
        }
    }
    
    /**
     * Set session data
     */
    public static function set(string $key, $value): void {
        $_SESSION[$key] = $value;
    }
    
    /**
     * Get session data
     */
    public static function get(string $key, $default = null) {
        return $_SESSION[$key] ?? $default;
    }
    
    /**
     * Check if session key exists
     */
    public static function has(string $key): bool {
        return isset($_SESSION[$key]);
    }
    
    /**
     * Remove session data
     */
    public static function remove(string $key): void {
        unset($_SESSION[$key]);
    }
    
    /**
     * Flash message functions
     */
    public static function setFlash(string $type, string $message): void {
        if (!isset($_SESSION['flash'])) {
            $_SESSION['flash'] = [];
        }
        $_SESSION['flash'][$type] = $message;
    }
    
    public static function getFlash(string $type): ?string {
        $message = $_SESSION['flash'][$type] ?? null;
        if ($message !== null) {
            unset($_SESSION['flash'][$type]);
        }
        return $message;
    }
    
    public static function hasFlash(string $type): bool {
        return isset($_SESSION['flash'][$type]);
    }
}