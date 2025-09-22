<?php
require_once __DIR__ . '/../lib/request.php';
require_once __DIR__ . '/../lib/logger.php';
// api/kds_poll.php - KDS devices poll with API key header X-API-KEY
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';

$api_key = $_SERVER['HTTP_X_API_KEY'] ?? (req_get('api_key') ?? null);
if (!$api_key) {
    http_response_code(401);
    echo json_encode(['success'=>false,'error'=>'API key required']);
    exit;
}

try {
    $pdo = get_pdo();
    $stmt = $pdo->prepare("SELECT id FROM kds_devices WHERE device_key = :k AND enabled = 1 LIMIT 1");
    $stmt->execute([':k'=>$api_key]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$device) {
        http_response_code(403);
        echo json_encode(['success'=>false,'error'=>'invalid device key']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id, order_id, item_id, qty, status, created_at FROM kds_messages WHERE processed = 0 ORDER BY created_at ASC LIMIT 100");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success'=>true,'messages'=>$rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
