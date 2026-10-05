<?php
require __DIR__ . '/m4-runtime.php';
global $wpdb;
$op = $argv[1] ?? ''; $job = (int) ($argv[2] ?? 0);
if ($op === 'create') {
    if (($argv[2] ?? '') === 'original') { list($id, $job) = m4_original(); $result = array('id' => $job, 'attachment_id' => $id); }
    else { $enroll = ($argv[2] ?? '') !== 'unbound'; list($id, $job, $view) = m4_replaced($enroll); $result = array('id' => $job, 'attachment_id' => $id, 'approval' => $enroll ? m4_approval($view) : null); }
} elseif ($op === 'expire') {
    foreach (array('jobs', 'items') as $suffix) { $key = $suffix === 'jobs' ? 'id' : 'job_id'; $wpdb->update(WP_Seed_Pixel_Job_Store::table($suffix), array('lease_until' => time() - 1), array($key => $job)); }
    $result = true;
} elseif ($op === 'run') {
    $action = $argv[3]; $boundary = $argv[4] ?? '';
    $hook = static function ($name) use ($boundary) { if ($name === $boundary) { posix_kill(getmypid(), SIGKILL); } };
    add_action('wp_seed_pixel_m4_boundary', $hook); add_action('wp_seed_pixel_m3_boundary', $hook);
    $view = WP_Seed_Pixel_Quarantine::inspect(m4_item($job));
    $result = $action === 'step' ? WP_Seed_Pixel_Jobs::step($job) : WP_Seed_Pixel_Jobs::quarantine_action($job, $action, $action === 'purge' ? m4_approval($view) : array());
} elseif ($op === 'state') {
    $item = m4_item($job); $dir = WP_Seed_Pixel_Master_Storage::directory($item, false);
    $r = is_wp_error($dir) ? null : WP_Seed_Pixel_Master_Storage::load($dir, $item);
    $view = WP_Seed_Pixel_Quarantine::inspect($item);
    $result = array('stage' => $item['stage'], 'lease_until' => (int) $item['lease_until'], 'journal_valid' => WP_Seed_Pixel_Job_Store::journal_valid($item),
        'phase' => is_array($r) ? $r['phase'] : null, 'view' => is_wp_error($view) ? null : $view,
        'master_sha' => hash_file('sha256', get_attached_file($item['attachment_id'])),
        'before_sha' => is_array($r) ? $r['before']['sha256'] : null, 'candidate_sha' => is_array($r) ? $r['candidate']['sha256'] : null);
} else { throw new RuntimeException('Unknown local test action'); }
if (is_wp_error($result)) { $result = array('error' => $result->get_error_code()); }
echo wp_json_encode($result) . "\n";
