<?php
require __DIR__ . '/m3-runtime.php';
WP_Seed_Pixel_Future_Uploads::configure('process', 1073741824, array());
define('WP_IMPORTING', true);
global $wpdb;
$before = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('jobs'));
$checks = array();
for ($i = 0; $i < 10; $i++) {
    $source = dirname(__DIR__) . '/.runtime/fixtures/m3-small.jpeg';
    $u = wp_upload_bits('m51-import-' . bin2hex(random_bytes(8)) . '.jpeg', null, file_get_contents($source));
    apply_filters('wp_handle_upload', $u, 'upload');
    $id = wp_insert_attachment(array('post_title' => 'Synthetic import', 'post_mime_type' => 'image/jpeg'), $u['file']);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $u['file']));
    $checks['Import ' . $i . ' excluded and usable'] = !get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true)
        && !wp_next_scheduled('wp_seed_pixel_future_job', array((int) $id)) && is_file(get_attached_file($id));
}
$checks['Bulk import creates no jobs'] = $before === (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('jobs'));
WP_Seed_Pixel_Future_Uploads::configure('off');
if (in_array(false, $checks, true)) { throw new RuntimeException('Import protection failed'); }
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-m5.1');
file_put_contents(dirname(__DIR__) . '/reports/storage-m5.1/imports.json', wp_json_encode(array('checks' => $checks), JSON_PRETTY_PRINT));
echo count($checks) . " M5.1 import checks passed.\n";
