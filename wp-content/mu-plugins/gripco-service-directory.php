<?php
/** Plugin Name: Gripco Service Directory
 * Description: Keeps the Our Businesses directory aligned with the Services menu.
 */
defined('ABSPATH') || exit;

add_shortcode('gripco_service_directory', static function () {
    $items = wp_get_nav_menu_items('header-menu') ?: [];
    $page = get_page_by_path('our-businesses');
    $root = 0;
    $children = [];
    foreach ($items as $item) {
        if ($page && $item->object === 'page' && (int) $item->object_id === (int) $page->ID) {
            $root = (int) $item->ID;
        }
        if ($item->type === 'post_type' && get_post_status($item->object_id) !== 'publish') {
            continue;
        }
        $children[(int) $item->menu_item_parent][] = $item;
    }
    if (!$root || empty($children[$root])) {
        return '';
    }
    $link = static function ($item) {
        return '<a href="' . esc_url($item->url) . '">' . esc_html(html_entity_decode($item->title, ENT_QUOTES | ENT_HTML5, 'UTF-8')) . '</a>';
    };
    $list = static function ($parent, $visited = []) use (&$list, $children, $link) {
        if (isset($visited[$parent]) || empty($children[$parent])) return '';
        $visited[$parent] = true;
        $html = '<ul>';
        foreach ($children[$parent] as $item) {
            $html .= '<li>' . $link($item) . $list((int) $item->ID, $visited) . '</li>';
        }
        return $html . '</ul>';
    };
    $html = '<div class="gripco-directory" aria-label="All services">';
    foreach ($children[$root] as $category) {
        $html .= '<section class="gripco-directory__card"><h3>' . $link($category) . '</h3>' . $list((int) $category->ID) . '</section>';
    }
    return $html . '</div>';
});

add_action('wp_enqueue_scripts', static function () {
    if (!is_page('our-businesses')) return;
    wp_enqueue_style('gripco-service-directory', plugin_dir_url(__FILE__) . 'gripco-service-directory.css', [], '1.0.0');
});
