<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
$settings = (array) get_option('wp_seed_pixel_settings', array());
// Recovery is independent of derivative cleanup, including after plugin removal.
global $wpdb;
$pending_master = $wpdb->get_var("SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key='_seed_pixel_master_state' LIMIT 1");
if ($pending_master) { return; }
$jobs_table = $wpdb->prefix . 'seed_pixel_jobs';
$jobs_exist = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($jobs_table)));
if ($jobs_exist && $wpdb->get_var("SELECT id FROM $jobs_table WHERE kind IN ('replace','retire') LIMIT 1")) { return; }
if (empty($settings['cleanup_on_uninstall']) || is_multisite()) {
    return;
}
if (!defined('WP_SEED_PIXEL_VERSION')) {
    define('WP_SEED_PIXEL_VERSION', '0.1.0');
}
require_once __DIR__ . '/includes/class-files.php';
require_once __DIR__ . '/includes/class-store.php';
global $wpdb;
$ids = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key='_seed_pixel_job'");
foreach ($ids as $id) {
    $result = WP_Seed_Pixel_Store::cleanup((int) $id);
    if (is_wp_error($result)) {
        return; // Leave recovery information rather than clearing evidence of a failed cleanup.
    }
    delete_post_meta((int) $id, '_seed_pixel_job');
    delete_post_meta((int) $id, '_seed_pixel_auto_attempts');
}
delete_option('wp_seed_pixel_settings');
delete_option('wp_seed_pixel_batch');
