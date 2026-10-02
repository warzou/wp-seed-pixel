<?php
require __DIR__ . '/runtime.php';
$input = json_decode(file_get_contents($argv[1]), true);
$output = $argv[2];
if (!is_array($input) || strpos(wp_normalize_path($output), wp_normalize_path(dirname(__DIR__))) === 0 || !is_dir($output)) {
    throw new RuntimeException('Benchmark output must exist outside the source project.');
}
$working = $output . '/working';
wp_mkdir_p($working);
$override = function ($uploads) use ($working) {
    $uploads['basedir'] = $working;
    $uploads['path'] = $working;
    $uploads['baseurl'] = 'http://127.0.0.1:8877/benchmark';
    $uploads['url'] = $uploads['baseurl'];
    $uploads['subdir'] = '';
    $uploads['error'] = false;
    return $uploads;
};
add_filter('upload_dir', $override);
$results = array();
foreach ($input as $row) {
    $hash_before = hash_file('sha256', $row['source']);
    $upload = wp_upload_bits($row['id'] . '.jpg', null, file_get_contents($row['source']));
    if ($upload['error']) {
        throw new RuntimeException('Private benchmark copy failed.');
    }
    $id = (int) wp_insert_attachment(array('post_title' => 'Local benchmark ' . $row['id'], 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'), $upload['file']);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
    $start = microtime(true);
    $optimized = wp_seed_pixel_optimize($id, 'participant_album');
    $entry = array('id' => $row['id'], 'master_bytes' => filesize($row['source']), 'master_sha256' => $hash_before, 'seconds' => round(microtime(true) - $start, 4), 'status' => is_wp_error($optimized) ? 'failed' : $optimized['status'], 'engine' => 'WP_Image_Editor_GD', 'native' => array(), 'files' => array());
    if (!is_wp_error($optimized) && $optimized['status'] === 'success') {
        foreach ($optimized['files'] as $name => $file) {
            $dest = $output . '/' . $row['id'] . '-' . $name . '.jpg';
            copy($file['path'], $dest);
            unset($file['path']);
            $file['transfer_saving_percent_vs_master'] = round(100 * (1 - $file['bytes'] / $entry['master_bytes']), 2);
            $entry['files'][$name] = $file;
            $editor = wp_get_image_editor(wp_get_original_image_path($id));
            $editor->maybe_exif_rotate();
            $dim = $editor->get_size();
            $limit = $name === 'thumb' ? 640 : 2048;
            if ($dim['width'] > $limit || $dim['height'] > $limit) {
                $editor->resize($limit, $limit, false);
            }
            $native = $output . '/' . $row['id'] . '-native-' . $name . '.jpg';
            $saved = $editor->save($native, 'image/jpeg');
            if (is_wp_error($saved)) {
                throw new RuntimeException('Native comparison failed.');
            }
            $entry['native'][$name] = array('bytes' => filesize($native), 'quality' => $editor->get_quality(), 'width' => $saved['width'], 'height' => $saved['height']);
            unset($editor);
        }
        $entry['added_disk_bytes'] = $optimized['added_disk_bytes'];
    } else {
        $entry['reason'] = is_wp_error($optimized) ? $optimized->get_error_code() : $optimized['reason'];
    }
    $entry['master_preserved'] = hash_equals($hash_before, hash_file('sha256', $row['source']));
    $entry['temporary_copy_preserved_during_processing'] = hash_equals($hash_before, hash_file('sha256', wp_get_original_image_path($id)));
    $results[] = $entry;
    wp_delete_attachment($id, true);
}
remove_filter('upload_dir', $override);
file_put_contents(dirname(__DIR__) . '/reports/final/benchmark-results.json', wp_json_encode($results, JSON_PRETTY_PRINT));
echo wp_json_encode(array('images' => count($results), 'success' => count(array_filter($results, function ($row) { return $row['status'] === 'success'; })), 'masters_preserved' => count(array_filter($results, function ($row) { return $row['master_preserved']; }))), JSON_PRETTY_PRINT);
