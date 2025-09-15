<?php
// /middleware/csrf_protection.php
declare(strict_types=1);

/**
 * CSRF Protection for Multi-tenant SaaS
 */

class CSRFProtection {
    private const TOKEN_LENGTH = 32;
    private const TOKEN_LIFETIME = 3600; // 1 hour
    private const MAX_TOKENS = 10; // Max tokens per session
    
    /**
     * Generate a new CSRF token
     */
    public static function generateToken(): string {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        
        // Store in session with timestamp
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        
        // Add new token
        $_SESSION['csrf_tokens'][$token] = time();
        
        // Clean old tokens
        self::cleanTokens();
        
        return $token;
    }
    
    /**
     * Validate a CSRF token
     */
    public static function validateToken(?string $token): bool {
        if (empty($token)) {
            return false;
        }
        
        if (!isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }
        
        $tokenTime = $_SESSION['csrf_tokens'][$token];
        
        // Check if token expired
        if (time() - $tokenTime > self::TOKEN_LIFETIME) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }
        
        // Token is valid - rotate it
        unset($_SESSION['csrf_tokens'][$token]);
        
        return true;
    }
    
    /**
     * Clean old tokens
     */
    private static function cleanTokens(): void {
        if (!isset($_SESSION['csrf_tokens'])) {
            return;
        }
        
        $now = time();
        
        // Remove expired tokens
        foreach ($_SESSION['csrf_tokens'] as $token => $time) {
            if ($now - $time > self::TOKEN_LIFETIME) {
                unset($_SESSION['csrf_tokens'][$token]);
            }
        }
        
        // Keep only last N tokens
        if (count($_SESSION['csrf_tokens']) > self::MAX_TOKENS) {
            $_SESSION['csrf_tokens'] = array_slice(
                $_SESSION['csrf_tokens'], 
                -self::MAX_TOKENS, 
                null, 
                true
            );
        }
    }
    
    /**
     * Get CSRF field HTML
     */
    public static function getField(): string {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
    
    /**
     * Verify request
     */
    public static function verify(): bool {
        // Skip for GET requests
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            return true;
        }
        
        // Check for token
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        if (!self::validateToken($token)) {
            http_response_code(403);
            audit_log('csrf_failure', [
                'url' => $_SERVER['REQUEST_URI'],
                'method' => $_SERVER['REQUEST_METHOD']
            ]);
            die('CSRF token validation failed');
        }
        
        return true;
    }
    
    /**
     * Add CSRF meta tag for AJAX
     */
    public static function getMetaTag(): string {
        $token = self::generateToken();
        return '<meta name="csrf-token" content="' . htmlspecialchars($token) . '">';
    }
}

/**
 * Helper function for templates
 */
function csrf_field(): string {
    return CSRFProtection::getField();
}

function csrf_token(): string {
    return CSRFProtection::generateToken();
}

function csrf_meta(): string {
    return CSRFProtection::getMetaTag();
}

/**
 * Middleware function to check CSRF
 */
function check_csrf(): void {
    CSRFProtection::verify();
}