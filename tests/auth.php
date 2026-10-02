<?php
require __DIR__ . '/runtime.php';
update_option('show_avatars', false); // Avoid a core Gravatar request in offline visual QA.
$role = isset($argv[1]) ? $argv[1] : 'administrator';
$user_id = 1;
if (in_array($role, array('subscriber', 'author'), true)) {
    $login = 'pixel_' . $role;
    $user_id = username_exists($login);
    if (!$user_id) {
        $user_id = wp_insert_user(array('user_login' => $login, 'user_pass' => bin2hex(random_bytes(24)), 'user_email' => $login . '@example.invalid', 'role' => $role));
    }
    if (is_wp_error($user_id)) {
        throw new RuntimeException('Disposable user could not be created.');
    }
    wp_set_current_user($user_id);
}
$expires = time() + 3600;
$token = WP_Session_Tokens::get_instance($user_id)->create($expires);
$auth = wp_generate_auth_cookie($user_id, $expires, 'auth', $token);
$logged = wp_generate_auth_cookie($user_id, $expires, 'logged_in', $token);
$_COOKIE[LOGGED_IN_COOKIE] = $logged;
echo wp_json_encode(array('cookies' => array(array('name' => AUTH_COOKIE, 'value' => $auth), array('name' => LOGGED_IN_COOKIE, 'value' => $logged)), 'nonce' => wp_create_nonce('wp_seed_pixel')));
