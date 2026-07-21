<?php

/* Docker/local configuration. Keep hosting-specific configuration out of this file. */
define('DB_NAME', getenv('WORDPRESS_DB_NAME') ?: 'wordpress');
define('DB_USER', getenv('WORDPRESS_DB_USER') ?: 'wordpress');
define('DB_PASSWORD', getenv('WORDPRESS_DB_PASSWORD') ?: 'wordpress');
define('DB_HOST', getenv('WORDPRESS_DB_HOST') ?: 'db:3306');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

$table_prefix = getenv('WORDPRESS_TABLE_PREFIX') ?: 'wp_an4rpmm56b_';

/* Override the production URLs stored in the imported GoDaddy database. */
$local_url = rtrim(getenv('WP_LOCAL_URL') ?: 'http://localhost:8000', '/');
define('WP_HOME', $local_url);
define('WP_SITEURL', $local_url);
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_CACHE', false);

define('AUTH_KEY',         'local-only-auth-key-change-before-production');
define('SECURE_AUTH_KEY',  'local-only-secure-auth-key-change-before-production');
define('LOGGED_IN_KEY',    'local-only-logged-in-key-change-before-production');
define('NONCE_KEY',        'local-only-nonce-key-change-before-production');
define('AUTH_SALT',        'local-only-auth-salt-change-before-production');
define('SECURE_AUTH_SALT', 'local-only-secure-auth-salt-change-before-production');
define('LOGGED_IN_SALT',   'local-only-logged-in-salt-change-before-production');
define('NONCE_SALT',       'local-only-nonce-salt-change-before-production');

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', false);
}

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php';
