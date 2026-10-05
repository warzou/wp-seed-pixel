<?php
require __DIR__ . '/m3-runtime.php';
if (!WP_Seed_Pixel_Master_Storage::enabled()) { throw new RuntimeException('Owned Linux M3 environment required'); }
global $wpdb;
$results = array();
foreach (array('same' => 0, 'downsize' => 1920) as $label => $edge) {
    $id = m3_fixture('m3-detail.jpg', false);
    $path = get_attached_file($id); $info = getimagesize($path);
    wp_update_attachment_metadata($id, array('file' => _wp_relative_upload_path($path), 'width' => $info[0], 'height' => $info[1], 'filesize' => filesize($path), 'sizes' => array()));
    $before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
    $intent = array('master' => 'replace_verified');
    if ($edge) { $intent['dimensions'] = 'max_edge'; $intent['max_edge'] = $edge; }
    $job = WP_Seed_Pixel_Jobs::replace_one($id, $intent, 1073741824);
    if (is_wp_error($job)) { throw new RuntimeException($job->get_error_code()); }
    $samples = array(); $started = microtime(true);
    $hook = static function ($boundary, $item_id) use (&$samples, $started, $wpdb) {
        $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' WHERE id=%d', $item_id), ARRAY_A);
        $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
        if (is_wp_error($dir)) { throw new RuntimeException('Missing operation directory'); }
        $bytes = 0;
        foreach (glob($dir . '/*') as $file) { clearstatcache(true, $file); if (is_file($file)) { $bytes += filesize($file); } }
        $samples[$boundary] = array('seconds' => microtime(true) - $started, 'private_file_bytes' => $bytes);
    };
    add_action('wp_seed_pixel_m3_boundary', $hook, 10, 2);
    try { $result = WP_Seed_Pixel_Jobs::step($job['id']); }
    finally { remove_action('wp_seed_pixel_m3_boundary', $hook, 10); }
    $elapsed = microtime(true) - $started;
    $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation'", $job['id']), ARRAY_A);
    if (is_wp_error($result) || $item['stage'] !== 'retained' || !isset($samples['escrow'], $samples['candidate'])) { throw new RuntimeException('Benchmark replacement did not verify'); }
    $record = WP_Seed_Pixel_Master_Storage::load(WP_Seed_Pixel_Master_Storage::directory($item, false), $item);
    $results[$label] = array('source_bytes' => $before['bytes'], 'source_dimensions' => array($before['width'], $before['height']),
        'candidate_bytes' => $record['candidate']['bytes'], 'candidate_dimensions' => array($record['candidate']['width'], $record['candidate']['height']),
        'encode_and_candidate_verification_seconds' => $samples['candidate']['seconds'] - $samples['escrow']['seconds'],
        'replacement_seconds' => $elapsed, 'checkpoint_private_file_peak_bytes' => max(array_column($samples, 'private_file_bytes')),
        'budget_bytes_not_measured_peak' => $record['peak_budget'], 'php_heap_peak_bytes_not_native_backend_rss' => memory_get_peak_usage(true), 'samples' => $samples);
    $restore = WP_Seed_Pixel_Jobs::restore_master($job['id']);
    if (is_wp_error($restore) || WP_Seed_Pixel_Master_Adapter::snapshot($id) !== $before) { throw new RuntimeException('Benchmark exact rollback failed'); }
}
$report = array('observations' => $results, 'limits' => 'Checkpoint file-byte samples are not continuous peak disk measurements. PHP heap excludes Imagick native RSS. Timings are local observations, not performance promises. Both fixtures restored exactly.');
file_put_contents(dirname(__DIR__) . '/reports/storage-m3/performance.json', wp_json_encode($report, JSON_PRETTY_PRINT));
echo wp_json_encode($report, JSON_PRETTY_PRINT) . "\n";
