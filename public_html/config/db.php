<?php
// /config/db.php - MULTI-TENANT CONFIGURATION
declare(strict_types=1);

// Database configuration constants
const DB_HOST     = 'localhost';
const DB_NAME     = 'dbvtrnbzad193e';
const DB_USER     = 'uta6umaa0iuif';
const DB_PASS     = '2m%[11|kb1Z4';
const DB_CHARSET  = 'utf8mb4';

// Session configuration
const SESSION_NAME = 'pos_backend_session';
const SESSION_TIMEOUT = 1800; // 30 minutes
const SESSION_REGENERATE_TIME = 300; // 5 minutes

// PDO instance (singleton)
$_db_instance = null;

/**
 * Get database connection
 */
function db(): PDO {
    global $_db_instance;
    
    if ($_db_instance === null) {
        try {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                DB_HOST,
                DB_NAME,
                DB_CHARSET
            );
            
            $_db_instance = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET,
            ]);
        } catch (PDOException $e) {
            // More detailed error for debugging (remove in production)
            error_log('Database connection failed: ' . $e->getMessage());
            error_log('DSN: ' . $dsn);
            error_log('User: ' . DB_USER);
            
            // In development, show more details:
            if (isset($_GET['debug'])) {
                die('Database Error: ' . $e->getMessage());
            }
            
            die('Database connection error. Please contact support.');
        }
    }
    
    return $_db_instance;
}

/**
 * Test database connection
 */
function test_db_connection(): bool {
    try {
        $pdo = db();
        $stmt = $pdo->query("SELECT 1");
        return $stmt !== false;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Backend session helper with timeout
 */
function use_backend_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (($_SESSION['session_type'] ?? null) === 'pos') return;
        if (session_name() === SESSION_NAME) {
            check_session_timeout();
            return;
        }
    }
    
    if (session_status() === PHP_SESSION_NONE) {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => SESSION_TIMEOUT,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        $_SESSION['session_type'] = $_SESSION['session_type'] ?? 'backend';
        $_SESSION['last_activity'] = time();
        $_SESSION['last_regeneration'] = time();
    }
}

/**
 * Check session timeout
 */
function check_session_timeout(): void {
    if (isset($_SESSION['last_activity'])) {
        $inactive_time = time() - $_SESSION['last_activity'];
        if ($inactive_time > SESSION_TIMEOUT) {
            destroy_session();
            redirect('/views/auth/login.php?timeout=1');
            exit;
        }
    }
    
    $_SESSION['last_activity'] = time();
    
    if (isset($_SESSION['last_regeneration'])) {
        if (time() - $_SESSION['last_regeneration'] > SESSION_REGENERATE_TIME) {
            session_regenerate_id(true);
            $_SESSION['last_regeneration'] = time();
        }
    } else {
        $_SESSION['last_regeneration'] = time();
    }
}

/**
 * Destroy session
 */
function destroy_session(): void {
    if (session_status() !== PHP_SESSION_NONE) {
        $_SESSION = [];
        
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        
        session_destroy();
    }
}

/**
 * Redirect helper
 */
function redirect(string $url): void {
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    $safe = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    echo "<script>window.location.href='{$safe}';</script>";
    echo "<noscript><meta http-equiv='refresh' content='0;url={$safe}'></noscript>";
    exit;
}

// ===== TENANT & BRANCH FUNCTIONS =====

/**
 * Get current tenant ID from session
 */
function get_tenant_id(): ?int {
    if (session_status() === PHP_SESSION_NONE) {
        use_backend_session();
    }
    return isset($_SESSION['tenant_id']) ? (int)$_SESSION['tenant_id'] : null;
}

/**
 * Get current branch ID from session
 */
function get_branch_id(): ?int {
    if (session_status() === PHP_SESSION_NONE) {
        use_backend_session();
    }
    return isset($_SESSION['branch_id']) ? (int)$_SESSION['branch_id'] : null;
}

/**
 * Validate tenant/branch access
 */
function validate_tenant_access(): bool {
    $tenant_id = get_tenant_id();
    $branch_id = get_branch_id();
    return ($tenant_id !== null && $branch_id !== null);
}

