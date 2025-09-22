<?php
// api/auth_logout.php - invalidate refresh token (logout)
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
$input = json_decode(file_get_contents('php://input'), true);
$refresh = $input['refresh_token'] ?? null;
if ($refresh) {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("DELETE FROM user_tokens WHERE token = :t");
    $stmt->execute([':t'=>$refresh]);
}
echo json_encode(['success'=>true]);
