<?php
require __DIR__ . '/m3-runtime.php';
$checks = array();
function m51_crash_check($ok, $name) { global $checks; $checks[$name] = (bool) $ok; if (!$ok) { throw new RuntimeException('FAIL: ' . $name); } }
$root = getenv('PIXEL_M3_ROOT'); $ext = "$root/root/usr/lib/php/20250925";
$cmd = array(PHP_BINARY, '-d', "extension=$ext/gd.so", '-d', "extension=$ext/mysqli.so", '-d', "extension=$ext/imagick.so", __DIR__ . '/m51-crash-worker.php');
$p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes); fclose($pipes[0]);
$fixture = json_decode(fgets($pipes[1]), true); stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p);
m51_crash_check($exit !== 0 && !$err && !empty($fixture['id']), 'Actual future worker SIGKILL');
$id = (int) $fixture['id']; wp_cache_delete('alloptions', 'options'); wp_cache_delete(WP_Seed_Pixel_Future_Uploads::OPTION, 'options'); wp_cache_delete($id, 'post_meta');
$v = get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true); $job = (int) $v['job_id'];
global $wpdb;
$item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation'", $job), ARRAY_A);
$dir = WP_Seed_Pixel_Master_Storage::directory($item, false); $candidate = hash_file('sha256', $dir . '/candidate.jpg');
m51_crash_check(hash_file('sha256', get_attached_file($id)) === $fixture['before_sha256'] && $item['stage'] === 'preparing', 'Death before publication leaves native upload intact and durable stage');
// Advance only the synthetic expired lease clock; keep tokens, revisions and all evidence.
$wpdb->update(WP_Seed_Pixel_Job_Store::table('items'), array('lease_until' => time() - 1), array('id' => $item['id']));
$wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('lease_until' => time() - 1), array('id' => $job));
WP_Seed_Pixel_Future_Uploads::run($id);
$item = WP_Seed_Pixel_Job_Store::item($item['id']);
m51_crash_check($item['stage'] === 'retained', 'Reloaded future generation resumes accepted reconciliation');
m51_crash_check(hash_file('sha256', get_attached_file($id)) === $candidate, 'Crash candidate reused not re-encoded');
WP_Seed_Pixel_Future_Uploads::run($id); wp_cache_delete($id, 'post_meta');
m51_crash_check((int) get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true)['job_id'] === $job
    && (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE attachment_id=%d AND kind='operation'", $id)) === 1, 'Persisted enrollment idempotent after reload and duplicate cron');
m51_crash_check(!is_wp_error(WP_Seed_Pixel_Quarantine::inspect($item)) && WP_Seed_Pixel_Quarantine::inspect($item)['rollback_available'], 'Resumed future job retains verified M4 rollback');
WP_Seed_Pixel_Future_Uploads::configure('off');
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-m5.1');
file_put_contents(dirname(__DIR__) . '/reports/storage-m5.1/crash.json', wp_json_encode(array('checks' => $checks), JSON_PRETTY_PRINT));
echo count($checks) . " M5.1 future crash checks passed.\n";
