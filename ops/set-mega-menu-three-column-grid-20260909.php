<?php
/** Arrange all ElementsKit service groups as a responsive three-column grid. */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER['REQUEST_METHOD'] = 'CLI';
require '/var/www/html/wp-load.php';

global $wpdb;

$data = json_decode(get_post_meta(268, '_elementor_data', true), true);
if (!is_array($data)) {
    fwrite(STDERR, "Mega-menu Elementor data is invalid.\n");
    exit(1);
}

$section = &$data[0]['elements'][0]['elements'][0];
$columns = &$section['elements'];
if (!is_array($columns)) {
    fwrite(STDERR, "Mega-menu columns were not found.\n");
    exit(1);
}

// Elementor's legacy section renderer wraps columns when their combined width
// exceeds 100%, yielding three columns per row at desktop widths.
foreach ($columns as $index => &$column) {
    $column['settings']['_column_size'] = 33.333;
    $column['settings']['_inline_size'] = 33.333;
    $column['settings']['_inline_size_tablet'] = 50;
    $column['settings']['_inline_size_mobile'] = 100;
    $column['settings']['padding'] = [
        'unit' => 'px', 'top' => '28', 'right' => '28',
        'bottom' => '32', 'left' => '28', 'isLinked' => false,
    ];
    $column['settings']['border_border'] = 'solid';
    $column['settings']['border_width'] = [
        'unit' => 'px', 'top' => '0', 'right' => '1',
        'bottom' => '1', 'left' => '0', 'isLinked' => false,
    ];
    $column['settings']['border_color'] = '#EDEDED';
}
unset($column);

$section['settings']['column_gap'] = 'no';

$wpdb->update(
    $wpdb->postmeta,
    ['meta_value' => wp_json_encode($data)],
    ['post_id' => 268, 'meta_key' => '_elementor_data'],
    ['%s'],
    ['%d', '%s']
);

clean_post_cache(268);
if (class_exists('Elementor\\Plugin')) {
    Elementor\Plugin::$instance->files_manager->clear_cache();
}

echo 'Mega menu arranged as three columns across ' . count($columns) . " service sections.\n";
