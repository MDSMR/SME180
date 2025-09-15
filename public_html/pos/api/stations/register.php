<?php
declare(strict_types=1);

/**
 * Stations - Register
 * Body: { tenant_id, branch_id, station_code, station_name, [station_type='pos'] }
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

$in = read_input();
require_fields($in, ['tenant_id','branch_id','station_code','station_name']);

$tenantId    = (int)$in['tenant_id'];
$branchId    = (int)$in['branch_id'];
$stationCode = trim((string)$in['station_code']);
$stationName = trim((string)$in['station_name']);
$stationType = trim((string)($in['station_type'] ?? 'pos'));

try {
    $pdo = db();

    // Use distinct placeholders in ON DUPLICATE to avoid HY093
    $sql = "INSERT INTO pos_stations
              (tenant_id, branch_id, station_code, station_name, station_type, last_heartbeat, is_active, created_at)
            VALUES
              (:t, :b, :code, :name, :type, NOW(), 1, NOW())
            ON DUPLICATE KEY UPDATE
              station_name   = :name_upd,
              station_type   = :type_upd,
              last_heartbeat = NOW(),
              updated_at     = NOW()";

    $st = $pdo->prepare($sql);
    $st->execute([
        't'        => $tenantId,
        'b'        => $branchId,
        'code'     => $stationCode,
        'name'     => $stationName,
        'type'     => $stationType ?: 'pos',
        'name_upd' => $stationName,
        'type_upd' => $stationType ?: 'pos',
    ]);

    $idStmt = $pdo->prepare("SELECT id FROM pos_stations WHERE tenant_id=:t AND branch_id=:b AND station_code=:c LIMIT 1");
    $idStmt->execute(['t'=>$tenantId,'b'=>$branchId,'c'=>$stationCode]);
    $row = $idStmt->fetch(PDO::FETCH_ASSOC);

    respond(true, ['station_id' => $row ? (int)$row['id'] : null]);
} catch (Throwable $e) {
    respond(false,$e->getMessage(),500);
}