<?php
declare(strict_types=1);

/**
 * Simple echo endpoint to verify request body parsing on SiteGround.
 * Reads JSON → POST → GET (via _common).
 */
require_once __DIR__ . '/_common.php';

$in = read_input();
respond(true, [
    'method'       => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
    'content_type' => $_SERVER['CONTENT_TYPE'] ?? '',
    'received'     => $in,
    'server_time'  => date('c'),
]);
