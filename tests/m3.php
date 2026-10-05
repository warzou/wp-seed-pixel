<?php
require __DIR__ . '/m3-runtime.php';
error_reporting(E_ALL & ~E_DEPRECATED);
global $wpdb;
if (!WP_Seed_Pixel_Master_Storage::enabled()) { throw new RuntimeException('Disposable Linux M3 runtime required'); }
$checks = array(); $results = array();
function m3_check($value, $name) {
    global $checks;
    $checks[$name] = (bool) $value;
    if (!$value) { throw new RuntimeException('FAIL: ' . $name); }
}
function m3_job($id, $edge = 0, $quota = 1073741824) {
    $p = array('master' => 'replace_verified');
    if ($edge) { $p['dimensions'] = 'max_edge'; $p['max_edge'] = $edge; }
    $job = WP_Seed_Pixel_Jobs::replace_one($id, $p, $quota);
    if (is_wp_error($job)) { throw new RuntimeException('Plan: ' . $job->get_error_code()); }
    return (int) $job['id'];
}
function m3_item($job) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation'", $job), ARRAY_A);
}
function m3_full_fixture($name) {
    $id = m3_fixture($name, false);
    $path = get_attached_file($id); $i = getimagesize($path);
    wp_update_attachment_metadata($id, array('file' => _wp_relative_upload_path($path), 'width' => $i[0], 'height' => $i[1], 'filesize' => filesize($path), 'sizes' => array(), 'foreign' => array('keep' => 'yes')));
    return $id;
}
$start = microtime(true);
foreach (array('same' => array('m3-detail.jpg', 0), 'downsize' => array('m3-detail.jpg', 1920), 'small' => array('m3-small.jpeg', 1000), 'rights' => array('m3-rights.jpg', 0)) as $label => $case) {
    $id = m3_full_fixture($case[0]); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
    $url = wp_get_attachment_url($id); $direct = wp_insert_post(array('post_title' => 'Synthetic direct reference', 'post_status' => 'publish', 'post_content' => '<img src="' . esc_url($url) . '">' . wp_get_attachment_image($id, 'full')));
    $job = m3_job($id, $case[1]); $status = WP_Seed_Pixel_Jobs::step($job); $item = m3_item($job);
    m3_check(!is_wp_error($status) && $item['stage'] === 'retained', "$label replacement retained " . ($item['error_code'] ?? ''));
    $r = WP_Seed_Pixel_Master_Storage::load(WP_Seed_Pixel_Master_Storage::directory($item), $item);
    if (is_wp_error($r)) { throw new RuntimeException('Retained journal read: ' . $r->get_error_code()); }
    m3_check($r['candidate']['bytes'] < $before['bytes'] && $r['phase'] === 'retained', "$label verified savings");
    m3_check($url === wp_get_attachment_url($id), "$label URL identical");
    m3_check(get_post_meta($id, '_wp_attachment_metadata', true)['foreign'] === array('keep' => 'yes'), "$label foreign native key retained");
    m3_check(get_post($direct)->post_content === '<img src="' . esc_url($url) . '">' . wp_get_attachment_image($id, 'full') || strpos(get_post($direct)->post_content, $url) !== false, "$label direct content unchanged URL");
    $resp = rest_do_request(new WP_REST_Request('GET', '/wp/v2/media/' . $id));
    m3_check($resp->get_status() === 200 && $resp->get_data()['source_url'] === $url, "$label REST native");
    m3_check($r['candidate']['width'] <= $before['width'] && $r['candidate']['height'] <= $before['height'], "$label no upscale");
    $files = glob(WP_Seed_Pixel_Master_Storage::directory($item) . '/*');
    m3_check(count($files) === 2, "$label only escrow and journal remain");
    $active = $r['candidate']['bytes']; $recovery = filesize(WP_Seed_Pixel_Master_Storage::directory($item) . '/recovery.jpg');
    m3_check($recovery === $before['bytes'] && $active + $recovery > $before['bytes'] && $status['reclaimed_bytes'] === 0, "$label honest physical storage");
    $after_hash = hash_file('sha256', get_attached_file($id));
    WP_Seed_Pixel_Jobs::step($job);
    m3_check(hash_file('sha256', get_attached_file($id)) === $after_hash && m3_item($job)['revision'] === $item['revision'], "$label complete no recompress");
    $out = dirname(__DIR__) . '/reports/storage-m3/visual'; wp_mkdir_p($out);
    copy(WP_Seed_Pixel_Master_Storage::directory($item) . '/recovery.jpg', $out . '/' . $label . '-before.jpg');
    copy(get_attached_file($id), $out . '/' . $label . '-after.jpg');
    $results[$label] = array('attachment_id' => $id, 'job' => $job, 'url' => $url, 'before_bytes' => $before['bytes'], 'candidate' => $r['candidate'], 'peak_budget' => $r['peak_budget']);
    $restored = WP_Seed_Pixel_Jobs::restore_master($job);
    m3_check(!is_wp_error($restored) && m3_item($job)['stage'] === 'rolled_back', "$label rollback terminal");
    m3_check(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, "$label exact file and native rollback");
    m3_check(!is_wp_error(WP_Seed_Pixel_Jobs::restore_master($job)), "$label rollback idempotent");
    if ($label === 'same') {
        $second = m3_job($id); WP_Seed_Pixel_Jobs::step($second);
        $late = WP_Seed_Pixel_Jobs::restore_master($job);
        m3_check(m3_item($second)['stage'] === 'retained' && m3_item($job)['stage'] === 'rolled_back', 'new generation has distinct owner');
        WP_Seed_Pixel_Jobs::restore_master($second);
    }
}

