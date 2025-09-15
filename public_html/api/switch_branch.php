<?php
// /api/switch_branch.php - Branch Switching API
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/db.php';
require_once dirname(__DIR__) . '/includes/AuditLogger.php';

header('Content-Type: application/json');

// Start session
use_backend_session();

// Check if user is logged in
if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_branch_id = isset($_POST['branch_id']) ? (int)$_POST['branch_id'] : 0;
    $tenant_id = get_tenant_id();
    $old_branch_id = get_branch_id();
    
    if ($new_branch_id <= 0) {
        $response['message'] = 'Invalid branch ID';
        echo json_encode($response);
        exit;
    }
    
    if ($new_branch_id === $old_branch_id) {
        $response['message'] = 'Already on selected branch';
        $response['success'] = true; // Not an error, just no action needed
        echo json_encode($response);
        exit;
    }
    
    try {
        $pdo = db();
        
        // Verify branch belongs to current tenant and is active
        $sql = "SELECT id, branch_name 
                FROM branches 
                WHERE id = :branch_id 
                AND tenant_id = :tenant_id 
                AND status = 'active'";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':branch_id' => $new_branch_id,
            ':tenant_id' => $tenant_id
        ]);
        
        $branch = $stmt->fetch();
        
        if ($branch) {
            // Log the branch switch
            AuditLogger::logBranchSwitch($old_branch_id, $new_branch_id);
            
            // Update session
            $_SESSION['branch_id'] = $new_branch_id;
            $_SESSION['branch_name'] = $branch['branch_name'];
            
            $response['success'] = true;
            $response['message'] = 'Branch switched successfully';
            $response['branch_name'] = $branch['branch_name'];
            $response['branch_id'] = $new_branch_id;
        } else {
            $response['message'] = 'Invalid branch selection or branch is not active';
        }
        
    } catch (Exception $e) {
        error_log("Branch switch error: " . $e->getMessage());
        $response['message'] = 'An error occurred while switching branches';
    }
} else {
    http_response_code(405);
    $response['message'] = 'Method not allowed. Use POST.';
}

echo json_encode($response);
?>