<?php
require __DIR__.'/m5-support.php';global $wpdb;$checks=array();
$wpdb->query("UPDATE {$wpdb->posts} SET post_status='trash' WHERE post_type='attachment'");wp_cache_flush();
$ids=array(m5_image('m3-large.jpg',true),m5_image('m3-large.jpg',true));$masters=array();foreach($ids as$i){$masters[$i]=hash_file('sha256',get_attached_file($i));}
$plan=m5_plan('retire');$j=m5_result(WP_Seed_Pixel_Jobs::start($plan['id']));$s=m5_finish($j['id']);m5_assert(($s['states']['retained']??0)===2,'two original retirements use M4 executor');
foreach($ids as$i){m5_assert(hash_file('sha256',get_attached_file($i))===$masters[$i],'operational master unchanged '.$i);}
$items=m5_items($j['id']);$a=m5_result(WP_Seed_Pixel_Jobs::storage_audit($j['id']));m5_assert($a['current_active_bytes']===0&&$a['active_saved_bytes']===$a['quarantine_bytes']&&$a['removed_source_bytes']===0,'retired originals kept physically in quarantine');
$v=m5_result(WP_Seed_Pixel_Quarantine::inspect($items[0]));m5_result(WP_Seed_Pixel_Jobs::quarantine_action($j['id'],'restore',array(),(int)$items[0]['id']));m5_assert(is_file(wp_get_original_image_path($items[0]['attachment_id'])),'selected original restored natively');
$v=m5_result(WP_Seed_Pixel_Quarantine::inspect($items[1]));m5_result(WP_Seed_Pixel_Jobs::quarantine_action($j['id'],'purge',m4_approval($v),(int)$items[1]['id']));
$b=m5_result(WP_Seed_Pixel_Jobs::storage_audit($j['id']));m5_assert($b['complete']&&$b['quarantine_bytes']===0&&$b['removed_source_bytes']===$v['quarantine_bytes'],'restore and explicit purge reconcile independently');
m5_report('original',array('audit'=>$b));
