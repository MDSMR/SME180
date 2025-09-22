<?php
header('Content-Type: text/plain');
echo "DOCUMENT_ROOT: " . $_SERVER['DOCUMENT_ROOT'] . PHP_EOL;
$path = $_SERVER['DOCUMENT_ROOT'] . '/views/admin/_nav.php';
echo "_nav path: $path" . PHP_EOL;
echo "file_exists? " . (file_exists($path) ? 'YES' : 'NO') . PHP_EOL;