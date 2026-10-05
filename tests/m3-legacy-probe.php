<?php
require __DIR__ . '/m3-runtime.php';
$out = array();
$gd = static function () { return array('WP_Image_Editor_GD'); };
foreach (array('default', 'gd') as $backend) {
    if ($backend === 'gd') { add_filter('wp_image_editors', $gd); }
    foreach (array('gps.jpg', 'orientation-2.jpg', 'orientation-5.jpg', 'orientation-7.jpg', 'orientation-8.jpg') as $fixture) {
        $id = m3_fixture($fixture); $p = wp_get_original_image_path($id); $before = hash_file('sha256', $p);
        $r = wp_seed_pixel_optimize($id, 'balanced');
        $out[$backend][$fixture] = array('outcome' => is_wp_error($r) ? $r->get_error_code() : $r['status'], 'source_preserved' => hash_file('sha256', $p) === $before);
    }
    if ($backend === 'gd') { remove_filter('wp_image_editors', $gd); }
}
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-m3');
file_put_contents(dirname(__DIR__) . '/reports/storage-m3/legacy-backends.json', wp_json_encode($out, JSON_PRETTY_PRINT));
echo wp_json_encode($out);
