<?php
// api/tables.php - list tables and update table status
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') {
    try {
        $stmt = $pdo->query("SELECT id, table_number, seats, status FROM tables ORDER BY table_number ASC");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'tables'=>$rows], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
} elseif ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'Invalid JSON']); exit; }
    $id = intval($input['id'] ?? 0);
    $status = $input['status'] ?? null;
    if (!$id || !$status) { http_response_code(400); echo json_encode(['success'=>false,'error'=>'id and status required']); exit; }
    try {
        $stmt = $pdo->prepare("UPDATE tables SET status = :status WHERE id = :id");
        $stmt->execute([':status'=>$status, ':id'=>$id]);
        echo json_encode(['success'=>true]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
    }
} else {
    http_response_code(405); echo json_encode(['success'=>false,'error'=>'Method not allowed']);
}
