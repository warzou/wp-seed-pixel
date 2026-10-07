<?php
require __DIR__ . '/format-bootstrap.php';
$id = format_fixture('smooth-provenance');
$override_id = format_fixture('provenance');
update_user_meta(1, 'locale', 'fr_FR');
add_filter('send_auth_cookies', '__return_false');
$cookies = array();
add_action('set_auth_cookie', static function ($cookie) use (&$cookies) { $cookies[] = array('name' => AUTH_COOKIE, 'value' => $cookie, 'domain' => '127.0.0.1', 'path' => '/', 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax'); });
add_action('set_logged_in_cookie', static function ($cookie) use (&$cookies) { $cookies[] = array('name' => LOGGED_IN_COOKIE, 'value' => $cookie, 'domain' => '127.0.0.1', 'path' => '/', 'httpOnly' => true, 'secure' => false, 'sameSite' => 'Lax'); });
wp_set_auth_cookie(1, false);
$target = '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/.runtime/format-browser.json';
file_put_contents($target, wp_json_encode(array('id' => $id, 'override_id' => $override_id, 'cookies' => $cookies)));
echo "Ephemeral local browser session created; no credentials printed.\n";
