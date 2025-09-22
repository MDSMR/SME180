<?php
$current = 'variations';
require_once __DIR__ . '/../_header.php';
$target = __DIR__ . '/attributes.php';
if (is_file($target)) {
    require_once $target;
} else {
    http_response_code(500);
    echo "<main style='font-family:system-ui;margin:24px'><h1>Missing dependency</h1><p>Expected " . htmlspecialchars($target) . "</p></main>";
}
