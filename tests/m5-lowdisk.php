<?php
require __DIR__.'/m5-support.php';
if(getenv('PIXEL_M3_LOW_DISK')!=='1'){throw new RuntimeException('Owned constrained volume required');}
$volume=getenv('PIXEL_M3_ROOT').'/volume';global $wpdb;$checks=array();
$filter=static function($u)use($volume){$u['basedir']=$volume.'/uploads';$u['path']=$u['basedir'];$u['baseurl']='http://127.0.0.1:8877/m5-lowdisk-uploads';$u['url']=$u['baseurl'];$u['subdir']='';$u['error']=false;return $u;};add_filter('upload_dir',$filter);
$wpdb->query("UPDATE {$wpdb->posts} SET post_status='trash' WHERE post_type='attachment'");wp_cache_flush();
$ids=array(m5_image(),m5_image());$plan=m5_plan();$j=m5_result(WP_Seed_Pixel_Jobs::start($plan['id']));$id=(int)$j['id'];
$j=m5_result(WP_Seed_Pixel_Jobs::bulk_step($id));if(($j['states']['retained']??0)!==1){throw new RuntimeException('Initial constrained result '.wp_json_encode($j));}m5_assert(true,'one item completes on real constrained filesystem');
$rows=m5_items($id);$first=$rows[0];$second=$rows[1];$data=json_decode($second['data'],true);$b=$data['before'];$peak=$b['bytes']*4+$b['width']*$b['height']*8+16777216;
$v=m5_result(WP_Seed_Pixel_Quarantine::inspect($first));
// Fill to just below the next item's safety budget. No Pixel source/recovery is deleted.
$free=disk_free_space($volume);$target=$peak-(int)floor($v['quarantine_bytes']/2);$amount=(int)max(0,$free-$target);
$f=fopen($volume.'/private/owned-m5-filler','xb');$chunk=str_repeat('x',1048576);while($amount>0){$n=min($amount,strlen($chunk));if(fwrite($f,substr($chunk,0,$n))!==$n){throw new RuntimeException('Fixture fill failed');}$amount-=$n;}fflush($f);fsync($f);fclose($f);clearstatcache();
$before=WP_Seed_Pixel_Master_Adapter::snapshot($second['attachment_id']);$s=m5_result(WP_Seed_Pixel_Jobs::bulk_step($id));
m5_assert($s['status']==='failed_systemic'&&($s['states']['failed']??0)===1,'actual disk budget pauses next item');
m5_assert(WP_Seed_Pixel_Master_Adapter::snapshot($second['attachment_id'])===$before,'no delete-first: next source and native metadata exact');
$dir=WP_Seed_Pixel_Master_Storage::directory(WP_Seed_Pixel_Job_Store::item($second['id']));m5_assert(!file_exists($dir.'/recovery.jpg')&&!file_exists($dir.'/candidate.jpg'),'budget checked before recovery or encoding');
$free_before=disk_free_space($volume);m5_result(WP_Seed_Pixel_Jobs::quarantine_action($id,'purge',m4_approval($v),(int)$first['id']));clearstatcache();$free_after=disk_free_space($volume);
m5_assert($free_after>$free_before&&$free_after>$peak,'explicit purge physically frees enough for next item');
m5_result(WP_Seed_Pixel_Jobs::control($id,'retry'));m5_result(WP_Seed_Pixel_Jobs::control($id,'resume'));$done=m5_finish($id);
m5_assert($done['status']==='completed'&&($done['states']['retained']??0)===1&&($done['states']['purged']??0)===1,'live budget recalculated and pending image completes');
$audit=m5_result(WP_Seed_Pixel_Jobs::storage_audit($id));m5_assert($audit['removed_source_bytes']===$v['quarantine_bytes'],'only explicitly purged version accounted');
m5_report('low-disk',array('free_before'=>$free_before,'free_after'=>$free_after,'peak'=>$peak,'audit'=>$audit));