$source_quality = static function () { return 100; };
add_filter('wp_editor_set_quality', $source_quality);
$id = m3_fixture('m3-large.jpg');
remove_filter('wp_editor_set_quality', $source_quality);
$before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
$original = wp_get_original_image_path($id); $original_hash = hash_file('sha256', $original);
$job = m3_job($id); WP_Seed_Pixel_Jobs::step($job); $item = m3_item($job);
m3_check($item['stage'] === 'retained', 'scaled operational replacement retained ' . $item['error_code']);
m3_check($original !== get_attached_file($id) && hash_file('sha256', $original) === $original_hash, 'scaled preserved original byte exact');
m3_check(wp_get_attachment_metadata($id)['original_image'] === maybe_unserialize($before['rows']['_wp_attachment_metadata'][0])['original_image'], 'scaled original_image unchanged');
m3_check((bool) wp_get_attachment_image_srcset($id, 'full'), 'scaled srcset native');
$r = WP_Seed_Pixel_Master_Storage::load(WP_Seed_Pixel_Master_Storage::directory($item), $item);
copy(get_attached_file($id), dirname(__DIR__) . '/reports/storage-m3/visual/scaled-after.jpg');
copy(WP_Seed_Pixel_Master_Storage::directory($item) . '/recovery.jpg', dirname(__DIR__) . '/reports/storage-m3/visual/scaled-before.jpg');
$results['scaled'] = array('attachment_id' => $id, 'job' => $job, 'url' => wp_get_attachment_url($id), 'candidate' => $r['candidate']);
WP_Seed_Pixel_Jobs::restore_master($job);
m3_check(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'scaled exact rollback');

foreach (array('m3-light.jpg' => 'skipped', 'm3-orientation.jpg' => 'needs_review') as $name => $stage) {
    $id = m3_full_fixture($name); $hash = hash_file('sha256', get_attached_file($id));
    $job = m3_job($id); WP_Seed_Pixel_Jobs::step($job); $item = m3_item($job);
    m3_check($item['stage'] === $stage, "$name safe nonreplacement " . $item['error_code']);
    m3_check(hash_file('sha256', get_attached_file($id)) === $hash, "$name untouched");
}
$id = m3_full_fixture('m3-texture.jpg'); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id); $job = m3_job($id);
WP_Seed_Pixel_Jobs::step($job); $item = m3_item($job);
if ($item['stage'] === 'retained') {
    $r = WP_Seed_Pixel_Master_Storage::load(WP_Seed_Pixel_Master_Storage::directory($item), $item);
    m3_check($r['candidate']['metric']['ssim'] >= 0.995 && $r['candidate']['metric']['psnr'] >= 40 && $r['candidate']['bytes'] <= $before['bytes'] * 0.95, 'difficult texture accepted only above master quality and gain floors');
    WP_Seed_Pixel_Jobs::restore_master($job);
} else { m3_check(in_array($item['stage'], array('skipped', 'needs_review'), true), 'difficult texture fails closed'); }
m3_check(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'difficult texture preserved or exact rollback');
$id = m3_full_fixture('m3-detail.jpg'); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id); $job = m3_job($id, 0, 1);
WP_Seed_Pixel_Jobs::step($job);
m3_check(m3_item($job)['error_code'] === 'QUOTA_UNKNOWN' && WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'unknown capacity no file mutation');
$id = m3_full_fixture('m3-detail.jpg'); $job = m3_job($id); update_post_meta($id, '_wp_attachment_metadata', array_merge(wp_get_attachment_metadata($id), array('foreign' => 'external')));
WP_Seed_Pixel_Jobs::step($job);
m3_check(m3_item($job)['error_code'] === 'SOURCE_CHANGED', 'stale plan metadata blocked');
$id = m3_full_fixture('m3-detail.jpg'); $path = get_attached_file($id); $other = wp_insert_attachment(array('post_title' => 'Shared synthetic', 'post_mime_type' => 'image/jpeg'), $path);
wp_update_attachment_metadata($other, wp_get_attachment_metadata($id));
m3_check(is_wp_error(WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824)), 'shared path refused');
$id = m3_full_fixture('m3-detail.jpg'); $path = get_attached_file($id); link($path, dirname($path) . '/hardlink-m3.jpg');
m3_check(is_wp_error(WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824)), 'hardlink refused');
unlink(dirname($path) . '/hardlink-m3.jpg');
$id = m3_full_fixture('m3-detail.jpg'); $meta = wp_get_attachment_metadata($id); $meta['sizes']['bigger'] = array('file' => basename(get_attached_file($id)), 'width' => 2400, 'height' => 1600, 'mime-type' => 'image/jpeg'); wp_update_attachment_metadata($id, $meta);
$job = m3_job($id, 1000); WP_Seed_Pixel_Jobs::step($job);
m3_check(m3_item($job)['error_code'] === 'DIMENSION_CONFLICT', 'required size larger than new master refused');
m3_check(!file_exists(WP_Seed_Pixel_Master_Storage::directory(m3_item($job)) . '/candidate.jpg'), 'dimension conflict cleans only owned uncommitted candidate');
wp_set_current_user(0);
m3_check(is_wp_error(WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824)), 'unauthorized replacement denied');
wp_set_current_user(1);
$report = array('checks' => $checks, 'passed' => count($checks), 'results' => $results, 'elapsed_seconds' => microtime(true) - $start,
    'environment' => array('php' => PHP_VERSION, 'wp' => get_bloginfo('version'), 'db' => $wpdb->db_version(), 'gd' => gd_info(), 'imagick' => Imagick::getVersion(), 'os' => PHP_OS));
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-m3');
file_put_contents(dirname(__DIR__) . '/reports/storage-m3/integration.json', wp_json_encode($report, JSON_PRETTY_PRINT));
echo count($checks) . " M3 integration checks passed.\n";
