<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
function respond(bool $ok, $data = null, ?string $error = null, int $code = 200): void {
    http_response_code($code);
    echo json_encode(['ok'=>$ok,'data'=>$data,'error'=>$error], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once dirname(__DIR__, 3) . '/config/db.php';
    require_once dirname(__DIR__, 3) . '/middleware/auth_login.php';
    
    auth_require_login();
    $pdo = db();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $user = $_SESSION['user'] ?? null;
    if (!$user) respond(false, null, 'Unauthorized', 401);
    $tenantId = (int)($user['tenant_id'] ?? 0);
    
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // Add zone CRUD operations here
    // This will handle zones as sections in your current database
    
} catch (Throwable $e) {
    respond(false, null, 'Error: ' . $e->getMessage(), 500);
}