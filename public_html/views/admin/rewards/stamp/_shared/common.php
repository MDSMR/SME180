<?php
// /views/admin/rewards/stamp/_shared/common.php
// Shared bootstrap, authentication, and helper functions for stamp rewards
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

/* Stamp Tab Navigation */
function render_stamp_nav(string $active): void {
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

/* Load branches for tenant */
function load_branches(PDO $pdo, int $tenantId): array {
    try {
        // Try multiple possible table names and structures for branches
        $queries = [
            "SELECT id, name FROM branches WHERE tenant_id = ? AND is_active = 1 ORDER BY name ASC",
            "SELECT id, branch_name as name FROM branches WHERE tenant_id = ? AND status = 'active' ORDER BY branch_name ASC",
            "SELECT id, name FROM locations WHERE tenant_id = ? AND is_active = 1 ORDER BY name ASC",
            "SELECT id, location_name as name FROM locations WHERE tenant_id = ? AND status = 'active' ORDER BY location_name ASC"
        ];
        
        foreach ($queries as $sql) {
            try {
                $st = $pdo->prepare($sql);
                $st->execute([$tenantId]);
                $results = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                if ($results) {
                    return $results;
                }
            } catch (Throwable $e) {
                continue;
            }
        }
        
        // If no branches table exists, return a default "All Branches" option
        return [['id' => 0, 'name' => 'All Branches']];
        
    } catch (Throwable $e) {
        error_log('Load branches error: ' . $e->getMessage());
        return [['id' => 0, 'name' => 'All Branches']];
    }
}

/* Load products for dropdowns - Updated with branch support */
function load_products(PDO $pdo, int $tenantId, array $branchIds = []): array {
    try {
        // Base query for tenant
        $sql = "SELECT DISTINCT p.id, p.name_en, p.branch_id FROM products p WHERE p.tenant_id = ? AND p.is_active = 1";
        $params = [$tenantId];
        
        // Add branch filtering if specified
        if (!empty($branchIds) && !in_array(0, $branchIds)) {
            $placeholders = str_repeat('?,', count($branchIds) - 1) . '?';
            $sql .= " AND p.branch_id IN ($placeholders)";
            $params = array_merge($params, $branchIds);
        }
        
        $sql .= " ORDER BY p.name_en ASC";
        
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $results = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // If no results and we tried branch filtering, try without branch filtering
        if (empty($results) && !empty($branchIds)) {
            $st = $pdo->prepare("SELECT id, name_en FROM products WHERE tenant_id = ? AND is_active = 1 ORDER BY name_en ASC");
            $st->execute([$tenantId]);
            $results = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        
        return $results;
        
    } catch (Throwable $e) {
        error_log('Load products error: ' . $e->getMessage());
        
        // Fallback: try simple query without branch
        try {
            $st = $pdo->prepare("SELECT id, name_en FROM products WHERE tenant_id = ? AND is_active = 1 ORDER BY name_en ASC");
            $st->execute([$tenantId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e2) {
            return [];
        }
    }
}

/* Load stamp programs - Robust version that tries multiple approaches */
function load_stamp_programs(PDO $pdo, int $tenantId): array {
    try {
        // Try the most common query patterns in order
        $queries = [
            "SELECT id, name, status, start_at, end_at, stamps_required, 
                     reward_item_id, per_visit_cap, carry_over, earn_scope_json,
                     branch_ids, created_at, updated_at
             FROM loyalty_programs
             WHERE tenant_id = ? AND type = 'stamp'
             ORDER BY id DESC",
             
            "SELECT id, name, status, start_at, end_at, stamps_required, 
                     reward_item_id, per_visit_cap, carry_over, earn_scope_json,
                     created_at, updated_at
             FROM loyalty_programs
             WHERE tenant_id = ? AND program_type = 'stamp'
             ORDER BY id DESC",
             
            "SELECT id, name, status, start_at, end_at, stamps_required, 
                     reward_item_id, per_visit_cap, carry_over, earn_scope_json,
                     created_at, updated_at
             FROM loyalty_programs
             WHERE tenant_id = ?
             ORDER BY id DESC"
        ];
        
        foreach ($queries as $sql) {
            try {
                $st = $pdo->prepare($sql);
                $st->execute([$tenantId]); 
                $results = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
                
                // If we found results, return them
                if ($results) {
                    return $results;
                }
            } catch(Throwable $e) {
                // Continue to next query if this one fails
                continue;
            }
        }
        
        return [];
    } catch(Throwable $e) { 
        error_log('Load stamp programs error: ' . $e->getMessage());
        return [];
    }
}

/* Load customers with stamp balances */
function load_customers_with_balances(PDO $pdo, int $tenantId, int $programId, int $offset = 0, int $limit = 12): array {
    try {
        if ($programId <= 0) return ['customers' => [], 'total' => 0];
        
        $sql = "
            SELECT SQL_CALC_FOUND_ROWS c.id, c.name, c.phone,
                COALESCE((
                    SELECT SUM(CASE WHEN ll.direction='redeem' THEN -ll.amount ELSE ll.amount END)
                    FROM loyalty_ledgers ll
                    WHERE ll.tenant_id = :t AND ll.program_type='stamp' AND ll.program_id = :pid AND ll.customer_id = c.id
                ), 0) AS balance
            FROM customers c
            WHERE c.tenant_id = :t
            ORDER BY c.id DESC
            LIMIT :o, :l
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $programId, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $customers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
        
        return ['customers' => $customers, 'total' => $total];
    } catch(Throwable $e) {
        error_log('Load customers error: ' . $e->getMessage());
        return ['customers' => [], 'total' => 0];
    }
}

/* Load stamp transactions */  
function load_stamp_transactions(PDO $pdo, int $tenantId, int $programId, int $offset = 0, int $limit = 12): array {
    try {
        if ($programId <= 0) return ['transactions' => [], 'total' => 0];
        
        $sql = "
            SELECT SQL_CALC_FOUND_ROWS ll.id, ll.created_at, ll.direction, ll.amount, ll.order_id, ll.user_id,
                   c.name AS customer_name, c.id AS customer_id
            FROM loyalty_ledgers ll
            LEFT JOIN customers c ON c.id = ll.customer_id
            WHERE ll.tenant_id=:t AND ll.program_type='stamp' AND ll.program_id=:pid
            ORDER BY ll.id DESC
            LIMIT :o, :l
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(':t', $tenantId, PDO::PARAM_INT);
        $stmt->bindValue(':pid', $programId, PDO::PARAM_INT);
        $stmt->bindValue(':o', $offset, PDO::PARAM_INT);
        $stmt->bindValue(':l', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $total = (int)$pdo->query("SELECT FOUND_ROWS()")->fetchColumn();
        
        return ['transactions' => $transactions, 'total' => $total];
    } catch(Throwable $e) {
        error_log('Load transactions error: ' . $e->getMessage());
        return ['transactions' => [], 'total' => 0];
    }
}

/* Pagination helper */
function pagelinks($total, $page, $limit, $qsKey){
    $pages = max(1, (int)ceil($total / $limit));
    if ($pages <= 1) return '';
    $qs = $_GET; unset($qs[$qsKey]);
    $base = strtok($_SERVER['REQUEST_URI'], '?');
    $out = '<div class="pager">';
    if ($page > 1) { $qs[$qsKey] = $page-1; $out .= '<a class="btn" href="'.$base.'?'.http_build_query($qs).'">Prev</a>'; }
    $out .= '<span class="helper">Page '.$page.' of '.$pages.'</span>';
    if ($page < $pages) { $qs[$qsKey] = $page+1; $out .= '<a class="btn" href="'.$base.'?'.http_build_query($qs).'">Next</a>'; }
    return $out.'</div>';
}
?>