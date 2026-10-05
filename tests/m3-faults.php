<?php
require __DIR__ . '/m3-runtime.php';
error_reporting(E_ALL & ~E_DEPRECATED);
global $wpdb;
$checks = array();
function fcheck($value, $name) { global $checks; $checks[$name] = (bool) $value; if (!$value) { throw new RuntimeException('FAIL ' . $name); } }
function fixture_job() {
    $id = m3_fixture('m3-detail.jpg', false); $p = get_attached_file($id); $i = getimagesize($p);
    wp_update_attachment_metadata($id, array('file' => _wp_relative_upload_path($p), 'width' => $i[0], 'height' => $i[1], 'filesize' => filesize($p), 'sizes' => array()));
    $j = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824);
    return array($id, (int) $j['id'], WP_Seed_Pixel_Master_Adapter::snapshot($id));
}
function fi($job) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' WHERE job_id=%d', $job), ARRAY_A); }
foreach (array('candidate.jpg' => 'SWAP_FAILED', 'recovery.jpg' => 'BACKUP_FAILED') as $file => $code) {
    [$id, $job, $before] = fixture_job();
    $hook = static function ($name, $item_id) use ($file) {
        if ($name === 'pre_swap') { $item = WP_Seed_Pixel_Job_Store::item($item_id); $dir = WP_Seed_Pixel_Master_Storage::directory($item); file_put_contents($dir . '/' . $file, 'corrupted-owned-fixture'); }
    };
    add_action('wp_seed_pixel_m3_boundary', $hook, 10, 2); WP_Seed_Pixel_Jobs::step($job); remove_action('wp_seed_pixel_m3_boundary', $hook);
    fcheck(fi($job)['error_code'] === $code, "$file corruption detected at destructive boundary");
    fcheck(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, "$file canonical exact before");
}
[$id, $job, $before] = fixture_job();
$hook = static function ($name) use ($id) { if ($name === 'pre_swap') { $m = wp_get_attachment_metadata($id); $m['external'] = 'different'; wp_update_attachment_metadata($id, $m); } };
add_action('wp_seed_pixel_m3_boundary', $hook); WP_Seed_Pixel_Jobs::step($job); remove_action('wp_seed_pixel_m3_boundary', $hook);
fcheck(fi($job)['error_code'] === 'SOURCE_CHANGED' && hash_file('sha256', get_attached_file($id)) === $before['sha256'], 'pre-swap metadata race refuses mutation');

[$id, $job, $before] = fixture_job(); $armed = false; $injected = false;
$hook = static function ($name) use (&$armed) { if ($name === 'post_swap') { $armed = true; } };
$filter = static function ($q) use (&$armed, &$injected) {
    if ($armed && !$injected && preg_match('/^UPDATE .*postmeta[ `]/', $q)) { $injected = true; return 'SELECT FROM m3_injected_failure'; } return $q;
};
add_action('wp_seed_pixel_m3_boundary', $hook); add_filter('query', $filter); $old = $wpdb->suppress_errors(true);
WP_Seed_Pixel_Jobs::step($job);
$wpdb->suppress_errors($old); remove_filter('query', $filter); remove_action('wp_seed_pixel_m3_boundary', $hook);
fcheck($injected && fi($job)['stage'] === 'recovery_required', 'native SQL failure cannot complete');
fcheck(!is_wp_error(WP_Seed_Pixel_Jobs::restore_master($job)) && WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'SQL failure byte-exact rollback');

[$id, $job, $before] = fixture_job(); $url = wp_get_attachment_url($id);
$http = static function ($pre, $args, $requested) use ($url) { return $url === $requested ? new WP_Error('local-delivery-failure') : $pre; };
add_filter('pre_http_request', $http, 10, 3); WP_Seed_Pixel_Jobs::step($job); remove_filter('pre_http_request', $http);
fcheck(fi($job)['stage'] === 'recovery_required' && fi($job)['error_code'] === 'VERIFY_FAILED', 'HTTP verification failure cannot complete');
WP_Seed_Pixel_Jobs::control($job, 'resume'); WP_Seed_Pixel_Jobs::step($job);
fcheck(fi($job)['stage'] === 'retained', 'HTTP transient recovery continues without re-encode');
WP_Seed_Pixel_Jobs::restore_master($job);
fcheck(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'HTTP recovered operation rollback exact');

[$id, $job, $before] = fixture_job(); $dir = WP_Seed_Pixel_Master_Storage::directory(fi($job));
symlink(get_attached_file($id), $dir . '/candidate.jpg'); WP_Seed_Pixel_Jobs::step($job);
fcheck(fi($job)['stage'] === 'needs_review' && hash_file('sha256', get_attached_file($id)) === $before['sha256'], 'candidate symlink cannot mutate canonical');
unlink($dir . '/candidate.jpg');

