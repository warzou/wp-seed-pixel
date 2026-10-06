<?php
require __DIR__ . '/m4-runtime.php';
$checks = array();
WP_Seed_Pixel_Future_Uploads::configure('off');
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array());
function cleanup_ok($r) { if (is_wp_error($r)) { throw new RuntimeException($r->get_error_code()); } return $r; }
function cleanup_fixture($boundary = '') {
    $id = m3_fixture('m6-efficient.png');
    $before = cleanup_ok(WP_Seed_Pixel_Master_Adapter::snapshot($id));
    $job = cleanup_ok(WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824));
    $hit = false;
    $crash = static function ($name) use ($boundary, &$hit) { if ($boundary && $name === $boundary) { $hit = true; throw new RuntimeException('Injected cleanup crash'); } };
    add_action('wp_seed_pixel_m3_boundary', $crash);
    try {
        $result = WP_Seed_Pixel_Jobs::step($job['id']);
        if (!$hit || !$boundary) { cleanup_ok($result); }
        elseif (!is_wp_error($result) || $result->get_error_code() !== 'STORE_FAILED') { throw new RuntimeException('Crash boundary was not fenced'); }
    }
    catch (RuntimeException $e) { if ($e->getMessage() !== 'Injected cleanup crash') { throw $e; } }
    finally { remove_action('wp_seed_pixel_m3_boundary', $crash); }
    $item = m4_item($job['id']);
    return array($id, $job['id'], $item, WP_Seed_Pixel_Master_Storage::directory($item, false), $before);
}
foreach (array('', 'terminal_decision', 'cleanup_intent', 'cleanup_before_unlink', 'cleanup_after_unlink', 'cleanup_complete') as $boundary) {
    list($id, $job, $item, $dir, $before) = cleanup_fixture($boundary);
    m4_check($item['stage'] === 'skipped' && $item['error_code'] === 'NO_BENEFIT', "$boundary decision persisted before cleanup");
    if ($boundary) { cleanup_ok(WP_Seed_Pixel_Jobs::step($job)); }
    $item = m4_item($job); $revision = $item['revision'];
    cleanup_ok(WP_Seed_Pixel_Jobs::reconcile_unreplaced($job, (int) $item['id']));
    m4_check(m4_item($job)['revision'] === $revision, "$boundary repeated cleanup is idempotent");
    m4_check(cleanup_ok(WP_Seed_Pixel_Master_Adapter::snapshot($id)) === $before, "$boundary source and complete native graph exact");
    m4_check(!file_exists($dir . '/recovery.jpg') && !file_exists($dir . '/candidate.jpg'), "$boundary no large file overhead");
    $r = cleanup_ok(WP_Seed_Pixel_Master_Storage::load($dir, $item));
    m4_check($r['phase'] === 'cleaned' && filesize($dir . '/journal.json') > 0, "$boundary bounded truthful audit metadata remains");
    $storage = json_decode($item['journal'], true)['storage'];
    m4_check($storage === array('active_delta' => 0, 'recovery_bytes' => 0), "$boundary zero actual saving and recovery bytes");
    $html = WP_Seed_Pixel_Media::details($id);
    m4_check(substr_count($html, 'class="pixel-media-panel"') === 1 && strpos($html, 'image_restore') === false
        && strpos($html, 'image_purge') === false && strpos($html, 'image_start') === false, "$boundary no false restore purge or retry");
}
foreach (array('source', 'derivative', 'symlink', 'hardlink', 'checksum', 'possible_swap', 'foreign_file', 'unknown_candidate') as $fault) {
    list($id, $job, $item, $dir, $before) = cleanup_fixture('terminal_decision');
    $source = get_attached_file($id);
    if ($fault === 'source') { file_put_contents($source, file_get_contents($source) . 'generation change'); }
    if ($fault === 'derivative') {
        // Efficient fixture may be too small for a derivative: metadata is still part of generation identity.
        $meta = wp_get_attachment_metadata($id); $meta['cleanup_fault'] = true; wp_update_attachment_metadata($id, $meta);
    }
    if ($fault === 'symlink') { unlink($dir . '/recovery.jpg'); symlink($source, $dir . '/recovery.jpg'); }
    if ($fault === 'hardlink') { unlink($dir . '/recovery.jpg'); link($source, $dir . '/recovery.jpg'); }
    if ($fault === 'checksum') { file_put_contents($dir . '/journal.json', '{}'); }
    if ($fault === 'foreign_file') { file_put_contents($dir . '/foreign.txt', 'unowned'); }
    if ($fault === 'unknown_candidate') { file_put_contents($dir . '/candidate.jpg', 'unpublished'); }
    if ($fault === 'possible_swap') { $r = cleanup_ok(WP_Seed_Pixel_Master_Storage::load($dir, $item)); $r['phase'] = 'switch_intent'; m4_fault_journal($id, $dir, $r); }
    $sha = hash_file('sha256', $source);
    $r = WP_Seed_Pixel_Jobs::reconcile_unreplaced($job, (int) $item['id']);
    m4_check(is_wp_error($r), "$fault cleanup fails closed");
    m4_check(file_exists($dir . '/recovery.jpg') && hash_file('sha256', $source) === $sha, "$fault active source and recovery preserved");
}
list($id, $job) = m4_replaced(); $item = m4_item($job); $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
$r = WP_Seed_Pixel_Jobs::reconcile_unreplaced($job, (int) $item['id']);
m4_check(is_wp_error($r) && file_exists($dir . '/recovery.jpg'), 'Successful replacement retains required recovery');
cleanup_ok(WP_Seed_Pixel_Jobs::quarantine_action($job, 'restore'));
m4_check(m4_item($job)['stage'] === 'rolled_back', 'Successful recovery remains restorable');
wp_set_current_user(0);
$r = WP_Seed_Pixel_Jobs::reconcile_unreplaced($job, (int) $item['id']);
m4_check(is_wp_error($r) && $r->get_error_code() === 'PERMISSION_DENIED', 'Reconciliation requires administrator');
wp_set_current_user(1);
foreach (array('terminal_decision', 'cleanup_intent', 'cleanup_after_unlink', 'cleanup_complete') as $boundary) {
    $command = array('/bin/bash', '/mnt/c/Dev/git/wp-seed-pixel/tests/m4-linux-php.sh', __DIR__ . '/unreplaced-cleanup-worker.php', $boundary);
    $process = proc_open($command, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    fclose($pipes[0]); $fixture = json_decode(fgets($pipes[1]), true); stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
    m4_check($exit !== 0 && is_array($fixture) && strpos($stderr, 'Fatal error') === false, "$boundary real SIGKILL boundary reached");
    $item = WP_Seed_Pixel_Job_Store::item($fixture['item_id']);
    m4_check($item['stage'] === 'skipped' && $item['error_code'] === 'NO_BENEFIT', "$boundary killed worker persisted decision");
    // Expire only the synthetic lease clock; preserve tokens, CAS revision and all evidence.
    $wpdb->update(WP_Seed_Pixel_Job_Store::table('items'), array('lease_until' => time() - 1), array('id' => $item['id']));
    $wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('lease_until' => time() - 1), array('id' => $fixture['job_id']));
    $no_encode = static function ($name) { if ($name === 'escrow') { throw new RuntimeException('Unexpected encoding on cleanup resume'); } };
    add_action('wp_seed_pixel_m3_boundary', $no_encode);
    try { cleanup_ok(WP_Seed_Pixel_Jobs::step($fixture['job_id'])); }
    finally { remove_action('wp_seed_pixel_m3_boundary', $no_encode); }
    $item = WP_Seed_Pixel_Job_Store::item($item['id']); $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
    m4_check(hash_file('sha256', get_attached_file($fixture['attachment_id'])) === $fixture['sha256']
        && !file_exists($dir . '/recovery.jpg') && !file_exists($dir . '/candidate.jpg'), "$boundary killed worker resumes without encoding or leak");
}
m4_report('unreplaced-cleanup');
