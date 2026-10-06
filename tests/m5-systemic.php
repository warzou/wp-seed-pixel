<?php
require __DIR__.'/m5-support.php';global $wpdb;$checks=array();
$wpdb->query("UPDATE {$wpdb->posts} SET post_status='trash' WHERE post_type='attachment'");wp_cache_flush();
$ids=array(m5_image(),m5_image(),m5_image());$before=array();foreach($ids as$i){$before[$i]=hash_file('sha256',get_attached_file($i));}
$plan=m5_plan();$j=m5_result(WP_Seed_Pixel_Jobs::start($plan['id']));
$none=static function(){return array();};add_filter('wp_image_editors',$none);
$s=m5_result(WP_Seed_Pixel_Jobs::bulk_step($j['id']));remove_filter('wp_image_editors',$none);
m5_assert($s['status']==='failed_systemic','actual absent editor backend pauses system');
m5_assert(($s['states']['queued']??0)===2&&($s['states']['failed']??0)===1,'no cascade of backend failures');
foreach($ids as$i){m5_assert(hash_file('sha256',get_attached_file($i))===$before[$i],'backend failure source untouched '.$i);}
$a=m5_result(WP_Seed_Pixel_Jobs::storage_audit($j['id']));
m5_assert($a['complete']&&$a['temporary_bytes']===0&&$a['audit_bytes']>0&&$a['quarantine_bytes']===0&&$a['net_reclaimed_bytes']===-$a['audit_bytes'],'failed pre-switch cleanup retains only exactly accounted audit metadata');
$r=m5_result(WP_Seed_Pixel_Jobs::control($j['id'],'retry'));m5_assert($r['status']==='paused','retry queues without starting');
m5_result(WP_Seed_Pixel_Jobs::control($j['id'],'resume'));$s=m5_finish($j['id']);m5_assert(($s['states']['retained']??0)===3,'backend restored resumes exact job');
m5_report('systemic');
