<?php
require __DIR__ . '/m3-runtime.php';
global $wpdb;
if (!WP_Seed_Pixel_Master_Storage::enabled()) { throw new RuntimeException('Disposable M3 environment required'); }
$tables = array();
foreach (array($wpdb->postmeta, WP_Seed_Pixel_Job_Store::table('jobs'), WP_Seed_Pixel_Job_Store::table('items')) as $table) {
    $tables[$table] = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $table));
    if ($tables[$table] !== 'InnoDB') { throw new RuntimeException('M3 requires actual InnoDB tables'); }
}
$report = array('php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'wordpress' => get_bloginfo('version'),
    'database_adapter' => get_class($wpdb), 'database' => $wpdb->get_var('SELECT VERSION()'),
    'client' => mysqli_get_client_info(), 'tables' => $tables, 'sqlite_dropin' => file_exists(WP_CONTENT_DIR . '/db.php'),
    'os' => PHP_OS_FAMILY, 'gd' => gd_info()['GD Version'], 'imagick_extension' => phpversion('imagick'),
    'imagemagick' => Imagick::getVersion()['versionString'], 'local_only' => home_url() === 'http://127.0.0.1:8877',
    'legacy_m1_label' => 'Historical storage-analyzer.php hardcodes SQLite Integration. This query is the actual database evidence for this Linux run.');
file_put_contents(dirname(__DIR__) . '/reports/storage-m3/environment.json', wp_json_encode($report, JSON_PRETTY_PRINT));
echo wp_json_encode($report, JSON_PRETTY_PRINT) . "\n";
