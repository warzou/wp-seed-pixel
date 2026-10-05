<?php
require __DIR__ . '/runtime.php';
if (getenv('PIXEL_M3_ROOT') !== '/home/warzy/.cache/wp-seed-pixel-m3-environment') {
    throw new RuntimeException('Owned disposable runtime required.');
}
global $wpdb;
if (DB_NAME !== 'pixel_m3') { throw new RuntimeException('Disposable database required.'); }
$wpdb->query("UPDATE {$wpdb->posts} SET post_status='trash' WHERE post_type='attachment'");
wp_cache_flush();
echo "Historical synthetic graph preserved outside the active M2 fixture pool.\n";
