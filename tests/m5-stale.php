<?php
require __DIR__ . '/m4-runtime.php';
global $wpdb;
$checks = array();
function m5_stale_check($v, $label) { global $checks; $checks[$label] = (bool) $v; if (!$v) { throw new RuntimeException('FAIL: ' . $label); } }
function m5_stale_ok($r) { if (is_wp_error($r)) { throw new RuntimeException($r->get_error_code()); } return $r; }
$ids = array();
for ($n = 0; $n < 5; $n++) { $ids[] = m3_fixture('m3-detail.jpg', false); }
$scan = m5_stale_ok(WP_Seed_Pixel_Scan::start());
while ($scan['status'] === 'running') { $scan = m5_stale_ok(WP_Seed_Pixel_Scan::step($scan['id'])); }
$plan = m5_stale_ok(WP_Seed_Pixel_Jobs::bulk_plan($scan['id'], array('master' => 'replace_verified'), 1073741824));
while ($plan['status'] === 'queued') { $plan = m5_stale_ok(WP_Seed_Pixel_Jobs::bulk_step($plan['id'])); }
foreach (array_slice($ids, 0, 2) as $id) {
    $meta = wp_get_attachment_metadata($id); $meta['external_synthetic_writer'] = 'changed after freeze'; wp_update_attachment_metadata($id, $meta);
}
$late = m3_fixture('m3-detail.jpg', false);
$job = m5_stale_ok(WP_Seed_Pixel_Jobs::start($plan['id']));
m5_stale_check((int) $job['total'] === 5, 'new attachment outside frozen plan');
$policy_hash = $job['policy_hash'];
update_option('wp_seed_pixel_m5_synthetic_changed_settings', array('quality' => 'quality'), false);
for ($n = 0; $n < 3; $n++) { $job = m5_stale_ok(WP_Seed_Pixel_Jobs::bulk_step($job['id'])); }
m5_stale_check(($job['states']['needs_review'] ?? 0) === 2 && $job['status'] === 'running', 'two stale peers do not stop healthy job');
m5_stale_check(($job['states']['retained'] ?? 0) === 1, 'healthy peer completed');
m5_stale_check($job['policy_hash'] === $policy_hash, 'settings do not alter frozen policy');
$job = m5_stale_ok(WP_Seed_Pixel_Jobs::control($job['id'], 'cancel'));
m5_stale_check($job['status'] === 'cancelled' && ($job['states']['cancelled'] ?? 0) === 2, 'cancel pending only');
m5_stale_check(($job['states']['retained'] ?? 0) === 1 && ($job['states']['needs_review'] ?? 0) === 2, 'cancel preserves successful and review results');
m5_stale_check(is_wp_error(WP_Seed_Pixel_Jobs::control($job['id'], 'retry')), 'cancelled job cannot replay success');
$out = dirname(__DIR__) . '/reports/storage-m5'; wp_mkdir_p($out);
file_put_contents($out . '/stale.json', wp_json_encode(array('checks' => $checks, 'job' => $job, 'late_id' => $late), JSON_PRETTY_PRINT));
echo 'M5 stale/cancel: ' . count($checks) . " PASS\n";
