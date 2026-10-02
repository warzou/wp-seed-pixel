<?php
require __DIR__ . '/runtime.php';
$input = json_decode(file_get_contents($argv[1]), true);
$output = $argv[2];
if (!is_array($input) || !is_dir($output) || strpos(wp_normalize_path($output), wp_normalize_path(dirname(__DIR__))) === 0) {
    throw new RuntimeException('An explicit output directory outside the source project is required.');
}
$network = 0;
add_filter('pre_http_request', function () use (&$network) { ++$network; return new WP_Error('offline', 'External requests forbidden in benchmark.'); });
$rows = array();
foreach ($input as $row) {
    $before = hash_file('sha256', $row['source']);
    $upload = wp_upload_bits('adaptive-' . $row['id'] . '.jpg', null, file_get_contents($row['source']));
    if ($upload['error']) { throw new RuntimeException('Benchmark copy failed.'); }
    $id = (int) wp_insert_attachment(array('post_title' => 'Adaptive benchmark ' . $row['id'], 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'), $upload['file']);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
    $start = microtime(true);
    $result = wp_seed_pixel_optimize($id, 'balanced');
    if (is_wp_error($result)) { throw new RuntimeException($row['id'] . ' ' . $result->get_error_code()); }
    $entry = array('id' => $row['id'], 'group' => $row['group'], 'master_bytes' => filesize($row['source']), 'master_sha256' => $before, 'seconds' => round(microtime(true) - $start, 4), 'added_disk_bytes' => $result['added_disk_bytes'], 'candidates' => $result['candidates'], 'files' => array(), 'fixed' => array());
    foreach ($result['files'] as $name => $file) {
        if ($file['kind'] !== 'master') { copy($file['path'], $output . '/' . $row['id'] . '-' . $name . '.jpg'); }
        unset($file['path']);
        $entry['files'][$name] = $file;
        foreach (array(78, 82, 86, 90, 94) as $q) {
            $editor = wp_get_image_editor(wp_get_original_image_path($id));
            $editor->maybe_exif_rotate();
            $limit = $name === 'thumb' ? 640 : 1920;
            $dim = $editor->get_size();
            if (max($dim['width'], $dim['height']) > $limit) { $editor->resize($limit, $limit, false); }
            $editor->set_quality($q);
            $dest = $output . '/fixed.jpg';
            $save = $editor->save($dest, 'image/jpeg');
            unset($editor);
            if (is_wp_error($save)) { throw new RuntimeException('Fixed baseline failed.'); }
            clearstatcache(true, $dest);
            $metric = WP_Seed_Pixel_Adaptive::metric(wp_get_original_image_path($id), $dest);
            if (is_wp_error($metric)) { throw new RuntimeException('Metric failed.'); }
            $entry['fixed'][$name][$q] = array('bytes' => filesize($dest), 'metric' => $metric);
            unlink($dest);
        }
    }
    $entry['unchanged_fast_path'] = !is_wp_error($again = wp_seed_pixel_optimize($id, 'balanced')) && !empty($again['unchanged']);
    $entry['master_preserved'] = hash_equals($before, hash_file('sha256', $row['source'])) && hash_equals($before, hash_file('sha256', wp_get_original_image_path($id)));
    $entry['peak_php_memory_bytes'] = memory_get_peak_usage(true);
    $rows[] = $entry;
    WP_Seed_Pixel_Store::cleanup($id);
    wp_delete_attachment($id, true);
    echo $row['id'] . ' ' . $result['candidates'] . ' candidates ' . $result['seconds'] . 's' . PHP_EOL;
}
$report = isset($argv[3]) && $argv[3] === 'zip-runtime-benchmark.json' ? $argv[3] : 'runtime-benchmark.json';
file_put_contents(dirname(__DIR__) . '/reports/adaptive/data/' . $report, wp_json_encode(array('version' => WP_SEED_PIXEL_VERSION, 'algorithm_version' => WP_Seed_Pixel_Adaptive::VERSION, 'engine_sha256' => hash_file('sha256', WP_PLUGIN_DIR . '/wp-seed-pixel/includes/class-engine.php'), 'adaptive_sha256' => hash_file('sha256', WP_PLUGIN_DIR . '/wp-seed-pixel/includes/class-adaptive.php'), 'network_attempts' => $network, 'rows' => $rows), JSON_PRETTY_PRINT));
if ($network !== 0) { throw new RuntimeException('Unexpected network call.'); }
