<?php
require __DIR__ . '/m4-runtime.php';
global $wpdb;
$checks = array();
function m5_scale_check($value, $label) {
    global $checks; $checks[$label] = (bool) $value;
    if (!$value) { throw new RuntimeException('FAIL: ' . $label); }
}
function m5_scale_ok($r) { if (is_wp_error($r)) { throw new RuntimeException($r->get_error_code()); } return $r; }
$shared = m3_fixture('m3-detail.jpg', false); $path = get_attached_file($shared);
$meta = wp_get_attachment_metadata($shared); $before_sha = hash_file('sha256', $path);
for ($n = 0; $n < 2000; $n++) {
    $id = wp_insert_attachment(array('post_title' => 'Synthetic M5 alias ' . $n, 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'), $path);
    wp_update_attachment_metadata($id, $meta);
}
$scan = m5_scale_ok(WP_Seed_Pixel_Scan::start());
$started = microtime(true);
for ($n = 0; $scan['status'] === 'running' && $n < 2500; $n++) { $scan = m5_scale_ok(WP_Seed_Pixel_Scan::step($scan['id'])); }
m5_scale_check($scan['status'] === 'complete' && (int) $scan['total'] >= 2001, 'large M1 inventory completed');
$plan = m5_scale_ok(WP_Seed_Pixel_Jobs::bulk_plan($scan['id'], array('master' => 'replace_verified'), 1073741824));
for ($n = 0; $plan['status'] === 'queued' && $n < 200; $n++) { $plan = m5_scale_ok(WP_Seed_Pixel_Jobs::bulk_step($plan['id'])); }
m5_scale_check($plan['status'] === 'completed', 'large frozen plan completed');
m5_scale_check(($plan['states']['needs_review'] ?? 0) >= 2001, 'shared physical source excluded for every alias');
$job = m5_scale_ok(WP_Seed_Pixel_Jobs::start($plan['id']));
m5_scale_check((int) $job['total'] === (int) $scan['total'], 'plan item membership frozen');
$wpdb->num_queries = 0;
$page = m5_scale_ok(WP_Seed_Pixel_Jobs::results($job['id'], 50));
$queries = $wpdb->num_queries;
m5_scale_check(count($page['items']) === 20 && $page['pages'] >= 101, 'bounded results page 50');
m5_scale_check($queries < 15, 'cold page query count bounded');
m5_scale_check(hash_file('sha256', $path) === $before_sha, 'shared source exact bytes preserved');
$cancel = m5_scale_ok(WP_Seed_Pixel_Jobs::control($job['id'], 'cancel'));
m5_scale_check($cancel['status'] === 'cancelled', 'pending-only cancellation');
$out = dirname(__DIR__) . '/reports/storage-m5'; wp_mkdir_p($out);
file_put_contents($out . '/scale.json', wp_json_encode(array('checks' => $checks, 'relations' => $scan['total'], 'real_encodings' => 0,
    'page_queries' => $queries, 'seconds' => microtime(true) - $started, 'plan' => $plan), JSON_PRETTY_PRINT));
echo 'M5 scale: ' . count($checks) . ' PASS; relations=' . $scan['total'] . "\n";
