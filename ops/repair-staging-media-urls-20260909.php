<?php
/** Restore references left behind by the retired GoDaddy staging hostname. */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$_SERVER['REQUEST_METHOD'] = 'CLI';
require '/var/www/html/wp-load.php';

global $wpdb;

$old_hosts = [
    'http://akm.8fe.myftpupload.com',
    'https://akm.8fe.myftpupload.com',
];
$new_host = 'https://gripcosaudia.com';
$old_values = array_merge(
    $old_hosts,
    array_map(static fn($host) => str_replace('/', '\\/', $host), $old_hosts)
);
$new_values = [
    $new_host,
    $new_host,
    str_replace('/', '\\/', $new_host),
    str_replace('/', '\\/', $new_host),
];

$replace = static function ($value) use (&$replace, $old_values, $new_values) {
    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $value[$key] = $replace($item);
        }
        return $value;
    }

    if (is_object($value)) {
        foreach (get_object_vars($value) as $key => $item) {
            $value->{$key} = $replace($item);
        }
        return $value;
    }

    return is_string($value) ? str_replace($old_values, $new_values, $value) : $value;
};

$targets = [
    [$wpdb->posts, 'ID', ['post_content', 'post_excerpt']],
    [$wpdb->postmeta, 'meta_id', ['meta_value']],
    [$wpdb->options, 'option_id', ['option_value']],
];

$updated_rows = 0;
foreach ($targets as [$table, $primary_key, $columns]) {
    foreach ($columns as $column) {
        $where = "{$column} LIKE %s";
        $likes = ['%' . $wpdb->esc_like('akm.8fe.myftpupload.com') . '%'];
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT {$primary_key} AS row_id, {$column} AS stored_value FROM {$table} WHERE {$where}",
            ...$likes
        ));

        foreach ($rows as $row) {
            $decoded = maybe_unserialize($row->stored_value);
            $replaced = $replace($decoded);
            $stored = is_string($decoded) ? $replaced : maybe_serialize($replaced);

            if ($stored !== $row->stored_value) {
                $wpdb->update(
                    $table,
                    [$column => $stored],
                    [$primary_key => $row->row_id],
                    ['%s'],
                    ['%d']
                );
                ++$updated_rows;
            }
        }
    }
}

if (class_exists('Elementor\\Plugin')) {
    Elementor\Plugin::$instance->files_manager->clear_cache();
}

echo "Repaired {$updated_rows} database rows.\n";
