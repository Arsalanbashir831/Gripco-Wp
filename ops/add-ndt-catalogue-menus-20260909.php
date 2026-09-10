<?php
/** Add the NDT catalogue hierarchy to WordPress and ElementsKit menus. */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$_SERVER['REQUEST_METHOD'] = 'CLI';
require '/var/www/html/wp-load.php';

global $wpdb;

$tree = [
    'Material Testing' => [
        'slug' => 'material-testing',
        'children' => [
            'PMI – XRF' => ['slug'=>'pmi-xrf'],
            'PMI – OES' => ['slug'=>'pmi-oes'],
            'Hardness Testing' => ['slug'=>'hardness-testing','children'=>[
                'Telebrinell Comparator Bar'=>['slug'=>'telebrinell-comparator-bar'],
                'Hydraulic Brinell'=>['slug'=>'hydraulic-brinell'],
                'UCI Hardness'=>['slug'=>'uci-hardness'],
            ]],
        ],
    ],
    'Surface NDT' => ['slug'=>'surface-ndt','children'=>[
        'Penetrant Testing'=>['slug'=>'penetrant-testing'],
        'Magnetic Particle Testing'=>['slug'=>'magnetic-particle-testing'],
    ]],
    'Ultrasonic Testing' => ['slug'=>'ultrasonic-testing','children'=>[
        'Ultrasonic Thickness Gauging'=>['slug'=>'ultrasonic-thickness-gauging'],
        'Conventional UT'=>['slug'=>'conventional-ut'],
        'Longitudinal Wave'=>['slug'=>'longitudinal-wave'],
        'Shear Wave'=>['slug'=>'shear-wave'],
    ]],
    'Advanced NDT' => ['slug'=>'advanced-ndt','children'=>[
        'PAUT'=>['slug'=>'paut'], 'TOFD'=>['slug'=>'tofd'], 'Corrosion Mapping'=>['slug'=>'corrosion-mapping'],
    ]],
    'Visual Inspection' => ['slug'=>'visual-inspection','children'=>[
        'Video Borescope Inspection'=>['slug'=>'video-borescope-inspection'],
        'Internal Pipe/Tube Inspection'=>['slug'=>'internal-pipe-tube-inspection','children'=>[
            'Full HD Long-Distance Camera Inspection'=>['slug'=>'full-hd-long-distance-camera-inspection'],
        ]],
    ]],
];

$find_page = static function (string $slug, int $parent_id = 0): WP_Post {
    $posts = get_posts(['name'=>$slug,'post_type'=>'page','post_status'=>'publish','post_parent'=>$parent_id,'numberposts'=>1]);
    if (!$posts) { throw new RuntimeException("Page not found: {$slug}"); }
    return $posts[0];
};

// Standard WordPress menu (used by mobile and as a navigation fallback).
$menu = wp_get_nav_menu_object('header-menu');
$service_item = 133;
$existing = [];
foreach (wp_get_nav_menu_items($menu->term_id) ?: [] as $item) {
    if ($item->type === 'post_type' && $item->object === 'page') $existing[(int)$item->object_id] = (int)$item->ID;
}
$add_branch = static function ($branches, int $page_parent, int $menu_parent) use (&$add_branch, &$existing, $find_page, $menu) {
    foreach ($branches as $title => $branch) {
        $page = $find_page($branch['slug'], $page_parent);
        if (isset($existing[$page->ID])) {
            $item_id = $existing[$page->ID];
            wp_update_nav_menu_item($menu->term_id, $item_id, ['menu-item-parent-id'=>$menu_parent,'menu-item-title'=>$title,'menu-item-object-id'=>$page->ID,'menu-item-object'=>'page','menu-item-type'=>'post_type','menu-item-status'=>'publish']);
        } else {
            $item_id = wp_update_nav_menu_item($menu->term_id, 0, ['menu-item-parent-id'=>$menu_parent,'menu-item-title'=>$title,'menu-item-object-id'=>$page->ID,'menu-item-object'=>'page','menu-item-type'=>'post_type','menu-item-status'=>'publish']);
            $existing[$page->ID] = (int)$item_id;
        }
        if (!empty($branch['children'])) $add_branch($branch['children'], $page->ID, (int)$item_id);
    }
};
$add_branch($tree, 0, $service_item);

// Desktop ElementsKit mega-menu template.
$data = json_decode(get_post_meta(268, '_elementor_data', true), true);
$columns = &$data[0]['elements'][0]['elements'][0]['elements'];
$new_titles = array_keys($tree);
$columns = array_values(array_filter($columns, static function ($column) use ($new_titles) {
    $title = $column['elements'][0]['settings']['ekit_heading_title'] ?? '';
    return !in_array($title, $new_titles, true);
}));
$prototype = $columns[count($columns)-1];
$sequence = 0;
foreach ($tree as $title => $branch) {
    $page = $find_page($branch['slug']);
    $column = $prototype;
    $token = substr(md5($branch['slug']), 0, 7);
    $column['id'] = 'c' . $token;
    $column['settings']['border_border'] = 'solid';
    $column['settings']['ekit_all_conditions_list'][0]['_id'] = 'x' . $token;
    $column['elements'][0]['id'] = 'h' . $token;
    $column['elements'][0]['settings']['ekit_heading_title'] = $title;
    $column['elements'][0]['settings']['ekit_heading_link']['url'] = get_permalink($page);
    $column['elements'][0]['settings']['ekit_all_conditions_list'][0]['_id'] = 'y' . $token;
    $column['elements'][1]['id'] = 'l' . $token;
    $list = [];
    $flatten = static function ($children, int $page_parent, int $depth=0) use (&$flatten, &$list, $find_page) {
        foreach ($children as $child_title => $child) {
            $child_page = $find_page($child['slug'], $page_parent);
            $list[] = [$depth, $child_title, get_permalink($child_page), $child_page->ID];
            if (!empty($child['children'])) $flatten($child['children'], $child_page->ID, $depth+1);
        }
    };
    $flatten($branch['children'] ?? [], $page->ID);
    $base = $prototype['elements'][1]['settings']['icon_list'][0];
    $entries = [];
    foreach ($list as [$depth,$child_title,$url,$page_id]) {
        $entry = $base;
        $entry['_id'] = substr(md5((string)$page_id),0,7);
        $entry['text'] = ($depth ? '— ' : '') . $child_title;
        $entry['ekit_page_list_website_link']['url'] = $url;
        $entries[] = $entry;
    }
    $column['elements'][1]['settings']['icon_list'] = $entries;
    $column['elements'][1]['settings']['ekit_all_conditions_list'][0]['_id'] = 'z' . $token;
    $columns[] = $column;
    ++$sequence;
}
foreach ($columns as &$column) {
    $column['settings']['_column_size'] = 25;
    $column['settings']['border_border'] = 'solid';
}
unset($column);
$columns[count($columns)-1]['settings']['border_border'] = '';

$wpdb->update($wpdb->postmeta, ['meta_value'=>wp_json_encode($data)], ['post_id'=>268,'meta_key'=>'_elementor_data'], ['%s'], ['%d','%s']);
clean_post_cache(268);
if (class_exists('Elementor\\Plugin')) Elementor\Plugin::$instance->files_manager->clear_cache();
echo "Added NDT hierarchy to the WordPress menu and {$sequence} mega-menu sections.\n";
