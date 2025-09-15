<?php
// /config/app.php
declare(strict_types=1);

// Application settings
define('APP_NAME', 'Smorll POS');
define('APP_VERSION', '1.0.0');
define('APP_ENV', $_ENV['APP_ENV'] ?? 'production');
define('APP_DEBUG', APP_ENV === 'development');
define('APP_URL', $_ENV['APP_URL'] ?? 'https://mohamedk10.sg-host.com');

// Multi-tenant settings
define('MULTI_TENANT', true);
define('REQUIRE_BRANCH', true);
define('DEFAULT_TENANT_ID', null); // Force explicit tenant selection

// Session settings
define('SESSION_LIFETIME', 14400); // 4 hours
define('SESSION_NAME_BACKEND', 'smorll_session');
define('SESSION_NAME_POS', 'smorll_pos_session');

// Security settings
define('CSRF_ENABLED', true);
define('RATE_LIMIT_ENABLED', true);