<?php
/** One-time production cleanup for the 2026-09-09 content compromise. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
require_once ABSPATH . 'wp-admin/includes/post.php';

$spam_post_ids = [
    2029, 2069, 2080, 2081, 2090, 2091, 2092, 2093, 2094, 2096, 2097,
    2101, 2102, 2274, 2276, 2277, 2278, 2279, 2280, 2281, 2282, 2284,
    2285, 2286, 2287, 2288, 2289, 2290, 2291, 2292, 2293, 2294, 2296,
    2297, 2298, 2299, 2300, 2301, 2328,
];

$deleted = 0;
foreach ($spam_post_ids as $post_id) {
    if (get_post($post_id) && wp_delete_post($post_id, true)) {
        $deleted++;
    }
}

$cleaned = 0;
$posts = get_posts([
    'post_type'      => ['post', 'page'],
    'post_status'    => 'any',
    'posts_per_page' => -1,
]);

foreach ($posts as $post) {
    $content = preg_replace(
        '~\s*For students\b.*?<a\b[^>]+href=["\'][^"\']*(?:arbeit|schreib|ghostwrit)[^"\']*["\'][^>]*>.*?</a>[^.]*\.~is',
        '',
        $post->post_content
    );

    if ($content !== null && $content !== $post->post_content) {
        wp_update_post([
            'ID'           => $post->ID,
            'post_content' => $content,
        ]);
        $cleaned++;
    }
}

// Elementor stores rendered text separately from post_content. Clean both the
// live documents and revisions without disturbing the surrounding JSON.
global $wpdb;
$elementor_rows = $wpdb->get_results(
    "SELECT meta_id, post_id, meta_value FROM {$wpdb->postmeta}
     WHERE meta_key = '_elementor_data'
       AND meta_value REGEXP 'arbeit|schreib|ghostwrit'"
);

$clean_elementor_value = static function (&$value) use (&$clean_elementor_value): void {
    if (is_array($value)) {
        foreach ($value as &$child) {
            $clean_elementor_value($child);
        }
        unset($child);
        return;
    }

    if (is_string($value)) {
        $value = (string) preg_replace(
            '~\s*For students\b.*?<a\b[^>]+href=["\'][^"\']*(?:arbeit|schreib|ghostwrit)[^"\']*["\'][^>]*>.*?</a>[^.]*\.~is',
            '',
            $value
        );
    }
};

foreach ($elementor_rows as $row) {
    $data = json_decode($row->meta_value, true);
    if (!is_array($data)) {
        continue;
    }

    $clean_elementor_value($data);
    update_metadata_by_mid('post', (int) $row->meta_id, wp_json_encode($data));
    $cleaned++;
}

deactivate_plugins([
    'easy-tools-plus/easy-tools-plus.php',
    'wp-security-tool/wp-security-tool.php',
    'wp-file-manager/file_folder_manager.php',
]);

$removed_users = 0;
foreach (['backupadmin', 'wp-backup'] as $login) {
    $user = get_user_by('login', $login);
    if ($user && wp_delete_user($user->ID)) {
        $removed_users++;
    }
}

foreach (get_users(['fields' => 'ID']) as $user_id) {
    WP_Session_Tokens::get_instance($user_id)->destroy_all();
}

delete_expired_transients(true);
if (function_exists('rocket_clean_domain')) {
    rocket_clean_domain();
}
if (did_action('elementor/loaded')) {
    Elementor\Plugin::$instance->files_manager->clear_cache();
}
flush_rewrite_rules(false);

printf(
    "Deleted spam posts: %d\nCleaned injected links: %d\nRemoved unauthorized users: %d\n",
    $deleted,
    $cleaned,
    $removed_users
);
