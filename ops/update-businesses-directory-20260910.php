<?php
/** Replace the incomplete services grid with the full menu-backed directory. */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER['REQUEST_METHOD'] = 'CLI';
require '/var/www/html/wp-load.php';
$page = get_page_by_path('our-businesses');
if (!$page) throw new RuntimeException('Our Businesses page missing.');
$raw = get_post_meta($page->ID, '_elementor_data', true);
$data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
$elements = &$data[0]['elements'][0]['elements'];
if (($elements[1]['id'] ?? '') !== '1055cc2' && ($elements[1]['id'] ?? '') !== 'gripdir') {
    throw new RuntimeException('Unexpected services grid; no changes made.');
}
if (!get_post_meta($page->ID, '_gripco_directory_original_elementor', true)) {
    add_post_meta($page->ID, '_gripco_directory_original_elementor', $raw, true);
}
$elements[1] = ['id'=>'gripdir','elType'=>'widget','widgetType'=>'shortcode','settings'=>['shortcode'=>'[gripco_service_directory]'],'elements'=>[]];
$data[0]['settings']['padding']['bottom'] = '80';
$data[0]['settings']['padding_tablet']['left'] = '20';
$data[0]['settings']['padding_tablet']['right'] = '20';
update_post_meta($page->ID, '_elementor_data', wp_slash(wp_json_encode($data)));
delete_post_meta($page->ID, '_elementor_element_cache');
clean_post_cache($page->ID);
if (class_exists('Elementor\\Plugin')) Elementor\Plugin::$instance->files_manager->clear_cache();
$html = do_shortcode('[gripco_service_directory]');
echo 'Directory updated: ' . substr_count($html, '<section ') . ' categories, ' . substr_count($html, '<a ') . " linked pages.\n";
