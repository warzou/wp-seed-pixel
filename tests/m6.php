<?php
require __DIR__ . '/m4-runtime.php';
$checks = array(); $results = array();
function m6_ok($v) { if (is_wp_error($v)) { throw new RuntimeException($v->get_error_code()); } return $v; }
function m6_pixels($path) {
    $image = imagecreatefrompng($path); if (!$image) { throw new RuntimeException('Decode failed'); }
    $hash = hash_init('sha256');
    for ($y = 0; $y < imagesy($image); $y++) {
        $row = ''; for ($x = 0; $x < imagesx($image); $x++) { $c = imagecolorsforindex($image, imagecolorat($image, $x, $y)); $row .= pack('CCCC', $c['red'], $c['green'], $c['blue'], $c['alpha']); }
        hash_update($hash, $row);
    }
    imagedestroy($image); return hash_final($hash);
}
WP_Seed_Pixel_Future_Uploads::configure('off'); update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array());
$policy = WP_Seed_Pixel_Policy::normalize(array('master' => 'replace_verified'));
$fixtures = dirname(__DIR__) . '/.runtime/fixtures';
// Native uploads use an isolated month child; inherited fixture directories must not exhaust the bounded sibling audit.
$sub = '/m6-' . bin2hex(random_bytes(6));
add_filter('upload_dir', static function ($u) use ($sub) { $u['path'] .= $sub; $u['url'] .= $sub; $u['subdir'] .= $sub; return $u; });
foreach (array('RGB', 'RGBA', 'L', 'LA', 'P', 'profile', 'large') as $name) {
    $source = "$fixtures/m6-$name.png"; $output = "$fixtures/m6-$name-candidate.png";
    $r = m6_ok(WP_Seed_Pixel_Master_Processor::create($source, $output, $policy));
    m4_check($r['metric']['lossless'] && $r['bytes'] + 65536 < filesize($source), "$name meaningful net saving after journal reserve");
    m4_check(m6_pixels($source) === m6_pixels($output), "$name decoded RGBA exact");
    $a = m6_ok(WP_Seed_Pixel_PNG_Processor::inspect($source, true)); $b = m6_ok(WP_Seed_Pixel_PNG_Processor::inspect($output, true));
    m4_check($a['header'] === $b['header'] && $a['pixels'] === $b['pixels'], "$name dimensions depth filtered pixels exact");
    $results[$name] = $r; unlink($output);
}
foreach (array('efficient' => 'NO_BENEFIT', 'animation' => 'NEEDS_REVIEW', 'depth16' => 'NEEDS_REVIEW', 'oversized' => 'NEEDS_REVIEW', 'crc' => 'CANDIDATE_INVALID', 'trailing' => 'CANDIDATE_INVALID', 'bad-filter' => 'CANDIDATE_INVALID', 'inflate-extra' => 'CANDIDATE_INVALID', 'unknown-critical' => 'NEEDS_REVIEW', 'compressed-text' => 'NEEDS_REVIEW', 'bad-profile' => 'ICC_UNSAFE') as $name => $code) {
    $source = "$fixtures/m6-$name.png"; $sha = hash_file('sha256', $source);
    $r = WP_Seed_Pixel_Master_Processor::create($source, "$fixtures/m6-rejected.png", $policy);
    m4_check(is_wp_error($r) && $r->get_error_code() === $code, "$name explicit safe refusal");
    m4_check(hash_file('sha256', $source) === $sha && !file_exists("$fixtures/m6-rejected.png"), "$name source preserved no candidate");
}
$id = m3_fixture('m6-RGBA.png'); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id); $sha = hash_file('sha256', get_attached_file($id)); $url = wp_get_attachment_url($id);
$job = m6_ok(WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824)); m6_ok(WP_Seed_Pixel_Jobs::step($job['id']));
$item = m4_item($job['id']); $view = m6_ok(WP_Seed_Pixel_Jobs::quarantine_action($job['id'], 'retain'));
m4_check($item['stage'] === 'retained' && $view['rollback_available'], 'PNG shared M2 M3 M4 coordinator');
m4_check(wp_get_attachment_url($id) === $url && get_post_mime_type($id) === 'image/png', 'PNG native URL MIME unchanged');
foreach ($before['files'] as $path => $file) { if ($path !== $before['relative']) { m4_check(hash_file('sha256', wp_upload_dir()['basedir'] . '/' . $path) === $file['sha256'], 'PNG native derivative unchanged ' . basename($path)); } }
m4_check(WP_Seed_Pixel_Media::state($id) === 'master_optimized', 'Media editor recognizes native optimized master');
m6_ok(WP_Seed_Pixel_Jobs::quarantine_action($job['id'], 'restore'));
m4_check(hash_file('sha256', get_attached_file($id)) === $sha, 'PNG restore exact original');
$heavy = m3_fixture('m6-L.png'); $hsha = hash_file('sha256', get_attached_file($heavy));
$meta = wp_get_attachment_metadata($heavy); $meta['synthetic_large_metadata'] = str_repeat('x', 100000); wp_update_attachment_metadata($heavy, $meta);
$hj = m6_ok(WP_Seed_Pixel_Jobs::replace_one($heavy, array('master' => 'replace_verified'), 1073741824)); m6_ok(WP_Seed_Pixel_Jobs::step($hj['id']));
m4_check(m4_item($hj['id'])['stage'] === 'skipped' && m4_item($hj['id'])['error_code'] === 'NO_BENEFIT', 'PNG native metadata overhead prevents false net benefit');
m4_check(hash_file('sha256', get_attached_file($heavy)) === $hsha, 'PNG insufficient net benefit preserves native master');
$id2 = m3_fixture('m6-RGB.png'); $job2 = m6_ok(WP_Seed_Pixel_Jobs::replace_one($id2, array('master' => 'replace_verified'), 1073741824)); m6_ok(WP_Seed_Pixel_Jobs::step($job2['id']));
$view = m6_ok(WP_Seed_Pixel_Jobs::quarantine_action($job2['id'], 'retain')); $current = hash_file('sha256', get_attached_file($id2));
$purge = m6_ok(WP_Seed_Pixel_Jobs::quarantine_action($job2['id'], 'purge', m4_approval($view)));
m4_check(!$purge['rollback_available'] && $purge['removed_bytes'] > 65536, 'PNG explicit purge removes only original');
m4_check(hash_file('sha256', get_attached_file($id2)) === $current && strpos(WP_Seed_Pixel_Media::details($id2), 'Restoration unavailable') !== false, 'PNG purged editor never offers fake restore');
$old = m3_fixture('m6-RGB.png'); $oldsha = hash_file('sha256', get_attached_file($old));
$s = m6_ok(WP_Seed_Pixel_Future_Uploads::configure('process', 1073741824));
m4_check($s['formats'] === array('jpeg'), 'Upgrade/default JPEG policy does not enable PNG');
$legacy = $s; unset($legacy['formats']); update_option(WP_Seed_Pixel_Future_Uploads::OPTION, $legacy);
m4_check(WP_Seed_Pixel_Future_Uploads::settings()['formats'] === array('jpeg'), 'Existing opt-in preserved without implicit PNG upgrade');
$s = m6_ok(WP_Seed_Pixel_Future_Uploads::configure('process', 1073741824, null, array('jpeg', 'png')));
WP_Seed_Pixel_Future_Uploads::created($old); WP_Seed_Pixel_Future_Uploads::run($old);
m4_check(hash_file('sha256', get_attached_file($old)) === $oldsha && !get_post_meta($old, WP_Seed_Pixel_Future_Uploads::META, true), 'Preexisting PNG protected despite explicit new PNG opt-in');
$source = "$fixtures/m6-RGB.png"; $upload = wp_upload_bits('m6-future-' . bin2hex(random_bytes(8)) . '.png', null, file_get_contents($source));
apply_filters('wp_handle_upload', $upload, 'upload');
$new = wp_insert_attachment(array('post_title' => 'M6 native PNG upload', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'), $upload['file']);
wp_update_attachment_metadata($new, wp_generate_attachment_metadata($new, $upload['file']));
WP_Seed_Pixel_Future_Uploads::run($new); $marker = get_post_meta($new, WP_Seed_Pixel_Future_Uploads::META, true);
m4_check(!empty($marker['job_id']) && m4_item($marker['job_id'])['stage'] === 'retained', 'Explicit future PNG uses same proven coordinator');
$revision = m4_item($marker['job_id'])['revision']; WP_Seed_Pixel_Future_Uploads::run($new);
m4_check(m4_item($marker['job_id'])['revision'] === $revision, 'PNG future hook idempotent no recompression');
WP_Seed_Pixel_Future_Uploads::configure('off');
$scan = m6_ok(WP_Seed_Pixel_Scan::start());
for ($n = 0; $scan['status'] === 'running' && $n < 200; $n++) { $scan = m6_ok(WP_Seed_Pixel_Scan::step($scan['id'])); }
$plan = m6_ok(WP_Seed_Pixel_Jobs::bulk_plan($scan['id'], array('master' => 'replace_verified'), 1073741824, 'replace', 1, false, array($old)));
for ($n = 0; $plan['status'] === 'queued' && $n < 200; $n++) { $plan = m6_ok(WP_Seed_Pixel_Jobs::bulk_step($plan['id'])); }
global $wpdb; $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='plan' AND attachment_id=%d", $plan['id'], $old), ARRAY_A);
m4_check($row && $row['stage'] === 'queued', 'Existing PNG explicit M5 plan eligibility');
$bulk = m6_ok(WP_Seed_Pixel_Jobs::start($plan['id']));
for ($n = 0; $bulk['status'] === 'running' && $n < 200; $n++) { $bulk = m6_ok(WP_Seed_Pixel_Jobs::bulk_step($bulk['id'])); }
m4_check(($bulk['states']['retained'] ?? 0) === 1 && $bulk['status'] === 'completed', 'Selected PNG M5 execution exactly one native master');
m4_check(hash_file('sha256', get_attached_file($id2)) === $current, 'Unselected PNG unchanged through selected bulk');
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-v1');
file_put_contents(dirname(__DIR__) . '/reports/storage-v1/m6.json', wp_json_encode(array('checks' => $checks, 'results' => $results), JSON_PRETTY_PRINT));
echo count($checks) . " M6 checks PASS\n";
