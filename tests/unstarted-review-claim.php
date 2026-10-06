<?php
require __DIR__ . '/m4-runtime.php';
$checks = array();
WP_Seed_Pixel_Future_Uploads::configure('off');
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array());
$id = m3_fixture('m6-RGB.png');
$sha = hash_file('sha256', get_attached_file($id));
$old = WP_Seed_Pixel_Jobs::replace_one($id, array('master'=>'replace_verified'), 1073741824);
if (is_wp_error($old)) { throw new RuntimeException($old->get_error_code()); }
$items = WP_Seed_Pixel_Job_Store::table('items'); $jobs = WP_Seed_Pixel_Job_Store::table('jobs');
$row = m4_item($old['id']);
$data = wp_json_encode(array('before'=>null,'original'=>null,'reason'=>'NEEDS_REVIEW','peak_bytes'=>0,'planned_bytes'=>0));
$wpdb->update($items, array('stage'=>'needs_review','bytes'=>0,'data'=>$data,'snapshot_hash'=>hash('sha256',$data)), array('id'=>$row['id']));
$wpdb->update($jobs, array('status'=>'completed_errors'), array('id'=>$old['id']));
$original = WP_Seed_Pixel_Job_Store::item($row['id']);
$predicate = new ReflectionMethod(WP_Seed_Pixel_Jobs::class, 'unstarted_review');
m4_check($predicate->invoke(null,$original), 'Exact pre-execution needs_review evidence does not reserve a source');
foreach (array('revision'=>1,'attempts'=>1,'bytes'=>1,'lease'=>'held','lease_until'=>time()+60,'journal'=>'{}','receipt'=>'{}','error_code'=>'EVIDENCE_INVALID','snapshot_hash'=>str_repeat('0',64),'stage'=>'recovery_required') as $key=>$value) {
    $mutated=$original; $mutated[$key]=$value;
    m4_check(!$predicate->invoke(null,$mutated), 'Potential execution evidence still reserves image: '.$key);
}
foreach (array('before'=>array('sha256'=>$sha),'original'=>array('path'=>'recovery'),'reason'=>'','planned_bytes'=>1,'peak_bytes'=>1) as $key=>$value) {
    $mutated=$original; $payload=json_decode($data,true);$payload[$key]=$value;
    $mutated['data']=wp_json_encode($payload);$mutated['snapshot_hash']=hash('sha256',$mutated['data']);
    m4_check(!$predicate->invoke(null,$mutated), 'Ambiguous planning evidence still reserves image: '.$key);
}
$fresh = WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified'),1073741824);
if (is_wp_error($fresh)) { throw new RuntimeException($fresh->get_error_code()); }
$wpdb->update($items,array('attempts'=>1),array('id'=>$original['id']));
$blocked=WP_Seed_Pixel_Jobs::step($fresh['id']);
m4_check(is_wp_error($blocked)&&$blocked->get_error_code()==='CLAIM_CONFLICT', 'Any historical execution attempt blocks a new destructive job');
m4_check(hash_file('sha256',get_attached_file($id))===$sha, 'Claim conflict leaves canonical source unchanged');
$wpdb->update($items,array('attempts'=>0),array('id'=>$original['id']));
$done=WP_Seed_Pixel_Jobs::step($fresh['id']);
if (is_wp_error($done) || m4_item($fresh['id'])['stage'] !== 'retained') {
    throw new RuntimeException(wp_json_encode(array('error'=>is_wp_error($done)?$done->get_error_code():null,
        'state'=>array_intersect_key(m4_item($fresh['id']),array_flip(array('stage','error_code','error_class','attempts'))))));
}
m4_check(!is_wp_error($done)&&m4_item($fresh['id'])['stage']==='retained', 'Fresh PNG retry can execute after an unstarted rejected plan');
m4_check(WP_Seed_Pixel_Job_Store::item($original['id'])===$original, 'Historical rejected plan remains byte-for-byte intact');
$retained=WP_Seed_Pixel_Jobs::quarantine_action($fresh['id'],'retain');
if (is_wp_error($retained)) { throw new RuntimeException('retain '.$retained->get_error_code()); }
$restored=WP_Seed_Pixel_Jobs::quarantine_action($fresh['id'],'restore');
if (is_wp_error($restored)) { throw new RuntimeException('restore '.$restored->get_error_code()); }
m4_check(!is_wp_error($restored)&&hash_file('sha256',get_attached_file($id))===$sha, 'Exact restore is not blocked by the rejected historical plan');
m4_report('unstarted-review-claim');
