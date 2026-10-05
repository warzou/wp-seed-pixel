<?php
require __DIR__ . '/runtime.php';
$fixture = json_decode(file_get_contents(dirname(__DIR__) . '/reports/storage-m1/integration.json'), true);
$id = (int) $fixture['fixtures']['normal']; $checks = array();
function edge_check($name, $pass) { global $checks; $checks[$name] = (bool) $pass; if (!$pass) { throw new RuntimeException($name); } }
function edge_state($id) {
    global $wpdb;
    return array($wpdb->get_results("SELECT * FROM $wpdb->posts ORDER BY ID", ARRAY_A), $wpdb->get_results("SELECT * FROM $wpdb->postmeta ORDER BY meta_id", ARRAY_A), hash_file('sha256', get_attached_file($id)), get_option('wp_seed_pixel_settings'), get_option(WP_Seed_Pixel_Batch::OPTION));
}
$before = edge_state($id);
$meta = get_post_meta($id, '_wp_attachment_metadata', true);
$baseline = WP_Seed_Pixel_Analyzer::analyze($id);
edge_check('preview local and nonempty', strpos($baseline['preview_url'], 'http://127.0.0.1:8877/') === 0);
edge_check('source-only analyzer contains no processing or canonical mutators', !preg_match('/(?:wp_seed_pixel_optimize|wp_update_attachment_metadata|update_post_meta|unlink|imagejpeg|imagepng|mkdir)\s*\(/', file_get_contents(dirname(__DIR__) . '/includes/class-analyzer.php')));
edge_check('V1 analyzer does not invalidate derivative engine', WP_SEED_PIXEL_VERSION === '0.4.0' && WP_SEED_PIXEL_ENGINE_VERSION === '0.3.1');
$bounded = $meta; $bounded['sizes'] = array_fill(0, 300, array('file' => 'not-present.jpg', 'width' => 10, 'height' => 10));
wp_update_attachment_metadata($id, $bounded);
$read_before = edge_state($id); $limited = WP_Seed_Pixel_Analyzer::analyze($id);
edge_check('malformed huge relationship graph bounded and disclosed', in_array('inventory_limit', $limited['issues'], true) && count($limited['files']) <= 256);
edge_check('bounded analysis still zero canonical mutation', edge_state($id) === $read_before);
wp_update_attachment_metadata($id, $meta);
$bad_original = $meta; $bad_original['original_image'] = 'm1-absent-original.jpg'; wp_update_attachment_metadata($id, $bad_original);
$missing = WP_Seed_Pixel_Analyzer::analyze($id);
edge_check('missing original flagged without invented reclaim', $missing['health'] === 'missing' && $missing['storage']['potential_original_bytes'] === 0);
wp_update_attachment_metadata($id, $meta);
$link = dirname(get_attached_file($id)) . '/m1-hardlink.jpg';
if (link(get_attached_file($id), $link)) {
    $hard_id = wp_insert_attachment(array('post_title' => 'Synthetic M1 hardlink', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'), $link);
    $hard_meta = array('file' => _wp_relative_upload_path($link), 'width' => 1280, 'height' => 800, 'original_image' => basename(get_attached_file($id)), 'sizes' => array());
    wp_update_attachment_metadata($hard_id, $hard_meta);
    $hard = WP_Seed_Pixel_Analyzer::analyze($hard_id);
    edge_check('two hardlink names one physical identity', count($hard['files']) === 1 && count($hard['files'][0]['relative_paths']) === 2 && $hard['storage']['unique_logical_bytes'] === filesize($link));
    edge_check('hardlink review not removal permission', $hard['health'] === 'needs_review' && $hard['storage']['potential_original_bytes'] === 0);
    unset($hard_meta['original_image']); wp_update_attachment_metadata($hard_id, $hard_meta); wp_delete_attachment($hard_id, true);
} else { throw new RuntimeException('Hardlink fixture unavailable'); }
$job_id = (int) $fixture['large_scan'];
$stale_probe = $meta; $stale_probe['pixel_fixture_revision'] = 1;
wp_update_attachment_metadata($id, $stale_probe);
$page = WP_Seed_Pixel_Scan::results($job_id);
edge_check('changed graph marked stale rather than silently refreshed', in_array(true, array_column($page['items'], 'stale'), true));
wp_update_attachment_metadata($id, $meta);
// Keep the completed fixture scan current for visual QA; only own empty cancelled job is removed.
$current = WP_Seed_Pixel_Scan::current();
if ($current['status'] === 'cancelled' && $current['done'] === 0) { $wpdb->delete($wpdb->prefix . 'seed_pixel_jobs', array('id' => $current['id'], 'kind' => 'scan')); }
edge_check('canonical source metadata restored after fixture probes', get_post_meta($id, '_wp_attachment_metadata', true) === $meta && hash_file('sha256', get_attached_file($id)) === $before[2]);
$fresh_before = edge_state($id);
foreach ($fixture['fixtures'] as $fixture_id) { WP_Seed_Pixel_Analyzer::analyze((int) $fixture_id); }
edge_check('latest analyzer zero canonical mutation', edge_state($id) === $fresh_before);
file_put_contents(dirname(__DIR__) . '/reports/storage-m1/edge.json', wp_json_encode(array('passed' => count($checks), 'checks' => $checks), JSON_PRETTY_PRINT));
echo count($checks) . " edge checks PASS\n";
