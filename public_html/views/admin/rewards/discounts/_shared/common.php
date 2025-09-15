<?php
// /views/admin/rewards/discounts/_shared/common.php
// Final version with proper navigation tabs
declare(strict_types=1);

/* Debug Mode */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { 
    @ini_set('display_errors','1'); 
    @ini_set('display_startup_errors','1'); 
    error_reporting(E_ALL); 
}

/* Bootstrap Database - Simple approach */
$bootstrap_warning = ''; 
$bootstrap_ok = false; 

// Try to find db.php
$db_paths = [
    __DIR__ . '/../../../../../config/db.php',
    $_SERVER['DOCUMENT_ROOT'] . '/config/db.php'
];

foreach ($db_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $bootstrap_ok = true;
        break;
    }
}

if (!$bootstrap_ok) {
    $bootstrap_warning = 'Database configuration not found';
}

// Start session if needed
if ($bootstrap_ok && function_exists('use_backend_session')) {
    try {
        use_backend_session();
    } catch(Exception $e) {
        // Continue anyway
    }
} else if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Authentication */
$user = $_SESSION['user'] ?? null;
if (!$user) { 
    header('Location: /views/auth/login.php'); 
    exit; 
}

// Force tenant_id to 1 (your database value)
$tenantId = 1;
$userId = (int)($user['id'] ?? 0);
$userName = $user['name'] ?? 'User';

/* Database Connection */
$pdo = null;
if (function_exists('db')) {
    try {
        $pdo = db();
    } catch(Exception $e) {
        $bootstrap_warning = 'Database connection failed: ' . $e->getMessage();
    }
}

/* Helper Functions */
function h($s): string { 
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); 
}

// Get currency
$currency = 'EGP';
if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = ? AND `key` = 'currency' LIMIT 1");
        $stmt->execute([$tenantId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result && !empty($result['value'])) {
            $currency = $result['value'];
        }
    } catch(Exception $e) {
        // Keep default
    }
}

/* Navigation include - properly include admin nav */
function include_admin_nav(string $active = ''): bool {
    // Correct path from /views/admin/rewards/discounts/_shared/
    // To /views/partials/admin_nav.php (up 4 directories)
    $nav_paths = [
        __DIR__ . '/../../../../partials/admin_nav.php',
        dirname(__DIR__) . '/../../../partials/admin_nav.php',
        $_SERVER['DOCUMENT_ROOT'] . '/views/partials/admin_nav.php'
    ];
    
    foreach ($nav_paths as $nav_path) {
        if (file_exists($nav_path)) {
            // Set the active state for the navigation
            $GLOBALS['active'] = $active;
            include $nav_path;
            return true;
        }
    }
    
    return false;
}

/* Discount Navigation Tabs - Updated without Members */
function render_discount_nav(string $active): void {
    $tabs = [
        'index' => ['title' => 'Programs', 'url' => 'index.php'],
        'edit_scheme' => ['title' => 'Create Program', 'url' => 'edit_scheme.php'],
        'reports' => ['title' => 'Reports', 'url' => 'reports.php']
    ];
    
    echo '<div class="discount-nav">';
    foreach ($tabs as $key => $tab) {
        $isActive = ($key === $active) ? ' active' : '';
        echo '<a href="' . h($tab['url']) . '" class="discount-nav-tab' . $isActive . '">';
        echo h($tab['title']);
        echo '</a>';
    }
    echo '</div>';
}

/* Load discount schemes */
function load_discount_schemes(PDO $pdo, int $tenantId): array {
    try {
        $st = $pdo->prepare("SELECT * FROM discount_schemes WHERE tenant_id = ? ORDER BY is_active DESC, created_at DESC");
        $st->execute([$tenantId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Exception $e) {
        return [];
    }
}

/* Get discount label */
function get_discount_label(array $scheme): string {
    $type = strtolower((string)($scheme['type'] ?? 'percent'));
    $value = (float)($scheme['value'] ?? 0);
    
    if ($type === 'percent') {
        return number_format($value, 0) . '% OFF';
    } else {
        global $currency;
        return $currency . ' ' . number_format($value, 0) . ' OFF';
    }
}

/* Load customers for assignments */
function load_customers(PDO $pdo, int $tenantId): array {
    try {
        $st = $pdo->prepare("
            SELECT id, name, phone, email, classification
            FROM customers 
            WHERE tenant_id = ? 
            ORDER BY name ASC
        ");
        $st->execute([$tenantId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Exception $e) {
        return [];
    }
}

/* Get a single discount scheme by ID */
function get_discount_scheme(PDO $pdo, int $schemeId, int $tenantId): ?array {
    try {
        $st = $pdo->prepare("
            SELECT * FROM discount_schemes 
            WHERE id = ? AND tenant_id = ? 
            LIMIT 1
        ");
        $st->execute([$schemeId, $tenantId]);
        $result = $st->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    } catch(Exception $e) {
        return null;
    }
}

/* Save or update discount scheme */
function save_discount_scheme(PDO $pdo, array $data, int $tenantId): bool {
    try {
        if (isset($data['id']) && $data['id'] > 0) {
            // Update existing
            $st = $pdo->prepare("
                UPDATE discount_schemes 
                SET name = ?, code = ?, type = ?, value = ?, is_stackable = ?, is_active = ?
                WHERE id = ? AND tenant_id = ?
            ");
            return $st->execute([
                $data['name'],
                $data['code'],
                $data['type'],
                $data['value'],
                $data['is_stackable'] ?? 0,
                $data['is_active'] ?? 1,
                $data['id'],
                $tenantId
            ]);
        } else {
            // Insert new
            $st = $pdo->prepare("
                INSERT INTO discount_schemes (tenant_id, name, code, type, value, is_stackable, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            return $st->execute([
                $tenantId,
                $data['name'],
                $data['code'],
                $data['type'],
                $data['value'],
                $data['is_stackable'] ?? 0,
                $data['is_active'] ?? 1
            ]);
        }
    } catch(Exception $e) {
        return false;
    }
}
?>