<?php
/** Move Heat Treatment out of Metallurgical into its own mega-menu column. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$_SERVER['REQUEST_METHOD'] = 'CLI';
require '/var/www/html/wp-load.php';

global $wpdb;

$data = json_decode(get_post_meta(268, '_elementor_data', true), true);
if (!is_array($data)) {
    fwrite(STDERR, "Mega-menu Elementor data is invalid.\n");
    exit(1);
}

$columns = &$data[0]['elements'][0]['elements'][0]['elements'];
if (!is_array($columns) || count($columns) < 5) {
    fwrite(STDERR, "Expected mega-menu columns were not found.\n");
    exit(1);
}

$metallurgical_list = &$columns[0]['elements'][1]['settings']['icon_list'];
$pwht_entry = null;
foreach ($metallurgical_list as $entry) {
    if (($entry['text'] ?? '') === 'Post Weld Heat Treatment (PWHT)') {
        $pwht_entry = $entry;
    }
}

$metallurgical_list = array_values(array_filter(
    $metallurgical_list,
    static fn($entry) => !in_array(
        $entry['text'] ?? '',
        ['Heat Treatment', 'Post Weld Heat Treatment (PWHT)'],
        true
    )
));

if (!$pwht_entry) {
    fwrite(STDERR, "PWHT list entry was not found.\n");
    exit(1);
}

foreach ($columns as &$column) {
    $column['settings']['_column_size'] = 16.666;
}
unset($column);

// The previous final column now needs its right divider.
$columns[count($columns) - 1]['settings']['border_border'] = 'solid';

$heat_column = $columns[count($columns) - 1];
$heat_column['id'] = 'gripheat';
$heat_column['settings']['border_border'] = '';
$heat_column['settings']['ekit_all_conditions_list'][0]['_id'] = 'griphcol';

$heading = &$heat_column['elements'][0];
$heading['id'] = 'griphthd';
$heading['settings']['ekit_heading_title'] = 'Heat Treatment';
$heading['settings']['ekit_heading_link']['url'] = get_permalink(2386);
$heading['settings']['ekit_all_conditions_list'][0]['_id'] = 'griphh01';

$list = &$heat_column['elements'][1];
$list['id'] = 'griphtls';
$pwht_entry['_id'] = 'grippwht';
$pwht_entry['ekit_page_list_website_link']['url'] = get_permalink(2391);
$list['settings']['icon_list'] = [$pwht_entry];
$list['settings']['ekit_all_conditions_list'][0]['_id'] = 'griphl01';

$columns[] = $heat_column;

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

echo "Heat Treatment is now a separate mega-menu section with PWHT as its child.\n";
