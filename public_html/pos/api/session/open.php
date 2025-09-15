<?php
declare(strict_types=1);

/**
 * Session - Open
 * Body: { tenant_id, branch_id, user_id, [station_id], [opening_amount=0] }
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

$in = read_input();
require_fields($in, ['tenant_id','branch_id','user_id']);

$tenantId = (int)$in['tenant_id'];
$branchId = (int)$in['branch_id'];
$userId   = (int)$in['user_id'];
$stationId = isset($in['station_id']) ? (int)$in['station_id'] : null;
$opening   = isset($in['opening_amount']) ? (float)$in['opening_amount'] : 0.0;

try {
    $pdo = db();
    $sql = "INSERT INTO cash_sessions (tenant_id, branch_id, shift_id, station_id, user_id, opening_amount, status, opened_at)
            VALUES (:t,:b,NULL,:station,:user,:open,'open',NOW())";
    $s = $pdo->prepare($sql);
    $s->execute(['t'=>$tenantId,'b'=>$branchId,'station'=>$stationId,'user'=>$userId,'open'=>$opening]);
    respond(true, ['session_id'=>(int)$pdo->lastInsertId()]);
} catch (Throwable $e) { respond(false,$e->getMessage(),500); }
