<?php
// Reinstall only after the disposable SQLite file has been deliberately removed.
$root = realpath(dirname(__DIR__) . '/.runtime/wordpress');
if (!$root || strpos(wp_path_placeholder($root), '/.runtime/wordpress') === false) { throw new RuntimeException('Disposable runtime required'); }
define('WP_INSTALLING', true);
require $root . '/wp-load.php';
if (is_blog_installed() || get_option('home') && get_option('home') !== 'http://127.0.0.1:8877') { throw new RuntimeException('Fresh local database required'); }
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
add_filter('pre_wp_mail', '__return_false');
wp_install('Pixel M1 disposable QA', 'pixel_qa', 'qa@example.invalid', false, '', bin2hex(random_bytes(24)), 'en_US');
$result = activate_plugin('wp-seed-pixel/wp-seed-pixel.php');
if (is_wp_error($result)) { throw new RuntimeException('Activation failed'); }
echo "Disposable M1 database installed.\n";
function wp_path_placeholder($path) { return str_replace('\\', '/', $path); }
