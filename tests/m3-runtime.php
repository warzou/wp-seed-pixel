<?php
require __DIR__ . '/runtime.php';
error_reporting(E_ALL & ~E_DEPRECATED);
$schema = WP_Seed_Pixel_Job_Store::install();
if (is_wp_error($schema)) { throw new RuntimeException('Disposable job schema: ' . $schema->get_error_code()); }
function m3_fixture($name, $native = true) {
    $sub = '/m3-' . bin2hex(random_bytes(6));
    $filter = static function ($u) use ($sub) { $u['path'] .= $sub; $u['url'] .= $sub; $u['subdir'] .= $sub; return $u; };
    add_filter('upload_dir', $filter);
    try { return pixel_fixture($name, $native); }
    finally { remove_filter('upload_dir', $filter); }
}