[$id, $job, $before] = fixture_job(); $path = get_attached_file($id); $raw = file_get_contents($path); $mtime = filemtime($path); $raw[strlen($raw)-4] = chr(ord($raw[strlen($raw)-4]) ^ 1); file_put_contents($path, $raw); touch($path, $mtime);
WP_Seed_Pixel_Jobs::step($job);
fcheck(fi($job)['error_code'] === 'SOURCE_CHANGED', 'equal length equal mtime source hash race detected');

[$id, $job, $before] = fixture_job(); $lock = WP_Seed_Pixel_Files::lock($id);
fcheck(WP_Seed_Pixel_Jobs::step($job)->get_error_code() === 'LOCKED', 'legacy per-attachment flock shared');
WP_Seed_Pixel_Files::unlock($lock);
WP_Seed_Pixel_Jobs::control($job, 'pause');
fcheck(WP_Seed_Pixel_Jobs::status($job)['status'] === 'paused' && WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'real job pause no mutation');
WP_Seed_Pixel_Jobs::control($job, 'cancel');
fcheck(fi($job)['stage'] === 'cancelled' && WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'real queued cancel no mutation');

[$id, $job, $before] = fixture_job(); $parent = dirname(get_attached_file($id)); $mode = fileperms($parent) & 0777;
$hook = static function ($name) use ($parent) { if ($name === 'pre_swap') { chmod($parent, 0555); } };
try {
    add_action('wp_seed_pixel_m3_boundary', $hook); WP_Seed_Pixel_Jobs::step($job);
    fcheck(fi($job)['error_code'] === 'SWAP_FAILED' && hash_file('sha256', get_attached_file($id)) === $before['sha256'], 'real directory permission failure cannot unlink canonical');
} finally { chmod($parent, $mode); remove_action('wp_seed_pixel_m3_boundary', $hook); }

[$id, $job, $before] = fixture_job();
$wpdb->query("ALTER TABLE {$wpdb->postmeta} ENGINE=MyISAM");
try {
    $refused = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824);
    fcheck(is_wp_error($refused) && $refused->get_error_code() === 'UNSUPPORTED_STORAGE', 'nontransactional native metadata table refused');
} finally { $wpdb->query("ALTER TABLE {$wpdb->postmeta} ENGINE=InnoDB"); }

[$id, $job, $before] = fixture_job();
$race = static function ($meta, $attachment) use ($id) { if ($attachment === $id) { update_post_meta($id, '_seed_pixel_history', array('external' => 'changed')); } return $meta; };
add_filter('wp_update_attachment_metadata', $race, 10, 2); WP_Seed_Pixel_Jobs::step($job); remove_filter('wp_update_attachment_metadata', $race);
fcheck(fi($job)['stage'] === 'recovery_required' && get_post_meta($id, '_seed_pixel_history', true) === array('external' => 'changed'), 'metadata hook race detects protected row change and preserves foreign write');

[$id, $job, $before] = fixture_job();
$settings = get_option('wp_seed_pixel_settings'); update_option('wp_seed_pixel_settings', array('cleanup_on_uninstall' => true));
if (!defined('WP_UNINSTALL_PLUGIN')) { define('WP_UNINSTALL_PLUGIN', 'wp-seed-pixel/wp-seed-pixel.php'); }
require dirname(__DIR__) . '/uninstall.php';
fcheck(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before && WP_Seed_Pixel_Job_Store::job($job), 'uninstall before witness keeps incomplete M3 evidence and native bytes');
update_option('wp_seed_pixel_settings', $settings);

[$id, $job, $before] = fixture_job(); $foreign_hash = '';
$hook = static function ($name) use ($id, &$foreign_hash) {
    if ($name === 'escrow') { $p = get_attached_file($id); file_put_contents($p, file_get_contents($p) . 'another-writer'); $foreign_hash = hash_file('sha256', $p); }
};
add_action('wp_seed_pixel_m3_boundary', $hook); WP_Seed_Pixel_Jobs::step($job); remove_action('wp_seed_pixel_m3_boundary', $hook);
fcheck(fi($job)['error_code'] === 'SOURCE_CHANGED' && hash_file('sha256', get_attached_file($id)) === $foreign_hash, 'source changed after escrow preserved instead of overwritten');
$dir = WP_Seed_Pixel_Master_Storage::directory(fi($job)); $record = WP_Seed_Pixel_Master_Storage::load($dir, fi($job));
fcheck(hash_file('sha256', $dir . '/recovery.jpg') === $before['sha256'] && $record['candidate'], 'candidate encoded only from immutable verified escrow');

$report = array('checks' => $checks, 'passed' => count($checks));
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-m3');
file_put_contents(dirname(__DIR__) . '/reports/storage-m3/faults.json', wp_json_encode($report, JSON_PRETTY_PRINT));
echo count($checks) . " M3 fault checks passed.\n";