/**
 * Add tenant filter to SQL queries
 */
function add_tenant_filter(string $query, string $alias = ''): string {
    $tenant_id = get_tenant_id();
    if (!$tenant_id) {
        throw new RuntimeException('No tenant context set');
    }
    
    $table = $alias ? "$alias." : "";
    $condition = "{$table}tenant_id = " . $tenant_id;
    
    if (stripos($query, 'WHERE') !== false) {
        return preg_replace('/WHERE/i', "WHERE $condition AND", $query, 1);
    } else {
        return $query . " WHERE $condition";
    }
}

// ===== USER SESSION FUNCTIONS =====

/**
 * Get logged in user info from session
 */
function get_logged_in_user(): ?array {
    if (session_status() === PHP_SESSION_NONE) {
        use_backend_session();
    }
    check_session_timeout();
    return $_SESSION['user'] ?? null;
}

/**
 * Check if user is logged in
 */
function is_logged_in(): bool {
    if (session_status() === PHP_SESSION_NONE) {
        use_backend_session();
    }
    check_session_timeout();
    return get_logged_in_user() !== null;
}

/**
 * Get user ID from session
 */
function get_user_id(): ?int {
    if (session_status() === PHP_SESSION_NONE) {
        use_backend_session();
    }
    return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
}

/**
 * Get user role from session
 */
function get_user_role(): ?string {
    $user = get_logged_in_user();
    return $user ? ($user['role_key'] ?? null) : null;
}

/**
 * Check if user has specific role
 */
function user_has_role(string $role): bool {
    $current_role = get_user_role();
    return $current_role === $role;
}

/**
 * Check if user has any of the specified roles
 */
function user_has_any_role(array $roles): bool {
    $current_role = get_user_role();
    return $current_role && in_array($current_role, $roles, true);
}

/**
 * Require login - redirect to login if not logged in
 */
function require_login(): void {
    if (!is_logged_in() || !validate_tenant_access()) {
        redirect('/views/auth/login.php');
        exit;
    }
}

/**
 * Require specific role
 */
function require_role(string $role): void {
    require_login();
    if (!user_has_role($role)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        redirect('/views/admin/dashboard.php');
        exit;
    }
}

/**
 * Require any of specified roles
 */
function require_any_role(array $roles): void {
    require_login();
    if (!user_has_any_role($roles)) {
        $_SESSION['error'] = 'You do not have permission to access this page.';
        redirect('/views/admin/dashboard.php');
        exit;
    }
}

// ===== MULTI-TENANT FUNCTIONS =====

/**
 * Get all tenants for a user
 */
function get_user_tenants(int $user_id): array {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT DISTINCT
                t.id,
                t.name,
                CASE 
                    WHEN ut.is_primary = 1 THEN 1
                    WHEN u.tenant_id = t.id THEN 1
                    ELSE 0
                END as is_primary
            FROM tenants t
            JOIN users u ON u.id = :user_id
            LEFT JOIN user_tenants ut ON t.id = ut.tenant_id AND ut.user_id = :user_id2
            WHERE (ut.user_id = :user_id3 OR t.id = u.tenant_id)
                AND t.is_active = 1
            ORDER BY is_primary DESC, t.name ASC
        ");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':user_id2' => $user_id,
            ':user_id3' => $user_id
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Error fetching user tenants: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get branches for user in specific tenant
 */
function get_user_branches(int $user_id, int $tenant_id): array {
    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT 
                b.id,
                b.name,
                b.branch_type,
                b.address
            FROM branches b
            JOIN user_branches ub ON b.id = ub.branch_id
            WHERE ub.user_id = :user_id
                AND b.tenant_id = :tenant_id
                AND b.is_active = 1
            ORDER BY b.name ASC
        ");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':tenant_id' => $tenant_id
        ]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log('Error fetching user branches: ' . $e->getMessage());
        return [];
    }
}

/**
 * Validate user has access to tenant and branch
 */
