<?php
/**
 * Plugin Name: WP Seed Pixel
 * Description: Local image optimization, storage analysis and verified recovery jobs.
 * Version: 0.5.1
 * Build: 0.5.1
 * Update URI: false
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: WP Seed
 * License: GPL-2.0-or-later
 * Text Domain: wp-seed-pixel
 */
defined('ABSPATH') || exit;
define('WP_SEED_PIXEL_VERSION', '0.5.1');
define('WP_SEED_PIXEL_BUILD', '0.5.1');
// The existing JPEG/PNG engines retain their certified generation identity.
define('WP_SEED_PIXEL_ENGINE_VERSION', '0.3.1');
define('WP_SEED_PIXEL_FILE', __FILE__);
foreach (array('presets', 'authority', 'files', 'color', 'store', 'adaptive', 'engine', 'batch', 'media', 'admin', 'analyzer', 'scan', 'storage-admin', 'policy', 'job-store', 'job-executor', 'png-processor', 'master-processor', 'master-adapter', 'storage-budget', 'master-storage', 'master-executor', 'quarantine', 'original-executor', 'jobs', 'jobs-admin', 'future-uploads', 'plugin') as $component) {
    require_once __DIR__ . '/includes/class-' . $component . '.php';
}
require_once __DIR__ . '/includes/api.php';
require_once __DIR__ . '/includes/class-quarantine-admin.php';
require_once __DIR__ . '/includes/class-bulk-admin.php';
require_once __DIR__ . '/includes/class-host-admin.php';
require_once __DIR__ . '/includes/class-recovery-setup.php';
WP_Seed_Pixel_Recovery_Setup::apply();
require_once __DIR__ . '/includes/class-selected-admin.php';
require_once __DIR__ . '/includes/class-workflow.php';
require_once __DIR__ . '/includes/class-format-processor.php';
require_once __DIR__ . '/includes/class-format-conversion.php';
require_once __DIR__ . '/includes/class-format-admin.php';
WP_Seed_Pixel_Format_Admin::boot();
add_action('plugins_loaded', array('WP_Seed_Pixel_Host_Admin', 'boot'));
add_action('plugins_loaded', array('WP_Seed_Pixel_Bulk_Admin', 'boot'));
add_action('plugins_loaded', array('WP_Seed_Pixel_Quarantine_Admin', 'boot'));
require_once __DIR__ . '/includes/class-i18n.php';
require_once __DIR__ . '/includes/class-updater.php';
WP_Seed_Pixel_Updater::boot();
add_action('plugins_loaded', array('WP_Seed_Pixel_Plugin', 'boot'));
register_activation_hook(__FILE__, array('WP_Seed_Pixel_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('WP_Seed_Pixel_Plugin', 'deactivate'));
