<?php
declare(strict_types=1);

/**
 * Tables Module - Permissions Helper
 * Path: /public_html/controllers/admin/tables/permissions.php
 *
 * Helpers:
 *  - hasTablePermission(PDO $db, int $tenantId, string $roleKey, string $permission): bool
 *  - ensureTablePermission(PDO $db, int $tenantId, string $roleKey, string $permission): void (403 JSON)
 *  - getUserRole(): string
 */

function hasTablePermission(PDO $db, int $tenantId, string $roleKey, string $permission): bool {
    // Allow if ACL table is missing
    try {
        $db->query("SELECT 1 FROM `pos_role_permissions` LIMIT 1");
    } catch (Throwable $e) {
        return true;
    }

    $sql = "
        SELECT is_allowed
        FROM pos_role_permissions
        WHERE tenant_id = :t
          AND role_key = :rk
          AND module = 'tables'
          AND permission = :perm
        LIMIT 1
    ";
    $st = $db->prepare($sql);
    $st->execute([':t' => $tenantId, ':rk' => $roleKey, ':perm' => $permission]);
    $val = $st->fetchColumn();
    if ($val === false) return false;
    return (int)$val === 1 || $val === '1';
}

function ensureTablePermission(PDO $db, int $tenantId, string $roleKey, string $permission): void {
    if (!hasTablePermission($db, $tenantId, $roleKey, $permission)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Permission denied: tables.' . $permission
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function getUserRole(): string {
    return (string)($_SESSION['user']['role_key'] ?? $_SESSION['user']['role'] ?? 'pos_waiter');
}