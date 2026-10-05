<?php
require __DIR__ . '/runtime.php';
$root = wp_upload_dir(null, false)['basedir']; $hashes = array();
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
    if ($file->isFile()) { $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
}
ksort($hashes);
$state = array($wpdb->get_results("SELECT * FROM $wpdb->posts ORDER BY ID", ARRAY_A), $wpdb->get_results("SELECT * FROM $wpdb->postmeta ORDER BY meta_id", ARRAY_A), $hashes, get_option('wp_seed_pixel_settings'), get_option(WP_Seed_Pixel_Batch::OPTION));
echo wp_json_encode(array('canonical_hash' => hash('sha256', serialize($state)), 'attachments' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $wpdb->posts WHERE post_type='attachment' AND post_status<>'trash'")));
