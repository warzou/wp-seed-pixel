<?php
require __DIR__ . '/m4-runtime.php';
$checks = array();
function m5_check($condition, $label) {
    global $checks;
    $checks[$label] = (bool) $condition;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
}
function m5_ok($result) {
    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_code()); }
    return $result;
}
$fixtures = dirname(__DIR__) . '/.runtime/fixtures'; wp_mkdir_p($fixtures);
$image = imagecreatetruecolor(2400, 1600);
for ($x = 0; $x < 2400; $x += 8) {
    $color = imagecolorallocate($image, $x % 240, 70 + ($x % 150), 50 + ($x % 180));
    imagefilledrectangle($image, $x, 0, $x + 7, 1599, $color);
}
imagejpeg($image, $fixtures . '/m3-detail.jpg', 100);
$ids = array(); $before = array();
for ($n = 0; $n < 4; $n++) {
    $id = m3_fixture('m3-detail.jpg', false); $path = get_attached_file($id);
    wp_update_attachment_metadata($id, array('file' => _wp_relative_upload_path($path), 'width' => 2400, 'height' => 1600, 'filesize' => filesize($path), 'sizes' => array()));
    $ids[] = $id; $before[$id] = hash_file('sha256', $path);
}
$scan = m5_ok(WP_Seed_Pixel_Scan::start());
for ($n = 0; $scan['status'] === 'running' && $n < 100; $n++) { $scan = m5_ok(WP_Seed_Pixel_Scan::step($scan['id'])); }
m5_check($scan['status'] === 'complete', 'M1 complete');
$plan = m5_ok(WP_Seed_Pixel_Jobs::bulk_plan($scan['id'], array('master' => 'replace_verified'), 1073741824));
for ($n = 0; $plan['status'] === 'queued' && $n < 100; $n++) { $plan = m5_ok(WP_Seed_Pixel_Jobs::bulk_step($plan['id'])); }
m5_check($plan['status'] === 'completed', 'bounded plan completed');
global $wpdb;
$items = WP_Seed_Pixel_Job_Store::table('items');
$rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d AND kind='plan' ORDER BY id", $plan['id']), ARRAY_A);
m5_check(count($rows) >= 4, 'frozen inventory includes four new identities');
m5_check(count(array_filter($rows, static function ($r) { return $r['stage'] === 'queued'; })) === 4, 'four eligible JPEGs');
foreach ($ids as $id) { m5_check(hash_file('sha256', get_attached_file($id)) === $before[$id], 'planning read-only ' . $id); }
$job = m5_ok(WP_Seed_Pixel_Jobs::start($plan['id']));
m5_check($job['kind'] === 'replace', 'shared M3 real executor');
m5_check(m5_ok(WP_Seed_Pixel_Jobs::start($plan['id']))['id'] === $job['id'], 'Start idempotent');
$job = m5_ok(WP_Seed_Pixel_Jobs::bulk_step($job['id']));
m5_check(($job['states']['retained'] ?? 0) === 1, 'one item per default lot');
$job = m5_ok(WP_Seed_Pixel_Jobs::control($job['id'], 'pause'));
m5_check($job['status'] === 'paused', 'pause');
m5_check(m5_ok(WP_Seed_Pixel_Jobs::bulk_step($job['id']))['states'] === $job['states'], 'paused no replay');
$job = m5_ok(WP_Seed_Pixel_Jobs::control($job['id'], 'resume'));
for ($n = 0; $job['status'] === 'running' && $n < 20; $n++) { $job = m5_ok(WP_Seed_Pixel_Jobs::bulk_step($job['id'])); }
m5_check($job['status'] === 'completed' && ($job['states']['retained'] ?? 0) === 4, 'all four complete');
$operations = $wpdb->get_results($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d AND kind='operation' ORDER BY id", $job['id']), ARRAY_A);
$revisions = array_column($operations, 'revision');
m5_ok(WP_Seed_Pixel_Jobs::bulk_step($job['id']));
$after_rows = $wpdb->get_results($wpdb->prepare("SELECT revision FROM $items WHERE job_id=%d AND kind='operation' ORDER BY id", $job['id']), ARRAY_A);
m5_check(array_column($after_rows, 'revision') === $revisions, 'completed never replayed');
m5_check(is_wp_error(WP_Seed_Pixel_Jobs::quarantine_action($job['id'], 'restore')), 'bulk requires explicit item target');
$first = $operations[0];
$automatic = m5_ok(WP_Seed_Pixel_Quarantine::inspect($first));
m5_check($automatic['rollback_available'] && $automatic['purge_available'], 'bulk automatically binds M4 quarantine before completion');
$view = m5_ok(WP_Seed_Pixel_Jobs::quarantine_action($job['id'], 'retain', array(), (int) $first['id']));
m5_check($view['rollback_available'], 'item recovery available');
m5_ok(WP_Seed_Pixel_Jobs::quarantine_action($job['id'], 'restore', array(), (int) $first['id']));
m5_check(hash_file('sha256', get_attached_file($first['attachment_id'])) === $before[$first['attachment_id']], 'targeted restore exact');
m5_check(is_wp_error(WP_Seed_Pixel_Jobs::restore_master($job['id'])), 'legacy restore cannot select arbitrary bulk item');
$second = $operations[1];
$view = m5_ok(WP_Seed_Pixel_Jobs::quarantine_action($job['id'], 'retain', array(), (int) $second['id']));
$purge = m5_ok(WP_Seed_Pixel_Jobs::quarantine_action($job['id'], 'purge', m4_approval($view), (int) $second['id']));
m5_check($purge['removed_bytes'] > 0 && !$purge['rollback_available'], 'individual M4 purge requires consent and loses rollback');
$before_repeat = WP_Seed_Pixel_Job_Store::item($second['id']);
m5_ok(WP_Seed_Pixel_Jobs::quarantine_action($job['id'], 'purge', m4_approval($view), (int) $second['id']));
m5_check(WP_Seed_Pixel_Job_Store::item($second['id'])['revision'] === $before_repeat['revision'], 'purge receipt not replayed');
$status = m5_ok(WP_Seed_Pixel_Jobs::status($job['id']));
m5_check($status['storage']['removed_source_bytes'] === $purge['removed_bytes'], 'purge bytes aggregated once');
m5_check($status['storage']['allocated_reclaimed_bytes'] === null && $status['storage']['net_reclaimed_bytes'] === null, 'logical receipts never claim quota or net saving');
m5_check(($status['states']['rolled_back'] ?? 0) === 1 && ($status['states']['purged'] ?? 0) === 1, 'mixed restored and purged item truth');
$out = dirname(__DIR__) . '/reports/storage-m5'; wp_mkdir_p($out);
file_put_contents($out . '/integration.json', wp_json_encode(array('checks' => $checks, 'job' => $job, 'ids' => $ids), JSON_PRETTY_PRINT));
echo 'M5 integration: ' . count($checks) . " PASS\n";
