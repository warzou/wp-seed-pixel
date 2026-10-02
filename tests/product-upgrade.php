<?php
require __DIR__ . '/runtime.php';
$state = dirname(__DIR__) . '/.runtime/product-upgrade.json';
if (isset($argv[1]) && $argv[1] === 'seed') {
    if (WP_SEED_PIXEL_VERSION !== '0.2.0') { throw new RuntimeException('Expected 0.2.0 installed ZIP.'); }
    $id = pixel_fixture('small.jpg');
    $result = wp_seed_pixel_optimize($id, 'balanced');
    if (is_wp_error($result)) { throw new RuntimeException('Seed failed'); }
    file_put_contents($state, wp_json_encode(array('id' => $id, 'manifest' => WP_Seed_Pixel_Store::manifest($id), 'metadata' => wp_get_attachment_metadata($id), 'settings' => get_option('wp_seed_pixel_settings'), 'cron' => _get_cron_array(), 'hash' => hash_file('sha256', wp_get_original_image_path($id)))));
    echo "0.2.0 ZIP state seeded.\n";
    exit;
}
$before = json_decode(file_get_contents($state), true);
$id = $before['id'];
$checks = array(
    'Final ZIP active' => WP_SEED_PIXEL_VERSION === '0.3.0' && is_plugin_active('wp-seed-pixel/wp-seed-pixel.php'),
    'Manifest unchanged' => WP_Seed_Pixel_Store::manifest($id) === $before['manifest'],
    'Native metadata unchanged' => wp_get_attachment_metadata($id) === $before['metadata'],
    'Settings unchanged' => get_option('wp_seed_pixel_settings') === $before['settings'],
    'No automatic processing scheduled by upgrade' => _get_cron_array() === $before['cron'],
    'Master unchanged' => hash_file('sha256', wp_get_original_image_path($id)) === $before['hash'],
    'Getter still works' => !is_wp_error(wp_seed_pixel_get_derivative($id, 'view')),
    'Source reuse preserved' => $before['manifest']['files']['view']['kind'] === 'master',
);
file_put_contents(dirname(__DIR__) . '/reports/product-ux/upgrade-tests.json', wp_json_encode($checks, JSON_PRETTY_PRINT));
echo count(array_filter($checks)) . '/' . count($checks) . " upgrade checks passed.\n";
exit(in_array(false, $checks, true) ? 1 : 0);
