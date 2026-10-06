<?php
require __DIR__.'/m5-support.php';
global $wpdb; $checks=array();
$wpdb->query("UPDATE {$wpdb->posts} SET post_status='trash' WHERE post_type='attachment'");wp_cache_flush();
$healthy=m5_image();$stale=m5_image();$retry=m5_image();$review=m5_image('m3-orientation.jpg');
$shared=m5_image();$alias=wp_insert_attachment(array('post_title'=>'Synthetic alias','post_mime_type'=>'image/jpeg','post_status'=>'inherit'),get_attached_file($shared));wp_update_attachment_metadata($alias,wp_get_attachment_metadata($shared));
$png=m3_fixture('png.png');
$already=m5_image();$single=m5_result(WP_Seed_Pixel_Jobs::replace_one($already,array('master'=>'replace_verified'),1073741824));m5_result(WP_Seed_Pixel_Jobs::step($single['id']));
$plan=m5_plan();$job=m5_result(WP_Seed_Pixel_Jobs::start($plan['id']));$id=(int)$job['id'];
$meta=wp_get_attachment_metadata($stale);$meta['external']='kept';wp_update_attachment_metadata($stale,$meta);
require_once ABSPATH.'wp-includes/class-wp-image-editor.php';require_once ABSPATH.'wp-includes/class-wp-image-editor-gd.php';
class M5_Reject_Editor extends WP_Image_Editor_GD { public function save($destfilename=null,$mime_type=null){return new WP_Error('synthetic_retry');} }
$fail=static function($editors)use($retry){return array('M5_Reject_Editor');};
$armed=static function($name,$item)use($retry,$fail){if($name==='intent'&&WP_Seed_Pixel_Job_Store::item($item)['attachment_id']==$retry){add_filter('wp_image_editors',$fail);}};
add_action('wp_seed_pixel_m3_boundary',$armed,10,2);
for($n=0;$job['status']==='running'&&$n<30;$n++){$job=m5_result(WP_Seed_Pixel_Jobs::bulk_step($id));remove_filter('wp_image_editors',$fail);}
remove_action('wp_seed_pixel_m3_boundary',$armed);
$by=array_column(m5_items($id),null,'attachment_id');
m5_assert($by[$healthy]['stage']==='retained','healthy item completed despite mixed peers');
m5_assert($by[$stale]['stage']==='needs_review' && wp_get_attachment_metadata($stale)['external']==='kept','external stale metadata preserved');
m5_assert($by[$retry]['stage']==='failed'&&$by[$retry]['error_code']==='CANDIDATE_INVALID','real editor retryable failure');
$failed_dir=WP_Seed_Pixel_Master_Storage::directory($by[$retry],false);
m5_assert(!is_wp_error($failed_dir)&&!file_exists($failed_dir.'/recovery.jpg')&&!file_exists($failed_dir.'/candidate.jpg')
    &&!empty(json_decode($by[$retry]['receipt'],true)['cleanup_unreplaced']),'safe failed-before-replacement copy cleaned before explicit retry');
if($by[$review]['stage']!=='needs_review'){throw new RuntimeException('Mixed orientation state '.wp_json_encode(array('job'=>$job,'orientation'=>array_intersect_key($by[$review],array_flip(array('stage','error_code','error_class'))),'states'=>array_map(static function($r){return array($r['stage'],$r['error_code']);},$by))));}m5_assert(true,'orientation review no destructive shortcut');
m5_assert($by[$png]['stage']==='skipped','unsupported PNG excluded');
m5_assert($by[$shared]['stage']==='needs_review'&&$by[$alias]['stage']==='needs_review','shared physical file excluded');
m5_assert($by[$already]['stage']==='skipped','already optimized source skipped');
$revision=$by[$healthy]['revision'];$audit=m5_result(WP_Seed_Pixel_Jobs::storage_audit($id));
m5_assert($audit['known_subtotals']['quarantine_bytes']>0&&$audit['removed_source_bytes']===0,'quarantine is not reclaimed disk');
m5_assert(!$audit['complete']&&$audit['unknown_items']>0&&$audit['net_reclaimed_bytes']===null&&$audit['current_active_bytes']===null,'uncertain item never produces a falsely complete physical total');
m5_result(WP_Seed_Pixel_Jobs::control($id,'retry'));m5_result(WP_Seed_Pixel_Jobs::control($id,'resume'));m5_finish($id);
$by=array_column(m5_items($id),null,'attachment_id');m5_assert($by[$retry]['stage']==='retained','retry converges with shared executor');m5_assert($by[$healthy]['revision']===$revision,'retry never repeats healthy item');
$item=$by[$healthy];$v=m5_result(WP_Seed_Pixel_Quarantine::inspect($item));
m5_assert(is_wp_error(WP_Seed_Pixel_Jobs::quarantine_action($id,'purge',array(),(int)$item['id'])),'purge missing consent refused');
$dir=WP_Seed_Pixel_Master_Storage::directory($item);chmod($dir,0500);
$r=WP_Seed_Pixel_Jobs::quarantine_action($id,'purge',m4_approval($v),(int)$item['id']);chmod($dir,0700);
m5_assert(is_wp_error($r)&&m5_result(WP_Seed_Pixel_Jobs::status($id))['storage']['removed_source_bytes']===0,'failed physical purge never increases removed counter');
$r=m5_result(WP_Seed_Pixel_Jobs::quarantine_action($id,'purge',m4_approval($v),(int)$item['id']));
$a=m5_result(WP_Seed_Pixel_Jobs::storage_audit($id));m5_assert($a['removed_source_bytes']===$r['removed_bytes'],'fresh audit verifies physical removal');
m5_assert(!$a['complete']&&$a['net_reclaimed_bytes']===null&&$a['known_subtotals']['temporary_bytes']===0
    &&$a['known_subtotals']['audit_bytes']>0,'uncertain totals withheld; safe pre-swap escrow removed, bounded metadata counted');
m5_result(WP_Seed_Pixel_Jobs::quarantine_action($id,'purge',m4_approval($v),(int)$item['id']));$b=m5_result(WP_Seed_Pixel_Jobs::storage_audit($id));m5_assert($a['removed_source_bytes']===$b['removed_source_bytes'],'repeat purge does not double-count');
wp_set_current_user(0);m5_assert(is_wp_error(WP_Seed_Pixel_Jobs::storage_audit($id))&&is_wp_error(WP_Seed_Pixel_Jobs::bulk_step($id)),'anonymous audit and execution refused');wp_set_current_user(1);
$second=$by[$retry];m5_assert(is_wp_error(WP_Seed_Pixel_Jobs::quarantine_action($single['id'],'restore',array(),(int)$second['id'])),'wrong job-item pair refused');
m5_report('mixed',array('job'=>$id,'audit'=>$b));
