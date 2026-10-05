<?php
require __DIR__ . '/m4-runtime.php';
if (getenv('PIXEL_M3_LOW_DISK') !== '1') { throw new RuntimeException('Owned low disk volume required'); }
$volume = getenv('PIXEL_M3_ROOT') . '/volume';
$filter = static function ($u) use ($volume) { $u['basedir'] = $volume . '/uploads'; $u['path'] = $u['basedir']; $u['subdir'] = ''; $u['error'] = false; return $u; };
add_filter('upload_dir', $filter);
$checks = array();
list($id, $job) = m4_original(); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
WP_Seed_Pixel_Jobs::step($job); $item = m4_item($job); $dir = WP_Seed_Pixel_Master_Storage::directory($item);
m4_check($item['error_code'] === 'LOW_DISK', 'real low disk original retirement blocked');
m4_check(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'low disk exact original and metadata unchanged');
m4_check(!file_exists($dir . '/recovery.jpg') && !file_exists($dir . '/journal.json'), 'low disk before escrow and pointer mutation');
m4_report('low-disk', array('free_bytes' => disk_free_space($dir), 'budget' => filesize(wp_get_original_image_path($id)) * 2 + 16777216));
