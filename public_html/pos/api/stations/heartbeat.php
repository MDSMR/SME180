<?php
declare(strict_types=1);

/**
 * Stations - Heartbeat
 * Body: { tenant_id, branch_id, station_code }
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

$in = read_input();
require_fields($in, ['tenant_id','branch_id','station_code']);

$tenantId    = (int)$in['tenant_id'];
$branchId    = (int)$in['branch_id'];
$stationCode = (string)$in['station_code'];

try {
    $pdo = db();
    $q = $pdo->prepare("UPDATE pos_stations SET last_heartbeat=NOW() WHERE tenant_id=:t AND branch_id=:b AND station_code=:c");
    $q->execute(['t'=>$tenantId,'b'=>$branchId,'c'=>$stationCode]);
    respond(true, ['server_time'=>date('c')]);
} catch (Throwable $e) { respond(false,$e->getMessage(),500); }
