<?php
require __DIR__ . '/format-bootstrap.php';
global $wpdb;
$checks = array();
function claim_ok($v, $name) { global $checks; if (!$v || is_wp_error($v)) { throw new RuntimeException($name . (is_wp_error($v) ? ':' . $v->get_error_code() : '')); } $checks[$name] = true; }
function claim_block($id, $name) { $v = WP_Seed_Pixel_Jobs::reconcile_claims($id); claim_ok(is_wp_error($v), $name); }
$id = format_fixture(); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id); $meta = wp_get_attachment_metadata($id);
$bad = $meta; $bad['width']++; wp_update_attachment_metadata($id, $bad);
$scan = WP_Seed_Pixel_Scan::start();
claim_ok($scan, 'scan started');
while (!is_wp_error($scan) && $scan['status'] === 'running') { $scan = WP_Seed_Pixel_Scan::step($scan['id']); }
claim_ok($scan, 'scan complete');
$plan = WP_Seed_Pixel_Jobs::bulk_plan($scan['id'], array('master' => 'replace_verified'), 1073741824, 'replace', 1, false, array($id));
claim_ok($plan, 'selected plan');
while ($plan['status'] === 'queued') { $plan = WP_Seed_Pixel_Jobs::bulk_step($plan['id']); claim_ok($plan, 'plan step'); }
$job = WP_Seed_Pixel_Jobs::start($plan['id']); claim_ok($job, 'official rejected operation');
$job = WP_Seed_Pixel_Jobs::bulk_step($job['id']); claim_ok($job, 'official review terminal');
$items = WP_Seed_Pixel_Job_Store::table('items'); $jobs = WP_Seed_Pixel_Job_Store::table('jobs');
$item = $wpdb->get_row($wpdb->prepare("SELECT * FROM $items WHERE job_id=%d AND kind='operation'", $job['id']), ARRAY_A);
$parent = WP_Seed_Pixel_Job_Store::job($job['id']);
claim_ok($item['stage'] === 'needs_review' && (int) $item['revision'] === 0 && $parent['status'] === 'completed_errors', 'faithful preexecution review fixture');
wp_update_attachment_metadata($id, $meta);
$first = WP_Seed_Pixel_Jobs::reconcile_claims($id); claim_ok($first, 'official reconciliation succeeds');
claim_ok($first['mutations'] === 0 && $first['reviews'][0]['item_id'] === (int) $item['id'], 'read-only classification evidence');
$second = WP_Seed_Pixel_Jobs::reconcile_claims($id); claim_ok($first === $second, 'second reconciliation exact no-op');
claim_ok(WP_Seed_Pixel_Job_Store::item($item['id']) === $item && WP_Seed_Pixel_Job_Store::job($job['id']) === $parent, 'historical rows retained byte-identical');
claim_ok(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'dry reconciliation preserves media');
wp_set_current_user(0); claim_block($id, 'anonymous reconciliation denied'); wp_set_current_user(1);
foreach (array('stage' => 'preparing', 'revision' => 1, 'attempts' => 1, 'bytes' => 1, 'owners' => 1, 'lease' => 'foreign', 'lease_until' => time() + 60, 'error_code' => 'SOURCE_CHANGED', 'journal' => '{}', 'receipt' => '{}', 'snapshot_hash' => str_repeat('0', 64)) as $key => $value) {
    $wpdb->update($items, array($key => $value), array('id' => $item['id'])); claim_block($id, 'execution or ambiguous evidence blocks ' . $key);
    $wpdb->update($items, array($key => $item[$key]), array('id' => $item['id']));
}
foreach (array('status' => 'running', 'lease' => 'expired-but-not-empty', 'lease_until' => time() + 60, 'policy_hash' => str_repeat('0', 64)) as $key => $value) {
    $wpdb->update($jobs, array($key => $value), array('id' => $job['id'])); claim_block($id, 'ambiguous parent blocks ' . $key);
    $wpdb->update($jobs, array($key => $parent[$key]), array('id' => $job['id']));
}
$dir = WP_SEED_PIXEL_RECOVERY_ROOT . '/m3-' . $job['id'] . '-' . $item['id'];
mkdir($dir, 0700); claim_block($id, 'unexpected recovery directory blocks'); rmdir($dir);
symlink(WP_SEED_PIXEL_RECOVERY_ROOT . '/absent', $dir); claim_block($id, 'dangling recovery symlink blocks'); unlink($dir);
foreach (array(0, $id) as $slot) {
    list($process, $pipes) = format_worker(array((string) $slot, 'hold'));
    claim_ok(trim(fgets($pipes[1])) === 'HELD', 'real SQL owner acquired ' . $slot);
    claim_block($id, 'live SQL authority cannot be stolen ' . $slot);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); claim_ok(proc_close($process) === 0, 'owned test worker exits normally ' . $slot);
}
$other = format_fixture(); $pending = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824); claim_ok($pending, 'legitimate pending operation');
claim_block($id, 'legitimate queued work still blocks');
$unrelated = WP_Seed_Pixel_Format_Conversion::analyze($other); claim_ok($unrelated, 'blocked attachment does not globally block another attachment');
claim_ok(WP_Seed_Pixel_Jobs::control($pending['id'], 'cancel'), 'official pending cancellation');
$result = WP_Seed_Pixel_Format_Conversion::analyze($id); claim_ok($result, 'conversion acquires claim after safe review reconciliation');
claim_ok($result['phase'] === 'ready' && WP_Seed_Pixel_Job_Store::item($item['id']) === $item, 'history preserved after candidate acquisition');
$dir = getenv('PIXEL_FORMAT_REPORT_DIR');
file_put_contents($dir . '/claims-' . ($argv[1] ?? 'dev') . '.json', wp_json_encode(array('checks' => $checks, 'count' => count($checks)), JSON_PRETTY_PRINT));
echo count($checks) . " claim reconciliation checks PASS\n";
