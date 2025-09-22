<?php
require_once __DIR__ . '/../config/api_guard.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');
$pdo = get_pdo();

try {
    // Categories (optional)
    $categories = [];
    try {
        $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $categories = [];
    }

    // Auto-detect products columns
    $cols = $pdo->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    $nameCol  = null; foreach (['name','title','product_name'] as $c) if (in_array($c,$cols,true)) { $nameCol=$c; break; }
    $priceCol = null; foreach (['price','sell_price','amount','unit_price'] as $c) if (in_array($c,$cols,true)) { $priceCol=$c; break; }
    if (!$nameCol)  { throw new RuntimeException("Products: missing name column (name/title/product_name)."); }
    if (!$priceCol) { throw new RuntimeException("Products: missing price column (price/sell_price/amount/unit_price)."); }

    $catCol    = in_array('category_id',$cols,true) ? 'category_id' : null;
    $activeCol = in_array('is_active',$cols,true)   ? 'is_active'   : null;

    $sql = "SELECT id, `$nameCol` AS name, `$priceCol` AS price";
    if ($catCol) $sql .= ", `$catCol` AS category_id";
    $sql .= " FROM products";
    $where = [];
    if ($activeCol) $where[] = "`$activeCol`=1";
    if ($where) $sql .= " WHERE ".implode(' AND ',$where);
    $sql .= " ORDER BY `$nameCol` ASC";

    $items = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true,'categories'=>$categories,'items'=>$items]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}