<?php
// /api/superadmin/users/toggle_status.php
declare(strict_types=1);
require_once dirname(__DIR__, 3) . '/config/db.php';
require_once dirname(__DIR__, 3) . '/controllers/superadmin/users/user_controller.php';

// Set JSON response header
header('Content-Type: application/json');

// Check if user is super admin
use_backend_session();
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'super_admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Validate input
    $user_id = isset($input['user_id']) ? (int)$input['user_id'] : 0;
    $action = $input['action'] ?? '';
    
    if (!$user_id) {
        throw new Exception('User ID is required');
    }
    
    if (!in_array($action, ['enable', 'disable'])) {
        throw new Exception('Invalid action. Must be "enable" or "disable"');
    }
    
    // Check if trying to disable themselves
    if ($action === 'disable') {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT u.username 
            FROM users u 
            WHERE u.id = :user_id
        ");
        $stmt->execute(['user_id' => $user_id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            throw new Exception('User not found');
        }
        
        // Optional: Prevent disabling certain system users
        if ($user['username'] === 'admin') {
            throw new Exception('Cannot disable the main admin user');
        }
    }
    
    // Initialize controller
    $controller = new UserController();
    
    // Toggle status
    $result = $controller->toggleUserStatus($user_id, $action);
    
    // Return response
    if ($result['success']) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $action === 'enable' ? 'User enabled successfully' : 'User disabled successfully'
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Operation failed'
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}