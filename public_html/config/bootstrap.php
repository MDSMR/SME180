<?php
// /config/bootstrap.php - Fixed version
declare(strict_types=1);

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Define root path
define('ROOT_PATH', '/home/customer/www/mohamedk10.sg-host.com/public_html');
define('CONFIG_PATH', ROOT_PATH . '/config');
define('MIDDLEWARE_PATH', ROOT_PATH . '/middleware');

// Security headers
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// Include database configuration
require_once CONFIG_PATH . '/db.php';

// Include MySQL session handler (ONLY ONCE!)
require_once MIDDLEWARE_PATH . '/mysql_session_handler.php';

// Verify critical functions
if (!function_exists('db')) {
    die('FATAL: db() function not available');
}

if (!function_exists('use_backend_session')) {
    die('FATAL: use_backend_session() function not available');
}

if (!function_exists('redirect')) {
    die('FATAL: redirect() function not available');
}

// Initialize secure session handler
function init_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    
    // Use the existing use_backend_session function
    use_backend_session();
}

// CSRF Protection class
class CSRFProtection {
    private const TOKEN_LENGTH = 32;
    private const TOKEN_LIFETIME = 3600;
    
    public static function generateToken(): string {
        $token = bin2hex(random_bytes(self::TOKEN_LENGTH));
        
        if (!isset($_SESSION['csrf_tokens'])) {
            $_SESSION['csrf_tokens'] = [];
        }
        
        $_SESSION['csrf_tokens'][$token] = time();
        
        if (count($_SESSION['csrf_tokens']) > 10) {
            $_SESSION['csrf_tokens'] = array_slice($_SESSION['csrf_tokens'], -10, null, true);
        }
        
        return $token;
    }
    
    public static function validateToken(?string $token): bool {
        if (empty($token) || !isset($_SESSION['csrf_tokens'][$token])) {
            return false;
        }
        
        $tokenTime = $_SESSION['csrf_tokens'][$token];
        
        if (time() - $tokenTime > self::TOKEN_LIFETIME) {
            unset($_SESSION['csrf_tokens'][$token]);
            return false;
        }
        
        unset($_SESSION['csrf_tokens'][$token]);
        return true;
    }
    
    public static function getField(): string {
        $token = self::generateToken();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}

// Helper functions
function get_tenant_id(): ?int {
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'super_admin') {
        return isset($_SESSION['viewing_tenant_id']) ? (int)$_SESSION['viewing_tenant_id'] : null;
    }
    return isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
}

function get_branch_id(): ?int {
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'super_admin') {
        return isset($_SESSION['viewing_branch_id']) ? (int)$_SESSION['viewing_branch_id'] : null;
    }
    return isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;
}

function require_tenant_id(): int {
    $tenant_id = get_tenant_id();
    if ($tenant_id === null) {
        throw new Exception('Tenant context required but not set');
    }
    return $tenant_id;
}

function require_branch_id(): int {
    $branch_id = get_branch_id();
    if ($branch_id === null) {
        throw new Exception('Branch context required but not set');
    }
    return $branch_id;
}

function audit_log(string $action, array $details = []): void {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            INSERT INTO audit_logs (
                user_id, tenant_id, branch_id, action, 
                details, ip_address, user_agent, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $_SESSION['user_id'] ?? null,
            get_tenant_id(),
            get_branch_id(),
            $action,
            json_encode($details),
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log('Audit log failed: ' . $e->getMessage());
    }
}

function csrf_field(): string {
    return CSRFProtection::getField();
}

function csrf_token(): string {
    return CSRFProtection::generateToken();
}

function check_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        
        if (!CSRFProtection::validateToken($token)) {
            http_response_code(403);
            audit_log('csrf_failure', [
                'url' => $_SERVER['REQUEST_URI'],
                'method' => $_SERVER['REQUEST_METHOD']
            ]);
            die('CSRF token validation failed');
        }
    }
}

function require_auth(): void {
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        redirect('/views/auth/login.php');
    }
}

function require_role(array $allowed_roles): void {
    require_auth();
    
    $user_role = $_SESSION['role_key'] ?? $_SESSION['user_type'] ?? '';
    
    if (!in_array($user_role, $allowed_roles)) {
        http_response_code(403);
        die('Access denied - insufficient privileges');
    }
}

function json_response(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Initialize secure session
init_secure_session();

// Validate session for authenticated users
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_type'] !== 'super_admin') {
        if (!isset($_SESSION['tenant_id']) || !isset($_SESSION['branch_id'])) {
            // Invalid session - force re-login
            session_destroy();
            redirect('/views/auth/login.php');
        }
    }
}