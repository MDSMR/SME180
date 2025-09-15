<?php
// /controllers/admin/orders/api/search.php
// Customer & product search API (tenant-aware, robust)
declare(strict_types=1);

// Strict JSON output
@ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

/** JSON response helper */
function jsonResponse(bool $success, string $message = '', array $payload = []): void {
    $out = [
        'success'   => $success,
        'message'   => $message,
        'results'   => $payload['results'] ?? ($payload['data'] ?? []),
        'timestamp' => time(),
    ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    /* ---------- Bootstrap: load /config/db.php and session ---------- */
    $root = dirname(__DIR__, 4); // FIXED: 3 levels up to /public_html, not 4
    $dbPath = $root . '/config/db.php';
    
    if (!is_file($dbPath)) {
        throw new RuntimeException('Database config file not found at: ' . $dbPath);
    }
    require_once $dbPath;

    // Ensure db() function exists with fallback
    if (!function_exists('db')) {
        // Try to create from global $pdo if available
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            function db(): PDO {
                return $GLOBALS['pdo'];
            }
        } else {
            throw new RuntimeException('Database connection not available');
        }
    }
    
    // Session handling with fallback
    if (function_exists('use_backend_session')) {
        use_backend_session(); // preferred if available
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) { 
            @session_start(); 
        }
    }

    // Logged-in user (if present)
    $user = $_SESSION['user'] ?? null;
    $sessionTenantId = (int)($user['tenant_id'] ?? 0);

    /* ---------- Inputs ---------- */
    $type = isset($_GET['type']) ? (string)$_GET['type'] : '';
    $qRaw = isset($_GET['q']) ? (string)$_GET['q'] : '';
    $q = trim($qRaw);

    // Prefer session's tenant; otherwise accept GET param
    $tenantId = $sessionTenantId > 0
        ? $sessionTenantId
        : (int)($_GET['tenant_id'] ?? 0);

    if ($type === '') {
        jsonResponse(false, 'Search type is required (e.g., type=customer).');
    }
    if ($tenantId <= 0) {
        jsonResponse(false, 'Tenant not resolved. Please sign in or provide tenant_id.');
    }

    $pdo = db();
    $results = [];

    /* ---------- CUSTOMER SEARCH ---------- */
    if ($type === 'customer') {
        // If empty query: show last 10 (most recent) active customers
        if ($q === '') {
            $stmt = $pdo->prepare("
                SELECT id, name, phone, email, classification,
                       COALESCE(points_balance, 0) AS points_balance,
                       COALESCE(rewards_enrolled, 0) AS rewards_enrolled
                FROM customers
                WHERE tenant_id = :t
                  AND (classification <> 'blocked' OR classification IS NULL)
                ORDER BY created_at DESC
                LIMIT 10
            ");
            $stmt->execute([':t' => $tenantId]);
        } else {
            // Normalize phone digits for robust matching
            $digits = preg_replace('/\D+/', '', $q);
            
            // Determine if this looks like a phone search (3+ consecutive digits)
            $isPhoneSearch = ctype_digit($q) || (strlen($digits) >= 3 && strlen($digits) / strlen($q) > 0.5);

            if ($isPhoneSearch) {
                // Primarily a phone search with name/email fallback
                $stmt = $pdo->prepare("
                    SELECT id, name, phone, email, classification,
                           COALESCE(points_balance, 0) AS points_balance,
                           COALESCE(rewards_enrolled, 0) AS rewards_enrolled
                    FROM customers
                    WHERE tenant_id = :t
                      AND (classification <> 'blocked' OR classification IS NULL)
                      AND (
                            phone LIKE :phone_like
                         OR REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE :digits_like
                         OR name LIKE :name_like
                         OR email LIKE :email_like
                      )
                    ORDER BY
                        CASE
                            WHEN REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') = :digits_exact THEN 1
                            WHEN phone = :phone_exact THEN 2
                            WHEN phone LIKE :phone_prefix THEN 3
                            WHEN REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', '') LIKE :digits_prefix THEN 4
                            ELSE 5
                        END,
                        name ASC
                    LIMIT 20
                ");
                $stmt->execute([
                    ':t'             => $tenantId,
                    ':phone_like'    => "%{$q}%",
                    ':digits_like'   => "%{$digits}%",
                    ':name_like'     => "%{$q}%",
                    ':email_like'    => "%{$q}%",
                    ':digits_exact'  => $digits,
                    ':phone_exact'   => $q,
                    ':phone_prefix'  => "{$q}%",
                    ':digits_prefix' => "{$digits}%"
                ]);
            } else {
                // Name/email search (case-insensitive LIKE)
                $like = "%{$q}%";
                $stmt = $pdo->prepare("
                    SELECT id, name, phone, email, classification,
                           COALESCE(points_balance, 0) AS points_balance,
                           COALESCE(rewards_enrolled, 0) AS rewards_enrolled
                    FROM customers
                    WHERE tenant_id = :t
                      AND (classification <> 'blocked' OR classification IS NULL)
                      AND (
                            LOWER(name) LIKE LOWER(:like1)
                         OR phone LIKE :like2
                         OR LOWER(email) LIKE LOWER(:like3)
                      )
                    ORDER BY
                        CASE 
                            WHEN LOWER(name) = LOWER(:exact) THEN 1
                            WHEN LOWER(name) LIKE LOWER(:starts) THEN 2 
                            ELSE 3 
                        END,
                        name ASC
                    LIMIT 20
                ");
                $stmt->execute([
                    ':t'      => $tenantId,
                    ':like1'  => $like,
                    ':like2'  => $like,
                    ':like3'  => $like,
                    ':exact'  => $q,
                    ':starts' => "{$q}%"
                ]);
            }
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Normalize types for frontend consistency
        foreach ($rows as &$r) {
            $r['id']               = (int)$r['id'];
            $r['name']             = $r['name'] ?? '';
            $r['phone']            = $r['phone'] ?? '';
            $r['email']            = $r['email'] ?? '';
            $r['classification']   = $r['classification'] ?? 'regular';
            $r['points_balance']   = (int)($r['points_balance'] ?? 0);
            $r['rewards_enrolled'] = (bool)($r['rewards_enrolled'] ?? 0);
        }
        $results = $rows;

    /* ---------- PRODUCT SEARCH ---------- */
    } elseif ($type === 'product') {
        $branchId = (int)($_GET['branch_id'] ?? 0);
        $where = ["p.tenant_id = :t", "p.is_active = 1", "p.pos_visible = 1"];
        $params = [':t' => $tenantId];

        if ($branchId > 0) {
            $where[] = "EXISTS (
                SELECT 1 FROM product_branches pb
                WHERE pb.product_id = p.id AND pb.branch_id = :b
            )";
            $params[':b'] = $branchId;
        }

        if ($q !== '') {
            $where[] = "(
                LOWER(p.name_en) LIKE LOWER(:q) 
                OR LOWER(p.name_ar) LIKE LOWER(:q) 
                OR p.id = :qid
            )";
            $params[':q'] = "%{$q}%";
            $params[':qid'] = ctype_digit($q) ? (int)$q : 0;
        }

        $sql = "
            SELECT
                p.id,
                p.name_en,
                p.name_ar,
                p.price,
                p.standard_cost,
                p.is_inventory_tracked,
                p.inventory_unit,
                GROUP_CONCAT(DISTINCT c.name_en SEPARATOR ', ') AS categories,
                COALESCE(ssl.current_stock, 0) AS available_stock
            FROM products p
            LEFT JOIN product_categories pc ON pc.product_id = p.id
            LEFT JOIN categories c ON c.id = pc.category_id
            LEFT JOIN stockflow_stock_levels ssl ON ssl.product_id = p.id
                 AND ssl.tenant_id = p.tenant_id
                 " . ($branchId > 0 ? "AND ssl.branch_id = :b2" : "") . "
            WHERE " . implode(' AND ', $where) . "
            GROUP BY p.id
            ORDER BY p.name_en
            LIMIT 50
        ";

        $stmt = $pdo->prepare($sql);
        if ($branchId > 0) { 
            $params[':b2'] = $branchId; 
        }
        $stmt->execute($params);
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        
        // Normalize product data
        foreach ($results as &$p) {
            $p['id']                   = (int)$p['id'];
            $p['price']                = (float)$p['price'];
            $p['standard_cost']        = (float)$p['standard_cost'];
            $p['available_stock']      = (float)$p['available_stock'];
            $p['is_inventory_tracked'] = (bool)$p['is_inventory_tracked'];
        }

    } else {
        jsonResponse(false, 'Unsupported search type: ' . $type);
    }

    jsonResponse(true, 'Search completed', ['results' => $results]);

} catch (PDOException $e) {
    error_log('[Search API DB Error] ' . $e->getMessage());
    jsonResponse(false, 'Database error occurred. Please try again.');
} catch (Throwable $e) {
    error_log('[Search API Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(false, 'Search failed: ' . $e->getMessage());
}
?>