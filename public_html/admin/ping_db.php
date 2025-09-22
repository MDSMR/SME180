<?php
// /public_html/admin/ping_db.php
// Quick connectivity test. Delete this file after testing!
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/_auth_bootstrap.php'; // creates $pdo or exits with details

try {
    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
    $dbName  = $pdo->query("SELECT DATABASE()")->fetchColumn();
    echo "<h1>DB OK</h1>";
    echo "<p>Connected to: <strong>" . htmlspecialchars($dbName) . "</strong></p>";
    echo "<p>MySQL version: " . htmlspecialchars($version) . "</p>";

    // sanity checks on required tables
    $tables = ['users','categories','products','orders','order_items'];
    echo "<h2>Required Tables</h2><ul>";
    foreach ($tables as $t) {
        try {
            $pdo->query("SELECT 1 FROM `$t` LIMIT 1");
            echo "<li>$t — OK</li>";
        } catch (Throwable $e) {
            echo "<li style='color:#b00;'>$t — MISSING (" . htmlspecialchars($e->getMessage()) . ")</li>";
        }
    }
    echo "</ul>";
} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>DB FAIL</h1><pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
