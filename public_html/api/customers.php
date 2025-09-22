<?php
require_once __DIR__ . '/../config/api_guard.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
$pdo = get_pdo();

$phone = trim($_GET['phone'] ?? '');
if ($phone === '') {
    echo json_encode(['success'=>false,'error'=>'phone required']); exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, name, phone FROM customers WHERE phone=? LIMIT 1");
    $stmt->execute([$phone]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$c) {
        $ins = $pdo->prepare("INSERT INTO customers (name, phone) VALUES (?, ?)");
        $ins->execute([null, $phone]);
        $c = ['id'=>(int)$pdo->lastInsertId(),'name'=>null,'phone'=>$phone];
    }

    echo json_encode(['success'=>true,'customer'=>$c]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}