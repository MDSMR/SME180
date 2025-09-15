<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../middleware/pos_auth.php';
pos_auth_require_login();

declare(strict_types=1);
require_once __DIR__ . '/../../middleware/pos_auth.php';
require_once __DIR__ . '/../../config/db.php';

$user = pos_require_user();

json_out([
    'ok' => true,
    'user' => $user,
    'permissions' => pos_permissions(),
]);