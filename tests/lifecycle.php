<?php
require __DIR__ . '/runtime.php';
$tests = array();
function lifecycle_check($name, $passed) {
    global $tests;
    $tests[] = array('test' => $name, 'status' => $passed ? 'PASS' : 'FAIL');
}
$plugin = 'wp-seed-pixel/wp-seed-pixel.php';
if (!is_plugin_active($plugin)) {
    $activate = activate_plugin($plugin);
    if (is_wp_error($activate)) {
        throw new RuntimeException('ZIP activation failed.');
    }
    WP_Seed_Pixel_Plugin::boot();
}
lifecycle_check('Extracted archive activates', is_plugin_active($plugin));
$id = pixel_fixture('rgb.jpg');
$master = wp_get_original_image_path($id);
$hash = hash_file('sha256', $master);
$processed = wp_seed_pixel_optimize($id, 'participant_album');
lifecycle_check('Extracted archive optimizes', !is_wp_error($processed) && $processed['status'] === 'success');
if (is_wp_error($processed)) {
    exit(1);
}
deactivate_plugins($plugin);
lifecycle_check('Deactivation preserves master and output', hash_file('sha256', $master) === $hash && is_file($processed['files']['thumb']['path']));
wp_set_current_user(0);
$public_rest = rest_do_request(new WP_REST_Request('GET', '/wp/v2/media/' . $id));
$public_data = wp_json_encode($public_rest->get_data());
lifecycle_check('Inactive plugin still hides internal paths from REST', strpos($public_data, 'C:') === false && strpos($public_data, 'master_sha256') === false && strpos($public_data, '_seed_pixel_manifest') === false);
wp_set_current_user(1);
$active = activate_plugin($plugin);
lifecycle_check('Reactivation succeeds', !is_wp_error($active) && is_plugin_active($plugin));
lifecycle_check('Reactivation preserves metadata', WP_Seed_Pixel_Store::manifest($id)['generation'] === $processed['generation']);
deactivate_plugins($plugin);
if (!defined('WP_UNINSTALL_PLUGIN')) {
    define('WP_UNINSTALL_PLUGIN', $plugin);
}
include WP_PLUGIN_DIR . '/wp-seed-pixel/uninstall.php';
lifecycle_check('Default uninstall retains derivative and master', is_file($processed['files']['thumb']['path']) && hash_file('sha256', $master) === $hash);
update_option('wp_seed_pixel_settings', array('automatic' => false, 'preset' => 'web', 'cleanup_on_uninstall' => true), false);
include WP_PLUGIN_DIR . '/wp-seed-pixel/uninstall.php';
lifecycle_check('Opt-in uninstall keeps master', is_file($master) && hash_file('sha256', $master) === $hash);
lifecycle_check('Opt-in uninstall removes owned output', !is_file($processed['files']['thumb']['path']));
lifecycle_check('Opt-in uninstall removes plugin metadata', !WP_Seed_Pixel_Store::manifest($id) && !get_post_meta($id, '_seed_pixel_job', true));
lifecycle_check('Opt-in uninstall removes settings', get_option('wp_seed_pixel_settings', null) === null);
$active = activate_plugin($plugin);
lifecycle_check('Clean reactivation succeeds', !is_wp_error($active) && is_plugin_active($plugin));
$failed = array_filter($tests, function ($row) { return $row['status'] === 'FAIL'; });
file_put_contents(dirname(__DIR__) . '/reports/final/lifecycle-results.json', wp_json_encode(array('tests' => $tests), JSON_PRETTY_PRINT));
echo wp_json_encode(array('tests' => count($tests), 'pass' => count($tests) - count($failed), 'failed' => array_values($failed)), JSON_PRETTY_PRINT);
exit($failed ? 1 : 0);
