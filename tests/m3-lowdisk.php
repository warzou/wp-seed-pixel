<?php
require __DIR__ . '/m3-runtime.php';
if (getenv('PIXEL_M3_LOW_DISK') !== '1') { throw new RuntimeException('Owned low disk volume required'); }
$volume = getenv('PIXEL_M3_ROOT') . '/volume';
$filter = static function ($u) use ($volume) {
    $u['basedir'] = $volume . '/uploads'; $u['path'] = $u['basedir']; $u['subdir'] = ''; $u['error'] = false;
    return $u;
};
add_filter('upload_dir', $filter);
$id = m3_fixture('m3-detail.jpg', false); $p = get_attached_file($id); $i = getimagesize($p);
wp_update_attachment_metadata($id, array('file' => _wp_relative_upload_path($p), 'width' => $i[0], 'height' => $i[1], 'sizes' => array()));
$before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
$j = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824);
if (is_wp_error($j)) { throw new RuntimeException($j->get_error_code()); }
WP_Seed_Pixel_Jobs::step($j['id']);
global $wpdb;
$item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' WHERE job_id=%d', $j['id']), ARRAY_A);
$dir = WP_Seed_Pixel_Master_Storage::directory($item);
$out = array('reason' => $item['error_code'], 'source_exact' => WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before,
    'candidate_absent' => !file_exists($dir . '/candidate.jpg'), 'recovery_absent' => !file_exists($dir . '/recovery.jpg'),
    'free_bytes' => disk_free_space($dir), 'same_device' => stat($p)['dev'] === stat($dir)['dev']);
if ($out['reason'] !== 'LOW_DISK' || !$out['source_exact'] || !$out['candidate_absent'] || !$out['same_device']) { throw new RuntimeException('Low disk gate failed'); }
file_put_contents(dirname(__DIR__) . '/reports/storage-m3/low-disk.json', wp_json_encode($out, JSON_PRETTY_PRINT));
echo wp_json_encode($out);