function validate_multi_tenant_access(int $user_id, int $tenant_id, int $branch_id): bool {
    try {
        $pdo = db();
        
        // Check tenant access
        $stmt = $pdo->prepare("
            SELECT 1
            FROM tenants t
            LEFT JOIN user_tenants ut ON t.id = ut.tenant_id AND ut.user_id = :user_id
            JOIN users ON users.id = :user_id2
            WHERE t.id = :tenant_id
                AND (ut.user_id = :user_id3 OR t.id = users.tenant_id)
                AND t.is_active = 1
            LIMIT 1
        ");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':user_id2' => $user_id,
            ':user_id3' => $user_id,
            ':tenant_id' => $tenant_id
        ]);
        
        if (!$stmt->fetch()) {
            return false;
        }
        
        // Check branch access
        $stmt = $pdo->prepare("
            SELECT 1
            FROM branches b
            JOIN user_branches ub ON b.id = ub.branch_id
            WHERE ub.user_id = :user_id
                AND b.id = :branch_id
                AND b.tenant_id = :tenant_id
                AND b.is_active = 1
            LIMIT 1
        ");
        
        $stmt->execute([
            ':user_id' => $user_id,
            ':branch_id' => $branch_id,
            ':tenant_id' => $tenant_id
        ]);
        
        return (bool)$stmt->fetch();
        
    } catch (Exception $e) {
        error_log('Error validating access: ' . $e->getMessage());
        return false;
    }
}

/**
 * Switch tenant/branch context without re-login
 */
function switch_tenant_context(int $tenant_id, int $branch_id): bool {
    if (session_status() === PHP_SESSION_NONE) {
        use_backend_session();
    }
    
    if (!isset($_SESSION['user_id'])) {
        return false;
    }
    
    try {
        $pdo = db();
        
        // Verify access
        if (!validate_multi_tenant_access($_SESSION['user_id'], $tenant_id, $branch_id)) {
            return false;
        }
        
        // Get tenant and branch names
        $stmt = $pdo->prepare("
            SELECT t.name as tenant_name, b.name as branch_name
            FROM tenants t
            JOIN branches b ON b.tenant_id = t.id
            WHERE t.id = :tenant_id AND b.id = :branch_id
        ");
        
        $stmt->execute([
            ':tenant_id' => $tenant_id,
            ':branch_id' => $branch_id
        ]);
        
        $result = $stmt->fetch();
        
        if (!$result) {
            return false;
        }
        
        // Update session
        $_SESSION['tenant_id'] = $tenant_id;
        $_SESSION['tenant_name'] = $result['tenant_name'];
        $_SESSION['branch_id'] = $branch_id;
        $_SESSION['branch_name'] = $result['branch_name'];
        
        // Update user array
        if (isset($_SESSION['user'])) {
            $_SESSION['user']['tenant_id'] = $tenant_id;
            $_SESSION['user']['tenant_name'] = $result['tenant_name'];
            $_SESSION['user']['branch_id'] = $branch_id;
            $_SESSION['user']['branch_name'] = $result['branch_name'];
        }
        
        return true;
        
    } catch (Exception $e) {
        error_log('Error switching context: ' . $e->getMessage());
        return false;
    }
}

// ===== HELPER FUNCTIONS =====

/**
 * Get client IP address
 */
function get_client_ip(): string {
    $ip_keys = ['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
    
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            $ip = $_SERVER[$key];
            
            if (strpos($ip, ',') !== false) {
                $ip = explode(',', $ip)[0];
            }
            
            $ip = trim($ip);
            
            if (filter_var($ip, FILTER_VALIDATE_IP, 
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

// ===== INITIALIZATION CHECK =====

// Verify critical functions are defined
$required_functions = [
    'db', 'use_backend_session', 'redirect', 
    'get_tenant_id', 'get_branch_id', 'is_logged_in',
    'get_user_tenants', 'get_user_branches',
    'validate_multi_tenant_access', 'switch_tenant_context'
];

foreach ($required_functions as $func) {
    if (!function_exists($func)) {
        error_log("CRITICAL: Function $func is not defined in db.php!");
    }
}

// Optional: Test database connection on load (comment out in production)
// if (!test_db_connection()) {
//     die('Database connection test failed. Check your credentials.');
// }
?>