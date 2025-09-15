<?php
// File: /public_html/pos/api/stations/register.php
declare(strict_types=1);

/**
 * POS Stations - Register/Update Station
 * Registers a new station or updates existing one
 * 
 * Request: POST /pos/api/stations/register.php
 * Body: {
 *   "tenant_id": 1,
 *   "branch_id": 1,
 *   "station_code": "POS1",
 *   "station_name": "Front POS",
 *   "station_type": "pos",
 *   "ip_address": "192.168.1.100" (optional)
 * }
 * 
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "station_id": 1,
 *     "station_code": "POS1",
 *     "station_name": "Front POS",
 *     "is_new": false
 *   },
 *   "error": null
 * }
 */

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
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

// Validate station type
$validTypes = ['pos', 'bar', 'kitchen', 'host', 'kds', 'mobile'];
if (!in_array($stationType, $validTypes)) {
    respond(false, 'Invalid station type. Must be one of: ' . implode(', ', $validTypes), 400);
}

// Validate required fields
if (empty($stationCode)) {
    respond(false, 'Station code is required', 400);
}
if (empty($stationName)) {
    respond(false, 'Station name is required', 400);
}

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
        // Update existing station
        $updateStmt = $pdo->prepare(
            "UPDATE pos_stations 
             SET station_name = :station_name,
                 station_type = :station_type,
                 ip_address = :ip_address,
                 last_heartbeat = NOW(),
                 is_active = 1,
                 updated_at = NOW()
             WHERE id = :id"
        );
        
        $updateStmt->execute([
            'station_name' => $stationName,
            'station_type' => $stationType,
            'ip_address' => $ipAddress,
            'id' => $existing['id']
        ]);
        
        // Log station update
        $logStmt = $pdo->prepare(
            "INSERT INTO audit_logs (tenant_id, branch_id, user_id, action, entity_type, entity_id, details)
             VALUES (:tenant_id, :branch_id, NULL, 'station_updated', 'pos_station', :station_id, :details)"
        );
        $logStmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'station_id' => $existing['id'],
            'details' => json_encode([
                'station_code' => $stationCode,
                'old_name' => $existing['station_name'],
                'new_name' => $stationName,
                'ip_address' => $ipAddress
            ])
        ]);
        
        respond(true, [
            'station_id' => (int)$existing['id'],
            'station_code' => $stationCode,
            'station_name' => $stationName,
            'is_new' => false,
            'message' => 'Station updated successfully'
        ]);
        
    } else {
        // Create new station
        $insertStmt = $pdo->prepare(
            "INSERT INTO pos_stations 
             (tenant_id, branch_id, station_code, station_name, station_type, ip_address, last_heartbeat, is_active)
             VALUES (:tenant_id, :branch_id, :station_code, :station_name, :station_type, :ip_address, NOW(), 1)"
        );
        
        $insertStmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'station_code' => $stationCode,
            'station_name' => $stationName,
            'station_type' => $stationType,
            'ip_address' => $ipAddress
        ]);
        
        $stationId = (int)$pdo->lastInsertId();
        
        // Log station creation
        $logStmt = $pdo->prepare(
            "INSERT INTO audit_logs (tenant_id, branch_id, user_id, action, entity_type, entity_id, details)
             VALUES (:tenant_id, :branch_id, NULL, 'station_created', 'pos_station', :station_id, :details)"
        );
        $logStmt->execute([
            'tenant_id' => $tenantId,
            'branch_id' => $branchId,
            'station_id' => $stationId,
            'details' => json_encode([
                'station_code' => $stationCode,
                'station_name' => $stationName,
                'station_type' => $stationType,
                'ip_address' => $ipAddress
            ])
        ]);
        
        respond(true, [
            'station_id' => $stationId,
            'station_code' => $stationCode,
            'station_name' => $stationName,
            'is_new' => true,
            'message' => 'Station registered successfully'
        ]);
    }
    
} catch (PDOException $e) {
    // Handle duplicate key errors
    if ($e->getCode() == '23000') {
        respond(false, 'Station code already exists for this branch', 409);
    }
    respond(false, 'Database error: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    respond(false, 'Registration failed: ' . $e->getMessage(), 500);
}
