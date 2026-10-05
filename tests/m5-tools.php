<?php
require __DIR__.'/m5-support.php';
global $wpdb;
$op=$argv[1]??''; $id=(int)($argv[2]??0); $item_id=(int)($argv[3]??0);
if($op==='create') {
    // Every object in this guarded runtime is synthetic. Isolate the next frozen scan.
    $wpdb->query("UPDATE {$wpdb->posts} SET post_status='trash' WHERE post_type='attachment'"); wp_cache_flush();
    $operation=($argv[2]??'')==='retire'?'retire':'replace'; $ids=array();
    for($n=0;$n<2;$n++) { $ids[]=m5_image($operation==='retire'?'m3-large.jpg':'m3-detail.jpg',$operation==='retire'); }
    $plan=m5_plan($operation); $r=m5_result(WP_Seed_Pixel_Jobs::start($plan['id']));
    $r=array('id'=>(int)$r['id'],'plan_id'=>(int)$plan['id'],'ids'=>$ids,'items'=>array_map('intval',array_column(m5_items($r['id']),'id')));
} elseif($op==='run') {
    $action=$argv[4]??'step'; $boundary=$argv[5]??'';
    $hook=static function($name)use($boundary){if($name===$boundary){posix_kill(getmypid(),SIGKILL);}};
    add_action('wp_seed_pixel_m3_boundary',$hook); add_action('wp_seed_pixel_m4_boundary',$hook); add_action('wp_seed_pixel_m5_boundary',$hook);
    if($boundary==='hold_intent') { add_action('wp_seed_pixel_m3_boundary',static function($name){if($name==='intent'){echo "OWNED\n";flush();sleep(3);}}); }
    if($action==='step') {$r=WP_Seed_Pixel_Jobs::bulk_step($id);}
    else { $view=WP_Seed_Pixel_Quarantine::inspect(WP_Seed_Pixel_Job_Store::item($item_id));
        // Purge intent may have survived a crash; generation remains bound to the same record.
        if(is_wp_error($view)) { $record=WP_Seed_Pixel_Quarantine::record(WP_Seed_Pixel_Job_Store::item($item_id)); $view=array('generation'=>hash('sha256',wp_json_encode($record['quarantine']).$record['policy_hash'])); }
        $r=WP_Seed_Pixel_Jobs::quarantine_action($id,$action,$action==='purge'?m4_approval($view):array(),$item_id);
    }
} elseif($op==='expire') {foreach(array('jobs','items')as$t){$wpdb->update(WP_Seed_Pixel_Job_Store::table($t),array('lease_until'=>time()-1),array($t==='jobs'?'id':'job_id'=>$id));} $r=true;}
elseif($op==='finish') {$r=m5_finish($id);}
elseif($op==='resume') {$r=WP_Seed_Pixel_Jobs::control($id,'resume');}
elseif($op==='state') {
    $r=array('status'=>WP_Seed_Pixel_Jobs::status($id),'audit'=>WP_Seed_Pixel_Jobs::storage_audit($id),'items'=>array());
    foreach(m5_items($id)as$item){$dir=WP_Seed_Pixel_Master_Storage::directory($item,false);$record=is_wp_error($dir)?null:WP_Seed_Pixel_Master_Storage::load($dir,$item);
        $p=get_attached_file($item['attachment_id']);$r['items'][]=array('id'=>(int)$item['id'],'attachment_id'=>(int)$item['attachment_id'],'stage'=>$item['stage'],'revision'=>(int)$item['revision'],'journal_valid'=>WP_Seed_Pixel_Job_Store::journal_valid($item),'sha'=>hash_file('sha256',$p),'candidate'=>$record['candidate']['sha256']??null,'encode_receipt'=>$record['candidate']??null,'view'=>WP_Seed_Pixel_Quarantine::inspect($item));}
} elseif($op==='duplicate') { $source=WP_Seed_Pixel_Job_Store::job($id); $plan=WP_Seed_Pixel_Job_Store::job($source['source_id']); $p=m5_result(WP_Seed_Pixel_Jobs::bulk_plan((int)$plan['source_id'],array('master'=>'replace_verified'),1073741824)); for($n=0;$p['status']==='queued'&&$n<100;$n++){$p=m5_result(WP_Seed_Pixel_Jobs::bulk_step($p['id']));} $r=WP_Seed_Pixel_Jobs::start($p['id']); }
elseif($op==='untrash') { foreach(m5_items($id)as$i){$wpdb->update($wpdb->posts,array('post_status'=>'inherit'),array('ID'=>$i['attachment_id']));clean_post_cache($i['attachment_id']);} $r=true; }
elseif($op==='external') { $item=WP_Seed_Pixel_Job_Store::item($item_id);$meta=wp_get_attachment_metadata($item['attachment_id']);$meta['external']='authoritative';wp_update_attachment_metadata($item['attachment_id'],$meta);$r=true; }
elseif($op==='hold') {
    $lock=WP_Seed_Pixel_Files::lock(0); if(is_wp_error($lock)){throw new RuntimeException('Hold lock failed');}
    echo "OWNED\n"; flush(); sleep(3); WP_Seed_Pixel_Files::unlock($lock); $r=true;
} else {throw new RuntimeException('Unknown synthetic test operation');}
if(is_wp_error($r)) {$r=array('error'=>$r->get_error_code());}
echo wp_json_encode($r)."\n";
