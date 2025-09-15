<?php
// File: /public_html/pos/api/stations/register_safe.php
// Safe version - works without entity_type column
declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

$in = read_input();
require_fields($in, ['tenant_id', 'branch_id', 'station_code', 'station_name']);

$tenantId = (int)$in['tenant_id'];
$branchId = (int)$in['branch_id'];
$stationCode = trim($in['station_code']);
$stationName = trim($in['station_name']);
$stationType = $in['station_type'] ?? 'pos';

try {
    $pdo = db();
    
    // Check if station exists
    $stmt = $pdo->prepare(
        "SELECT id, station_name, is_active 
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
    
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing
        $updateStmt = $pdo->prepare(
            "UPDATE pos_stations 
             SET station_name = :station_name,
                 station_type = :station_type,
                 last_heartbeat = NOW(),
                 is_active = 1
             WHERE id = :id"
        );
        
        $updateStmt->execute([
            'station_name' => $stationName,
            'station_type' => $stationType,
            'id' => $existing['id']
        ]);
        
        respond(true, [
            'station_id' => (int)$existing['id'],
            'station_code' => $stationCode,
            'station_name' => $stationName,
            'is_new' => false
        ]);
        
    } else {
        // Create new
        $insertStmt = $pdo->prepare(
            "INSERT INTO pos_stations 
             (tenant_id, branch_id, station_code, station_name, station_type, last_heartbeat, is_active)
             VALUES (:tenant_id, :branch_id, :station_code, :station_name, :station_type, NOW(), 1)"
        );
        
        $insertStmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'station_code' => $stationCode,
            'station_name' => $stationName,
            'station_type' => $stationType
        ]);
        
        $stationId = (int)$pdo->lastInsertId();
        
        respond(true, [
            'station_id' => $stationId,
            'station_code' => $stationCode,
            'station_name' => $stationName,
            'is_new' => true
        ]);
    }
    
} catch (Throwable $e) {
    respond(false, 'Registration failed: ' . $e->getMessage(), 500);
}
