<?php
require __DIR__ . '/runtime.php';
$action = isset($argv[1]) ? $argv[1] : 'reset';
if ($action === 'warning' || $action === 'restore') {
    $active = get_option('active_plugins', array());
    $active = array_values(array_diff($active, array('shortpixel-image-optimiser/synthetic.php')));
    if ($action === 'warning') { $active[] = 'shortpixel-image-optimiser/synthetic.php'; }
    update_option('active_plugins', $active, false);
    echo "Synthetic optimizer-warning state updated.\n";
    exit;
}
update_option(WP_Seed_Pixel_Batch::OPTION, array(), false);
update_option('wp_seed_pixel_settings', array('automatic' => false, 'preset' => 'balanced', 'cleanup_on_uninstall' => false), false);
$file = dirname(__DIR__) . '/reports/product-ux/fixtures.json';
$fixtures = json_decode(file_get_contents($file), true);
if (empty($fixtures['manual'])) {
    $fixtures['manual'] = pixel_fixture('rgb.jpg');
    wp_update_post(array('ID' => $fixtures['manual'], 'post_title' => 'UX demo - existing image ready to optimize'));
} else {
    $clean = WP_Seed_Pixel_Store::cleanup((int) $fixtures['manual']);
    if (is_wp_error($clean)) { throw new RuntimeException('Synthetic reset failed'); }
    delete_post_meta($fixtures['manual'], '_seed_pixel_job');
}
file_put_contents($file, wp_json_encode($fixtures));
echo "Synthetic queue and manual workflow reset.\n";
