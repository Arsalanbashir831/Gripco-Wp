<?php
/** Apply the explicitly audited production security/maintenance updates. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$_SERVER['REQUEST_METHOD'] = 'CLI';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/update.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';

wp_version_check();
$core_transient = get_site_transient('update_core');
$core_updates = (array) ($core_transient->updates ?? []);
$target = null;
foreach ($core_updates as $update) {
    if (($update->current ?? '') === '6.9.7') {
        $target = $update;
        break;
    }
}

if ($target && version_compare(get_bloginfo('version'), '6.9.7', '<')) {
    $result = (new Core_Upgrader(new Automatic_Upgrader_Skin()))->upgrade($target);
    if (is_wp_error($result) || !$result) {
        fwrite(STDERR, "WordPress core update failed.\n");
        exit(1);
    }
    echo "WordPress updated to 6.9.7.\n";
} else {
    echo "WordPress 6.9.7 is already installed or unavailable.\n";
}

wp_update_plugins();
$updates = get_site_transient('update_plugins');
$approved_plugins = [
    'forminator/forminator.php',
    'contact-form-7/wp-contact-form-7.php',
    'ga-google-analytics/ga-google-analytics.php',
    'wordpress-seo/wp-seo.php',
    'wp-google-maps/wpGoogleMaps.php',
    'auxin-elements/auxin-elements.php',
];

foreach ($approved_plugins as $plugin) {
    if (empty($updates->response[$plugin]->package)) {
        echo "No downloadable update for {$plugin}.\n";
        continue;
    }

    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    $result = $upgrader->upgrade($plugin);
    if (is_wp_error($result) || !$result) {
        fwrite(STDERR, "Plugin update failed: {$plugin}\n");
        continue;
    }

    echo "Updated {$plugin}.\n";
}
