<?php
require __DIR__ . '/m4-runtime.php';
$checks = array();
WP_Seed_Pixel_Future_Uploads::configure('off');
$id = m3_fixture('m6-RGB.png');
$source = get_attached_file($id); $dir = dirname($source); $stem = pathinfo($source, PATHINFO_FILENAME);
$sha = hash_file('sha256', $source);
$foreign_file = $dir . '/' . $stem . '.jpg';
copy(dirname(__DIR__) . '/.runtime/fixtures/m3-detail.jpg', $foreign_file);
$foreign = wp_insert_attachment(array('post_title'=>'Synthetic other-format owner','post_mime_type'=>'image/jpeg','post_status'=>'inherit'), $foreign_file);
wp_update_attachment_metadata($foreign, wp_generate_attachment_metadata($foreign, $foreign_file));
$foreign_sha = hash_file('sha256', $foreign_file);
for ($i=0;$i<1367;$i++) { file_put_contents($dir . '/unrelated-' . $i . '.txt', 'synthetic'); }
$a = WP_Seed_Pixel_Analyzer::analyze($id);
m4_check($a['extra_inventory_complete'] && $a['health']==='healthy', '1367 unrelated directory entries do not invalidate complete target inventory');
foreach ($a['files'] as $f) { m4_check(str_ends_with($f['relative_path'], '.png'), 'Other canonical JPEG graph excluded by explicit native ownership ' . basename($f['relative_path'])); }
$before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
m4_check(!is_wp_error($before), 'Dense mixed-format month reaches native snapshot');
$r = WP_Seed_Pixel_Selected_Admin::start(array($id));
for($n=0;!is_wp_error($r)&&$r['status']!=='complete'&&$n<100;$n++) { $r=WP_Seed_Pixel_Selected_Admin::command($r['phase']==='ready'?'native_start':'native_step',$r['id']); }
m4_check(!is_wp_error($r) && $r['status']==='complete' && $r['success']===1 && $r['failed']===0, 'Normal single-image coordinator completes on dense mixed-format directory');
$w = get_post_meta($id, '_seed_pixel_master_state', true);
$record = WP_Seed_Pixel_Quarantine::record(WP_Seed_Pixel_Job_Store::item($w['item_id']));
$original_dir = WP_Seed_Pixel_Master_Storage::directory(WP_Seed_Pixel_Job_Store::item($w['item_id']), false);
$a = WP_Seed_Pixel_PNG_Processor::inspect($original_dir . '/recovery.jpg', true);
$b = WP_Seed_Pixel_PNG_Processor::inspect($source, true);
m4_check(!is_wp_error($a)&&!is_wp_error($b)&&$a['pixels']===$b['pixels']&&$a['header']===$b['header'], 'Dense-host PNG decoded scanline identity');
m4_check(hash_file('sha256',$foreign_file)===$foreign_sha && !get_post_meta($foreign,'_seed_pixel_master_state',true), 'Other canonical JPEG untouched');
m4_check(!is_wp_error(WP_Seed_Pixel_Workflow::original($id,'restore')) && hash_file('sha256',$source)===$sha, 'Dense-host PNG restoration exact');
$orphan = $dir . '/' . $stem . '-unmapped.png'; copy($source,$orphan);
$a = WP_Seed_Pixel_Analyzer::analyze($id);
m4_check($a['health']==='needs_review' && in_array('ownership_review',$a['issues'],true), 'Truly unattributed matching sibling still blocks replacement');
m4_check(is_wp_error(WP_Seed_Pixel_Master_Adapter::snapshot($id)), 'Unattributed sibling guard not bypassed'); unlink($orphan);
for($i=1367;$i<=WP_Seed_Pixel_Analyzer::MAX_DIRECTORY_ENTRIES;$i++) { file_put_contents($dir . '/unrelated-' . $i . '.txt','synthetic'); }
$a=WP_Seed_Pixel_Analyzer::analyze($id);$blocked=WP_Seed_Pixel_Master_Adapter::snapshot($id);
m4_check(!$a['extra_inventory_complete'] && is_wp_error($blocked) && $blocked->get_error_code()==='INVENTORY_INCOMPLETE', 'Finite directory bound remains fail-closed with explicit reason');
m4_check(hash_file('sha256',$source)===$sha && hash_file('sha256',$foreign_file)===$foreign_sha, 'Bounded refusal never changes images');
foreach(glob($dir.'/unrelated-*.txt') as $p) { unlink($p); }
m4_report('dense-media-inventory');
