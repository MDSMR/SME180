<?php
// /views/admin/rewards/points/_shared/common.php
// Shared bootstrap, authentication, and helper functions
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

// Get currency from tenant settings
$currency = '$';
if ($pdo instanceof PDO) {
    try {
        $st = $pdo->prepare("SELECT currency_symbol FROM tenant_settings WHERE tenant_id = ? LIMIT 1");
        $st->execute([$tenantId]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        if ($res && isset($res['currency_symbol'])) {
            $currency = $res['currency_symbol'];
        }
    } catch(Throwable $e) {
        // Default to $ if query fails
    }
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

/* Points Tab Navigation */
function render_points_nav(string $active): void {
    $tabs = [
        'index' => ['title' => 'Programs', 'url' => 'index.php'],
        'create' => ['title' => 'Create Program', 'url' => 'create.php'],
        'reports' => ['title' => 'Reports', 'url' => 'reports.php']
    ];
    
    echo '<div class="points-nav">';
    foreach ($tabs as $key => $tab) {
        $isActive = $key === $active ? ' active' : '';
        echo '<a href="' . h($tab['url']) . '" class="points-nav-tab' . $isActive . '">';
        echo h($tab['title']);
        echo '</a>';
    }
    echo '</div>';
}

/* Load programs helper */
function load_loyalty_programs(PDO $pdo, int $tenantId): array {
    try {
        $st = $pdo->prepare("SELECT id, name, status, program_type, start_at, end_at, created_at, updated_at,
                                    earn_mode, earn_rate, redeem_rate, min_redeem_points, max_redeem_percent,
                                    award_timing, expiry_policy, expiry_days, rounding, earn_rule_json, welcome_bonus_points
                            FROM loyalty_programs
                            WHERE tenant_id = ? AND program_type = 'points'
                            ORDER BY (CASE WHEN start_at IS NULL THEN 0 ELSE 1 END),
                                     COALESCE(start_at, created_at) DESC, id DESC");
        $st->execute([$tenantId]); 
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch(Throwable $e) { 
        return [];
    }
}

/* Load branches helper - THIS WAS MISSING */
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

/* Load loyalty statistics */
function load_loyalty_stats(PDO $pdo, int $tenantId): array {
    $stats = [
        'totalMembers' => 0,
        'liveCount' => 0,
        'recentTxCount' => 0
    ];

    try {
        // Count members with points
        $st = $pdo->prepare("SELECT COUNT(DISTINCT customer_id) as cnt FROM loyalty_ledger WHERE tenant_id = ? AND points_delta != 0");
        $st->execute([$tenantId]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        $stats['totalMembers'] = (int)($res['cnt'] ?? 0);

        // Count recent transactions
        $st = $pdo->prepare("SELECT COUNT(*) as cnt FROM loyalty_ledger WHERE tenant_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)");
        $st->execute([$tenantId]);
        $res = $st->fetch(PDO::FETCH_ASSOC);
        $stats['recentTxCount'] = (int)($res['cnt'] ?? 0);
    } catch(Throwable $e) {}
    
    return $stats;
}
?>