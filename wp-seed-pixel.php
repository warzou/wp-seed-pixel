<?php
/**
 * Plugin Name: WP Seed Pixel
 * Description: Local, reversible JPEG derivatives with immutable masters and resumable batches.
 * Version: 0.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: WP Seed
 * License: GPL-2.0-or-later
 * Text Domain: wp-seed-pixel
 */
defined('ABSPATH') || exit;
define('WP_SEED_PIXEL_VERSION', '0.1.0');
define('WP_SEED_PIXEL_FILE', __FILE__);
foreach (array('presets', 'files', 'store', 'engine', 'batch', 'admin', 'plugin') as $component) {
    require_once __DIR__ . '/includes/class-' . $component . '.php';
}
require_once __DIR__ . '/includes/api.php';
add_action('plugins_loaded', array('WP_Seed_Pixel_Plugin', 'boot'));
register_activation_hook(__FILE__, array('WP_Seed_Pixel_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('WP_Seed_Pixel_Plugin', 'deactivate'));
