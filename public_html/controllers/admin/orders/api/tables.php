<?php
// /controllers/admin/orders/api/tables.php
// List dine-in tables for a branch (tenant-aware), clean JSON output
declare(strict_types=1);

// JSON response
@ini_set('display_errors', '0');
error_reporting(E_ALL);
header('Content-Type: application/json; charset=utf-8');

/** Emit a normalized JSON response and exit */
function jsonResponse(bool $success, string $message = '', array $payload = []): void {
    $out = [
        'success'   => $success,
        'message'   => $message,
        // keep both keys for callers that expect either "data" or "results"
        'data'      => $payload['data'] ?? ($payload['results'] ?? []),
        'results'   => $payload['results'] ?? ($payload['data'] ?? []),
        'timestamp' => time(),
    ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    /* ---------- Bootstrap: /config/db.php + session ---------- */
    // We are under /controllers/admin/orders/api -> 3 levels up to /public_html
    $root = dirname(__DIR__, 3);
    $dbPath = $root . '/config/db.php';
    if (!is_file($dbPath)) {
        throw new RuntimeException('Database config file not found at: ' . $dbPath);
    }
    require_once $dbPath;

    if (!function_exists('db')) {
        // Optional fallback to $GLOBALS['pdo'] if present
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            function db(): PDO { return $GLOBALS['pdo']; }
        } else {
            throw new RuntimeException('Database connection not available');
        }
    }

    if (function_exists('use_backend_session')) {
        use_backend_session();
    } else {
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    }

    // Logged-in user context
    $user = $_SESSION['user'] ?? null;
    $sessionTenantId = (int)($user['tenant_id'] ?? 0);

    /* ---------- Inputs ---------- */
    $branchId  = (int)($_GET['branch_id'] ?? 0);
    $tenantId  = $sessionTenantId > 0 ? $sessionTenantId : (int)($_GET['tenant_id'] ?? 0);

    if ($tenantId <= 0) {
        jsonResponse(false, 'Tenant not resolved. Please sign in or provide tenant_id.');
    }
    if ($branchId <= 0) {
        jsonResponse(false, 'branch_id is required.');
    }

    /* ---------- Query ---------- */
    // Schema references:
    // dining_tables(id, tenant_id, branch_id, table_number, section, seats, status, created_at, updated_at)
    // (No is_active column on tables; we’ll return all rows for the branch/tenant.)
    // Customers and other tables are in the same database per your db dump. :contentReference[oaicite:1]{index=1}
    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT 
            id,
            table_number,
            COALESCE(section, 'main') AS section,
            COALESCE(seats, 0)      AS seats,
            COALESCE(status, 'free') AS status
        FROM dining_tables
        WHERE tenant_id = :t AND branch_id = :b
        ORDER BY 
            CASE WHEN section = 'vip' THEN 0 ELSE 1 END,
            table_number
    ");
    $stmt->execute([':t' => $tenantId, ':b' => $branchId]);

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $r['id']     = (int)$r['id'];
        $r['seats']  = (int)$r['seats'];
        $r['status'] = (string)$r['status'];
    }

    jsonResponse(true, 'Tables loaded', ['data' => $rows]);

} catch (PDOException $e) {
    error_log('[Tables API DB Error] ' . $e->getMessage());
    jsonResponse(false, 'Database error occurred. Please try again.');
} catch (Throwable $e) {
    error_log('[Tables API Error] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(false, 'Failed to load tables: ' . $e->getMessage());
}