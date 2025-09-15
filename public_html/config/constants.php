<?php
/**
 * /config/constants.php
 * System-wide constants for SaaS POS System
 */
declare(strict_types=1);

// Session Configuration
define('SESSION_NAME', 'smorll_backend');
define('SESSION_TIMEOUT', 3600); // 1 hour
define('POS_SESSION_TIMEOUT', 43200); // 12 hours for POS
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minutes

// System Settings
define('SYSTEM_VERSION', '1.0.0');
define('DEFAULT_TIMEZONE', 'Africa/Cairo');
define('DEFAULT_LANGUAGE', 'en');
define('SUPPORTED_LANGUAGES', ['en', 'ar']);

// Security Settings
define('CSRF_TOKEN_NAME', 'csrf_token');
define('API_RATE_LIMIT', 100); // requests per minute
define('PASSWORD_MIN_LENGTH', 8);

// Database Settings
define('DB_QUERY_TIMEOUT', 30); // seconds
define('DB_CONNECTION_RETRIES', 3);

// File Upload Settings
define('MAX_UPLOAD_SIZE', 10485760); // 10MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif', 'webp']);
define('UPLOAD_PATH', '/uploads/');

// POS Specific Settings
define('ORDER_NUMBER_PREFIX', 'ORD');
define('RECEIPT_NUMBER_PREFIX', 'RCP');
define('DEFAULT_GUEST_COUNT', 1);

// Cache Settings
define('CACHE_ENABLED', true);
define('CACHE_TTL', 300); // 5 minutes

// Development/Production Mode
define('DEBUG_MODE', false);
define('LOG_ERRORS', true);
define('ERROR_LOG_PATH', '/logs/');

// Business Rules
define('TAX_CALCULATION_METHOD', 'exclusive'); // or 'inclusive'
define('ALLOW_NEGATIVE_STOCK', false);
define('REQUIRE_TABLE_FOR_DINE_IN', true);
define('AUTO_LOGOUT_IDLE_TIME', 1800); // 30 minutes

// API Settings
define('API_VERSION', 'v1');
define('API_BASE_URL', '/api/');
define('API_TIMEOUT', 30);

// Pagination
define('DEFAULT_PAGE_SIZE', 25);
define('MAX_PAGE_SIZE', 100);
?>