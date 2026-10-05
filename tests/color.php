<?php
require __DIR__ . '/runtime.php';
$checks = array(); $outputs = array();
wp_mkdir_p(dirname(__DIR__) . '/reports/color');
function color_check($name, $condition) { global $checks; $checks[$name] = (bool)$condition; }
$available = WP_Seed_Pixel_Color::available();
$requests = 0;
add_filter('pre_http_request', function () use (&$requests) { ++$requests; return new WP_Error('offline'); });
foreach (array('srgb-icc.jpg', 'p3-icc.jpg', 'p3-small-icc.jpg', 'p3-large-icc.jpg', 'p3-rotated-icc.jpg') as $name) {
    $id = pixel_fixture($name);
    $master = wp_get_original_image_path($id); $before = hash_file('sha256', $master);
    $meta = wp_get_attachment_metadata($id, true);
    $result = wp_seed_pixel_optimize($id, 'balanced');
    color_check($name . ' master immutable', hash_equals($before, hash_file('sha256', $master)));
    if (!$available) {
        color_check($name . ' GD-only honest skip', !is_wp_error($result) && $result['status'] === 'skipped' && strpos($result['reason'], 'LittleCMS') !== false);
        color_check($name . ' mappings unchanged', $meta === wp_get_attachment_metadata($id, true));
        color_check($name . ' no manifest invented', !WP_Seed_Pixel_Store::manifest($id));
    } else {
        color_check($name . ' managed success', !is_wp_error($result) && $result['status'] === 'success');
        if (is_wp_error($result) || $result['status'] !== 'success') { $outputs[$name] = is_wp_error($result) ? $result->get_error_code() : $result; continue; }
        color_check($name . ' provenance', !empty($result['color']['converted']) && $result['color']['source_profile_sha256'] === hash('sha256', WP_Seed_Pixel_Color::inspect($master)));
        $test_lock = WP_Seed_Pixel_Files::lock($id);
        if (is_wp_error($test_lock)) { throw new RuntimeException('Color fixture ownership unavailable'); }
        $workspace = WP_Seed_Pixel_Store::workspace($id);
        $reference = WP_Seed_Pixel_Color::prepare($master, $workspace);
        color_check($name . ' reference available', !is_wp_error($reference));
        foreach ($result['files'] as $kind => $file) {
            $markers = WP_Seed_Pixel_Files::markers($file['path']);
            color_check($name . ' ' . $kind . ' no raw reuse', $file['kind'] === 'derived');
            color_check($name . ' ' . $kind . ' private metadata removed', !is_wp_error($markers) && !$markers['private'] && !$markers['icc']);
            color_check($name . ' ' . $kind . ' color-managed metric', WP_Seed_Pixel_Adaptive::accepts(WP_Seed_Pixel_Adaptive::metric($reference['path'], $file['path']), $kind === 'thumb'));
            if ($name === 'p3-rotated-icc.jpg') { color_check($name . ' ' . $kind . ' orientation', $file['height'] > $file['width']); }
            $target = dirname(__DIR__) . '/reports/color/' . $name . '-' . $kind . '.jpg';
            copy($file['path'], $target);
            $outputs[$name][$kind] = array('path' => basename($target), 'width' => $file['width'], 'height' => $file['height'], 'metric' => $file['metric']);
        }
        copy($reference['path'], dirname(__DIR__) . '/reports/color/' . $name . '-reference.png');
        WP_Seed_Pixel_Store::clean_workspace($workspace);
        WP_Seed_Pixel_Files::unlock($test_lock);
        color_check($name . ' workspace cleanup', !is_dir($workspace));
        $again = wp_seed_pixel_optimize($id, 'balanced');
        color_check($name . ' idempotence', !is_wp_error($again) && !empty($again['unchanged']));
        WP_Seed_Pixel_Store::cleanup($id);
        color_check($name . ' rollback retains master', hash_equals($before, hash_file('sha256', $master)));
    }
    wp_delete_attachment($id, true);
}
foreach (array('bad-icc.jpg', 'missing-icc-chunk.jpg', 'non-rgb-icc.jpg', 'cmyk.jpg') as $name) {
    $id = pixel_fixture($name); $meta = wp_get_attachment_metadata($id, true);
    $result = wp_seed_pixel_optimize($id, 'balanced');
    color_check($name . ' fail closed', !is_wp_error($result) && $result['status'] === 'skipped');
    color_check($name . ' no mapping mutation', $meta === wp_get_attachment_metadata($id, true));
    wp_delete_attachment($id, true);
}
color_check('zero runtime network calls', $requests === 0);
color_check('no stale job workspace', !glob(wp_upload_dir()['basedir'] . '/wp-seed-pixel/job-*'));
$report = array('backend' => $available ? 'Imagick/LittleCMS' : 'GD-only', 'checks' => $checks, 'outputs' => $outputs, 'pass' => !in_array(false, $checks, true));
file_put_contents(dirname(__DIR__) . '/reports/color/' . ($available ? 'imagick' : 'gd') . '-RESULT.json', wp_json_encode($report, JSON_PRETTY_PRINT));
echo wp_json_encode(array('backend' => $report['backend'], 'checks' => count($checks), 'failures' => array_keys(array_filter($checks, function ($value) { return !$value; })), 'outputs' => $outputs), JSON_PRETTY_PRINT);
exit($report['pass'] ? 0 : 1);
