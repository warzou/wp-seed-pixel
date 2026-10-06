<?php
require __DIR__ . '/runtime.php';
$s = WP_Seed_Pixel_Recovery_Setup::verify();
echo wp_json_encode(array('recovery' => is_wp_error($s) ? $s->get_error_code() : $s['state'], 'missing' => WP_Seed_Pixel_Recovery_Setup::png_requirements(), 'limits' => WP_Seed_Pixel_Storage_Budget::settings(), 'future' => WP_Seed_Pixel_Future_Uploads::settings(), 'policy' => get_option('wp_seed_pixel_storage_policy_confirmed')), JSON_PRETTY_PRINT);
