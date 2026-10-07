<?php
$root = dirname(__DIR__) . '/.runtime/wordpress';
if (!str_contains(realpath($root), 'wp-seed-pixel-m3-environment')) { throw new RuntimeException('Disposable tree required'); }
require $root . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user(1);
$result = activate_plugin('wp-seed-pixel/wp-seed-pixel.php');
if (is_wp_error($result)) { throw new RuntimeException($result->get_error_code()); }
echo json_encode(array('active' => is_plugin_active('wp-seed-pixel/wp-seed-pixel.php'), 'version' => WP_SEED_PIXEL_VERSION));
