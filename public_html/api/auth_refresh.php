<?php
// api/auth_refresh.php - exchange refresh token for new access token
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../lib/jwt.php';
require_once __DIR__ . '/../lib/logger.php';

$input = json_decode(file_get_contents('php://input'), true);
$refresh = $input['refresh_token'] ?? null;
if (!$refresh) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'refresh required']); exit; }

$pdo = get_pdo();
$stmt = $pdo->prepare("SELECT user_id, expires_at FROM user_tokens WHERE token = :t LIMIT 1");
$stmt->execute([':t'=>$refresh]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'invalid token']); exit; }
if (strtotime($row['expires_at']) < time()) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'expired']); exit; }

$user_id = intval($row['user_id']);
$stmtU = $pdo->prepare("SELECT id, role_id FROM users WHERE id = :id LIMIT 1");
$stmtU->execute([':id'=>$user_id]);
$u = $stmtU->fetch(PDO::FETCH_ASSOC);
if (!$u) { http_response_code(401); echo json_encode(['success'=>false,'error'=>'user missing']); exit; }

$access_exp = intval(env('JWT_EXPIRE',7200));
$payload = ['sub'=>$u['id'],'role'=>$u['role_id'],'iat'=>time()];
$token = jwt_encode($payload, $access_exp);
echo json_encode(['success'=>true,'access_token'=>$token,'expires_in'=>$access_exp]);
