<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

echo "<p>✅ PHP is working</p>";

$scanPath = dirname(__DIR__, 2) . '/public_html/views/admin';
echo "<p>Scan path: $scanPath</p>";

try {
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanPath));
    echo "<p>✅ Directory scan initialized</p>";
} catch (Throwable $e) {
    echo "<p style='color:red;'>❌ Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}