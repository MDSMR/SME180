<?php
declare(strict_types=1);

/**
 * Session - Active
 * Body: { tenant_id, branch_id, [station_id] }
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

$in = read_input();
require_fields($in, ['tenant_id','branch_id']);

$tenantId = (int)$in['tenant_id'];
$branchId = (int)$in['branch_id'];
$stationId = isset($in['station_id']) ? (int)$in['station_id'] : null;

try {
    $pdo = db();
    if ($stationId) {
        $q = $pdo->prepare("SELECT id, opening_amount, opened_at
                            FROM cash_sessions
                            WHERE tenant_id=:t AND branch_id=:b AND station_id=:s AND status='open'
                            ORDER BY opened_at DESC LIMIT 1");
        $q->execute(['t'=>$tenantId,'b'=>$branchId,'s'=>$stationId]);
    } else {
        $q = $pdo->prepare("SELECT id, opening_amount, opened_at
                            FROM cash_sessions
                            WHERE tenant_id=:t AND branch_id=:b AND status='open'
                            ORDER BY opened_at DESC LIMIT 1");
        $q->execute(['t'=>$tenantId,'b'=>$branchId]);
    }
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) respond(false,'No active session',404);
    $row['id'] = (int)$row['id']; $row['opening_amount'] = (float)$row['opening_amount'];
    respond(true, $row);
} catch (Throwable $e) { respond(false,$e->getMessage(),500); }
