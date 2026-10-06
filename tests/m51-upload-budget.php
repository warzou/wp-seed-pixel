<?php
require __DIR__ . '/m3-runtime.php';
$checks = array(); $results = array();
function m51_assert($ok, $name) { global $checks; $checks[$name] = (bool) $ok; if (!$ok) { throw new RuntimeException('FAIL: ' . $name); } }
$usage = array('complete' => true, 'live' => true, 'includes_recovery' => true, 'bytes' => 1000, 'measured_at' => time(), 'uncertainty_bytes' => 10);
$provider = static function () use (&$usage) { $usage['measured_at'] = time(); return $usage; };
add_filter('wp_seed_pixel_storage_usage', $provider);
$limits = array('operational_ceiling_bytes' => 2000, 'safety_reserve_bytes' => 100);
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, $limits);
m51_assert(WP_Seed_Pixel_Storage_Budget::status(10)['state'] === 'OK', 'Well below operational ceiling');
m51_assert(WP_Seed_Pixel_Storage_Budget::status(790)['state'] === 'WARNING', 'Warning retains reserve');
m51_assert(WP_Seed_Pixel_Storage_Budget::status(889)['peak_bytes'] === 1999, 'Exactly one byte below admitted');
m51_assert(WP_Seed_Pixel_Storage_Budget::status(890)['state'] === 'BLOCKED', 'Exact ceiling blocked');
m51_assert(WP_Seed_Pixel_Storage_Budget::status(891)['reason'] === 'CEILING_EXCEEDED', 'Above ceiling blocked');
m51_assert(WP_Seed_Pixel_Storage_Budget::status(891, sys_get_temp_dir())['reason'] === 'CEILING_EXCEEDED', 'Physical free cannot bypass ceiling');
$usage['bytes'] += 900;
m51_assert(WP_Seed_Pixel_Storage_Budget::status()['state'] === 'BLOCKED', 'Quarantine bytes count');
$usage['bytes'] -= 900;
m51_assert(WP_Seed_Pixel_Storage_Budget::status()['state'] === 'OK', 'Verified ledger removal frees admission');
$usage['complete'] = false; m51_assert(WP_Seed_Pixel_Storage_Budget::status()['state'] === 'UNKNOWN', 'Incomplete total fails closed'); $usage['complete'] = true;
$usage['includes_recovery'] = false; m51_assert(WP_Seed_Pixel_Storage_Budget::status()['state'] === 'UNKNOWN', 'Missing recovery fails closed'); $usage['includes_recovery'] = true;
$usage['live'] = false; m51_assert(WP_Seed_Pixel_Storage_Budget::status()['state'] === 'UNKNOWN', 'Cached usage cannot admit'); $usage['live'] = true;
$usage['bytes'] = PHP_INT_MAX; m51_assert(WP_Seed_Pixel_Storage_Budget::status(1)['state'] === 'UNKNOWN', 'Byte addition overflow fails closed'); $usage['bytes'] = 1000;
m51_assert(is_wp_error(WP_Seed_Pixel_Master_Storage::peak(array('bytes' => PHP_INT_MAX, 'width' => 2, 'height' => 2))), 'Peak multiplication overflow fails closed');
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array());
m51_assert(WP_Seed_Pixel_Storage_Budget::status(PHP_INT_MAX, sys_get_temp_dir())['reason'] === 'LOW_DISK', 'Disabled ceiling cannot bypass physical safety');
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array('operational_ceiling_bytes' => PHP_INT_MAX));
$physical_peak = (int) disk_free_space(sys_get_temp_dir()) + 1;
m51_assert(WP_Seed_Pixel_Storage_Budget::status($physical_peak, sys_get_temp_dir())['reason'] === 'LOW_DISK', 'Ceiling sufficient but physical disk insufficient');
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array());
m51_assert(is_wp_error(WP_Seed_Pixel_Storage_Budget::admit(1, sys_get_temp_dir())), 'Admission requires site authority');
m51_assert(WP_Seed_Pixel_Host_Admin::mb('600.123456') === 600123456, 'Decimal MB exact byte conversion');
m51_assert(is_wp_error(WP_Seed_Pixel_Host_Admin::mb('1e9')), 'Exponential input rejected');

