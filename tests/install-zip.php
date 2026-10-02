<?php
require __DIR__ . '/runtime.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
if (!defined('FS_METHOD')) {
    define('FS_METHOD', 'direct');
}
$plugin = 'wp-seed-pixel/wp-seed-pixel.php';
update_option('wp_seed_pixel_settings', array('automatic' => false, 'preset' => 'web', 'cleanup_on_uninstall' => false), false);
deactivate_plugins($plugin);
$deleted = delete_plugins(array($plugin));
if (is_wp_error($deleted) || is_dir(WP_PLUGIN_DIR . '/wp-seed-pixel')) {
    throw new RuntimeException('Disposable plugin removal failed.');
}
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
$skin = new Automatic_Upgrader_Skin();
$installer = new Plugin_Upgrader($skin);
$installed = $installer->install(dirname(__DIR__) . '/dist/wp-seed-pixel-' . (isset($argv[1]) ? $argv[1] : '0.2.0') . '.zip');
if (is_wp_error($installed) || $installed !== true) {
    throw new RuntimeException('WordPress ZIP installation failed.');
}
$active = activate_plugin($plugin);
if (is_wp_error($active)) {
    throw new RuntimeException('Installed ZIP activation failed.');
}
echo wp_json_encode(array('zip_installed_by_wordpress' => true, 'plugin' => $installer->plugin_info(), 'version' => get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin, false, false)['Version']), JSON_PRETTY_PRINT);
