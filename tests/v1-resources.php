<?php
require __DIR__ . '/m4-runtime.php';
$checks = array(); global $wpdb;
list($id, $job, $view) = m4_replaced();
$original = WP_Seed_Pixel_Master_Storage::load(WP_Seed_Pixel_Master_Storage::directory(m4_item($job)), m4_item($job))['before']['sha256'];
$table = WP_Seed_Pixel_Job_Store::table('jobs'); $minimum = (int) $wpdb->get_var("SELECT MAX(id) FROM $table");
try {
    $sql = $wpdb->prepare("( 'replace','completed',%d,%d )", 1, time());
    for ($i = 0; $i < 100; $i++) { if ($wpdb->query("INSERT INTO $table (kind,status,actor,created) VALUES " . implode(',', array_fill(0, 100, $sql))) !== 100) { throw new RuntimeException('Resource fixture failed'); } }
    $result = WP_Seed_Pixel_Job_Store::insert_job('replace', 0, WP_Seed_Pixel_Policy::normalize(), 1);
    m4_check(is_wp_error($result) && $result->get_error_code() === 'HISTORY_FULL', 'History limit stops new real work without deleting receipts');
    $result = WP_Seed_Pixel_Jobs::quarantine_action($job, 'restore');
    m4_check(!is_wp_error($result) && hash_file('sha256', get_attached_file($id)) === $original, 'History full preserves exact restoration');
} finally { $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE id>%d AND kind='replace' AND policy IS NULL", $minimum)); }
m4_check(is_array(WP_Seed_Pixel_Job_Store::job($job)), 'Owned history survives bounded resource fixture cleanup');
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-v1');
file_put_contents(dirname(__DIR__) . '/reports/storage-v1/resources.json', wp_json_encode(array('checks' => $checks), JSON_PRETTY_PRINT));
echo count($checks) . " V1 resource checks PASS\n";
