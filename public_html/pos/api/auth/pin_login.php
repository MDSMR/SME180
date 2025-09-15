<?php
declare(strict_types=1);

/**
 * POS Auth - PIN Login
 * Body: { pin, station_code, [tenant_id], [branch_id] }
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

function column_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare(
        "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1"
    );
    $stmt->execute(['t' => $table, 'c' => $column]);
    return (bool)$stmt->fetchColumn();
}

$in = read_input();
$pin = $in['pin'] ?? '';
$stationCode = $in['station_code'] ?? '';
if ($pin === '' || $stationCode === '') respond(false, 'Missing pin or station_code', 400);

try {
    $pdo = db();

    // Build portable SELECT that adapts to your users schema.
    $hasRoleKey = column_exists($pdo, 'users', 'role_key');
    $hasRole    = column_exists($pdo, 'users', 'role');
    $hasUsern   = column_exists($pdo, 'users', 'username');
    $hasName    = column_exists($pdo, 'users', 'name');
    $hasEmail   = column_exists($pdo, 'users', 'email');
    $hasStatus  = column_exists($pdo, 'users', 'status');

    $roleExpr   = $hasRoleKey ? 'role_key' : ($hasRole ? 'role' : "''");
    $nameExpr   = $hasUsern ? 'username' : ($hasName ? 'name' : ($hasEmail ? 'email' : "CONCAT('user#', id)"));
    $statusExpr = $hasStatus ? 'status' : "'active'";

    $sql = "SELECT id, {$nameExpr} AS name, {$roleExpr} AS role, {$statusExpr} AS status
            FROM users
            WHERE pin_code = :pin
            LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['pin' => $pin]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        respond(false, 'Invalid PIN', 401);
    }

    // If a status column exists and is not active, block login.
    if ($hasStatus && strtolower((string)$u['status']) !== 'active') {
        respond(false, 'User disabled', 403);
    }

    if (session_status() === PHP_SESSION_NONE) @session_start();
    $_SESSION['pos_user_id']   = (int)$u['id'];
    $_SESSION['pos_user_name'] = (string)$u['name'];
    $_SESSION['pos_user_role'] = (string)$u['role'];
    $_SESSION['station_code']  = $stationCode;

    respond(true, [
        'user_id'       => (int)$u['id'],
        'name'          => (string)$u['name'],
        'role'          => (string)$u['role'],
        'station_code'  => $stationCode,
        'session_token' => session_id(),
    ]);
} catch (Throwable $e) {
    respond(false, $e->getMessage(), 500);
}