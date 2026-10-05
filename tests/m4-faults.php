<?php
require __DIR__ . '/m4-runtime.php';
$checks = array();
foreach (array('corrupt', 'missing', 'symlink', 'hardlink', 'metadata', 'master', 'permission', 'manifest') as $fault) {
    list($id, $job, $view) = m4_replaced(); $item = m4_item($job); $dir = WP_Seed_Pixel_Master_Storage::directory($item); $q = $dir . '/recovery.jpg';
    $master = get_attached_file($id); $sha = hash_file('sha256', $master); $original = file_get_contents($q);
    if ($fault === 'corrupt') { file_put_contents($q, 'unknown bytes'); }
    if ($fault === 'missing') { unlink($q); }
    if ($fault === 'symlink') { unlink($q); symlink($master, $q); }
    if ($fault === 'hardlink') { link($q, $dir . '/second-link'); }
    if ($fault === 'metadata') { $meta = wp_get_attachment_metadata($id); $meta['foreign'] = 'external wins'; wp_update_attachment_metadata($id, $meta); }
    if ($fault === 'master') { file_put_contents($master, $original); $sha = hash_file('sha256', $master); }
    if ($fault === 'permission') { chmod($dir, 0500); }
    if ($fault === 'manifest') { $r = WP_Seed_Pixel_Quarantine::record($item); $r['before']['relative'] = '../../sentinel'; m4_fault_journal($id, $dir, $r); }
    $purge = WP_Seed_Pixel_Jobs::quarantine_action($job, 'purge', m4_approval($view));
    m4_check(is_wp_error($purge), $fault . ' purge refuses');
    m4_check(hash_file('sha256', $master) === $sha, $fault . ' active never deleted');
    $now = WP_Seed_Pixel_Quarantine::inspect(m4_item($job));
    m4_check(is_wp_error($now) || $now['removed_bytes'] === 0, $fault . ' no false saving');
    if ($fault !== 'permission') { m4_check(is_wp_error($now) || !$now['rollback_available'], $fault . ' no false rollback'); }
    if ($fault === 'permission') { chmod($dir, 0700); }
}
list($id, $job, $view) = m4_replaced(); $item = m4_item($job); $dir = WP_Seed_Pixel_Master_Storage::directory($item);
$change = static function ($name) use ($id) { if ($name === 'purge_intent') { $meta = wp_get_attachment_metadata($id); $meta['external'] = true; wp_update_attachment_metadata($id, $meta); } };
add_action('wp_seed_pixel_m4_boundary', $change);
$result = WP_Seed_Pixel_Jobs::quarantine_action($job, 'purge', m4_approval($view));
remove_action('wp_seed_pixel_m4_boundary', $change);
m4_check(is_wp_error($result) && file_exists($dir . '/recovery.jpg'), 'purge race external metadata wins');

list($id, $job, $view) = m4_replaced(); $item = m4_item($job); $dir = WP_Seed_Pixel_Master_Storage::directory($item);
$before = WP_Seed_Pixel_Quarantine::record($item);
$tamper = static function ($name) use ($dir, $before) { if ($name === 'purge_deleted') { file_put_contents($dir . '/recovery.jpg', 'foreign reappearance'); } };
add_action('wp_seed_pixel_m4_boundary', $tamper);
$result = WP_Seed_Pixel_Jobs::quarantine_action($job, 'purge', m4_approval($view)); remove_action('wp_seed_pixel_m4_boundary', $tamper);
m4_check(is_wp_error($result) && file_get_contents($dir . '/recovery.jpg') === 'foreign reappearance', 'unknown reappearance never deleted');
m4_check(WP_Seed_Pixel_Quarantine::inspect(m4_item($job))['removed_bytes'] === 0, 'reappeared file not reclaimed');

list($id, $job) = m4_original(); $meta = wp_get_attachment_metadata($id); $meta['extra'] = 'concurrent'; wp_update_attachment_metadata($id, $meta);
$original = wp_get_original_image_path($id); $sha = hash_file('sha256', $original);
WP_Seed_Pixel_Jobs::step($job);
m4_check(hash_file('sha256', $original) === $sha && m4_item($job)['stage'] === 'needs_review', 'stale original plan refuses');
$id = m3_fixture('m3-large.jpg'); $original = wp_get_original_image_path($id);
$post = wp_insert_post(array('post_title' => 'Direct original reference', 'post_content' => '<img src="' . basename($original) . '">'));
m4_check(is_wp_error(WP_Seed_Pixel_Jobs::retire_original($id, 1073741824, true)), 'known original reference blocks retirement');
m4_check(is_wp_error(WP_Seed_Pixel_Jobs::retire_original($id, 1073741824, false)), 'unknown URL risk requires explicit acknowledgment');
wp_set_current_user(0);
m4_check(is_wp_error(WP_Seed_Pixel_Jobs::quarantine_action($job, 'purge', array())), 'anonymous purge denied');
m4_report('faults');
