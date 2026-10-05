<?php
require __DIR__ . '/m4-runtime.php';
$checks = array();
list($id, $job, $view) = m4_replaced(); $item = m4_item($job); $dir = WP_Seed_Pixel_Master_Storage::directory($item); $master = get_attached_file($id);
// This fixture's declared account scope is exactly one active file and its private lifecycle.
$read = static function () use ($master, $dir) {
    clearstatcache(); $bytes = filesize($master);
    foreach (new DirectoryIterator($dir) as $file) { if ($file->isFile() && !$file->isLink()) { $bytes += $file->getSize(); } }
    return array('complete' => true, 'live' => true, 'includes_recovery' => true, 'bytes' => $bytes, 'uncertainty_bytes' => 0, 'measured_at' => time());
};
add_filter('wp_seed_pixel_storage_usage', $read);
$before = $read()['bytes']; $account_before = $before; $source = filesize($dir . '/recovery.jpg'); $sha = hash_file('sha256', $master);
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array('operational_ceiling_bytes' => $before + 10));
m4_check(WP_Seed_Pixel_Storage_Budget::status(11)['state'] === 'BLOCKED', 'Real same-disk recovery consumes admission');
$attempt = WP_Seed_Pixel_Jobs::quarantine_action($job, 'purge', m4_approval($view));
m4_check(is_wp_error($attempt) && file_exists($dir . '/recovery.jpg'), 'No delete-first to finance purge journal peak');
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array('operational_ceiling_bytes' => $before + 1048576));
$purged = WP_Seed_Pixel_Jobs::quarantine_action($job, 'purge', m4_approval($view));
m4_check(!is_wp_error($purged) && !file_exists($dir . '/recovery.jpg'), 'Explicit verified purge physically removes recovery');
$after = $read()['bytes'];
m4_check($after < $before && $purged['removed_bytes'] === $source, 'Live account reading falls only after actual deletion');
m4_check(hash_file('sha256', $master) === $sha, 'Canonical file exact through blocked and successful purge');
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array()); remove_filter('wp_seed_pixel_storage_usage', $read);

list($id, $job) = m4_original(); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id); $path = get_attached_file($id); $sha = hash_file('sha256', $path); $original = wp_get_original_image_path($id); $original_sha = hash_file('sha256', $original);
$fault = static function ($name) {
    if ($name !== 'original_pre_move') { return; }
    global $wpdb; $connection = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
    $killer = mysqli_init(); mysqli_real_connect($killer, 'localhost', 'root', '', 'pixel_m3', 0, getenv('PIXEL_M3_ROOT') . '/mysql.sock');
    mysqli_query($killer, 'KILL ' . $connection); mysqli_close($killer); $wpdb->suppress_errors(true);
};
add_action('wp_seed_pixel_m4_boundary', $fault);
WP_Seed_Pixel_Jobs::step($job); remove_action('wp_seed_pixel_m4_boundary', $fault);
m4_check(hash_file('sha256', $path) === $sha && is_file($original) && hash_file('sha256', $original) === $original_sha, 'DB session lost immediately before destructive original move fails closed');
global $wpdb; $wpdb->suppress_errors(false);
$resume = WP_Seed_Pixel_Jobs::control($job, 'resume'); WP_Seed_Pixel_Jobs::step($job);
m4_check(!is_wp_error($resume) && m4_item($job)['stage'] === 'retained', 'Fresh session reconciles interrupted operation');
WP_Seed_Pixel_Jobs::quarantine_action($job, 'retain');
$restored = WP_Seed_Pixel_Jobs::quarantine_action($job, 'restore');
m4_check(!is_wp_error($restored) && WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'Recovered operation exact rollback');
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-m5.1');
file_put_contents(dirname(__DIR__) . '/reports/storage-m5.1/quarantine.json', wp_json_encode(array('checks' => $checks, 'physical_before' => $account_before, 'physical_after' => $after), JSON_PRETTY_PRINT));
echo count($checks) . " M5.1 real quarantine checks passed.\n";
