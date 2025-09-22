<?php
// No auth guard here to avoid redirects during maintenance
require_once __DIR__ . '/../config/db.php';
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
header('Content-Type: text/plain');

function out($msg){ echo $msg . "\n"; @ob_flush(); @flush(); }

function hasCol(PDO $pdo, $table, $col){
  // Avoid placeholders; quote safely
  $tableQ = str_replace('`','``',$table);
  $colQ   = $pdo->quote($col);
  $sql = "SHOW COLUMNS FROM `{$tableQ}` LIKE {$colQ}";
  try {
    $stmt = $pdo->query($sql);
    return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e){
    return false;
  }
}

function createIfNotExists(PDO $pdo, $sql){
  try { $pdo->exec($sql); }
  catch (Throwable $e) { /* ignore if exists / older engines */ }
}

try {
  out("== Migrating schema ==");

  // Baseline tables (safe if exist)
  out("Creating baseline categories/products if missing...");
  createIfNotExists($pdo, "CREATE TABLE IF NOT EXISTS categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL UNIQUE,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  createIfNotExists($pdo, "CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    category_id INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_products_category (category_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Categories extra columns
  out("Altering categories columns (if missing)...");
  if (!hasCol($pdo,'categories','name_en'))   $pdo->exec("ALTER TABLE categories ADD COLUMN name_en VARCHAR(150) NULL AFTER name");
  if (!hasCol($pdo,'categories','name_ar'))   $pdo->exec("ALTER TABLE categories ADD COLUMN name_ar VARCHAR(150) NULL AFTER name_en");
  if (!hasCol($pdo,'categories','parent_id')) $pdo->exec("ALTER TABLE categories ADD COLUMN parent_id INT NULL AFTER name_ar");
  if (!hasCol($pdo,'categories','sequence'))  $pdo->exec("ALTER TABLE categories ADD COLUMN sequence INT NOT NULL DEFAULT 0 AFTER parent_id");
  try { $pdo->exec("ALTER TABLE categories ADD CONSTRAINT fk_categories_parent FOREIGN KEY (parent_id) REFERENCES categories(id) ON UPDATE CASCADE ON DELETE SET NULL"); } catch (Throwable $e) {}

  // Products extra columns
  out("Altering products columns (if missing)...");
  if (!hasCol($pdo,'products','name_en'))           $pdo->exec("ALTER TABLE products ADD COLUMN name_en VARCHAR(200) NULL AFTER name");
  if (!hasCol($pdo,'products','name_ar'))           $pdo->exec("ALTER TABLE products ADD COLUMN name_ar VARCHAR(200) NULL AFTER name_en");
  if (!hasCol($pdo,'products','tax_rate'))          $pdo->exec("ALTER TABLE products ADD COLUMN tax_rate DECIMAL(5,2) NULL DEFAULT NULL AFTER price");
  if (!hasCol($pdo,'products','standard_cost'))     $pdo->exec("ALTER TABLE products ADD COLUMN standard_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER tax_rate");
  if (!hasCol($pdo,'products','is_open_price'))     $pdo->exec("ALTER TABLE products ADD COLUMN is_open_price TINYINT(1) NOT NULL DEFAULT 0 AFTER standard_cost");
  if (!hasCol($pdo,'products','weight'))            $pdo->exec("ALTER TABLE products ADD COLUMN weight DECIMAL(10,3) NULL DEFAULT NULL AFTER is_open_price");
  if (!hasCol($pdo,'products','calories'))          $pdo->exec("ALTER TABLE products ADD COLUMN calories INT NULL DEFAULT NULL AFTER weight");
  if (!hasCol($pdo,'products','prep_time_minutes')) $pdo->exec("ALTER TABLE products ADD COLUMN prep_time_minutes INT NULL DEFAULT NULL AFTER calories");

  // Printers + pivots
  out("Creating printers & pivots if missing...");
  createIfNotExists($pdo, "CREATE TABLE IF NOT EXISTS printers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    route VARCHAR(150) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_printer_name (name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  createIfNotExists($pdo, "CREATE TABLE IF NOT EXISTS category_printers (
    category_id INT NOT NULL,
    printer_id INT NOT NULL,
    PRIMARY KEY (category_id, printer_id),
    INDEX idx_cp_cat (category_id),
    INDEX idx_cp_prn (printer_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  createIfNotExists($pdo, "CREATE TABLE IF NOT EXISTS product_categories (
    product_id INT NOT NULL,
    category_id INT NOT NULL,
    PRIMARY KEY (product_id, category_id),
    INDEX idx_pc_p (product_id),
    INDEX idx_pc_c (category_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Attributes + values + assignments
  out("Creating attributes, attribute_values, product_attribute_values...");
  createIfNotExists($pdo, "CREATE TABLE IF NOT EXISTS attributes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name_en VARCHAR(120) NOT NULL,
    name_ar VARCHAR(120) NULL,
    is_required TINYINT(1) NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  createIfNotExists($pdo, "CREATE TABLE IF NOT EXISTS attribute_values (
    id INT AUTO_INCREMENT PRIMARY KEY,
    attribute_id INT NOT NULL,
    value_en VARCHAR(120) NOT NULL,
    value_ar VARCHAR(120) NULL,
    price_delta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    INDEX idx_av_attr (attribute_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  createIfNotExists($pdo, "CREATE TABLE IF NOT EXISTS product_attribute_values (
    product_id INT NOT NULL,
    attribute_value_id INT NOT NULL,
    PRIMARY KEY (product_id, attribute_value_id),
    INDEX idx_pav_p (product_id),
    INDEX idx_pav_v (attribute_value_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  out("OK. Schema upgraded.");
  out("You can delete this file afterwards.");
} catch (Throwable $e) {
  http_response_code(500);
  out("ERROR: ".$e->getMessage());
}