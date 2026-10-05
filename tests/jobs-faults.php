<?php
require __DIR__ . '/runtime.php';
$checks = array();
function fault_check($name, $ok) { global $checks; $checks[$name] = (bool) $ok; if (!$ok) { throw new RuntimeException($name); } }
function fault_job() {
    $scan = json_decode(file_get_contents(dirname(__DIR__) . '/reports/storage-m2/integration.json'), true)['scan_id'];
    $p = WP_Seed_Pixel_Jobs::plan($scan);
    while ($p['status'] === 'queued') { $p = WP_Seed_Pixel_Jobs::step($p['id']); }
    return WP_Seed_Pixel_Jobs::start($p['id']);
}
function inject_query($pattern, callable $operation) {
    global $wpdb;
    $old = $wpdb->suppress_errors(true); $hit = false;
    $filter = static function ($query) use ($pattern, &$hit) {
        if (!$hit && preg_match($pattern, $query)) { $hit = true; return 'SELECT FROM pixel_m2_injected_failure'; }
        return $query;
    };
    add_filter('query', $filter);
    try { return array($operation(), &$hit); } finally { remove_filter('query', $filter); $wpdb->suppress_errors($old); }
}
global $wpdb;
$jobs = WP_Seed_Pixel_Job_Store::table('jobs'); $items = WP_Seed_Pixel_Job_Store::table('items');
$scan = json_decode(file_get_contents(dirname(__DIR__) . '/reports/storage-m2/integration.json'), true)['scan_id'];
$before = $wpdb->get_var("SELECT COUNT(*) FROM $jobs");
list($result, $hit) = inject_query('/INSERT INTO .*seed_pixel_jobs/i', static function () use ($scan) { return WP_Seed_Pixel_Jobs::plan($scan); });
fault_check('job creation failure is explicit without phantom job', $hit && is_wp_error($result) && $wpdb->get_var("SELECT COUNT(*) FROM $jobs") === $before);
$scan_total = $wpdb->get_var($wpdb->prepare("SELECT total FROM $jobs WHERE id=%d", $scan));
$wpdb->update($jobs, array('total' => (int) $scan_total + 1), array('id' => $scan));
try { $observed = fault_job(); fault_check('plan counts recorded analysis rows not obsolete scan forecast', (int) $observed['total'] === 3); }
finally { $wpdb->update($jobs, array('total' => $scan_total), array('id' => $scan)); }
$job = fault_job();
list($result, $hit) = inject_query('/UPDATE .*seed_pixel_items SET lease=/i', static function () use ($job) { return WP_Seed_Pixel_Jobs::step($job['id']); });
fault_check('claim failure cannot advance item', $hit && is_wp_error($result) && WP_Seed_Pixel_Jobs::status($job['id'])['states']['queued'] === 3);
list($result, $hit) = inject_query('/UPDATE .*seed_pixel_items SET stage=/i', static function () use ($job) { return WP_Seed_Pixel_Jobs::step($job['id']); });
fault_check('transition persistence failure stops systemic', $hit && is_wp_error($result) && WP_Seed_Pixel_Jobs::status($job['id'])['status'] === 'failed_systemic');
fault_check('persistence failure leaves prior durable stage', WP_Seed_Pixel_Jobs::status($job['id'])['states']['queued'] === 3);
$job = fault_job();
list($result, $hit) = inject_query('/UPDATE .*seed_pixel_items SET stage=\x27switched\x27/i', static function () use ($job) { return WP_Seed_Pixel_Jobs::step($job['id']); });
fault_check('result persistence interruption preserves intent', $hit && is_wp_error($result) && WP_Seed_Pixel_Jobs::status($job['id'])['states']['switch_intent'] === 1);
fault_check('cancel cannot discard persisted intent after store failure', WP_Seed_Pixel_Jobs::control($job['id'], 'cancel')->get_error_code() === 'RECOVERY_REQUIRED');
WP_Seed_Pixel_Jobs::control($job['id'], 'resume'); WP_Seed_Pixel_Jobs::step($job['id']);
fault_check('result write failure is safely resumable', WP_Seed_Pixel_Jobs::status($job['id'])['states']['retained'] === 1);
$job = fault_job();
list($result, $hit) = inject_query('/UPDATE .*seed_pixel_jobs SET status=/i', static function () use ($job) { return WP_Seed_Pixel_Jobs::control($job['id'], 'pause'); });
fault_check('failed pause leaves actual running state', $hit && is_wp_error($result) && WP_Seed_Pixel_Jobs::status($job['id'])['status'] === 'running');
WP_Seed_Pixel_Jobs::control($job['id'], 'pause');
list($result, $hit) = inject_query('/UPDATE .*seed_pixel_jobs SET status=/i', static function () use ($job) { return WP_Seed_Pixel_Jobs::control($job['id'], 'resume'); });
fault_check('failed resume leaves paused state', $hit && is_wp_error($result) && WP_Seed_Pixel_Jobs::status($job['id'])['status'] === 'paused');
$wpdb->query($wpdb->prepare("UPDATE $items SET stage='failed',error_class='retryable' WHERE job_id=%d", $job['id']));
list($result, $hit) = inject_query('/UPDATE .*seed_pixel_items SET stage=/i', static function () use ($job) { return WP_Seed_Pixel_Jobs::control($job['id'], 'retry'); });
fault_check('failed retry does not hide failed items', $hit && is_wp_error($result) && WP_Seed_Pixel_Jobs::status($job['id'])['states']['failed'] === 3);
$job = fault_job(); $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d ORDER BY id LIMIT 1", $job['id']), ARRAY_A);
$journal = array('events' => array_fill(0, 60, array('stage' => 'queued', 'simulation' => true)), 'dropped' => 0);
$journal['checksum'] = hash('sha256', wp_json_encode($journal));
$wpdb->update($items, array('journal' => wp_json_encode($journal)), array('id' => $item['id']));
WP_Seed_Pixel_Jobs::step($job['id']); $item = WP_Seed_Pixel_Job_Store::item($item['id']); $journal = json_decode($item['journal'], true);
fault_check('journal retention is bounded with explicit dropped count', count($journal['events']) === 32 && $journal['dropped'] === 34 && WP_Seed_Pixel_Job_Store::journal_valid($item));
fault_check('terminal item cannot be transitioned or rerun', WP_Seed_Pixel_Job_Store::transition($item, 'any', 'queued')->get_error_code() === 'CLAIM_CONFLICT');
$job = fault_job(); $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d ORDER BY id LIMIT 1", $job['id']), ARRAY_A);
$wpdb->update($items, array('journal' => '{"events":[],"checksum":"altered"}'), array('id' => $item['id'])); WP_Seed_Pixel_Jobs::step($job['id']);
fault_check('damaged journal needs review without execution', WP_Seed_Pixel_Job_Store::item($item['id'])['stage'] === 'needs_review');
$job = fault_job(); $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d ORDER BY id LIMIT 1", $job['id']), ARRAY_A);
$wpdb->update($items, array('stage' => 'failed', 'error_class' => 'retryable', 'attempts' => 3), array('id' => $item['id'])); $wpdb->update($jobs, array('status' => 'completed_errors'), array('id' => $job['id']));
fault_check('retry exhaustion cannot requeue forever', WP_Seed_Pixel_Jobs::control($job['id'], 'retry')->get_error_code() === 'RETRY_UNAVAILABLE');
$job = fault_job(); $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d ORDER BY id LIMIT 1", $job['id']), ARRAY_A);
$deny = static function ($caps, $cap, $user, $args) use ($item) { return $cap === 'edit_post' && (int) ($args[0] ?? 0) === (int) $item['attachment_id'] ? array('do_not_allow') : $caps; };
add_filter('map_meta_cap', $deny, 10, 4);
try {
    WP_Seed_Pixel_Jobs::step($job['id']);
    fault_check('per-image permission rechecked at execution', WP_Seed_Pixel_Job_Store::item($item['id'])['error_code'] === 'PERMISSION_DENIED');
    fault_check('per-image permission enforced on results', count(WP_Seed_Pixel_Jobs::results($job['id'])['items']) === 2);
    $denied_plan = fault_job();
    fault_check('per-image denied analysis does not enter executable plan', WP_Seed_Pixel_Jobs::status($denied_plan['id'])['states']['skipped'] === 1);
} finally { remove_filter('map_meta_cap', $deny, 10); }
$job = fault_job(); $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d ORDER BY id LIMIT 1", $job['id']), ARRAY_A);
$path = get_attached_file((int) $item['attachment_id'], true); $bytes = file_get_contents($path); $mtime = filemtime($path);
try {
    $altered = $bytes; $offset = strlen($altered) - 20; $altered[$offset] = chr(ord($altered[$offset]) ^ 1);
    file_put_contents($path, $altered); touch($path, $mtime); clearstatcache(true, $path);
    $sha = hash_file('sha256', $path); WP_Seed_Pixel_Jobs::step($job['id']);
    fault_check('equal-size equal-mtime source mutation detected cryptographically', WP_Seed_Pixel_Job_Store::item($item['id'])['error_code'] === 'SOURCE_CHANGED');
    fault_check('stale-source simulation does not alter fixture bytes', hash_file('sha256', $path) === $sha);
} finally { file_put_contents($path, $bytes); touch($path, $mtime); clearstatcache(true, $path); }
fault_check('M2 source has no canonical mutators or destructive calls', !preg_match('/\b(?:unlink|rename|copy|imagejpeg|imagepng|wp_update_attachment_metadata|update_post_meta|wp_generate_attachment_metadata|wp_seed_pixel_optimize)\s*\(/', implode('', array_map('file_get_contents', glob(dirname(__DIR__) . '/includes/class-job*.php')))));
try {
    update_option('wp_seed_pixel_job_schema', 0, false);
    fault_check('retention is harmless before schema introduction', WP_Seed_Pixel_Job_Store::prune(time()) === 0);
    update_option('wp_seed_pixel_job_schema', 3, false);
    fault_check('retention refuses a newer unknown schema', WP_Seed_Pixel_Job_Store::prune(time())->get_error_code() === 'ENGINE_INCOMPATIBLE');
    fault_check('downgraded coordinator refuses unknown schema without execution', WP_Seed_Pixel_Jobs::step($job['id'])->get_error_code() === 'ENGINE_INCOMPATIBLE' && WP_Seed_Pixel_Jobs::control($job['id'], 'resume')->get_error_code() === 'ENGINE_INCOMPATIBLE');
} finally { update_option('wp_seed_pixel_job_schema', 2, false); }
file_put_contents(dirname(__DIR__) . '/reports/storage-m2/faults.json', wp_json_encode(array('checks' => $checks, 'passed' => count($checks)), JSON_PRETTY_PRINT));
echo wp_json_encode(array('passed' => count($checks)));
