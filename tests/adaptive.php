<?php
require __DIR__ . '/runtime.php';
$results = array();
function adaptive_check($name, $condition) { global $results; $results[] = array('test' => $name, 'status' => $condition ? 'PASS' : 'FAIL'); }
$requests = 0;
add_filter('pre_http_request', function () use (&$requests) { ++$requests; return new WP_Error('offline'); });
foreach (array('rgb.jpg', 'small.jpg', 'app0-private.jpg', 'gps.jpg', 'orientation-2.jpg', 'orientation-5.jpg', 'orientation-7.jpg', 'orientation-8.jpg') as $fixture) {
    $id = pixel_fixture($fixture);
    $master = wp_get_original_image_path($id);
    $hash = hash_file('sha256', $master);
    $result = wp_seed_pixel_optimize($id, 'balanced');
    adaptive_check($fixture . ' processed safely', !is_wp_error($result) && $result['status'] === 'success');
    if (is_wp_error($result)) { continue; }
    adaptive_check($fixture . ' MASTER immutable', hash_equals($hash, hash_file('sha256', $master)));
    adaptive_check($fixture . ' at most six encodes', $result['candidates'] <= 6);
    foreach ($result['files'] as $name => $file) {
        $m = WP_Seed_Pixel_Files::markers($file['path']);
        adaptive_check($fixture . ' ' . $name . ' no private metadata', !is_wp_error($m) && !$m['private']);
        if ($fixture === 'app0-private.jpg') {
            adaptive_check('Noncanonical APP0 is sanitized, never reused', $file['kind'] === 'derived' && strpos(file_get_contents($file['path']), 'SYNTHETIC PRIVATE APP0') === false);
        }
        adaptive_check($fixture . ' ' . $name . ' API works', !is_wp_error(wp_seed_pixel_get_derivative($id, $name)));
        adaptive_check($fixture . ' ' . $name . ' no upscale', $file['width']*$file['height'] <= getimagesize($master)[0]*getimagesize($master)[1]);
        if ($file['kind'] !== 'master' && $file['metric']) {
            adaptive_check($fixture . ' ' . $name . ' quality floor', WP_Seed_Pixel_Adaptive::accepts($file['metric'], $name === 'thumb'));
        }
    }
    $again = wp_seed_pixel_optimize($id, 'balanced');
    adaptive_check($fixture . ' idempotent', !is_wp_error($again) && !empty($again['unchanged']));
    $cleanup = WP_Seed_Pixel_Store::cleanup($id);
    adaptive_check($fixture . ' cleanup retains MASTER', !is_wp_error($cleanup) && is_file($master) && hash_equals($hash, hash_file('sha256', $master)));
    wp_delete_attachment($id, true);
}
$id = pixel_fixture('rgb.jpg');
$legacy = wp_seed_pixel_optimize($id, 'participant_album');
adaptive_check('Legacy fixed profile retained', !is_wp_error($legacy) && $legacy['files']['view']['quality'] === 90);
$updated = wp_seed_pixel_optimize($id, 'balanced');
adaptive_check('Explicit adaptive regeneration changes config hash', !is_wp_error($updated) && $legacy['config_sha256'] !== $updated['config_sha256']);
adaptive_check('Previous legacy URLs retained', is_file($legacy['files']['view']['path']));
adaptive_check('Algorithm version exposed', !is_wp_error($updated) && $updated['algorithm_version'] === WP_Seed_Pixel_Adaptive::VERSION);
adaptive_check('Optimization and regeneration zero network attempts', $requests === 0);
adaptive_check('Non-finite metrics rejected', !WP_Seed_Pixel_Adaptive::accepts(array('ssim' => NAN, 'psnr' => INF)));
adaptive_check('VIEW below quality floor rejected', !WP_Seed_Pixel_Adaptive::accepts(array('ssim' => 0.90, 'psnr' => 40.0)));
adaptive_check('THUMB uses explicit lighter intent', WP_Seed_Pixel_Adaptive::accepts(array('ssim' => 0.97, 'psnr' => 32.0), true));
wp_delete_attachment($id, true);
file_put_contents(dirname(__DIR__) . '/reports/adaptive/data/adaptive-tests.json', wp_json_encode($results, JSON_PRETTY_PRINT));
echo wp_json_encode(array_count_values(array_column($results, 'status')), JSON_PRETTY_PRINT);
exit(in_array('FAIL', array_column($results, 'status'), true) ? 1 : 0);
