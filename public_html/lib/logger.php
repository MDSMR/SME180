<?php
// lib/logger.php - simple file logger
function log_error($msg) {
    $path = __DIR__ . '/../storage/logs/error.log';
    $ts = date('Y-m-d H:i:s');
    error_log("[$ts] " . $msg . PHP_EOL, 3, $path);
}

function log_info($msg) {
    $path = __DIR__ . '/../storage/logs/info.log';
    $ts = date('Y-m-d H:i:s');
    error_log("[$ts] " . $msg . PHP_EOL, 3, $path);
}
