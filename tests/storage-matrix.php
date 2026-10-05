<?php
require __DIR__ . '/runtime.php';
set_exception_handler(static function (Throwable $error) { fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . ' at ' . basename($error->getFile()) . ':' . $error->getLine() . "\n"); exit(1); });
$checks = array(); $ids = array();
function matrix_check($name, $pass) { global $checks; $checks[$name] = (bool) $pass; if (!$pass) { throw new RuntimeException($name); } }
function matrix_state() {
    global $wpdb;
    $hashes = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(wp_upload_dir(null, false)['basedir'], FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && !$file->isLink()) { $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($hashes);
    return array($wpdb->get_results("SELECT * FROM $wpdb->posts ORDER BY ID", ARRAY_A), $wpdb->get_results("SELECT * FROM $wpdb->postmeta ORDER BY meta_id", ARRAY_A), $hashes, get_option('wp_seed_pixel_settings'), get_option(WP_Seed_Pixel_Batch::OPTION));
}
try {
    foreach (array('animated.gif', 'corrupt.jpg', 'srgb-icc.jpg', 'orientation-6.jpg') as $name) {
        $id = pixel_fixture($name, false); $ids[] = $id;
        $before = matrix_state(); $result = WP_Seed_Pixel_Analyzer::analyze($id);
        matrix_check($name . ' zero canonical mutation', matrix_state() === $before);
        if ($name === 'animated.gif') { matrix_check('GIF inventoried with animation honestly unknown', $result['image']['format'] === 'gif' && $result['image']['animation'] === 'unknown' && in_array('unsupported_format', $result['issues'], true)); }
        if ($name === 'corrupt.jpg') { matrix_check('malformed header unsupported, not healthy', $result['image']['format'] === 'unknown' && $result['health'] !== 'healthy'); }
        if ($name === 'srgb-icc.jpg') { matrix_check('ICC reported, never transformed', $result['image']['icc'] === 'present_unclassified'); }
        if ($name === 'orientation-6.jpg') { matrix_check('EXIF orientation read without rotation', $result['image']['orientation'] === 6); }
    }
    $id = pixel_fixture('small.jpg'); $ids[] = $id;
    $source = get_attached_file($id, true);
    $filtered = static function ($path, $attachment_id) use ($id) { return $attachment_id === $id ? 's3://synthetic-bucket/private.jpg' : $path; };
    add_filter('get_attached_file', $filtered, 10, 2);
    try {
        $before = matrix_state(); $external = WP_Seed_Pixel_Analyzer::analyze($id);
        matrix_check('offload filter flagged without remote inspection', in_array('filtered_storage', $external['issues'], true) && matrix_state() === $before);
    } finally { remove_filter('get_attached_file', $filtered, 10); }
    $root = wp_normalize_path(wp_upload_dir(null, false)['basedir']);
    $junction = $root . '/m1-link-test';
    matrix_check('real junction fixture available', is_dir($junction));
    update_post_meta($id, '_seed_pixel_manifest', array('files' => array(array('path' => $junction . '/small.jpg'))));
    $before = matrix_state(); $linked = WP_Seed_Pixel_Analyzer::analyze($id);
    matrix_check('reparse point outside uploads rejected without mutation', (in_array('symlink_storage', $linked['issues'], true) || in_array('external_storage', $linked['issues'], true)) && matrix_state() === $before);
    delete_post_meta($id, '_seed_pixel_manifest');
    $site_root = $root . '/sites/2'; $meta = get_post_meta($id, '_wp_attachment_metadata', true);
    $relative = _wp_relative_upload_path($source); $local = $site_root . '/' . $relative;
    wp_mkdir_p(dirname($local)); copy($source, $local);
    foreach ($meta['sizes'] as $size) { copy(dirname($source) . '/' . $size['file'], dirname($local) . '/' . $size['file']); }
    $subsite = static function ($uploads) use ($site_root) { $uploads['basedir'] = $site_root; $uploads['path'] = $site_root; return $uploads; };
    add_filter('upload_dir', $subsite);
    try {
        $before = matrix_state(); $sub = WP_Seed_Pixel_Analyzer::analyze($id);
        matrix_check('subsite upload root discovered without fixed root assumption', !is_wp_error($sub) && $sub['files'][0]['relative_path'] === $relative && $sub['health'] === 'healthy');
        matrix_check('subsite-root analysis zero canonical mutation', matrix_state() === $before);
    } finally { remove_filter('upload_dir', $subsite); }
    unlink($local);
    foreach ($meta['sizes'] as $size) { unlink(dirname($local) . '/' . $size['file']); }
    file_put_contents(dirname(__DIR__) . '/reports/storage-m1/matrix.json', wp_json_encode(array('passed' => count($checks), 'checks' => $checks), JSON_PRETTY_PRINT));
    echo count($checks) . " focused matrix checks PASS\n";
} finally {
    foreach ($ids as $id) { delete_post_meta($id, '_seed_pixel_manifest'); wp_delete_attachment($id, true); }
}
