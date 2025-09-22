<?php
// File: /public_html/pos/api/stations/heartbeat.php
// FIXED VERSION - Removes system_messages dependency
declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

$in = read_input();
require_fields($in, ['tenant_id', 'branch_id', 'station_code']);

$tenantId = (int)$in['tenant_id'];
$branchId = (int)$in['branch_id'];
$stationCode = trim($in['station_code']);
$statusData = $in['status_data'] ?? [];

try {
    $pdo = db();
    
    // Find the station
    $stmt = $pdo->prepare(
        "SELECT id, station_name, station_type, is_active, 
                last_heartbeat,
                TIMESTAMPDIFF(SECOND, last_heartbeat, NOW()) as seconds_since_last
         FROM pos_stations 
         WHERE tenant_id = :tenant_id 
           AND branch_id = :branch_id 
           AND station_code = :station_code
         LIMIT 1"
    );
    $stmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId,
        'station_code' => $stationCode
    ]);
    
    $station = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$station) {
        respond(false, 'Station not found. Please register first.', 404);
    }
    
    $stationId = (int)$station['id'];
    
    // Update heartbeat - simplified version
    $updateStmt = $pdo->prepare(
        "UPDATE pos_stations 
         SET last_heartbeat = NOW(),
             is_active = 1
         WHERE id = :id"
    );
    
    $updateStmt->execute(['id' => $stationId]);
    
    // If status_data column exists, update it
    if (!empty($statusData)) {
        try {
            $statusStmt = $pdo->prepare(
                "UPDATE pos_stations 
                 SET status_data = :status_data 
                 WHERE id = :id"
            );
            $statusStmt->execute([
                'status_data' => json_encode($statusData),
                'id' => $stationId
            ]);
        } catch (Exception $e) {
            // Ignore if column doesn't exist
        }
    }
    
    // Check for active cash session on this station
    $sessionStmt = $pdo->prepare(
        "SELECT cs.id as session_id, 
                cs.opened_at,
                cs.opening_amount,
                u.name as user_name,
                u.username
         FROM cash_sessions cs
         LEFT JOIN users u ON cs.user_id = u.id
         WHERE cs.station_id = :station_id 
           AND cs.status = 'open'
         ORDER BY cs.opened_at DESC
         LIMIT 1"
    );
    $sessionStmt->execute(['station_id' => $stationId]);
    $activeSession = $sessionStmt->fetch(PDO::FETCH_ASSOC);
    
    // Check for pending approvals
    $pendingApprovals = 0;
    try {
        $approvalsStmt = $pdo->prepare(
            "SELECT COUNT(*) as pending_count
             FROM pos_approvals
             WHERE branch_id = :branch_id
               AND status = 'pending'
               AND (expires_at IS NULL OR expires_at > NOW())"
        );
        $approvalsStmt->execute(['branch_id' => $branchId]);
        $pendingApprovals = (int)$approvalsStmt->fetchColumn();
    } catch (Exception $e) {
        // Table might not exist
    }
    
    // Build response
    $response = [
        'station_id' => $stationId,
        'station_name' => $station['station_name'],
        'station_type' => $station['station_type'],
        'station_active' => true,
        'last_heartbeat' => date('Y-m-d H:i:s'),
        'pending_approvals' => $pendingApprovals,
        'server_time' => date('Y-m-d H:i:s')
    ];
    
    if ($activeSession) {
        $response['active_session'] = [
            'session_id' => (int)$activeSession['session_id'],
            'user_name' => $activeSession['user_name'] ?? $activeSession['username'],
            'opened_at' => $activeSession['opened_at'],
            'opening_amount' => (float)$activeSession['opening_amount']
        ];
    }
    
    respond(true, $response);
    
} catch (Throwable $e) {
    respond(false, 'Heartbeat failed: ' . $e->getMessage(), 500);
}