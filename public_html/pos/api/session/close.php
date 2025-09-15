<?php
declare(strict_types=1);

/**
 * Session - Close
 * Body: { session_id, [closing_amount=0] }
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

$in = read_input();
require_fields($in, ['session_id']);

$sessionId = (int)$in['session_id'];
$closing   = isset($in['closing_amount']) ? (float)$in['closing_amount'] : 0.0;

try {
    $pdo = db();
    $q = $pdo->prepare("UPDATE cash_sessions
                        SET closing_amount=:c, closed_at=NOW(), status='closed'
                        WHERE id=:id AND status='open'");
    $q->execute(['c'=>$closing,'id'=>$sessionId]);
    respond(true, ['updated'=>$q->rowCount()]);
} catch (Throwable $e) { respond(false,$e->getMessage(),500); }
