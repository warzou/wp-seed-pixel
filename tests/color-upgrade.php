<?php
require __DIR__ . '/runtime.php';
$path = dirname(__DIR__) . '/.runtime/color-upgrade.json';
if (($argv[1] ?? '') === 'seed') {
    if (WP_SEED_PIXEL_VERSION !== '0.3.0') { throw new RuntimeException('Expected 0.3.0 ZIP'); }
    $rows = array();
    foreach (array('small.jpg', 'rgb.jpg') as $fixture) {
        $id = pixel_fixture($fixture);
        $result = wp_seed_pixel_optimize($id, 'balanced');
        if (is_wp_error($result)) { throw new RuntimeException('Seed failed'); }
        $rows[] = array('id' => $id, 'manifest' => base64_encode(serialize(WP_Seed_Pixel_Store::manifest($id))), 'metadata' => base64_encode(serialize(wp_get_attachment_metadata($id))), 'master' => hash_file('sha256', wp_get_original_image_path($id)));
    }
    file_put_contents($path, wp_json_encode(array('rows' => $rows, 'settings' => get_option('wp_seed_pixel_settings'), 'cron' => _get_cron_array())));
    echo "Synthetic 0.3.0 state seeded.\n";
    exit;
}
$before = json_decode(file_get_contents($path), true);
$checks = array('0.3.1 candidate active' => WP_SEED_PIXEL_VERSION === '0.3.1' && is_plugin_active('wp-seed-pixel/wp-seed-pixel.php'), 'settings preserved' => get_option('wp_seed_pixel_settings') === $before['settings'], 'no scheduled regeneration' => _get_cron_array() === $before['cron']);
foreach ($before['rows'] as $row) {
    $id = $row['id'];
    $checks[$id . ' old manifest exact'] = base64_encode(serialize(WP_Seed_Pixel_Store::manifest($id))) === $row['manifest'];
    $checks[$id . ' native metadata exact'] = base64_encode(serialize(wp_get_attachment_metadata($id))) === $row['metadata'];
    $checks[$id . ' master exact'] = hash_file('sha256', wp_get_original_image_path($id)) === $row['master'];
    foreach (array('thumb', 'view') as $kind) { $checks[$id . ' ' . $kind . ' old API readable'] = !is_wp_error(wp_seed_pixel_get_derivative($id, $kind)); }
    wp_delete_attachment($id, true);
}
unlink($path);
file_put_contents(dirname(__DIR__) . '/reports/color/UPGRADE-RESULT.json', wp_json_encode($checks, JSON_PRETTY_PRINT));
echo wp_json_encode(array('checks' => count($checks), 'failures' => array_keys(array_filter($checks, function ($v) { return !$v; }))), JSON_PRETTY_PRINT);
exit(in_array(false, $checks, true) ? 1 : 0);