function m51_new($name, $metadata = true) {
    $sub = '/m51-' . bin2hex(random_bytes(8));
    $filter = static function ($u) use ($sub) { $u['path'] .= $sub; $u['url'] .= $sub; $u['subdir'] .= $sub; return $u; };
    add_filter('upload_dir', $filter);
    $source = dirname(__DIR__) . '/.runtime/fixtures/' . $name;
    try { $upload = wp_upload_bits('m51-' . bin2hex(random_bytes(6)) . '-' . $name, null, file_get_contents($source)); }
    finally { remove_filter('upload_dir', $filter); }
    if ($upload['error']) { throw new RuntimeException('Synthetic upload failed'); }
    apply_filters('wp_handle_upload', $upload, 'upload');
    $id = wp_insert_attachment(array('post_title' => 'M5.1 synthetic ' . $name, 'post_mime_type' => wp_check_filetype($name)['type'], 'post_status' => 'inherit'), $upload['file']);
    if ($metadata) { $final = wp_generate_attachment_metadata($id, $upload['file']); $GLOBALS['m51_native_expected'][$id] = $final; wp_update_attachment_metadata($id, $final); }
    return (int) $id;
}
$old = m3_fixture('m3-small.jpeg', true); $old_hash = hash_file('sha256', get_attached_file($old));
$off = WP_Seed_Pixel_Future_Uploads::configure('off'); m51_assert(!is_wp_error($off), 'OFF configurable explicitly');
$off_id = m51_new('m3-small.jpeg'); m51_assert(!get_post_meta($off_id, WP_Seed_Pixel_Future_Uploads::META, true), 'OFF upload ordinary WordPress');
$s = WP_Seed_Pixel_Future_Uploads::configure('analyze'); m51_assert(!is_wp_error($s) && $s['cutoff_id'] >= $old, 'Durable ID cutoff persisted');
WP_Seed_Pixel_Future_Uploads::created($old); WP_Seed_Pixel_Future_Uploads::run($old);
m51_assert(!get_post_meta($old, WP_Seed_Pixel_Future_Uploads::META, true) && hash_file('sha256', get_attached_file($old)) === $old_hash, 'Old media excluded and immutable');
$analyze_id = m51_new('m3-small.jpeg'); WP_Seed_Pixel_Future_Uploads::run($analyze_id);
m51_assert(get_post_meta($analyze_id, WP_Seed_Pixel_Future_Uploads::META, true)['state'] === 'analyzed', 'Analyze new uploads no destructive job');
$restored = m3_fixture('m3-small.jpeg', true); WP_Seed_Pixel_Future_Uploads::created($restored);
m51_assert(!get_post_meta($restored, WP_Seed_Pixel_Future_Uploads::META, true), 'Restored ID without upload provenance excluded');

$s = WP_Seed_Pixel_Future_Uploads::configure('process', 1073741824, array()); m51_assert(!is_wp_error($s), 'Atomic opt-in and ceiling settings');
$delayed = m51_new('m3-small.jpeg', false);
m51_assert(!wp_next_scheduled('wp_seed_pixel_future_job', array($delayed)), 'Metadata incomplete not queued');
$final_meta = wp_generate_attachment_metadata($delayed, get_attached_file($delayed));
$partial = $final_meta; $partial['future_test_incomplete'] = true; wp_update_attachment_metadata($delayed, $partial);
WP_Seed_Pixel_Future_Uploads::run($delayed);
m51_assert((int) get_post_meta($delayed, WP_Seed_Pixel_Future_Uploads::META, true)['job_id'] === 0, 'Worker refuses intermediate native metadata graph');
wp_update_attachment_metadata($delayed, $final_meta);
m51_assert((bool) wp_next_scheduled('wp_seed_pixel_future_job', array($delayed)), 'Delayed native metadata schedules once');
WP_Seed_Pixel_Future_Uploads::metadata(wp_get_attachment_metadata($delayed), $delayed, 'create');
$events = _get_cron_array(); $count = 0;
foreach ($events as $hooks) { foreach ($hooks['wp_seed_pixel_future_job'] ?? array() as $event) { if ($event['args'] === array($delayed)) { $count++; } } }
m51_assert($count === 1, 'Duplicate metadata hook one scheduled event');

$busy = m51_new('m3-small.jpeg');
wp_clear_scheduled_hook('wp_seed_pixel_future_job', array($busy));
$coordinator = WP_Seed_Pixel_Files::lock(0);
m51_assert(!is_wp_error($coordinator), 'Contention fixture owns site coordinator');
WP_Seed_Pixel_Future_Uploads::run($busy);
WP_Seed_Pixel_Future_Uploads::run($busy);
$busy_marker = get_post_meta($busy, WP_Seed_Pixel_Future_Uploads::META, true);
m51_assert(!$busy_marker['job_id'] && is_file(get_attached_file($busy)), 'Busy coordinator creates no job or image effect');
$count = 0;
foreach (_get_cron_array() as $hooks) { foreach ($hooks['wp_seed_pixel_future_job'] ?? array() as $event) { if ($event['args'] === array($busy)) { $count++; } } }
m51_assert($count === 1, 'Busy coordinator retains exactly one future wakeup');
WP_Seed_Pixel_Files::unlock($coordinator);
wp_clear_scheduled_hook('wp_seed_pixel_future_job', array($busy));
WP_Seed_Pixel_Future_Uploads::run($busy);
$busy_marker = get_post_meta($busy, WP_Seed_Pixel_Future_Uploads::META, true);
m51_assert($busy_marker['job_id'] > 0 && WP_Seed_Pixel_Job_Store::job($busy_marker['job_id'])['status'] === 'completed', 'Deferred upload resumes same lifecycle after coordinator release');

