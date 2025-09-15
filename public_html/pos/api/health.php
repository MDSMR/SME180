<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/tenant_context.php';
require_once __DIR__ . '/_common.php';

try {
    $pdo = db();
    $pdo->query('SELECT 1');
    $db_ok = true;
} catch (Throwable $e) {
    $db_ok = false;
}

respond(true, [
    'pos_api_mode' => defined('POS_API_MODE') ? (POS_API_MODE ? 'on' : 'off') : 'unknown',
    'db'           => $db_ok ? 'ok' : 'error',
    'tenant_id'    => get_tenant_id(),
    'branch_id'    => get_branch_id(),
    'server_time'  => date('c'),
]);
