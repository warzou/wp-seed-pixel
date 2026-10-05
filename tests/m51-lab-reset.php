<?php
require __DIR__ . '/runtime.php';
$root = getenv('PIXEL_M3_ROOT');
global $wpdb;
if ($root !== '/home/warzy/.cache/wp-seed-pixel-m3-environment' || $wpdb->get_var('SELECT DATABASE()') !== 'pixel_m3') {
    throw new RuntimeException('Owned disposable database required.');
}
foreach (get_posts(array('post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids')) as $id) {
    wp_delete_attachment($id, true);
}
$wpdb->query('DROP TABLE IF EXISTS ' . WP_Seed_Pixel_Job_Store::table('items'));
$wpdb->query('DROP TABLE IF EXISTS ' . WP_Seed_Pixel_Job_Store::table('jobs'));
delete_option('wp_seed_pixel_job_schema');
delete_option('wp_seed_pixel_scan_schema');
WP_Seed_Pixel_Scan::install();
if (get_option('wp_seed_pixel_job_schema')) {
    throw new RuntimeException('Lazy M2 schema must remain absent.');
}
echo "Owned M2 fixtures reset.\n";
