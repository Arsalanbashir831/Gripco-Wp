<?php

/* Docker configuration. Runtime secrets are supplied through the Compose .env file. */
define('DB_NAME', getenv('WORDPRESS_DB_NAME') ?: 'wordpress');
define('DB_USER', getenv('WORDPRESS_DB_USER') ?: 'wordpress');
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD') ?: 'wordpress');
define('DB_HOST', getenv('WORDPRESS_DB_HOST') ?: 'db:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = getenv('WORDPRESS_TABLE_PREFIX') ?: 'wp_an4rpmm56b_';

define('WP_HOME', rtrim(getenv('WP_HOME') ?: 'https://gripcosaudia.com', '/'));
define('WP_SITEURL', rtrim(getenv('WP_SITEURL') ?: 'https://gripcosaudia.com', '/'));
define('WP_ENVIRONMENT_TYPE', getenv('WP_ENVIRONMENT_TYPE') ?: 'production');
define('WP_CACHE', true);
define('WP_MEMORY_LIMIT', '256M');
define('WP_MAX_MEMORY_LIMIT', '512M');

/* Caddy terminates TLS and forwards the original protocol to Apache. */
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strpos($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') !== false) {
    $_SERVER['HTTPS'] = 'on';
}

define('FORCE_SSL_ADMIN', true);
define('DISALLOW_FILE_EDIT', true);
define('WP_AUTO_UPDATE_CORE', 'minor');

define('AUTH_KEY',         getenv('WP_AUTH_KEY') ?: 'change-me');
define('SECURE_AUTH_KEY',  getenv('WP_SECURE_AUTH_KEY') ?: 'change-me');
define('LOGGED_IN_KEY',    getenv('WP_LOGGED_IN_KEY') ?: 'change-me');
define('NONCE_KEY',        getenv('WP_NONCE_KEY') ?: 'change-me');
define('AUTH_SALT',        getenv('WP_AUTH_SALT') ?: 'change-me');
define('SECURE_AUTH_SALT', getenv('WP_SECURE_AUTH_SALT') ?: 'change-me');
define('LOGGED_IN_SALT',   getenv('WP_LOGGED_IN_SALT') ?: 'change-me');
define('NONCE_SALT',       getenv('WP_NONCE_SALT') ?: 'change-me');

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
