<?php
// /api/superadmin/users/save_user.php
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
    
    // If no JSON input, try regular POST data (form submission)
    if (empty($input)) {
        $input = $_POST;
    }
    
    // Extract data
    $user_id = isset($input['user_id']) ? (int)$input['user_id'] : null;
    
    $data = [
        'name' => trim($input['name'] ?? ''),
        'username' => trim($input['username'] ?? ''),
        'email' => trim($input['email'] ?? ''),
        'user_type' => $input['user_type'] ?? 'pos',
        'role_key' => $input['role_key'] ?? '',
        'password' => $input['password'] ?? '',
        'pin' => $input['pin'] ?? '',
        'tenants' => $input['tenants'] ?? [],
        'primary_tenant' => $input['primary_tenant'] ?? '',
        'branches' => $input['branches'] ?? []
    ];
    
    // Ensure arrays are arrays (in case of single values)
    if (!is_array($data['tenants'])) {
        $data['tenants'] = [$data['tenants']];
    }
    if (!is_array($data['branches'])) {
        $data['branches'] = empty($data['branches']) ? [] : [$data['branches']];
    }
    
    // Filter out empty values from arrays
    $data['tenants'] = array_filter($data['tenants']);
    $data['branches'] = array_filter($data['branches']);
    
    // Initialize controller
    $controller = new UserController();
    
    // Create or update user
    if ($user_id) {
        // Update existing user
        $result = $controller->updateUser($user_id, $data);
    } else {
        // Create new user
        $result = $controller->createUser($data);
    }
    
    // Return response
    if ($result['success']) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'message' => $user_id ? 'User updated successfully' : 'User created successfully',
            'user_id' => $result['user_id'] ?? $user_id
        ]);
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Operation failed',
            'errors' => $result['errors'] ?? []
        ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error: ' . $e->getMessage()
    ]);
}