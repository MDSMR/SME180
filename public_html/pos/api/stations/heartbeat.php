<?php
// File: /public_html/pos/api/stations/heartbeat.php
declare(strict_types=1);

/**
 * POS Stations - Heartbeat
 * Updates station last seen timestamp and returns station status
 * 
 * Request: POST /pos/api/stations/heartbeat.php
 * Body: {
 *   "tenant_id": 1,
 *   "branch_id": 1,
 *   "station_code": "POS1",
 *   "status_data": {
 *     "cpu_usage": 45,
 *     "memory_usage": 60,
 *     "disk_usage": 30,
 *     "printer_status": "online",
 *     "network_status": "connected"
 *   } (optional)
 * }
 * 
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "station_id": 1,
 *     "station_active": true,
 *     "last_heartbeat": "2025-09-15 10:30:45",
 *     "active_session": {
 *       "session_id": 5,
 *       "user_name": "John Doe",
 *       "opened_at": "2025-09-15 08:00:00"
 *     },
 *     "pending_approvals": 2,
 *     "server_time": "2025-09-15 10:30:45"
 *   },
 *   "error": null
 * }
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

$in = read_input();
require_fields($in, ['tenant_id', 'branch_id', 'station_code']);

$tenantId = (int)$in['tenant_id'];
$branchId = (int)$in['branch_id'];
$stationCode = trim($in['station_code']);
$statusData = $in['status_data'] ?? [];
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

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
    
    // Update heartbeat
    $updateStmt = $pdo->prepare(
        "UPDATE pos_stations 
         SET last_heartbeat = NOW(),
             ip_address = :ip_address,
             status_data = :status_data,
             is_active = 1
         WHERE id = :id"
    );
    
    $updateStmt->execute([
        'ip_address' => $ipAddress,
        'status_data' => !empty($statusData) ? json_encode($statusData) : null,
        'id' => $stationId
    ]);
    
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
    
    // Check for pending approvals for this station
    $approvalsStmt = $pdo->prepare(
        "SELECT COUNT(*) as pending_count
         FROM pos_approvals
         WHERE branch_id = :branch_id
           AND status = 'pending'
           AND (expires_at IS NULL OR expires_at > NOW())"
    );
    $approvalsStmt->execute(['branch_id' => $branchId]);
    $pendingApprovals = (int)$approvalsStmt->fetchColumn();
    
    // Check if station was inactive for too long (> 5 minutes)
    $wasInactive = false;
    if ($station['seconds_since_last'] !== null && $station['seconds_since_last'] > 300) {
        $wasInactive = true;
        
        // Log station came back online
        $logStmt = $pdo->prepare(
            "INSERT INTO audit_logs (tenant_id, branch_id, user_id, action, entity_type, entity_id, details)
             VALUES (:tenant_id, :branch_id, NULL, 'station_reconnected', 'pos_station', :station_id, :details)"
        );
        $logStmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'station_id' => $stationId,
            'details' => json_encode([
                'station_code' => $stationCode,
                'offline_duration_seconds' => $station['seconds_since_last'],
                'ip_address' => $ipAddress
            ])
        ]);
    }
    
    // Build response
    $response = [
        'station_id' => $stationId,
        'station_name' => $station['station_name'],
        'station_type' => $station['station_type'],
        'station_active' => true,
        'last_heartbeat' => date('Y-m-d H:i:s'),
        'was_inactive' => $wasInactive,
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
    
    // Get any system messages for this station
    $messageStmt = $pdo->prepare(
        "SELECT message, priority, created_at
         FROM system_messages
         WHERE tenant_id = :tenant_id
           AND (branch_id = :branch_id OR branch_id IS NULL)
           AND is_active = 1
           AND (expires_at IS NULL OR expires_at > NOW())
         ORDER BY priority DESC, created_at DESC
         LIMIT 1"
    );
    $messageStmt->execute([
        'tenant_id' => $tenantId,
        'branch_id' => $branchId
    ]);
    $systemMessage = $messageStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($systemMessage) {
        $response['system_message'] = $systemMessage['message'];
        $response['message_priority'] = $systemMessage['priority'];
    }
    
    respond(true, $response);
    
} catch (Throwable $e) {
    respond(false, 'Heartbeat failed: ' . $e->getMessage(), 500);
}
