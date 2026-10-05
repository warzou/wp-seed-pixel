<?php
require __DIR__ . '/m4-runtime.php';
global $wpdb;
$checks = array();
$engines = array();
foreach (array($wpdb->posts, $wpdb->postmeta, WP_Seed_Pixel_Job_Store::table('jobs'), WP_Seed_Pixel_Job_Store::table('items')) as $table) {
    $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
    $engines[$table] = $engine; m4_check(strtolower($engine) === 'innodb', $table . ' InnoDB');
}
$flush = $wpdb->get_var('SELECT @@innodb_flush_log_at_trx_commit');
m4_check($flush === '1', 'local MariaDB commit log flush configured');
m4_check(WP_Seed_Pixel_Master_Storage::sync_directory(WP_SEED_PIXEL_RECOVERY_ROOT), 'actual ext4 directory fsync supported');
m4_report('environment', array('php' => PHP_VERSION, 'wordpress' => get_bloginfo('version'), 'os' => PHP_OS, 'db' => $wpdb->db_version(), 'engines' => $engines,
    'innodb_flush_log_at_trx_commit' => $flush, 'gd' => gd_info(), 'imagick' => Imagick::getVersion(), 'littlecms' => WP_Seed_Pixel_Color::available(),
    'private_mode' => decoct(fileperms(WP_SEED_PIXEL_RECOVERY_ROOT) & 0777), 'power_loss_tested' => false));
