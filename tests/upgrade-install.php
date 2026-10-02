<?php
require __DIR__ . '/runtime.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
define('FS_METHOD', 'direct');
$plugin = 'wp-seed-pixel/wp-seed-pixel.php';
deactivate_plugins($plugin);
$upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
$result = $upgrader->run(array('package' => dirname(__DIR__) . '/dist/wp-seed-pixel-0.2.0.zip', 'destination' => WP_PLUGIN_DIR, 'clear_destination' => true, 'clear_working' => true, 'hook_extra' => array('plugin' => $plugin, 'type' => 'plugin', 'action' => 'update')));
if (is_wp_error($result) || !$result || get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false)['Version'] !== '0.2.0') {
    throw new RuntimeException('Final ZIP update failed.');
}
$active = activate_plugin($plugin);
if (is_wp_error($active)) { throw new RuntimeException('Updated ZIP activation failed.'); }
echo "WordPress Upgrader replaced installed 0.1.0 with the final 0.2.0 ZIP.\n";
