<?php
// No auth guard to avoid redirects during maintenance
require_once __DIR__ . '/../config/db.php';
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
header('Content-Type: text/plain');

function out($m){ echo $m."\n"; @ob_flush(); @flush(); }
function hasCol(PDO $pdo, $table, $col){
  try {
    $colQ = $pdo->quote($col);
    $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE $colQ");
    return (bool)$stmt->fetch();
  } catch(Throwable $e){ return false; }
}
function ensureIndex(PDO $pdo, $table, $indexName, $columnsSql){
  try { $pdo->query("SHOW INDEX FROM `$table` WHERE Key_name = ".$pdo->quote($indexName))->fetch() ?: $pdo->exec("ALTER TABLE `$table` ADD INDEX `$indexName` ($columnsSql)"); }
  catch(Throwable $e){}
}

try {
  out("== Migrating archive flags & indexes ==");

  // Make sure base tables exist (created previously by your earlier migrations)
  $pdo->exec("CREATE TABLE IF NOT EXISTS categories (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(150) NOT NULL UNIQUE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS products (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(200) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS attributes (id INT AUTO_INCREMENT PRIMARY KEY, name_en VARCHAR(120) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS attribute_values (id INT AUTO_INCREMENT PRIMARY KEY, attribute_id INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Add archived_at (nullable) where missing
  foreach (['categories','products','attributes','attribute_values'] as $t){
    if (!hasCol($pdo, $t, 'archived_at')) {
      $pdo->exec("ALTER TABLE `$t` ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL AFTER `is_active`");
      out("Added $t.archived_at");
    }
    ensureIndex($pdo, $t, "idx_{$t}_archived", "`archived_at`");
  }

  // Common pivots (from your previous setup)
  $pdo->exec("CREATE TABLE IF NOT EXISTS product_categories (
    product_id INT NOT NULL, category_id INT NOT NULL,
    PRIMARY KEY(product_id, category_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS product_attribute_values (
    product_id INT NOT NULL, attribute_value_id INT NOT NULL,
    PRIMARY KEY (product_id, attribute_value_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Optional: order_items/products linkage check table (won’t error if missing)
  // We won’t create orders/order_items here; we only check them if they exist.

  out("OK. You can delete this file afterwards.");
} catch (Throwable $e) {
  http_response_code(500);
  out("ERROR: ".$e->getMessage());
}