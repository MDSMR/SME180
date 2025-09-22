<?php
header('Content-Type: application/json; charset=utf-8');
try {
    require_once __DIR__ . '/../../config.php';
    $pdo = get_pdo();
    $stmt = $pdo->query("SELECT id, slug, display_name, commission_percent, fixed_fee, enabled FROM aggregators WHERE enabled = 1 ORDER BY display_name ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['success' => true, 'aggregators' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
