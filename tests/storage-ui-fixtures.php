<?php
require __DIR__ . '/runtime.php';
if (get_option(WP_Seed_Pixel_Recovery_Setup::OPTION, null) !== null) { throw new RuntimeException('Fresh UI state required'); }
WP_Seed_Pixel_Future_Uploads::configure('off');
update_option('wp_seed_pixel_settings', array('automatic' => false, 'preset' => 'balanced', 'cleanup_on_uninstall' => false), false);
$im = imagecreatetruecolor(320, 240);
imagefilledrectangle($im, 0, 0, 319, 239, imagecolorallocate($im, 48, 137, 99));
imagepng($im, dirname(__DIR__) . '/.runtime/fixtures/setup.png', 0);
imagejpeg($im, dirname(__DIR__) . '/.runtime/fixtures/setup.jpg', 95);
imagedestroy($im);
$png = pixel_fixture('setup.png'); $jpg = pixel_fixture('setup.jpg');
$password = bin2hex(random_bytes(24)); wp_set_password($password, 1);
update_user_meta(1, 'locale', 'fr_FR');
file_put_contents(dirname(__DIR__) . '/.runtime/browser-login.json', wp_json_encode(array('user' => 'pixel_qa', 'password' => $password, 'png' => $png, 'jpg' => $jpg)));
file_put_contents(dirname(__DIR__) . '/.runtime/storage-ui-before.json', wp_json_encode(array('png' => $png, 'png_sha' => hash_file('sha256', get_attached_file($png)), 'meta' => get_post_meta($png))));
echo 'Two synthetic UI images, automation off, fresh French setup state.';
