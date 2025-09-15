<?php
// /views/admin/rewards/stamp/controllers/program_delete.php
// AJAX endpoint to delete stamp programs
declare(strict_types=1);

require_once __DIR__ . '/../_shared/common.php';

// Set JSON header for responses
header('Content-Type: application/json; charset=utf-8');

if (!$bootstrap_ok) {
    echo json_encode(['ok' => false, 'error' => 'Bootstrap failed: ' . $bootstrap_warning]);
    exit;
}

/* Only allow POST requests */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!($pdo instanceof PDO)) {
    echo json_encode(['ok' => false, 'error' => 'Database connection failed']);
    exit;
}

/* Get JSON input for program ID */
$input = json_decode(file_get_contents('php://input'), true);
$programId = (int)($input['program_id'] ?? 0);

/* Validation */
if ($programId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid program ID']);
    exit;
}

try {
    /* Verify program exists and belongs to tenant */
    $stmt = $pdo->prepare("SELECT id, name, status FROM loyalty_programs 
                          WHERE tenant_id = ? AND id = ? AND type = 'stamp'");
    $stmt->execute([$tenantId, $programId]);
    $program = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$program) {
        echo json_encode(['ok' => false, 'error' => 'Program not found or access denied']);
        exit;
    }
    
    /* Check if program has active customer data */
    $stmt = $pdo->prepare("SELECT COUNT(*) as customer_count
                          FROM loyalty_ledgers
                          WHERE tenant_id = ? AND program_type = 'stamp' AND program_id = ?");
    $stmt->execute([$tenantId, $programId]);
    $customerCount = (int)($stmt->fetchColumn() ?: 0);
    
    /* Prevent deletion if program has customer data (safety measure) */
    if ($customerCount > 0) {
        echo json_encode([
            'ok' => false, 
            'error' => "Cannot delete program with existing customer data ({$customerCount} transactions). Consider marking as inactive instead.",
            'customer_count' => $customerCount,
            'suggestion' => 'Mark as inactive'
        ]);
        exit;
    }
    
    /* Begin transaction for safe deletion */
    $pdo->beginTransaction();
    
    try {
        /* Delete any associated tier conditions (if they exist) */
        $stmt = $pdo->prepare("DELETE FROM tier_conditions WHERE program_id = ?");
        $stmt->execute([$programId]);
        
        /* Delete loyalty ledger entries (should be none based on check above) */
        $stmt = $pdo->prepare("DELETE FROM loyalty_ledgers 
                              WHERE tenant_id = ? AND program_type = 'stamp' AND program_id = ?");
        $stmt->execute([$tenantId, $programId]);
        
        /* Delete loyalty program enrollments (if they exist) */
        $stmt = $pdo->prepare("DELETE FROM loyalty_program_enrollments 
                              WHERE tenant_id = ? AND program_id = ?");
        $stmt->execute([$tenantId, $programId]);
        
        /* Delete the program itself */
        $stmt = $pdo->prepare("DELETE FROM loyalty_programs 
                              WHERE tenant_id = ? AND id = ? AND type = 'stamp'");
        $stmt->execute([$tenantId, $programId]);
        
        /* Verify deletion */
        if ($stmt->rowCount() === 0) {
            throw new Exception('Program deletion failed - no rows affected');
        }
        
        $pdo->commit();
        
        /* Success response */
        echo json_encode([
            'ok' => true,
            'message' => 'Program deleted successfully',
            'program_id' => $programId,
            'program_name' => $program['name'],
            'deleted_at' => date('Y-m-d H:i:s'),
            'deleted_by' => $userId
        ]);
        
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Throwable $e) {
    error_log('Program deletion error: ' . $e->getMessage());
    echo json_encode([
        'ok' => false, 
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}