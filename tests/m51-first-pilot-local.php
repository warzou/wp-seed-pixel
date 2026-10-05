<?php
require __DIR__ . '/m3-runtime.php';
$checks = array();
function pilot_check($value, $name) { global $checks; $checks[$name] = (bool) $value; if (!$value) { throw new RuntimeException($name); } }
WP_Seed_Pixel_Future_Uploads::configure('off');
pilot_check(!WP_Seed_Pixel_Plugin::settings()['automatic'], 'Inert activation keeps legacy automatic OFF');
$old = get_posts(array('post_type'=>'attachment','post_status'=>'inherit','numberposts'=>-1,'fields'=>'ids'));
$old_markers = array_map(static function ($id) { return get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true); }, $old);
$source = dirname(__DIR__) . '/.runtime/fixtures/pilot-A.jpg';
pilot_check(hash_file('sha256', $source) === 'f0ee8da961dc4c49446e4f93d8e86a1d61ae0e765c39b41d90ee6de3774e655b', 'Exact single generated photographic source');
$baseline = 624000000;
$own = 0;
add_filter('wp_seed_pixel_storage_usage', static function () use ($baseline, &$own) {
    return array('bytes'=>$baseline+$own,'measured_at'=>time(),'uncertainty_bytes'=>8000000,'complete'=>true,'includes_recovery'=>true,'live'=>true);
});
$settings = WP_Seed_Pixel_Future_Uploads::configure('process', 50000000, array('provider_quota_bytes'=>1000000000,'operational_ceiling_bytes'=>700000000,'safety_reserve_bytes'=>4000000));
pilot_check(!is_wp_error($settings), 'Human pilot policy 624/700 MB configured');
foreach ($old as $id) { WP_Seed_Pixel_Future_Uploads::created($id); }
pilot_check($old_markers === array_map(static function ($id) { return get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true); }, $old), 'No new provenance for old attachments');
$sub = '/m51-real-pilot-local';
$dir_filter = static function ($u) use ($sub) { $u['path'] .= $sub; $u['url'] .= $sub; $u['subdir'] .= $sub; return $u; };
add_filter('upload_dir', $dir_filter);
try {
    $upload = wp_upload_bits('pixel-one-disposable-photo.jpg', null, file_get_contents($source));
    pilot_check(!$upload['error'], 'Ordinary native JPEG upload succeeds');
    apply_filters('wp_handle_upload', $upload, 'upload');
    $id = wp_insert_attachment(array('post_title'=>'Pixel one generic photographic fixture','post_mime_type'=>'image/jpeg','post_status'=>'inherit'), $upload['file']);
    $meta = wp_generate_attachment_metadata($id, $upload['file']);
    wp_update_attachment_metadata($id, $meta);
} finally { remove_filter('upload_dir', $dir_filter); }
$before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
pilot_check(!is_wp_error($before), 'Complete native graph snapshot');
$own = array_sum(array_column($before['files'], 'bytes'));
$admission = WP_Seed_Pixel_Storage_Budget::status(WP_Seed_Pixel_Master_Storage::peak($before));
pilot_check($admission['state'] === 'OK' && $admission['peak_bytes'] < 700000000, 'Bounded photographic pilot fits headroom');
WP_Seed_Pixel_Future_Uploads::run($id);
$marker = get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true);
$job = (int) ($marker['job_id'] ?? 0);
pilot_check($job > 0, 'Photographic fixture enters official future-upload job');
global $wpdb;
$items = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation'", $job), ARRAY_A);
if (!is_array($items)) { throw new RuntimeException('Operation query unavailable'); }
$item = reset($items);
pilot_check($item && $item['stage'] === 'retained', 'Exact photographic fixture optimized and retained');
$view = WP_Seed_Pixel_Quarantine::inspect($item);
pilot_check(!is_wp_error($view) && $view['rollback_available'], 'Verified rollback available');
$record = WP_Seed_Pixel_Quarantine::record($item);
pilot_check(!is_wp_error($record) && $record['candidate']['width'] === 1536 && $record['candidate']['height'] === 1024, 'No upscale or dimensions change');
WP_Seed_Pixel_Future_Uploads::run($id);
pilot_check(get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true)['job_id'] === $job, 'No duplicate future job');
WP_Seed_Pixel_Future_Uploads::configure('off');
$result = array('checks'=>$checks,'attachment'=>$id,'job'=>$job,'before_bytes'=>$before['bytes'],'candidate'=>$record['candidate'],'native_graph_bytes'=>$own,'admission'=>$admission,'quarantine'=>$view);
wp_mkdir_p(dirname(__DIR__) . '/reports/storage-m5.1/first-real-pilot');
file_put_contents(dirname(__DIR__) . '/reports/storage-m5.1/first-real-pilot/local-photo.json', wp_json_encode($result, JSON_PRETTY_PRINT));
echo count($checks) . " one-photo local checks PASS\n";
