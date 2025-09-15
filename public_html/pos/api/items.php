<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/pos_auth.php';
pos_auth_require_login();

declare(strict_types=1);
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../middleware/pos_auth.php';

try {
  $pdo = db();
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);

  // Auth / tenant
  $tenantId = 1;
  $u = pos_user();
  if ($u && isset($u['tenant_id'])) {
    $tenantId = (int)$u['tenant_id'];
  }

  // Inputs
  $q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
  $categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

  // Build WHERE + params
  $where = "p.tenant_id = :t_main AND p.is_active = 1 AND p.pos_visible = 1";
  $params = [
    ':t_main' => $tenantId,
    ':t_sub1' => $tenantId,
  ];

  if ($q !== '') {
    $where .= " AND (p.name_en LIKE :q OR p.name_ar LIKE :q)";
    $params[':q'] = "%{$q}%";
  }

  if ($categoryId > 0) {
    $where .= " AND EXISTS (
      SELECT 1
      FROM product_categories pc
      JOIN categories c ON c.id = pc.category_id AND c.tenant_id = :t_exists
      WHERE pc.product_id = p.id AND pc.category_id = :cid
    )";
    $params[':t_exists'] = $tenantId;
    $params[':cid'] = $categoryId;
  }

  $sql = "
    SELECT
      p.id,
      p.name_en,
      p.name_ar,
      p.price,
      p.image_path AS image_url,
      (
        SELECT pc.category_id
        FROM product_categories pc
        JOIN categories c ON c.id = pc.category_id AND c.tenant_id = :t_sub1
        WHERE pc.product_id = p.id
        ORDER BY c.sort_order, c.id
        LIMIT 1
      ) AS category_id
    FROM products p
    WHERE {$where}
    ORDER BY p.id DESC
    LIMIT 500
  ";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $items = $st->fetchAll(PDO::FETCH_ASSOC);

  json_out(['ok' => true, 'items' => $items]);

} catch (Throwable $e) {
  error_log('[items.php] ' . $e->getMessage());
  json_out(['ok' => false, 'error' => 'db_error'], 500);
}