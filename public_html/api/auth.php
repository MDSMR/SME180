<?php
// api/auth.php - login -> returns JWT and refresh token
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/jwt.php';
require_once __DIR__ . '/../lib/logger.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';

    if (!$username || !$password) {
        http_response_code(400);
        echo json_encode(['success'=>false,'error'=>'username and password required']);
        exit;
    }

    try {
        $pdo = get_pdo();
        $stmt = $pdo->prepare("SELECT id, username, password_hash, role_id FROM users WHERE username = :u LIMIT 1");
        $stmt->execute([':u'=>$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success'=>false,'error'=>'invalid credentials']);
            exit;
        }

        // create JWT
        $access_exp = intval(env('JWT_EXPIRE', 7200));
        $refresh_exp = intval(env('JWT_REFRESH_EXPIRE', 604800));
        $payload = ['sub'=>$user['id'],'role'=>$user['role_id'],'iat'=>time()];
        $token = jwt_encode($payload, $access_exp);

        // create refresh token and store in DB
        $refresh_token = bin2hex(random_bytes(32));
        $stmtIns = $pdo->prepare("INSERT INTO user_tokens (user_id, token, expires_at, created_at) VALUES (:uid, :tok, DATE_ADD(NOW(), INTERVAL :secs SECOND), NOW())");
        $stmtIns->execute([':uid'=>$user['id'], ':tok'=>$refresh_token, ':secs'=>$refresh_exp]);

        echo json_encode(['success'=>true,'access_token'=>$token,'expires_in'=>$access_exp,'refresh_token'=>$refresh_token]);
        exit;
    } catch (Exception $e) {
        log_error("Auth error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>'server error']);
        exit;
    }
} else {
    http_response_code(405);
    echo json_encode(['success'=>false,'error'=>'method not allowed']);
}
