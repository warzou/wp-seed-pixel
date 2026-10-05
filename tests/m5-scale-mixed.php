<?php
require __DIR__.'/m5-support.php';global $wpdb;$checks=array();
$resume=($argv[1]??'')==='resume';$verify=($argv[1]??'')==='verify';
if(!$resume&&!$verify) {
$wpdb->query("UPDATE {$wpdb->posts} SET post_status='trash' WHERE post_type='attachment'");wp_cache_flush();
$shared=m5_image();$path=get_attached_file($shared);$meta=wp_get_attachment_metadata($shared);$sha=hash_file('sha256',$path);
for($n=0;$n<2000;$n++){$i=wp_insert_attachment(array('post_title'=>'Synthetic shared alias '.$n,'post_mime_type'=>'image/jpeg','post_status'=>'inherit'),$path);wp_update_attachment_metadata($i,$meta);}
$ids=array(m5_image(),m5_image(),m3_fixture('png.png'),m5_image('m3-orientation.jpg'));
} else { $shared=(int)$wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status<>'trash' ORDER BY ID LIMIT 1"); $path=get_attached_file($shared);$sha=hash_file('sha256',$path); }
$time=microtime(true);if($verify){$j=m5_result(WP_Seed_Pixel_Jobs::status());}else{$plan=m5_plan('replace',1,$resume);$j=m5_result(WP_Seed_Pixel_Jobs::start($plan['id']));}$id=(int)$j['id'];
m5_assert((int)$j['total']===2005,'exact 2005-relation mixed frozen library');
m5_assert(($j['states']['needs_review']??0)>=2001,'shared physical aliases reviewed without encoding');
$s=m5_finish($id);m5_assert(($s['states']['retained']??0)===2&&($s['states']['needs_review']??0)===2002&&($s['states']['skipped']??0)===1,'real destructive JPEG subset plus review and unsupported states');
wp_cache_flush();$wpdb->num_queries=0;m5_result(WP_Seed_Pixel_Jobs::results($id,50));$full_cold=$wpdb->num_queries;
m5_assert($full_cold===4,'full WordPress cache flush adds one bounded alloptions query');
wp_cache_flush();wp_load_alloptions();$wpdb->num_queries=0;$page=m5_result(WP_Seed_Pixel_Jobs::results($id,50));$sql=$wpdb->num_queries;
m5_assert(count($page['items'])===20&&$page['pages']===101,'twenty rows across 101 pages');m5_assert($sql===3,'cold rows with loaded WordPress config exactly three SQL queries');
wp_cache_flush();wp_load_alloptions();$wpdb->num_queries=0;$first=m5_result(WP_Seed_Pixel_Jobs::results($id,1));$first_sql=$wpdb->num_queries;
m5_assert(count($first['items'])===20&&$first_sql===3,'mixed real-operation page also three SQL queries');
$ops=$wpdb->get_results($wpdb->prepare('SELECT * FROM '.WP_Seed_Pixel_Job_Store::table('items')." WHERE job_id=%d AND stage='retained' ORDER BY id",$id),ARRAY_A);
$v=m5_result(WP_Seed_Pixel_Quarantine::inspect($ops[0]));m5_result(WP_Seed_Pixel_Jobs::quarantine_action($id,'restore',array(),(int)$ops[0]['id']));
$v=m5_result(WP_Seed_Pixel_Quarantine::inspect($ops[1]));m5_result(WP_Seed_Pixel_Jobs::quarantine_action($id,'purge',m4_approval($v),(int)$ops[1]['id']));
m5_assert(hash_file('sha256',$path)===$sha,'2001 owners retain shared physical source exact');
$memory=memory_get_usage(true);$a=m5_result(WP_Seed_Pixel_Jobs::storage_audit($id));m5_assert($a['removed_source_bytes']===$v['quarantine_bytes']&&$a['quarantine_bytes']===0,'physical aggregate across large mixed library');
m5_assert(memory_get_usage(true)-$memory<16777216,'reconciliation bounded memory without image copies');
m5_report('scale-mixed',array('relations'=>2005,'page_queries'=>$sql,'mixed_page_queries'=>$first_sql,'full_wordpress_cold_queries'=>$full_cold,'seconds'=>microtime(true)-$time,'audit'=>$a));
