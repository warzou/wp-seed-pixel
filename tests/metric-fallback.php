<?php
// Branch-contract unit test only; this is not an Imagick runtime certification.
$root = dirname(__DIR__);
$stage = $root . '/.runtime/fallback-stage.jpg';
$prepared = $root . '/.runtime/fallback-candidate.jpg';
$source = $root . '/.runtime/fixtures/rgb.jpg';
if (isset($argv[1]) && $argv[1] === 'prepare') {
    require __DIR__ . '/runtime.php';
    $editor = wp_get_image_editor($source);
    $editor->resize(640, 640, false);
    $editor->set_quality(94);
    $saved = $editor->save($prepared, 'image/jpeg');
    if (is_wp_error($saved)) { throw new RuntimeException('Fallback unit fixture failed.'); }
    exit;
}
if (function_exists('imagecreatefromjpeg')) { throw new RuntimeException('Run this branch test with php -n, without GD.'); }
define('ABSPATH', 'local-unit-test');
if (!function_exists('is_wp_error')) {
class WP_Error {}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_normalize_path($value) { return str_replace('\\', '/', $value); }
final class Pixel_Fallback_Editor_Stub {
    private $quality;
    public function maybe_exif_rotate() { return true; }
    public function get_size() { return array('width' => 2400, 'height' => 1600); }
    public function resize($w, $h, $crop) { return true; }
    public function set_quality($q) { $this->quality = $q; return true; }
    public function get_quality() { return $this->quality; }
    public function save($path, $mime) { global $prepared; copy($prepared, $path); return array('path' => $path); }
}
function wp_get_image_editor($source) { return new Pixel_Fallback_Editor_Stub(); }
}
require $root . '/includes/class-files.php';
require $root . '/includes/class-adaptive.php';
$r = WP_Seed_Pixel_Adaptive::select($source, array('width' => 640, 'height' => 640, 'quality' => 94), $stage);
$tests = array(
    array('test' => 'No GD metric function in branch test', 'status' => !function_exists('imagecreatefromjpeg') ? 'PASS' : 'FAIL'),
    array('test' => 'Unavailable metric selects one Q94 candidate', 'status' => !is_wp_error($r) && $r['quality'] === 94 && $r['candidates'] === 1 ? 'PASS' : 'FAIL'),
    array('test' => 'Unavailable metric explicitly disclosed, not fabricated', 'status' => !is_wp_error($r) && $r['metric'] === null && strpos($r['reason'], 'unavailable') !== false ? 'PASS' : 'FAIL'),
    array('test' => 'Unit fallback candidate remains derived', 'status' => !is_wp_error($r) && $r['kind'] === 'derived' ? 'PASS' : 'FAIL'),
);
unlink($stage); unlink($prepared);
file_put_contents($root . '/reports/adaptive/data/fallback-unit-tests.json', json_encode($tests, JSON_PRETTY_PRINT));
echo json_encode(array_count_values(array_column($tests, 'status')), JSON_PRETTY_PRINT);
exit(in_array('FAIL', array_column($tests, 'status'), true) ? 1 : 0);
