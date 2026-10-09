<?php
define('WP_SEED_PIXEL_GRAPH_FIXTURES_ONLY',true);require __DIR__.'/metadata-graph-wp.php';
global $wpdb;
$id=graph_fixture(false,true);
$policy=WP_Seed_Pixel_Policy::normalize(array('master'=>'replace_verified'));
$policy['capability']=WP_Seed_Pixel_Master_Storage::ENGINE;$policy['capacity_bytes']=1073741824;
$policy['effective']=array('simulation_only'=>false,'replace'=>true,'retire'=>false,'purge'=>false);
$old=WP_Seed_Pixel_Job_Store::insert_job('replace',0,$policy,1);meta_check($old,'historical rejected plan job');
$wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'),array('engine'=>WP_Seed_Pixel_Master_Storage::ENGINE,'status'=>'completed_errors'),array('id'=>$old));
$data=wp_json_encode(array('before'=>null,'original'=>null,'reason'=>'TARGET_METADATA_UNSUPPORTED','planned_bytes'=>0,'peak_bytes'=>0));
meta_check($wpdb->insert(WP_Seed_Pixel_Job_Store::table('items'),array('job_id'=>$old,'kind'=>'operation','item_key'=>$id.':legacy','attachment_id'=>$id,'stage'=>'needs_review','action'=>'replace','data'=>$data,'snapshot_hash'=>hash('sha256',$data),'journal'=>'','receipt'=>''))===1,'historical unstarted item');
$row=meta_item($old);
$proof=WP_Seed_Pixel_Jobs::reconcile_claims($id);meta_check($proof,'unstarted false conflict classified read-only');
meta_check(count($proof['reviews'])===1 && $proof['mutations']===0,'historical classification proof');
$job=meta_job($id);meta_check(WP_Seed_Pixel_Jobs::step($job),'new metadata job ignores rejected nonowner');
meta_check(meta_item($old)===$row,'historical row preserved exactly');
meta_check(WP_Seed_Pixel_Jobs::restore_master($job),'restore historical regression target');

$id=graph_fixture(false,true);$job=meta_job($id);$changed=null;
$hook=static function($at) use($id,&$changed) {
    if ($at!=='after_file_1') {return;}
    $m=wp_get_attachment_metadata($id);$size=end($m['sizes']);$path=dirname(get_attached_file($id)).'/'.$size['file'];
    $bytes=file_get_contents($path);$text='Synthetic third-party edit';
    file_put_contents($path,substr($bytes,0,2)."\xff\xfe".pack('n',strlen($text)+2).$text.substr($bytes,2));clearstatcache();
    $changed=array($path,hash_file('sha256',$path));
};
add_action('wp_seed_pixel_metadata_graph_boundary',$hook);
WP_Seed_Pixel_Jobs::step($job);
remove_action('wp_seed_pixel_metadata_graph_boundary',$hook);
meta_check($changed!==null,'foreign edit injection reached');
meta_check(hash_file('sha256',$changed[0])===$changed[1],'foreign bytes not overwritten');
$item=meta_item($job);meta_check(in_array($item['stage'],array('recovery_required','needs_review'),true),'partial graph needs explicit recovery review');
meta_check(WP_Seed_Pixel_Metadata_Admin::state($id)==='review','no success UI on mixed graph');
meta_check(is_wp_error(meta_job_duplicate($id)),'mixed graph claim remains reserved');
$dir=WP_Seed_Pixel_Master_Storage::directory($item,false);$journal=WP_Seed_Pixel_Master_Storage::load($dir,$item);
meta_check($journal['phase']==='graph_needs_review','durable review journal');
meta_check(count(glob($dir.'/graph-original-*'))===3,'review preserves entire recovery evidence');
file_put_contents($root.'/evidence/runtime-review.json',wp_json_encode(array('passed'=>count($checks),'checks'=>$checks,'permanent_purge'=>0),JSON_PRETTY_PRINT));
echo count($checks)." native review/false-conflict checks PASS\n";
