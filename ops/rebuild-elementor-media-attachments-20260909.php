<?php
/** Recreate deleted media-library rows required by legacy Elementor widgets. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$_SERVER['REQUEST_METHOD'] = 'CLI';
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

global $wpdb;

$uploads = wp_get_upload_dir();
$base_url = rtrim($uploads['baseurl'], '/');
$base_dir = rtrim($uploads['basedir'], '/');
$attachment_cache = [];
$created = 0;
$connected = 0;

$resolve_attachment = static function ($url) use (
    &$attachment_cache,
    &$created,
    $base_url,
    $base_dir,
    $wpdb
) {
    $clean_url = strtok($url, '?');
    if (!str_starts_with($clean_url, $base_url . '/')) {
        return 0;
    }

    $relative = rawurldecode(substr($clean_url, strlen($base_url) + 1));
    if (!preg_match('/\.(?:avif|gif|jpe?g|png|webp)$/i', $relative)) {
        return 0;
    }

    $path = $base_dir . '/' . $relative;
    if (!is_file($path)) {
        return 0;
    }

    if (isset($attachment_cache[$relative])) {
        return $attachment_cache[$relative];
    }

    $existing = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
        $relative
    ));
    if ($existing) {
        return $attachment_cache[$relative] = $existing;
    }

    $type = wp_check_filetype(basename($path));
    $id = wp_insert_attachment([
        'post_mime_type' => $type['type'] ?: 'application/octet-stream',
        'post_title' => sanitize_text_field(pathinfo(basename($path), PATHINFO_FILENAME)),
        'post_status' => 'inherit',
        'guid' => $clean_url,
    ], $path);

    if (is_wp_error($id)) {
        return 0;
    }

    $metadata = wp_generate_attachment_metadata($id, $path);
    if (is_array($metadata)) {
        wp_update_attachment_metadata($id, $metadata);
    }
    ++$created;
    return $attachment_cache[$relative] = (int) $id;
};

$repair = static function (&$value) use (&$repair, $resolve_attachment, &$connected) {
    if (!is_array($value)) {
        return;
    }

    if (array_key_exists('url', $value) && !empty($value['url']) && empty($value['id'])) {
        $id = $resolve_attachment($value['url']);
        if ($id) {
            $value['id'] = $id;
            ++$connected;
        }
    }

    foreach ($value as &$item) {
        if (is_array($item)) {
            $repair($item);
        }
    }
    unset($item);
};

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
    '_elementor_data'
));

foreach ($rows as $row) {
    $data = json_decode($row->meta_value, true);
    if (!is_array($data)) {
        continue;
    }
    $before = wp_json_encode($data);
    $repair($data);
    $after = wp_json_encode($data);
    if ($after !== $before) {
        $wpdb->update(
            $wpdb->postmeta,
            ['meta_value' => $after],
            ['meta_id' => (int) $row->meta_id],
            ['%s'],
            ['%d']
        );
    }
}

if (class_exists('Elementor\\Plugin')) {
    Elementor\Plugin::$instance->files_manager->clear_cache();
}

echo "Created {$created} attachments and reconnected {$connected} Elementor image settings.\n";
