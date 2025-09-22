<?php
declare(strict_types=1);

// public_html/api/dashboard/kpis.php
// Returns JSON KPIs for the dashboard. Uses cookie tokens (HttpOnly) set at login.

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/jwt.php';
require_once __DIR__ . '/../../config/auth.php';

// ---- auth via cookies (like admin pages) ----
$access  = $_COOKIE['access_token']  ?? '';
$refresh = $_COOKIE['refresh_token'] ?? '';
$domain  = $_SERVER['HTTP_HOST'] ?? '';
$path    = '/';
$secure  = true;

// verify (and auto-refresh if needed)
function kpis_require_auth(): ?array {
    global $access, $refresh, $domain, $path, $secure;
    if ($access !== '') {
        [$ok, $data] = jwt_verify($access);
        if ($ok) {
            $user = get_auth_user($data);
            if ($user) return [$user, $data];
        }
    }
    if ($refresh !== '') {
        $uid = verify_refresh_token($refresh);
        if ($uid) {
            revoke_refresh_token($uid, $refresh);
            [$newAccess, $newRefresh] = issue_access_and_refresh($uid);
            // set new cookies
            setcookie('access_token', $newAccess, [
                'expires'  => time() + jwt_exp_seconds(),
                'path'     => $path, 'domain' => $domain,
                'secure'   => $secure, 'httponly' => true, 'samesite' => 'Lax',
            ]);
            setcookie('refresh_token', $newRefresh, [
                'expires'  => time() + jwt_refresh_exp_seconds(),
                'path'     => $path, 'domain' => $domain,
                'secure'   => $secure, 'httponly' => true, 'samesite' => 'Strict',
            ]);
            [$ok2, $data2] = jwt_verify($newAccess);
            if ($ok2) {
                $user = get_auth_user($data2);
                if ($user) return [$user, $data2];
            }
        }
    }
    return null;
}

$auth = kpis_require_auth();
if (!$auth) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}
[$user, $payload] = $auth;

// ---- KPIs ----
// NOTE: We use server-local "today" via CURDATE(). If you need strict Asia/Kuwait,
// we can switch to PHP date range filtering—just say the word.

$pdo = db();

// 1) Today’s Sales: sum total_price for paid orders today
$todaySales = 0.00;
$stmt = $pdo->query("
    SELECT COALESCE(SUM(total_price), 0) AS s
    FROM orders
    WHERE payment_status = 'paid'
      AND DATE(created_at) = CURDATE()
");
$todaySales = (float)($stmt->fetchColumn() ?: 0);

// 2) Open Orders: orders still pending
$openOrders = 0;
$stmt = $pdo->query("
    SELECT COUNT(*) FROM orders
    WHERE status = 'pending'
");
$openOrders = (int)$stmt->fetchColumn();

// 3) Active Products (menu items): products marked active and not archived
$activeItems = 0;
$stmt = $pdo->query("
    SELECT COUNT(*) FROM products
    WHERE is_active = 1
      AND archived_at IS NULL
");
$activeItems = (int)$stmt->fetchColumn();

// optional: add more KPIs later (e.g., avg order value, paid count, etc.)

echo json_encode([
    'ok' => true,
    'kpis' => [
        'today_sales'  => $todaySales,   // number
        'open_orders'  => $openOrders,   // integer
        'active_items' => $activeItems,  // integer
    ],
    'server_time' => date('Y-m-d H:i:s'),
]);