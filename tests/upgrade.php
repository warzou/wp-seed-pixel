<?php
require __DIR__ . '/runtime.php';
$state_file = dirname(__DIR__) . '/.runtime/upgrade-state.json';
if (isset($argv[1]) && $argv[1] === 'seed') {
    if (WP_SEED_PIXEL_VERSION !== '0.1.0') { throw new RuntimeException('Expected installed 0.1.0 ZIP.'); }
    $id = pixel_fixture('rgb.jpg');
    $legacy = wp_seed_pixel_optimize($id, 'participant_album');
    if (is_wp_error($legacy)) { throw new RuntimeException('Legacy processing failed.'); }
    file_put_contents($state_file, wp_json_encode(array('id' => $id, 'manifest' => $legacy, 'metadata' => wp_get_attachment_metadata($id), 'settings' => get_option('wp_seed_pixel_settings'), 'master_hash' => hash_file('sha256', wp_get_original_image_path($id)))));
    echo "Legacy ZIP processed fixture and recorded targeted upgrade state.\n";
    exit;
}
$before = json_decode(file_get_contents($state_file), true);
$id = $before['id'];
$tests = array();
$check = function ($test, $condition) use (&$tests) { $tests[] = array('test' => $test, 'status' => $condition ? 'PASS' : 'FAIL'); };
$check('Final ZIP version active', WP_SEED_PIXEL_VERSION === '0.2.0' && is_plugin_active('wp-seed-pixel/wp-seed-pixel.php'));
$check('No implicit legacy regeneration', WP_Seed_Pixel_Store::manifest($id)['generation'] === $before['manifest']['generation']);
$check('Native metadata unchanged on upgrade', wp_get_attachment_metadata($id) === $before['metadata']);
$check('Settings unchanged on upgrade', get_option('wp_seed_pixel_settings') === $before['settings']);
$check('MASTER hash unchanged on upgrade', hash_equals($before['master_hash'], hash_file('sha256', wp_get_original_image_path($id))));
$check('Legacy getter remains compatible', !is_wp_error(wp_seed_pixel_get_derivative($id, 'view')));
$next = wp_seed_pixel_optimize($id, 'balanced');
$check('Explicit adaptive opt-in succeeds', !is_wp_error($next) && $next['strategy'] === 'bounded-adaptive');
$check('Legacy URLs retained after opt-in', is_file($before['manifest']['files']['view']['path']));
$check('MASTER unchanged after opt-in', hash_equals($before['master_hash'], hash_file('sha256', wp_get_original_image_path($id))));
wp_delete_attachment($id, true);
unlink($state_file);
file_put_contents(dirname(__DIR__) . '/reports/adaptive/data/upgrade-tests.json', wp_json_encode($tests, JSON_PRETTY_PRINT));
echo wp_json_encode(array_count_values(array_column($tests, 'status')), JSON_PRETTY_PRINT);
exit(in_array('FAIL', array_column($tests, 'status'), true) ? 1 : 0);
