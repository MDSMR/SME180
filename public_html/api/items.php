<?php
/**
 * API: POS Items (by branch)
 * Path: public_html/api/items.php
 * Auth: POS session (middleware/pos_auth.php)
 *
 * Response:
 * {
 *   ok: true,
 *   branch_id: 1,
 *   categories: [{id,name_en,name_ar}],
 *   products: [{
 *     id,name_en,name_ar,price_base,price_eff,pos_visible,is_active,
 *     categories: ["Salads","Sides"], has_options: true|false
 *   }],
 *   groups_by_product: { "12":[{id,name,is_required,min_select,max_select}] },
 *   values_by_group:   { "5":[{id,value_en,price_delta}] }
 * }
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$DEBUG = isset($_GET['debug']) && $_GET['debug'] == '1';

// includes
$cfg = __DIR__ . '/../config/db.php';
$mw  = __DIR__ . '/../middleware/pos_auth.php';
if (!is_file($cfg) || !is_file($mw)) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>'Missing config or middleware']); exit;
}
require_once $cfg;
require_once $mw;

if (!function_exists('db')) {
  if (isset($pdo) && $pdo instanceof PDO) {
    function db(): PDO { global $pdo; return $pdo; }
  } else {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Database helper not available (db() or $pdo)']); exit;
  }
}

try {
  pos_session_start();
  $posUser = pos_user();
  if (!$posUser) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'error'=>'Unauthorized (POS login required)']); exit;
  }

  $tenantId   = (int)$posUser['tenant_id'];
  $branchId   = (int)($_GET['branch_id'] ?? 0);
  $q          = trim((string)($_GET['q'] ?? ''));
  $categoryId = (int)($_GET['category_id'] ?? 0);
  $onlyVis    = isset($_GET['only_visible']) ? (int)$_GET['only_visible'] : 1;

  if ($branchId <= 0) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>'branch_id is required']); exit;
  }

  // Validate branch is this tenant’s
  $chk = db()->prepare("SELECT id FROM branches WHERE id=:b AND tenant_id=:t LIMIT 1");
  $chk->execute([':b'=>$branchId, ':t'=>$tenantId]);
  if (!$chk->fetch()) {
    http_response_code(403);
    echo json_encode(['ok'=>false,'error'=>'Invalid branch for tenant']); exit;
  }

  // Categories
  $catsStmt = db()->prepare("
    SELECT id, name_en, name_ar
    FROM categories
    WHERE tenant_id = :t AND is_active = 1
    ORDER BY sort_order, name_en
  ");
  $catsStmt->execute([':t'=>$tenantId]);
  $categories = $catsStmt->fetchAll(PDO::FETCH_ASSOC);

  // Products with branch availability & effective price
  $where = ["p.tenant_id = :t"];
  $params = [':t'=>$tenantId, ':b'=>$branchId];

  if ($onlyVis) { $where[] = "p.pos_visible = 1 AND p.is_active = 1"; }
  if ($q !== '') { $where[] = "(p.name_en LIKE :q OR p.name_ar LIKE :q)"; $params[':q']="%$q%"; }
  if ($categoryId > 0) {
    $where[] = "EXISTS (SELECT 1 FROM product_categories pc2 WHERE pc2.product_id=p.id AND pc2.category_id=:cid)";
    $params[':cid'] = $categoryId;
  }

  $sql = "
    SELECT
      p.id, p.name_en, p.name_ar, p.price AS price_base, p.pos_visible, p.is_active,
      COALESCE(pba.price_override, p.price) AS price_eff,
      COALESCE(pba.is_available, 1) AS is_available
    FROM products p
    LEFT JOIN product_branch_availability pba
      ON pba.product_id = p.id AND pba.branch_id = :b
    WHERE " . implode(' AND ', $where) . "
      AND (pba.is_available = 1 OR pba.is_available IS NULL)
    ORDER BY p.name_en
  ";
  $pst = db()->prepare($sql);
  $pst->execute($params);
  $prodRows = $pst->fetchAll(PDO::FETCH_ASSOC);

  $productIds = array_map(fn($r)=>(int)$r['id'], $prodRows);
  $productIds = array_values(array_unique($productIds));

  // Product→category names
  $catsByProduct = [];
  if ($productIds) {
    $place = implode(',', array_fill(0, count($productIds), '?'));
    $pcStmt = db()->prepare("
      SELECT pc.product_id, c.name_en
      FROM product_categories pc
      JOIN categories c ON c.id = pc.category_id
      WHERE pc.product_id IN ($place)
      ORDER BY c.name_en
    ");
    $pcStmt->execute($productIds);
    foreach ($pcStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $catsByProduct[(int)$r['product_id']][] = $r['name_en'];
    }
  }

  // Variation groups & values
  $groupsByProduct = [];
  $valuesByGroup   = [];

  if ($productIds) {
    $place = implode(',', array_fill(0, count($productIds), '?'));
    $pvgStmt = db()->prepare("
      SELECT pvg.product_id, vg.id, vg.name, vg.is_required, vg.min_select, vg.max_select
      FROM product_variation_groups pvg
      JOIN variation_groups vg ON vg.id = pvg.group_id
      WHERE pvg.product_id IN ($place)
      ORDER BY pvg.sort_order, vg.name
    ");
    $pvgStmt->execute($productIds);
    $groups = $pvgStmt->fetchAll(PDO::FETCH_ASSOC);

    $groupIds = [];
    foreach ($groups as $g) {
      $pid = (int)$g['product_id'];
      $groupsByProduct[$pid][] = [
        'id'         => (int)$g['id'],
        'name'       => (string)$g['name'],
        'is_required'=> (bool)$g['is_required'],
        'min_select' => (int)$g['min_select'],
        'max_select' => (int)$g['max_select'],
      ];
      $groupIds[(int)$g['id']] = true;
    }

    if ($groupIds) {
      $gids = array_keys($groupIds);
      $place2 = implode(',', array_fill(0, count($gids), '?'));
      $vvStmt = db()->prepare("
        SELECT group_id, id, value_en, price_delta
        FROM variation_values
        WHERE is_active = 1 AND group_id IN ($place2)
        ORDER BY sort_order, value_en
      ");
      $vvStmt->execute($gids);
      foreach ($vvStmt->fetchAll(PDO::FETCH_ASSOC) as $v) {
        $valuesByGroup[(int)$v['group_id']][] = [
          'id'          => (int)$v['id'],
          'value_en'    => (string)$v['value_en'],
          'price_delta' => (float)$v['price_delta'],
        ];
      }
    }
  }

  // Shape products for UI
  $products = [];
  foreach ($prodRows as $r) {
    $pid = (int)$r['id'];
    $products[] = [
      'id'          => $pid,
      'name_en'     => (string)$r['name_en'],
      'name_ar'     => $r['name_ar'],
      'price_base'  => (float)$r['price_base'],
      'price_eff'   => (float)$r['price_eff'],
      'pos_visible' => (bool)$r['pos_visible'],
      'is_active'   => (bool)$r['is_active'],
      'categories'  => $catsByProduct[$pid] ?? [],
      'has_options' => !empty($groupsByProduct[$pid]),
    ];
  }

  echo json_encode([
    'ok' => true,
    'branch_id' => $branchId,
    'categories' => $categories,
    'products' => $products,
    'groups_by_product' => $groupsByProduct,
    'values_by_group' => $valuesByGroup
  ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
  http_response_code(500);
  $out = ['ok'=>false,'error'=>'Server error'];
  if ($DEBUG) { $out['message']=$e->getMessage(); $out['trace']=$e->getTraceAsString(); }
  echo json_encode($out);
}