<?php
declare(strict_types=1);
require_once __DIR__ . '/_auth_bootstrap.php';
[$user, $payload] = admin_require_auth();
header('Location: /admin/dashboard.php');
exit;