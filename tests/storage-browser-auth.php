<?php
require __DIR__ . '/runtime.php';
$locale = $argv[1] ?? 'en_US';
if (!in_array($locale, array('en_US', 'fr_FR'), true)) { throw new RuntimeException('Unsupported fixture locale'); }
update_user_meta(1, 'locale', $locale);
update_option('show_avatars', false);
$expires = time() + 3600;
$token = WP_Session_Tokens::get_instance(1)->create($expires);
$auth = wp_generate_auth_cookie(1, $expires, 'auth', $token);
$logged = wp_generate_auth_cookie(1, $expires, 'logged_in', $token);
$_COOKIE[LOGGED_IN_COOKIE] = $logged;
echo wp_json_encode(array('cookies' => array(array('name' => AUTH_COOKIE, 'value' => $auth), array('name' => LOGGED_IN_COOKIE, 'value' => $logged)), 'nonce' => wp_create_nonce('wp_seed_pixel_scan')));
