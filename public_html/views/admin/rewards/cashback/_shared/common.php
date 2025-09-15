<?php
// /views/admin/rewards/cashback/_shared/common.php
// Shared bootstrap, authentication, and helper functions for Cashback
declare(strict_types=1);

/* Debug Mode */
$DEBUG = isset($_GET['debug']) && $_GET['debug'] === '1';
if ($DEBUG) { 
    @ini_set('display_errors','1'); 
    @ini_set('display_startup_errors','1'); 
    error_reporting(E_ALL); 
}

/* Bootstrap Database Connection */
$bootstrap_warning = ''; 
$bootstrap_ok = false; 
$bootstrap_tried = []; 
$bootstrap_found = '';

function _try_add(&$arr, string $p){ if (!in_array($p, $arr, true)) { $arr[]=$p; } }

// Try multiple paths to find db.php
_try_add($bootstrap_tried, __DIR__ . '/../../../../../config/db.php');
_try_add($bootstrap_tried, __DIR__ . '/../../../../config/db.php');

$docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
if ($docRoot !== '') { 
    _try_add($bootstrap_tried, $docRoot . '/config/db.php');
}

$cursor = __DIR__;
for ($i = 0; $i < 7; $i++) { 
    $cursor = dirname($cursor); 
    if ($cursor === '/' || $cursor === '.' || $cursor === '') break; 
    _try_add($bootstrap_tried, $cursor . '/config/db.php');
}

foreach ($bootstrap_tried as $p) { 
    if (is_file($p)) { 
        $bootstrap_found = $p; 
        break; 
    } 
}

if ($bootstrap_found === '') {
    $bootstrap_warning = 'Configuration file not found: /config/db.php';
} else {
    $prevHandler = set_error_handler(function($s, $m, $f, $l) { 
        throw new ErrorException($m, 0, $s, $f, $l); 
    });
    try {
        require_once $bootstrap_found;
        if (!function_exists('db') || !function_exists('use_backend_session')) {
            $bootstrap_warning = 'Required functions missing in config/db.php (db(), use_backend_session()).';
        } else { 
            $bootstrap_ok = true; 
        }
    } catch(Throwable $e) { 
        $bootstrap_warning = 'Bootstrap error: ' . $e->getMessage(); 
    } finally { 
        if($prevHandler) set_error_handler($prevHandler); 
    }
}

if ($bootstrap_ok) {
    try { 
        use_backend_session(); 
    } catch(Throwable $e) { 
        $bootstrap_warning = $bootstrap_warning ?: ('Session bootstrap error: ' . $e->getMessage()); 
    }
}

/* Authentication Check */
if (function_exists('auth_require_login')) {
    auth_require_login();
}
$user = $_SESSION['user'] ?? null;
if (!$user) { 
    header('Location: /views/auth/login.php'); 
    exit; 
}
$tenantId = (int)($user['tenant_id'] ?? 0);
$userId = (int)($user['id'] ?? 0);
$userName = $user['name'] ?? 'User';

/* Database Connection */
$pdo = function_exists('db') ? db() : null;

/* Get currency from settings */
$currency = 'EGP';
if ($pdo instanceof PDO) {
    try {
        $st = $pdo->prepare("SELECT value FROM settings WHERE tenant_id = ? AND `key` = 'currency' LIMIT 1");
        $st->execute([$tenantId]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        if ($res && isset($res['value'])) {
            $currency = $res['value'];
        }
    } catch(Throwable $e) {
        // Default to EGP if query fails
    }
}

/* Helper Functions */
function h($s): string { 
    return htmlspecialchars((string)$s, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8'); 
}

function normalize_dt(?string $s): ?string {
    if (!$s) return null; 
    $s = trim($s); 
    if ($s === '') return null;
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) $s .= ' 00:00:00';
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $s)) $s .= ':00';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $s)) return null;
    return $s;
}

function classify_program(array $r, DateTimeImmutable $now): string {
    $isActive = ($r['status'] ?? '') === 'active';
    $hasStart = !empty($r['start_at']); 
    $hasEnd = !empty($r['end_at']);
    $startOk = !$hasStart || (new DateTimeImmutable($r['start_at']) <= $now);
    $endOk = !$hasEnd || (new DateTimeImmutable($r['end_at']) >= $now);
    if ($isActive && $startOk && $endOk) return 'live';
    if ($hasStart && (new DateTimeImmutable($r['start_at']) > $now)) return 'scheduled';
    return 'past';
}

/* Safe Navigation Include */
function include_admin_nav(string $active=''): bool {
    $nav_path_candidates = [
        __DIR__ . '/../../../../partials/admin_nav.php',
        __DIR__ . '/../../../../../views/partials/admin_nav.php',
        __DIR__ . '/../../../../admin_nav.php',
        __DIR__ . '/../../../admin_nav.php',
        __DIR__ . '/../../admin_nav.php',
        $_SERVER['DOCUMENT_ROOT'] . '/views/partials/admin_nav.php'
    ];

    foreach ($nav_path_candidates as $nav_path) {
        if (is_file($nav_path)) {
            try {
                include $nav_path;
                return true;
            } catch (Throwable $e) {
                error_log('Navigation include error: ' . $e->getMessage());
            }
        }
    }
    return false;
}

