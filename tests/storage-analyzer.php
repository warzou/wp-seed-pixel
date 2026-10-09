<?php
require __DIR__ . '/runtime.php';
if (get_posts(array('post_type' => 'attachment', 'posts_per_page' => 1))) { throw new RuntimeException('M1 fixture setup requires a fresh disposable database.'); }
$checks = array();
function scan_check($name, $pass) { global $checks; $checks[$name] = (bool) $pass; if (!$pass) { throw new RuntimeException($name); } }
function scan_fixture($name, $width, $height, $mime = 'image/jpeg') {
    $uploads = wp_upload_dir(); $path = $uploads['path'] . '/' . $name;
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 33, 126, 78));
    if ($mime === 'image/png') { imagesavealpha($image, true); imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 100)); imagepng($image, $path); }
    else { imagejpeg($image, $path, 92); }
    imagedestroy($image);
    $id = wp_insert_attachment(array('post_title' => 'Synthetic M1 ' . $name, 'post_mime_type' => $mime, 'post_status' => 'inherit'), $path);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $path));
    return (int) $id;
}
function scan_snapshot() {
    global $wpdb;
    $uploads = wp_upload_dir(null, false); $files = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads['basedir'], FilesystemIterator::SKIP_DOTS)) as $file) { if ($file->isFile()) { $files[substr($file->getPathname(), strlen($uploads['basedir']))] = hash_file('sha256', $file->getPathname()); } }
    ksort($files);
    return array('posts' => $wpdb->get_results("SELECT * FROM $wpdb->posts ORDER BY ID", ARRAY_A), 'postmeta' => $wpdb->get_results("SELECT * FROM $wpdb->postmeta ORDER BY meta_id", ARRAY_A), 'processing_options' => $wpdb->get_results("SELECT * FROM $wpdb->options WHERE option_name IN ('wp_seed_pixel_settings','wp_seed_pixel_batch','cron') ORDER BY option_name", ARRAY_A), 'files' => $files);
}
$normal = scan_fixture('m1-normal.jpg', 1280, 800);
$big = scan_fixture('m1-big.jpg', 3000, 2100);
$png = scan_fixture('m1-alpha.png', 640, 480, 'image/png');
$optimized = wp_seed_pixel_optimize($big, 'balanced');
scan_check('baseline Pixel resources generated on synthetic fixture before mutation proof', !is_wp_error($optimized));
$upload = wp_upload_dir(null, false);
$extra = dirname(get_attached_file($normal)) . '/m1-normal-extra.bin'; file_put_contents($extra, 'unknown-test');
$missing = wp_insert_attachment(array('post_title' => 'Synthetic M1 missing', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'), $upload['path'] . '/m1-missing.jpg');
wp_update_attachment_metadata($missing, array('file' => _wp_relative_upload_path($upload['path'] . '/m1-missing.jpg'), 'width' => 100, 'height' => 100, 'sizes' => array()));
$unsupported_file = $upload['path'] . '/m1-unsupported.dat'; file_put_contents($unsupported_file, 'synthetic non-image');
$unsupported = wp_insert_attachment(array('post_title' => 'Synthetic M1 unsupported', 'post_mime_type' => 'application/octet-stream', 'post_status' => 'inherit'), $unsupported_file);
$duplicate = wp_insert_attachment(array('post_title' => 'Synthetic M1 shared path', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'), get_attached_file($normal));
wp_update_attachment_metadata($duplicate, get_post_meta($normal, '_wp_attachment_metadata', true));
$edited = scan_fixture('m1-edited.jpg', 600, 400);
update_post_meta($edited, '_wp_attachment_backup_sizes', array('full-orig' => array('file' => basename(get_attached_file($edited)), 'width' => 600, 'height' => 400)));
$unsafe = scan_fixture('m1-unsafe.jpg', 300, 200);
update_post_meta($unsafe, '_seed_pixel_manifest', array('files' => array(array('path' => $upload['basedir'] . '/../not-a-media-file'))));
$normal_result = WP_Seed_Pixel_Analyzer::analyze($normal);
$big_result = WP_Seed_Pixel_Analyzer::analyze($big);
scan_check('ordinary JPEG identified', $normal_result['image']['format'] === 'jpeg');
scan_check('related unknown file measured', $normal_result['storage']['by_role']['unattributed'] === strlen('unknown-test'));
scan_check('big image original preserved by WordPress', !empty(get_post_meta($big, '_wp_attachment_metadata', true)['original_image']));
scan_check('big image separate operational and original roles', $big_result['storage']['by_role']['operational'] > 0 && $big_result['storage']['by_role']['preserved_original'] > 0);
scan_check('Pixel resource bytes present', $big_result['storage']['by_role']['pixel_current'] > 0);
scan_check('PNG alpha detected without encoding', WP_Seed_Pixel_Analyzer::analyze($png)['image']['alpha'] === true);
scan_check('missing file zero bytes and missing health', WP_Seed_Pixel_Analyzer::analyze($missing)['health'] === 'missing' && WP_Seed_Pixel_Analyzer::analyze($missing)['storage']['unique_logical_bytes'] === 0);
scan_check('unsupported operation classified', WP_Seed_Pixel_Analyzer::analyze($unsupported)['health'] === 'unsupported');
scan_check('edit backups need review', WP_Seed_Pixel_Analyzer::analyze($edited)['health'] === 'needs_review');
scan_check('unsafe manifest path not inspected/exported', in_array('unsafe_path', WP_Seed_Pixel_Analyzer::analyze($unsafe)['issues'], true));
scan_check('no compression estimates invented', $big_result['deep_analysis'] === false && $big_result['storage']['reclaimed_bytes'] === 0);
scan_check('no absolute paths in analysis', strpos(wp_json_encode($big_result), wp_normalize_path(ABSPATH)) === false);
$before = scan_snapshot();
$job = WP_Seed_Pixel_Scan::start(); scan_check('scan initialized', !is_wp_error($job));
$first = WP_Seed_Pixel_Scan::step($job['id']);
scan_check('one attachment per request', $first['done'] === 1);
WP_Seed_Pixel_Scan::control($job['id'], 'paused');
scan_check('pause cannot advance cursor', WP_Seed_Pixel_Scan::step($job['id'])['done'] === 1);
WP_Seed_Pixel_Scan::control($job['id'], 'running');
while (($job = WP_Seed_Pixel_Scan::step($job['id']))['status'] === 'running') { if (is_wp_error($job)) { throw new RuntimeException('Scan failed'); } }
$unique = array();
foreach (get_posts(array('post_type' => 'attachment', 'posts_per_page' => -1, 'post_status' => 'inherit')) as $post) { foreach (WP_Seed_Pixel_Analyzer::analyze($post->ID)['files'] as $file) { $unique[$file['identity']] = $file['logical_bytes']; } }
scan_check('cross-attachment physical dedup exact', $job['storage']['unique_logical_bytes'] === array_sum($unique));
scan_check('disjoint role sum equals unique total', array_sum($job['storage']['by_role']) === array_sum($unique));
scan_check('original opportunity not reported reclaimed', $job['storage']['potential_original_bytes'] === $big_result['storage']['by_role']['preserved_original'] && $job['storage']['reclaimed_bytes'] === 0);
scan_check('shared file marks review', $job['health']['needs_review'] >= 4);
$page = WP_Seed_Pixel_Scan::results($job['id']);
scan_check('results and freshness', count($page['items']) === 8 && !in_array(true, array_column($page['items'], 'stale'), true));
scan_check('zero canonical mutation including file hashes', scan_snapshot() === $before);
wp_set_current_user(0);
scan_check('unauthorized analysis denied', is_wp_error(WP_Seed_Pixel_Analyzer::analyze($normal)) && is_wp_error(WP_Seed_Pixel_Scan::start()) && is_wp_error(WP_Seed_Pixel_Scan::status($job['id'])));
wp_set_current_user(1);
// Create a large synthetic attachment graph, without another image encoding.
$metadata = get_post_meta($normal, '_wp_attachment_metadata', true);
for ($i = 0; $i < 2000; $i++) {
    $id = wp_insert_attachment(array('post_title' => 'Synthetic M1 library ' . $i, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'), get_attached_file($normal));
    wp_update_attachment_metadata($id, $metadata);
}
$before_large = scan_snapshot(); $large = WP_Seed_Pixel_Scan::start(); $start = microtime(true);
while (true) {
    $large = WP_Seed_Pixel_Scan::step($large['id']);
    if (is_wp_error($large)) { throw new RuntimeException($large->get_error_message()); }
    if ($large['status'] !== 'running') { break; }
}
scan_check('2008 attachments scanned in bounded steps', $large['done'] === 2008);
scan_check('large alias library no byte inflation', $large['storage']['unique_logical_bytes'] === array_sum($unique));
scan_check('large results paginated', count(WP_Seed_Pixel_Scan::results($large['id'], 1)['items']) === 20 && count(WP_Seed_Pixel_Scan::results($large['id'], 101)['items']) === 8);
scan_check('large graph zero canonical mutation', scan_snapshot() === $before_large);
$scan_lease = $wpdb->prefix . 'seed_pixel_jobs';
$paused = WP_Seed_Pixel_Scan::start(); $wpdb->update($scan_lease, array('lease' => 'synthetic-lock', 'lease_until' => time() + 60), array('id' => $paused['id']));
scan_check('concurrent step refused', is_wp_error(WP_Seed_Pixel_Scan::step($paused['id'])));
$wpdb->update($scan_lease, array('lease' => '', 'lease_until' => 0), array('id' => $paused['id'])); WP_Seed_Pixel_Scan::control($paused['id'], 'cancelled');
scan_check('cancel retains results and stops work', WP_Seed_Pixel_Scan::step($paused['id'])['status'] === 'cancelled');
$report = array('checks' => $checks, 'passed' => count($checks), 'wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'database' => $wpdb->db_version(), 'large_library' => 2008, 'large_seconds' => microtime(true) - $start, 'fixtures' => array('normal' => $normal, 'big' => $big, 'png' => $png, 'missing' => $missing, 'unsupported' => $unsupported, 'duplicate' => $duplicate, 'edited' => $edited, 'unsafe' => $unsafe), 'large_scan' => $large['id']);
$dir = dirname(__DIR__) . '/reports/storage-m1'; if (!is_dir($dir)) { mkdir($dir, 0755, true); }
file_put_contents($dir . '/integration.json', wp_json_encode($report, JSON_PRETTY_PRINT));
echo wp_json_encode(array('passed' => count($checks), 'large_library' => 2008, 'large_seconds' => $report['large_seconds']), JSON_PRETTY_PRINT);
