<?php
require __DIR__ . '/m3-runtime.php';
$checks = array();
function formats_ok($v, $name) { global $checks; if (!$v || is_wp_error($v)) { throw new RuntimeException($name); } $checks[$name] = true; }
function formats_upload($source, $mime) {
    $upload = wp_upload_bits('formats-' . bin2hex(random_bytes(8)) . '.' . pathinfo($source, PATHINFO_EXTENSION), null, file_get_contents($source));
    formats_ok(empty($upload['error']), 'synthetic upload ' . $mime);
    apply_filters('wp_handle_upload', $upload, 'upload');
    return wp_insert_attachment(array('post_title' => 'Synthetic format enrollment', 'post_mime_type' => $mime, 'post_status' => 'inherit'), $upload['file']);
}
$settings = WP_Seed_Pixel_Plugin::settings();
$settings['automatic'] = true;
update_option('wp_seed_pixel_settings', $settings, false);
formats_ok(WP_Seed_Pixel_Future_Uploads::configure('process', 268435456, null, array('png')), 'PNG-only enrollment configured');
$fixtures = dirname(__DIR__) . '/.runtime/fixtures';
$jpeg = formats_upload($fixtures . '/product.jpg', 'image/jpeg');
$before = hash_file('sha256', get_attached_file($jpeg));
formats_ok(!get_post_meta($jpeg, WP_Seed_Pixel_Future_Uploads::META, true), 'PNG-only policy does not enroll JPEG');
WP_Seed_Pixel_Plugin::uploaded(0, $jpeg, '_wp_attachment_metadata', array());
formats_ok(wp_next_scheduled('wp_seed_pixel_auto', array((int) $jpeg)) !== false, 'PNG-only policy preserves legacy JPEG scheduling');
wp_clear_scheduled_hook('wp_seed_pixel_auto', array((int) $jpeg));
formats_ok(hash_file('sha256', get_attached_file($jpeg)) === $before, 'enrollment test did not process JPEG');
$png = formats_upload($fixtures . '/product.png', 'image/png');
formats_ok(get_post_meta($png, WP_Seed_Pixel_Future_Uploads::META, true)['state'] === 'awaiting_metadata', 'PNG upload canonically enrolled');
formats_ok(WP_Seed_Pixel_Future_Uploads::configure('process', 268435456, null, array('jpeg', 'png')), 'both formats configured');
$native = formats_upload($fixtures . '/product.jpg', 'image/jpeg');
formats_ok(get_post_meta($native, WP_Seed_Pixel_Future_Uploads::META, true)['state'] === 'awaiting_metadata', 'native JPEG canonically enrolled');
WP_Seed_Pixel_Plugin::uploaded(0, $native, '_wp_attachment_metadata', array());
formats_ok(wp_next_scheduled('wp_seed_pixel_auto', array((int) $native)) === false, 'native JPEG never receives a second legacy job');
formats_ok(WP_Seed_Pixel_Future_Uploads::configure('off'), 'future enrollment reset off');
$settings['automatic'] = false;
update_option('wp_seed_pixel_settings', $settings, false);
file_put_contents(dirname(__DIR__) . '/reports/productization/formats.json', wp_json_encode(array('checks' => $checks, 'encoder_calls' => 0), JSON_PRETTY_PRINT));
echo count($checks) . " format routing checks PASS\n";
