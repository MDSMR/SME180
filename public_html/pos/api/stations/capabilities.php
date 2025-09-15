<?php
declare(strict_types=1);

/**
 * Stations - Capabilities (proxied via station_type)
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
    $s = $pdo->prepare("SELECT station_type FROM pos_stations WHERE tenant_id=:t AND branch_id=:b AND station_code=:c LIMIT 1");
    $s->execute(['t'=>$tenantId,'b'=>$branchId,'c'=>$stationCode]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    respond(true, ['capabilities' => $row ? [$row['station_type']] : []]);
} catch (Throwable $e) { respond(false,$e->getMessage(),500); }
