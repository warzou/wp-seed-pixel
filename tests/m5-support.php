<?php
require_once __DIR__ . '/m4-runtime.php';
function m5_assert($value,$label) { global $checks; $checks[$label]=(bool)$value; if(!$value) { throw new RuntimeException('FAIL '.$label); } }
function m5_result($value) { if(is_wp_error($value)) { throw new RuntimeException($value->get_error_code()); } return $value; }
function m5_image($name='m3-detail.jpg',$native=false) {
    $id=m3_fixture($name,$native); $path=get_attached_file($id); $i=getimagesize($path);
    if(!$native) { wp_update_attachment_metadata($id,array('file'=>_wp_relative_upload_path($path),'width'=>$i[0],'height'=>$i[1],'filesize'=>filesize($path),'sizes'=>array())); }
    return $id;
}
function m5_plan($operation='replace',$lot=1,$resume_scan=false) {
    $scan=$resume_scan ? WP_Seed_Pixel_Scan::current() : m5_result(WP_Seed_Pixel_Scan::start());
    for($n=0;$scan['status']==='running' && $n<3000;$n++) { $scan=m5_result(WP_Seed_Pixel_Scan::step($scan['id'])); }
    if($scan['status']!=='complete') { throw new RuntimeException('Scan did not converge'); }
    $p=m5_result(WP_Seed_Pixel_Jobs::bulk_plan($scan['id'],$operation==='retire'?array('original'=>'retire_verified'):array('master'=>'replace_verified'),1073741824,$operation,$lot,true));
    for($n=0;$p['status']==='queued' && $n<1000;$n++) { $p=m5_result(WP_Seed_Pixel_Jobs::bulk_step($p['id'])); }
    return $p;
}
function m5_items($id) { global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM '.WP_Seed_Pixel_Job_Store::table('items')." WHERE job_id=%d AND kind='operation' ORDER BY id",$id),ARRAY_A); }
function m5_finish($id) {
    $s=m5_result(WP_Seed_Pixel_Jobs::status($id));
    for($n=0;$s['status']==='running' && $n<100;$n++) { $s=m5_result(WP_Seed_Pixel_Jobs::bulk_step($id)); }
    return $s;
}
function m5_report($name,$details=array()) { global $checks; $out=dirname(__DIR__).'/reports/storage-m5'; wp_mkdir_p($out); file_put_contents($out.'/'.$name.'.json',wp_json_encode(array('checks'=>$checks,'details'=>$details),JSON_PRETTY_PRINT)); echo $name.': '.count($checks)." PASS\n"; }
