<?php
require_once __DIR__ . '/../config/api_guard.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
$pdo = get_pdo();

$code = trim($_GET['code'] ?? '');
if ($code === '') { echo json_encode(['success'=>false,'error'=>'code required']); exit; }

try {
    $stmt = $pdo->prepare("SELECT id, code, customer_id, order_id, visit_number, bill_amount, cashback_amount, is_redeemed, redeemed_at, expires_at FROM cashback_coupons WHERE code=? LIMIT 1");
    $stmt->execute([$code]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) { echo json_encode(['success'=>false,'error'=>'not found']); exit; }
    if ((int)$c['is_redeemed'] === 1) { echo json_encode(['success'=>false,'error'=>'redeemed']); exit; }
    if (!empty($c['expires_at']) && strtotime($c['expires_at']) < time()) {
        echo json_encode(['success'=>false,'error'=>'expired']); exit;
    }

    echo json_encode(['success'=>true,'coupon'=>$c]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}