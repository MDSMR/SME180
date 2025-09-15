<?php
// controllers/admin/products/list.php — FINAL
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

header('Content-Type: application/json');

try {
    $user = auth_user(); // from middleware
    if (!$user) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>'Unauthorized']); exit; }

    $tenantId = (int)($user['tenant_id'] ?? 0);
    if ($tenantId <= 0) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Invalid tenant']); exit; }

    $pdo = db();
    $st = $pdo->prepare("
        SELECT id, name_en, name_ar, inventory_unit, standard_cost, is_inventory_tracked, is_active
        FROM products
        WHERE tenant_id = :tenant AND is_active = 1 AND is_inventory_tracked = 1
        ORDER BY name_en ASC
    ");
    $st->execute([':tenant' => $tenantId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    echo json_encode(['ok' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}