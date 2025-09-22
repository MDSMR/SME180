<?php
// Quick, idempotent schema hot-fix for menu pages (categories, items, attributes, printers)
ini_set('display_errors',1); ini_set('display_startup_errors',1); error_reporting(E_ALL);

require_once __DIR__ . '/../config/db.php';
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
header('Content-Type: text/plain');

function out($m){ echo $m."\n"; }
function col_exists(PDO $pdo, $table, $col){
  $s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
  $s->execute([$table,$col]); return (int)$s->fetchColumn() > 0;
}
function table_exists(PDO $pdo, $table){
  $s=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  $s->execute([$table]); return (int)$s->fetchColumn() > 0;
}
function add_col(PDO $pdo, $table, $def){ $pdo->exec("ALTER TABLE `$table` ADD $def"); }

try{
  out("== Menu hot-fix migration ==");

  // --- categories ---
  if (!table_exists($pdo,'categories')){
    $pdo->exec("CREATE TABLE categories (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      name_en VARCHAR(255) NULL,
      name_ar VARCHAR(255) NULL,
      parent_id INT NULL,
      sequence INT NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      archived_at DATETIME NULL,
      INDEX idx_cat_parent (parent_id),
      INDEX idx_cat_active (is_active),
      INDEX idx_cat_arch (archived_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    out("created: categories");
  } else {
    if(!col_exists($pdo,'categories','name_en')) add_col($pdo,'categories',"`name_en` VARCHAR(255) NULL");
    if(!col_exists($pdo,'categories','name_ar')) add_col($pdo,'categories',"`name_ar` VARCHAR(255) NULL");
    if(!col_exists($pdo,'categories','parent_id')) add_col($pdo,'categories',"`parent_id` INT NULL");
    if(!col_exists($pdo,'categories','sequence')) add_col($pdo,'categories',"`sequence` INT NOT NULL DEFAULT 0");
    if(!col_exists($pdo,'categories','is_active')) add_col($pdo,'categories',"`is_active` TINYINT(1) NOT NULL DEFAULT 1");
    if(!col_exists($pdo,'categories','archived_at')) add_col($pdo,'categories',"`archived_at` DATETIME NULL");
    out("checked: categories");
  }

  // --- products ---
  if (!table_exists($pdo,'products')){
    $pdo->exec("CREATE TABLE products (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      name_en VARCHAR(255) NULL,
      name_ar VARCHAR(255) NULL,
      price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      tax_rate DECIMAL(6,2) NULL,
      standard_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      is_open_price TINYINT(1) NOT NULL DEFAULT 0,
      weight DECIMAL(10,3) NULL,
      calories INT NULL,
      prep_time_minutes INT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      archived_at DATETIME NULL,
      INDEX idx_prod_active (is_active),
      INDEX idx_prod_arch (archived_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    out("created: products");
  } else {
    foreach([
      ['name_en',"VARCHAR(255) NULL"],
      ['name_ar',"VARCHAR(255) NULL"],
      ['price',"DECIMAL(10,2) NOT NULL DEFAULT 0.00"],
      ['tax_rate',"DECIMAL(6,2) NULL"],
      ['standard_cost',"DECIMAL(10,2) NOT NULL DEFAULT 0.00"],
      ['is_open_price',"TINYINT(1) NOT NULL DEFAULT 0"],
      ['weight',"DECIMAL(10,3) NULL"],
      ['calories',"INT NULL"],
      ['prep_time_minutes',"INT NULL"],
      ['is_active',"TINYINT(1) NOT NULL DEFAULT 1"],
      ['archived_at',"DATETIME NULL"],
    ] as $pair){
      if(!col_exists($pdo,'products',$pair[0])) add_col($pdo,'products',"`{$pair[0]}` {$pair[1]}");
    }
    out("checked: products");
  }

  // --- product_categories (pivot) ---
  if (!table_exists($pdo,'product_categories')){
    $pdo->exec("CREATE TABLE product_categories (
      product_id INT NOT NULL,
      category_id INT NOT NULL,
      PRIMARY KEY (product_id, category_id),
      INDEX idx_pc_cat (category_id),
      CONSTRAINT fk_pc_p FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
      CONSTRAINT fk_pc_c FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    out("created: product_categories");
  } else {
    out("checked: product_categories");
  }

  // --- printers + category_printers ---
  if (!table_exists($pdo,'printers')){
    $pdo->exec("CREATE TABLE printers (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      ip_address VARCHAR(100) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      archived_at DATETIME NULL,
      UNIQUE KEY uk_printer_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    out("created: printers");
  } else {
    if(!col_exists($pdo,'printers','is_active')) add_col($pdo,'printers',"`is_active` TINYINT(1) NOT NULL DEFAULT 1");
    if(!col_exists($pdo,'printers','archived_at')) add_col($pdo,'printers',"`archived_at` DATETIME NULL");
    out("checked: printers");
  }

  if (!table_exists($pdo,'category_printers')){
    $pdo->exec("CREATE TABLE category_printers (
      category_id INT NOT NULL,
      printer_id INT NOT NULL,
      PRIMARY KEY (category_id, printer_id),
      CONSTRAINT fk_cp_c FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
      CONSTRAINT fk_cp_p FOREIGN KEY (printer_id) REFERENCES printers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    out("created: category_printers");
  } else {
    out("checked: category_printers");
  }

  // --- attributes + values + product_attribute_values ---
  if (!table_exists($pdo,'attributes')){
    $pdo->exec("CREATE TABLE attributes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name_en VARCHAR(255) NULL,
      name_ar VARCHAR(255) NULL,
      is_required TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      archived_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    out("created: attributes");
  } else {
    foreach([
      ['name_en',"VARCHAR(255) NULL"],
      ['name_ar',"VARCHAR(255) NULL"],
      ['is_required',"TINYINT(1) NOT NULL DEFAULT 0"],
      ['is_active',"TINYINT(1) NOT NULL DEFAULT 1"],
      ['archived_at',"DATETIME NULL"],
    ] as $pair){
      if(!col_exists($pdo,'attributes',$pair[0])) add_col($pdo,'attributes',"`{$pair[0]}` {$pair[1]}");
    }
    out("checked: attributes");
  }

  if (!table_exists($pdo,'attribute_values')){
    $pdo->exec("CREATE TABLE attribute_values (
      id INT AUTO_INCREMENT PRIMARY KEY,
      attribute_id INT NOT NULL,
      value_en VARCHAR(255) NULL,
      value_ar VARCHAR(255) NULL,
      price_delta DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      sort_order INT NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      archived_at DATETIME NULL,
      INDEX idx_av_attr (attribute_id),
      CONSTRAINT fk_av_a FOREIGN KEY (attribute_id) REFERENCES attributes(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    out("created: attribute_values");
  } else {
    foreach([
      ['price_delta',"DECIMAL(10,2) NOT NULL DEFAULT 0.00"],
      ['sort_order',"INT NOT NULL DEFAULT 0"],
      ['is_active',"TINYINT(1) NOT NULL DEFAULT 1"],
      ['archived_at',"DATETIME NULL"],
    ] as $pair){
      if(!col_exists($pdo,'attribute_values',$pair[0])) add_col($pdo,'attribute_values',"`{$pair[0]}` {$pair[1]}");
    }
    out("checked: attribute_values");
  }

  if (!table_exists($pdo,'product_attribute_values')){
    $pdo->exec("CREATE TABLE product_attribute_values (
      product_id INT NOT NULL,
      attribute_value_id INT NOT NULL,
      PRIMARY KEY (product_id, attribute_value_id),
      INDEX idx_pav_val (attribute_value_id),
      CONSTRAINT fk_pav_p FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
      CONSTRAINT fk_pav_v FOREIGN KEY (attribute_value_id) REFERENCES attribute_values(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    out("created: product_attribute_values");
  } else {
    out("checked: product_attribute_values");
  }

  out("OK. You can delete this file afterwards.");
} catch(Throwable $e){
  http_response_code(500);
  out("ERROR: ".$e->getMessage());
}