<?php
require __DIR__ . '/runtime.php';
function readonly_snapshot() {
    global $wpdb;
    $root = wp_upload_dir(null, false)['basedir']; $hashes = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) { if ($file->isFile()) { $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname()); } }
    ksort($hashes);
    return array($wpdb->get_results("SELECT * FROM $wpdb->posts ORDER BY ID", ARRAY_A), $wpdb->get_results("SELECT * FROM $wpdb->postmeta ORDER BY meta_id", ARRAY_A), $hashes, get_option('wp_seed_pixel_settings'), get_option(WP_Seed_Pixel_Batch::OPTION));
}
$before = readonly_snapshot(); $scan = WP_Seed_Pixel_Scan::start();
if (is_wp_error($scan)) { throw new RuntimeException($scan->get_error_message()); }
$start = microtime(true);
do { $scan = WP_Seed_Pixel_Scan::step($scan['id']); if (is_wp_error($scan)) { throw new RuntimeException($scan->get_error_message()); } } while ($scan['status'] === 'running');
$checks = array('latest candidate canonical invariance' => readonly_snapshot() === $before, 'latest 2008 graph complete' => $scan['done'] === 2008, 'latest results fresh' => !in_array(true, array_column(WP_Seed_Pixel_Scan::results($scan['id'])['items'], 'stale'), true), 'no reclaimed space claimed' => $scan['storage']['reclaimed_bytes'] === 0);
$checks['reason codes unique'] = true;
foreach (WP_Seed_Pixel_Scan::results($scan['id'])['items'] as $item) { if (count($item['issues']) !== count(array_unique($item['issues']))) { $checks['reason codes unique'] = false; } }
file_put_contents(dirname(__DIR__) . '/reports/storage-m1/rescan.json', wp_json_encode(array('checks' => $checks, 'scan' => $scan, 'seconds' => microtime(true) - $start), JSON_PRETTY_PRINT));
echo wp_json_encode($checks, JSON_PRETTY_PRINT);
exit(in_array(false, $checks, true) ? 1 : 0);
