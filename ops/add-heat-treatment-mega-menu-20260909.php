<?php
/** Add Heat Treatment services to the hard-coded ElementsKit mega menu. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$_SERVER['REQUEST_METHOD'] = 'CLI';
require '/var/www/html/wp-load.php';

global $wpdb;

$pwht = get_post(2391);
if ($pwht && $pwht->post_name === 'elementor-page-2391') {
    wp_update_post([
        'ID' => 2391,
        'post_name' => 'post-weld-heat-treatment-pwht',
    ]);
}

$items = [
    [
        'text' => 'Heat Treatment',
        'url' => get_permalink(2386),
        '_id' => 'gripht01',
    ],
    [
        'text' => 'Post Weld Heat Treatment (PWHT)',
        'url' => get_permalink(2391),
        '_id' => 'grippwht',
    ],
];

$data = json_decode(get_post_meta(268, '_elementor_data', true), true);
if (!is_array($data)) {
    fwrite(STDERR, "Mega-menu Elementor data is invalid.\n");
    exit(1);
}

$added = 0;
$update = static function (&$nodes) use (&$update, $items, &$added) {
    foreach ($nodes as &$node) {
        if (($node['id'] ?? '') === '20d644dc') {
            $list = &$node['settings']['icon_list'];
            if (empty($list) || !is_array($list)) {
                continue;
            }

            $existing = array_column($list, 'text');
            foreach ($items as $item) {
                if (in_array($item['text'], $existing, true)) {
                    continue;
                }
                $entry = $list[0];
                $entry['text'] = $item['text'];
                $entry['_id'] = $item['_id'];
                $entry['ekit_page_list_website_link']['url'] = $item['url'];
                $entry['ekit_page_list_website_link']['is_external'] = '';
                $entry['ekit_page_list_website_link']['nofollow'] = '';
                $list[] = $entry;
                ++$added;
            }
        }

        if (!empty($node['elements']) && is_array($node['elements'])) {
            $update($node['elements']);
        }
    }
    unset($node);
};

$update($data);
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

echo "Added {$added} mega-menu links.\n";
echo "PWHT URL: " . get_permalink(2391) . "\n";
