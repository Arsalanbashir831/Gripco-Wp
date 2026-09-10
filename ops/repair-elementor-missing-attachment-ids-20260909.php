<?php
/** Let Elementor use valid stored image URLs when attachment rows are missing. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$_SERVER['REQUEST_METHOD'] = 'CLI';
require '/var/www/html/wp-load.php';

global $wpdb;

$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
    '_elementor_data'
));

$fixed_images = 0;
$fixed_pages = 0;

$repair = static function (&$value) use (&$repair, &$fixed_images) {
    if (!is_array($value)) {
        return;
    }

    if (
        array_key_exists('url', $value)
        && !empty($value['url'])
        && !empty($value['id'])
        && !get_post((int) $value['id'])
    ) {
        $value['id'] = 0;
        ++$fixed_images;
    }

    foreach ($value as &$item) {
        if (is_array($item)) {
            $repair($item);
        }
    }
    unset($item);
};

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
            ['post_id' => (int) $row->post_id, 'meta_key' => '_elementor_data'],
            ['%s'],
            ['%d', '%s']
        );
        ++$fixed_pages;
    }
}

if (class_exists('Elementor\\Plugin')) {
    Elementor\Plugin::$instance->files_manager->clear_cache();
}

echo "Repaired {$fixed_images} missing attachment references across {$fixed_pages} Elementor records.\n";