/* Cashback Tab Navigation */
function render_cashback_nav(string $active): void {
    $tabs = [
        'index' => ['title' => 'Programs', 'url' => 'index.php'],
        'create' => ['title' => 'Create Program', 'url' => 'create.php'],
        'wallets' => ['title' => 'Members', 'url' => 'wallets.php'],
        'reports' => ['title' => 'Reports', 'url' => 'reports.php']
    ];
    
    echo '<div class="cashback-nav">';
    foreach ($tabs as $key => $tab) {
        $isActive = $key === $active ? ' active' : '';
        echo '<a href="' . h($tab['url']) . '" class="cashback-nav-tab' . $isActive . '">';
        echo h($tab['title']);
        echo '</a>';
    }
    echo '</div>';
}

/* Load cashback programs helper */
function load_cashback_programs(PDO $pdo, int $tenantId): array {
    try {
        $st = $pdo->prepare("SELECT id, name, status, program_type, start_at, end_at, created_at, updated_at,
                                    earn_mode, earn_rate, redeem_rate, min_redeem_points, max_redeem_percent,
                                    award_timing, expiry_policy, expiry_days, rounding, earn_rule_json, 
                                    redeem_rule_json, welcome_bonus_points
                            FROM loyalty_programs
                            WHERE tenant_id = ? AND program_type = 'cashback'
                            ORDER BY (CASE WHEN start_at IS NULL THEN 0 ELSE 1 END),
                                     COALESCE(start_at, created_at) DESC, id DESC");
        $st->execute([$tenantId]); 
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Throwable $e) { 
        return [];
    }
}

/* Load branches helper */
function load_branches(PDO $pdo, int $tenantId): array {
    try {
        $st = $pdo->prepare("SELECT id, name, display_name, branch_type, is_active 
                            FROM branches 
                            WHERE tenant_id = ? AND is_active = 1 
                            ORDER BY name ASC");
        $st->execute([$tenantId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Throwable $e) {
        error_log('Error loading branches: ' . $e->getMessage());
        return [];
    }
}

/* Load wallet balances */
function load_wallet_balances(PDO $pdo, int $tenantId, int $limit = 20, int $offset = 0, string $search = ''): array {
    try {
        $sql = "SELECT c.id, c.name, c.phone, c.email,
                       COALESCE(SUM(CASE WHEN l.type='cashback_redeem' THEN -l.cash_delta ELSE l.cash_delta END), 0.0) AS balance,
                       MAX(l.created_at) AS last_activity
                FROM customers c
                LEFT JOIN loyalty_ledger l ON l.customer_id = c.id AND l.tenant_id = :t
                WHERE c.tenant_id = :t";
        
        $params = [':t' => $tenantId];
        
        if ($search !== '') {
            $sql .= " AND (c.name LIKE :s OR c.phone LIKE :s OR c.email LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }
        
        $sql .= " GROUP BY c.id
                  HAVING balance > 0 OR last_activity IS NOT NULL
                  ORDER BY balance DESC
                  LIMIT :l OFFSET :o";
        
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':l', $limit, PDO::PARAM_INT);
        $st->bindValue(':o', $offset, PDO::PARAM_INT);
        $st->execute();
        
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Throwable $e) {
        error_log('Error loading wallet balances: ' . $e->getMessage());
        return [];
    }
}

/* Load cashback statistics */
function load_cashback_stats(PDO $pdo, int $tenantId): array {
    $stats = [
        'activeWallets' => 0,
        'totalBalance' => 0,
        'issued30d' => 0,
        'redeemed30d' => 0,
        'avgBalance' => 0
    ];

    try {
        // Active wallets and total balance
        $st = $pdo->prepare("
            SELECT COUNT(DISTINCT customer_id) as active_wallets,
                   SUM(CASE WHEN type='cashback_redeem' THEN -cash_delta ELSE cash_delta END) as total_balance
            FROM loyalty_ledger 
            WHERE tenant_id = ? 
            HAVING total_balance > 0
        ");
        $st->execute([$tenantId]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $stats['activeWallets'] = (int)($res['active_wallets'] ?? 0);
            $stats['totalBalance'] = (float)($res['total_balance'] ?? 0);
            if ($stats['activeWallets'] > 0) {
                $stats['avgBalance'] = $stats['totalBalance'] / $stats['activeWallets'];
            }
        }

        // 30-day issued
        $st = $pdo->prepare("SELECT SUM(cash_delta) FROM loyalty_ledger 
                           WHERE tenant_id = ? AND type = 'cashback_earn' 
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $st->execute([$tenantId]);
        $stats['issued30d'] = (float)($st->fetchColumn() ?: 0);

        // 30-day redeemed
        $st = $pdo->prepare("SELECT SUM(ABS(cash_delta)) FROM loyalty_ledger 
                           WHERE tenant_id = ? AND type = 'cashback_redeem' 
                           AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $st->execute([$tenantId]);
        $stats['redeemed30d'] = (float)($st->fetchColumn() ?: 0);
        
    } catch(Throwable $e) {
        error_log('Error loading cashback stats: ' . $e->getMessage());
    }
    
    return $stats;
}

/* Format money */
function money($amount, $currency = null): string {
    global $currency;
    $curr = $currency ?: $currency ?: 'EGP';
    $symbol = '';
    
    switch($curr) {
        case 'USD': $symbol = '$'; break;
        case 'EUR': $symbol = '€'; break;
        case 'GBP': $symbol = '£'; break;
        case 'EGP': $symbol = 'EGP '; break;
        default: $symbol = $curr . ' ';
    }
    
    return $symbol . number_format((float)$amount, 2);
}
?>