global $wpdb;
foreach (array('normal' => 'm3-small.jpeg', 'big' => 'm3-large.jpg', 'icc' => 'm3-p3.jpg', 'appropriate' => 'm3-light.jpg', 'unsupported' => 'png.png') as $label => $file) {
    $id = m51_new($file); $native_before = wp_get_attachment_metadata($id); $url = wp_get_attachment_url($id);
    WP_Seed_Pixel_Future_Uploads::run($id);
    $marker = get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true); $job_id = (int) ($marker['job_id'] ?? 0);
    WP_Seed_Pixel_Future_Uploads::run($id);
    $after = get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true);
    m51_assert(($after['job_id'] ?? 0) === $job_id, "$label duplicate hook no duplicate job");
    m51_assert(is_file(get_attached_file($id)) && wp_get_attachment_url($id) === $url, "$label successful WordPress upload remains usable");
    if ($label === 'unsupported') { m51_assert(!$job_id && empty($marker), 'PNG excluded at enrollment under JPEG-only policy'); }
    else {
        if (!$job_id) { throw new RuntimeException('Enrollment delta: ' . wp_json_encode(array('marker' => $marker, 'expected' => $GLOBALS['m51_native_expected'][$id], 'observed' => wp_get_attachment_metadata($id)))); }
        m51_assert($job_id > 0, "$label uses accepted M2 job");
        $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation'", $job_id), ARRAY_A);
        m51_assert(in_array($item['stage'], array('retained', 'skipped', 'needs_review'), true), "$label safe terminal stage " . $item['stage'] . ':' . $item['error_code']);
        if ($label === 'appropriate') { m51_assert($item['stage'] === 'skipped' && $item['error_code'] === 'NO_BENEFIT', 'Already appropriate JPEG preserved without useless replacement'); }
        if ($item['stage'] === 'retained') { m51_assert(!is_wp_error(WP_Seed_Pixel_Quarantine::inspect($item)) && WP_Seed_Pixel_Quarantine::inspect($item)['rollback_available'], "$label uses accepted M4 quarantine lifecycle"); }
        if ($label === 'big') {
            m51_assert(!empty($native_before['original_image']) && strpos($native_before['file'], '-scaled') !== false, 'WordPress big-image native graph established before Pixel');
            m51_assert(wp_get_attachment_metadata($id)['original_image'] === $native_before['original_image'] && is_file(wp_get_original_image_path($id)), 'Original-image preserved');
            if ($item['stage'] === 'retained') { $panel=WP_Seed_Pixel_Media::details($id); m51_assert(str_contains($panel,'Original') && str_contains($panel,'Optimized version'), 'Scaled native graph has clear Original and Optimized UI'); }
        }
        $results[$label] = array('id' => $id, 'job' => $job_id, 'stage' => $item['stage'], 'reason' => $item['error_code']);
    }
}
add_filter('big_image_size_threshold', '__return_false');
$oversized = m51_new('m3-large.jpg'); remove_filter('big_image_size_threshold', '__return_false');
WP_Seed_Pixel_Future_Uploads::run($oversized);
$marker = get_post_meta($oversized, WP_Seed_Pixel_Future_Uploads::META, true);
$large_item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation'", $marker['job_id'] ?? 0), ARRAY_A);
m51_assert($large_item && $large_item['stage'] === 'retained', 'Oversized unscaled JPEG admitted and verified by same executor');
$near = m51_new('m3-small.jpeg'); $near_hash = hash_file('sha256', get_attached_file($near));
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array('operational_ceiling_bytes' => 1000));
$usage['bytes'] = 1001;
WP_Seed_Pixel_Future_Uploads::run($near); $marker = get_post_meta($near, WP_Seed_Pixel_Future_Uploads::META, true);
$job = WP_Seed_Pixel_Job_Store::job($marker['job_id'] ?? 0);
m51_assert($job && $job['status'] === 'failed_systemic' && $job['error_code'] === 'CEILING_EXCEEDED', 'Upload already over ceiling blocks extra peak');
m51_assert(hash_file('sha256', get_attached_file($near)) === $near_hash, 'No delete-first or upload loss when ceiling blocks');
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array());
WP_Seed_Pixel_Future_Uploads::configure('off');
$native = WP_Seed_Pixel_Master_Adapter::snapshot($old);
m51_assert(!is_wp_error($native), 'Old media still manually inspectable');
$manual = WP_Seed_Pixel_Jobs::replace_one($old, array('master' => 'replace_verified'), 1073741824);
m51_assert(!is_wp_error($manual), 'Old media explicit manual processing available');
$unauthorized = get_current_user_id(); wp_set_current_user(0);
m51_assert(is_wp_error(WP_Seed_Pixel_Future_Uploads::configure('process', 1073741824)), 'No opt-in without admin capability'); wp_set_current_user($unauthorized);
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-m5.1');
file_put_contents(dirname(__DIR__) . '/reports/storage-m5.1/uploads-budget.json', wp_json_encode(array('checks' => $checks, 'results' => $results), JSON_PRETTY_PRINT));
echo count($checks) . " M5.1 upload/budget checks passed.\n";
