<?php
require __DIR__ . '/m3-runtime.php';
error_reporting(E_ALL & ~E_DEPRECATED);
global $wpdb;
$op = $argv[1] ?? ''; $job = (int) ($argv[2] ?? 0);
function m3_tools_item($id) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation'", $id), ARRAY_A); }
if ($op === 'create') {
    $id = m3_fixture('m3-detail.jpg', false);
    $file = get_attached_file($id); $info = getimagesize($file);
    wp_update_attachment_metadata($id, array('file' => _wp_relative_upload_path($file), 'width' => $info[0], 'height' => $info[1], 'filesize' => filesize($file), 'sizes' => array()));
    $result = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824);
} elseif ($op === 'duplicate') {
    $item = m3_tools_item($job);
    $result = WP_Seed_Pixel_Jobs::replace_one((int) $item['attachment_id'], array('master' => 'replace_verified'), 1073741824);
} elseif ($op === 'state') {
    $item = m3_tools_item($job); $dir = WP_Seed_Pixel_Master_Storage::directory($item); $r = WP_Seed_Pixel_Master_Storage::load($dir, $item);
    $current = WP_Seed_Pixel_Master_Adapter::snapshot($item['attachment_id'], true);
    $result = array('item' => array_intersect_key($item, array_flip(array('id','attachment_id','stage','error_code','revision','lease_until'))),
        'canonical' => $current['sha256'] ?? '', 'journal_valid' => WP_Seed_Pixel_Job_Store::journal_valid($item),
        'before' => $r['before']['sha256'] ?? '', 'candidate' => $r['candidate']['sha256'] ?? '', 'phase' => $r['phase'] ?? '',
        'candidate_exists' => is_file($dir . '/candidate.jpg'), 'recovery_exists' => is_file($dir . '/recovery.jpg'),
        'meta_before' => ($current['rows']['_wp_attachment_metadata'] ?? '') === ($r['before']['rows']['_wp_attachment_metadata'] ?? 'missing'),
        'meta_after' => isset($r['after']) && ($current['rows']['_wp_attachment_metadata'] ?? '') === array(maybe_serialize($r['after'])),
        'status' => WP_Seed_Pixel_Jobs::status($job));
} elseif ($op === 'expire') {
    $wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'), array('lease_until' => 0), array('id' => $job));
    $wpdb->update(WP_Seed_Pixel_Job_Store::table('items'), array('lease_until' => 0), array('job_id' => $job)); $result = true;
} elseif ($op === 'unknown') {
    $item = m3_tools_item($job); $path = get_attached_file($item['attachment_id']);
    // Intentionally simulate another writer's unexpected bytes, not engine behavior.
    $f = fopen($path, 'ab'); fwrite($f, 'foreign-writer'); fclose($f); $result = hash_file('sha256', $path);
} elseif ($op === 'step') { $result = WP_Seed_Pixel_Jobs::step($job); }
elseif ($op === 'restore') { $result = WP_Seed_Pixel_Jobs::restore_master($job); }
elseif ($op === 'resume') { $result = WP_Seed_Pixel_Jobs::control($job, 'resume'); }
elseif ($op === 'hold') {
    $item = m3_tools_item($job); $lock = WP_Seed_Pixel_Files::lock($item['attachment_id']);
    if (is_wp_error($lock)) { throw new RuntimeException('Lock failed'); }
    echo "OWNED\n"; flush(); sleep(8); WP_Seed_Pixel_Files::unlock($lock); exit;
} else { throw new RuntimeException('Unknown owned test action'); }
echo wp_json_encode(is_wp_error($result) ? array('error' => $result->get_error_code()) : $result) . "\n";
