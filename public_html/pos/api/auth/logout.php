<?php
declare(strict_types=1);

/**
 * POS Auth - Logout
 */

require_once __DIR__ . '/../_common.php';

if (session_status() === PHP_SESSION_NONE) @session_start();
$_SESSION = []; session_destroy();
respond(true, ['message'=>'Logged out']);
