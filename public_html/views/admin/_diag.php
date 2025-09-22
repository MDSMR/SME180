<?php
// show errors just for this diag
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h3>Path checks</h3>";
echo "__DIR__ = " . __DIR__ . "<br>";
$cfg = __DIR__ . '/../../config/db.php';
echo "db.php path = $cfg<br>";
echo "file_exists(db.php)? " . (file_exists($cfg) ? 'YES' : 'NO') . "<br>";

echo "<h3>Require & DB connect</h3>";
require_once $cfg;
if (!function_exists('get_pdo')) { die("get_pdo() NOT FOUND in config/db.php"); }
$pdo = get_pdo();
echo "PDO connected OK<br>";

// quick schema check
$stmt = $pdo->query("SELECT u.id, u.username, r.role_name FROM users u LEFT JOIN roles r ON r.id = u.role_id LIMIT 5");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "<pre>"; var_export($rows); echo "</pre>";
echo "All good.";