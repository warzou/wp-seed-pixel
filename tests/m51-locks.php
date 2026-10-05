<?php
require __DIR__ . '/m3-runtime.php';
$checks = array(); $latencies = array();
function m51_check($ok, $name) { global $checks; $checks[$name] = (bool) $ok; if (!$ok) { throw new RuntimeException('FAIL: ' . $name); } }
function m51_worker($id, $at, $hold) {
    $root = getenv('PIXEL_M3_ROOT'); $ext = "$root/root/usr/lib/php/20250925";
    $cmd = array(PHP_BINARY, '-d', "extension=$ext/gd.so", '-d', "extension=$ext/mysqli.so", '-d', "extension=$ext/imagick.so", __DIR__ . '/m51-lock-worker.php', (string) $id, (string) $at, (string) $hold);
    $p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($p)) { throw new RuntimeException('Worker unavailable'); } fclose($pipes[0]);
    return array($p, $pipes);
}
foreach (array(2, 10) as $n) {
    $workers = array(); $at = microtime(true) + 3;
    for ($i = 0; $i < $n; $i++) { $workers[] = m51_worker(95001, $at, 3); }
    $owned = 0; $flock = 0;
    foreach ($workers as [$p, $pipes]) {
        $line = json_decode(fgets($pipes[1]), true); $owned += (int) ($line['owned'] ?? false); $flock += (int) ($line['flock'] ?? false); $latencies[] = $line['ms'] ?? null;
        stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p);
        m51_check($exit === 0 && !$errors, "$n workers clean exit $flock");
    }
    m51_check($owned === 1 && $flock === $n, "$n lying flocks exactly one DB owner");
}
$a = WP_Seed_Pixel_Files::lock(95002); $b = WP_Seed_Pixel_Files::lock(95003);
m51_check(!is_wp_error($a) && !is_wp_error($b), 'Different attachments independent');
$clone = clone $a; WP_Seed_Pixel_Files::unlock($clone);
m51_check(WP_Seed_Pixel_Authority::valid(95002), 'Foreign handle cannot release owner');
WP_Seed_Pixel_Files::unlock($a); WP_Seed_Pixel_Files::unlock($b);

[$p, $pipes] = m51_worker(95004, microtime(true), 30);
$line = json_decode(fgets($pipes[1]), true); m51_check($line['owned'], 'Death worker owns session');
proc_terminate($p, 9); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
$deadline = microtime(true) + 2;
do { $recovery = WP_Seed_Pixel_Files::lock(95004); if (!is_wp_error($recovery)) { break; } usleep(20000); } while (microtime(true) < $deadline);
m51_check(!is_wp_error($recovery), 'SIGKILL releases SQL ownership within bounded server cleanup'); WP_Seed_Pixel_Files::unlock($recovery);

global $wpdb;
$old = WP_Seed_Pixel_Files::lock(95005); $connection = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
$killer = mysqli_init(); mysqli_real_connect($killer, 'localhost', 'root', '', 'pixel_m3', 0, getenv('PIXEL_M3_ROOT') . '/mysql.sock');
mysqli_query($killer, 'KILL ' . $connection); mysqli_close($killer);
$wpdb->suppress_errors(true);
m51_check(!WP_Seed_Pixel_Authority::valid(95005), 'Disconnected old owner fails closed after reconnect');
$blocked = WP_Seed_Pixel_Master_Storage::save('/not-a-pixel-directory', array());
m51_check(is_wp_error($blocked) && $blocked->get_error_code() === 'LOCKED', 'No journal write without authoritative DB');
WP_Seed_Pixel_Files::unlock($old); $next = WP_Seed_Pixel_Files::lock(95005);
m51_check(!is_wp_error($next), 'New DB session can claim generation');
WP_Seed_Pixel_Files::unlock($old); m51_check(WP_Seed_Pixel_Authority::valid(95005), 'ABA old release cannot remove new owner'); WP_Seed_Pixel_Files::unlock($next);
$wpdb->suppress_errors(false);

$id = m3_fixture('m3-small.jpeg', false); $path = get_attached_file($id); $im = getimagesize($path);
wp_update_attachment_metadata($id, array('file' => _wp_relative_upload_path($path), 'width' => $im[0], 'height' => $im[1], 'filesize' => filesize($path), 'sizes' => array()));
$j1 = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824);
$j2 = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824);
m51_check(!is_wp_error($j1) && !is_wp_error($j2), 'Two jobs may plan one attachment');
$item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation'", $j1['id']), ARRAY_A);
$site = WP_Seed_Pixel_Files::lock(0); $media = WP_Seed_Pixel_Files::lock($id); $token = bin2hex(random_bytes(24));
$wpdb->update(WP_Seed_Pixel_Job_Store::table('items'), array('lease' => $token, 'lease_until' => time() - 5), array('id' => $item['id']));
$wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('lease' => $token, 'lease_until' => time() - 5), array('id' => $j1['id']));
$item = WP_Seed_Pixel_Job_Store::item($item['id']);
$renewed = WP_Seed_Pixel_Job_Store::renew($item, $token);
m51_check(!is_wp_error($renewed) && (int) $renewed['lease_until'] > time(), 'Expired lease renews only under live session and token');
m51_check(is_wp_error(WP_Seed_Pixel_Job_Store::renew($renewed, 'foreign')), 'Foreign lease cannot renew');
[$p, $pipes] = m51_worker(0, microtime(true), 0);
$line = json_decode(fgets($pipes[1]), true); stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
m51_check(!$line['owned'], 'Live owner blocks takeover despite expired persisted lease');
WP_Seed_Pixel_Files::unlock($media); WP_Seed_Pixel_Files::unlock($site);
$wpdb->update(WP_Seed_Pixel_Job_Store::table('items'), array('stage' => 'preparing', 'lease_until' => 0), array('id' => $item['id']));
$wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('lease_until' => 0), array('id' => $j1['id']));
$other = WP_Seed_Pixel_Jobs::step($j2['id']);
m51_check(is_wp_error($other) && $other->get_error_code() === 'CLAIM_CONFLICT', 'Incomplete first job excludes second destructive job');
$hash = hash_file('sha256', $path);
$site = WP_Seed_Pixel_Files::lock(0); $media = WP_Seed_Pixel_Files::lock($id);
$stale = WP_Seed_Pixel_Job_Store::transition($renewed, $token, 'preparing');
m51_check(is_wp_error($stale) && hash_file('sha256', $path) === $hash, 'Old token and expired lease cannot transition');
WP_Seed_Pixel_Files::unlock($media); WP_Seed_Pixel_Files::unlock($site);
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-m5.1');
file_put_contents(dirname(__DIR__) . '/reports/storage-m5.1/locks.json', wp_json_encode(array('checks' => $checks, 'claim_ms' => $latencies, 'database' => $wpdb->get_var('SELECT VERSION()')), JSON_PRETTY_PRINT));
echo count($checks) . " M5.1 authority checks passed.\n";
