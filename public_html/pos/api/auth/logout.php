<?php
// File: /public_html/pos/api/auth/logout.php
declare(strict_types=1);

/**
 * POS Auth - Logout
 * Destroys the current POS session
 * 
 * Request: POST /pos/api/auth/logout.php
 * Body: {} (optional)
 * 
 * Response:
 * {
 *   "success": true,
 *   "data": {
 *     "message": "Logged out successfully"
 *   },
 *   "error": null
 * }
 */

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../middleware/tenant_context.php';
require_once __DIR__ . '/../_common.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

try {
    // Get current session info before destroying
    $userId = $_SESSION['pos_user_id'] ?? null;
    $sessionId = session_id();
    $stationCode = $_SESSION['station_code'] ?? null;
    
    // Clear all POS session variables
    $posKeys = [
        'pos_user_id',
        'pos_user_name', 
        'pos_user_role',
        'pos_station_id',
        'station_code',
        'tenant_id',
        'branch_id',
        'cash_session_id',
        'pos_logged_in'
    ];
    
    foreach ($posKeys as $key) {
        unset($_SESSION[$key]);
    }
    
    // If there's an active cash session, we should close it
    if (isset($_SESSION['cash_session_id'])) {
        $pdo = db();
        $stmt = $pdo->prepare(
            "UPDATE cash_sessions 
             SET status = 'pending_close', 
                 pending_close_at = NOW() 
             WHERE id = :id AND status = 'open'"
        );
        $stmt->execute(['id' => $_SESSION['cash_session_id']]);
        unset($_SESSION['cash_session_id']);
    }
    
    // Destroy the session completely if no other data exists
    if (empty($_SESSION)) {
        session_destroy();
    }
    
    respond(true, [
        'message' => 'Logged out successfully',
        'session_cleared' => true
    ]);
    
} catch (Throwable $e) {
    respond(false, 'Logout failed: ' . $e->getMessage(), 500);
}
