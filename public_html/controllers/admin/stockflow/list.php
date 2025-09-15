<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/auth_login.php';
auth_require_login();

// /controllers/admin/products/list.php

use_backend_session();

$user = $_SESSION['user'] ?? null;
if (!$user) { http_response_code(401); exit('Unauthorized'); }

$tenantId = (int)($user['tenant_id'] ?? 0);
header('Content-Type: application/json');

try {
    $pdo = db();
    $st = $pdo->prepare("
        SELECT id, name_en, inventory_unit, standard_cost, is_inventory_tracked
        FROM products 
        WHERE tenant_id = :tenant AND is_active = 1 AND is_inventory_tracked = 1
        ORDER BY name_en
    ");
    $st->execute([':tenant' => $tenantId]);
    echo json_encode(['ok' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
?>