<?php
require __DIR__.'/m5-support.php';global $wpdb;$checks=array();
$wpdb->query("UPDATE {$wpdb->posts} SET post_status='trash' WHERE post_type='attachment'");wp_cache_flush();
$large=m5_image();$small=m5_image('m3-small.jpeg');$hard=m5_image();$path=get_attached_file($hard);link($path,dirname($path).'/owned-hardlink.jpg');
$plan=m5_plan();$j=m5_result(WP_Seed_Pixel_Jobs::start($plan['id']));$items=m5_items($j['id']);
m5_assert((int)$items[0]['attachment_id']===$small,'smallest frozen peak budget scheduled first');
m5_assert(json_decode($items[0]['data'],true)['peak_bytes']<json_decode($items[1]['data'],true)['peak_bytes'],'peak estimate records reserve and candidate requirements');
$by=array_column($items,null,'attachment_id');m5_assert($by[$hard]['stage']==='needs_review','physical hardlink never eligible');
$sha=hash_file('sha256',$path);m5_finish($j['id']);m5_assert(hash_file('sha256',$path)===$sha,'hardlink bytes never overwritten');
$by=array_column(m5_items($j['id']),null,'attachment_id');$real=$by[$large];$view=m5_result(WP_Seed_Pixel_Quarantine::inspect($real));
$bad=m4_approval($view);$bad['generation']=str_repeat('0',64);m5_assert(is_wp_error(WP_Seed_Pixel_Jobs::quarantine_action($j['id'],'purge',$bad,(int)$real['id'])),'foreign generation never purgeable');
m5_assert(is_wp_error(WP_Seed_Pixel_Jobs::quarantine_action($j['id'],'restore',array(),(int)$by[$small]['id']+999999)),'foreign item never restorable');
$a=m5_result(WP_Seed_Pixel_Jobs::storage_audit($j['id']));m5_assert($a['removed_source_bytes']===0,'hardlink or denied purge never false physical saving');
$table=WP_Seed_Pixel_Job_Store::table('jobs');$job=WP_Seed_Pixel_Job_Store::job($j['id']);$p=json_decode($job['policy'],true);$p['bulk']['lot_size']=0;
$wpdb->update($table,array('policy'=>wp_json_encode($p),'policy_hash'=>WP_Seed_Pixel_Policy::hash($p)),array('id'=>$j['id']));m5_assert(is_wp_error(WP_Seed_Pixel_Jobs::bulk_step($j['id'])),'invalid saved bulk contract refused even with matching hash');
$wpdb->update($table,array('policy'=>$job['policy'],'policy_hash'=>$job['policy_hash']),array('id'=>$j['id']));
$before=WP_Seed_Pixel_Master_Adapter::snapshot($large,true);$cursor=m5_result(WP_Seed_Pixel_Scan::start());while($cursor['status']==='running'){$cursor=m5_result(WP_Seed_Pixel_Scan::step($cursor['id']));}m5_assert(WP_Seed_Pixel_Master_Adapter::snapshot($large,true)===$before,'M1 rescan stays read-only after bulk');
$dry=m5_result(WP_Seed_Pixel_Jobs::plan($cursor['id']));while($dry['status']==='queued'){$dry=m5_result(WP_Seed_Pixel_Jobs::step($dry['id']));}$simulation=m5_result(WP_Seed_Pixel_Jobs::start($dry['id']));for($n=0;$simulation['status']==='running'&&$n<20;$n++){$simulation=m5_result(WP_Seed_Pixel_Jobs::step($simulation['id']));}m5_assert(WP_Seed_Pixel_Master_Adapter::snapshot($large,true)===$before,'M2 simulation remains read-only after M5 arrival');
m5_report('order-security');
