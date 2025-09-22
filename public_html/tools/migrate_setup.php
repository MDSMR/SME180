<?php
require_once __DIR__ . '/../config/db.php';
$pdo = get_pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
header('Content-Type: text/plain');

function out($m){ echo $m."\n"; }

try {
  out("== Setup migration ==");

  // Settings table (key-value)
  $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Aggregators (Talabat, etc.)
  $pdo->exec("CREATE TABLE IF NOT EXISTS aggregators (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(50) NOT NULL UNIQUE,
    display_name VARCHAR(100) NOT NULL,
    commission_percent DECIMAL(6,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    archived_at DATETIME NULL DEFAULT NULL
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  // Seed (idempotent)
  $stmt = $pdo->prepare("SELECT COUNT(*) FROM aggregators WHERE slug=?");
  foreach([
    ['talabat','Talabat',18.00],
    ['elmenus','Elmenus',15.00],
    ['jahez','Jahez',20.00],
  ] as $row){
    [$slug,$name,$pct] = $row;
    $stmt->execute([$slug]);
    if ((int)$stmt->fetchColumn()===0){
      $ins=$pdo->prepare("INSERT INTO aggregators (slug, display_name, commission_percent, is_active, archived_at) VALUES (?,?,?,?,NULL)");
      $ins->execute([$slug,$name,$pct,1]);
    }
  }

  // Default settings (tax/service)
  $exists = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE `key`=?");
  foreach([
    ['tax_percent','0'],
    ['service_charge_percent','0'],
  ] as $kv){
    $exists->execute([$kv[0]]);
    if ((int)$exists->fetchColumn()===0){
      $pdo->prepare("INSERT INTO settings(`key`,`value`) VALUES(?,?)")->execute($kv);
    }
  }

  out("OK. You can delete this file afterwards.");
} catch(Throwable $e){
  http_response_code(500);
  out("ERROR: ".$e->getMessage());
}