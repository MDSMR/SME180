<?php
// api/users.php - list, get, create, update, toggle users
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/jwt.php';
require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/logger.php';

$method = $_SERVER['REQUEST_METHOD'];
$pdo = get_pdo();

// simple auth check (admin role id = 1)
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
function unauthorized(){ http_response_code(401); echo json_encode(['success'=>false,'error'=>'unauthorized']); exit; }
if (!$auth || stripos($auth,'Bearer ')!==0) unauthorized();
$token = trim(substr($auth,7));
$payload = jwt_decode($token);
if (!$payload) unauthorized();
$user_role = $payload['role'] ?? 0;
if ($user_role != 1 && $method != 'GET') { http_response_code(403); echo json_encode(['success'=>false,'error'=>'forbidden']); exit; }

if ($method === 'GET') {
    $id = isset($_GET['id']) ? intval($_GET['id']) : null;
    if ($id) {
        $stmt = $pdo->prepare("SELECT id, username, full_name, role_id, active FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id'=>$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'user'=>$user]);
    } else {
        $stmt = $pdo->query("SELECT id, username, full_name, role_id, active FROM users ORDER BY username ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'users'=>$rows]);
    }
    exit;
} elseif ($method === 'POST') {
    $data = req_json();
    if (!csrf_check($data['csrf_token'] ?? null)) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'csrf']); exit; }
    $username = substr(trim($data['username'] ?? ''),0,191);
    $full = substr(trim($data['full_name'] ?? ''),0,191);
    $password = $data['password'] ?? '';
    $role = intval($data['role_id'] ?? 2);
    if (!$username || !$password) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'username & password required']); exit; }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role_id, active, created_at) VALUES (:u,:p,:f,:r,1,NOW())");
    $stmt->execute([':u'=>$username, ':p'=>$hash, ':f'=>$full, ':r'=>$role]);
    echo json_encode(['success'=>true,'id'=>$pdo->lastInsertId()]); exit;
} elseif ($method === 'PUT') {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!csrf_check($data['csrf_token'] ?? null)) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'csrf']); exit; }
    $id = intval($data['id'] ?? 0);
    $enable = isset($data['enable']) ? intval($data['enable']) : null;
    if ($id && $enable !== null) {
        $stmt = $pdo->prepare("UPDATE users SET active = :a WHERE id = :id");
        $stmt->execute([':a'=>$enable, ':id'=>$id]);
        echo json_encode(['success'=>true]); exit;
    }
    echo json_encode(['success'=>false,'error'=>'invalid']); exit;
} else {
    http_response_code(405); echo json_encode(['success'=>false,'error'=>'method not allowed']); exit;
}
