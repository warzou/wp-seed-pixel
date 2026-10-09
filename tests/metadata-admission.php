<?php
$argv[1]='library'; require __DIR__.'/metadata-wp.php';
meta_check(WP_Seed_Pixel_Job_Store::install(),'SQL schema');
global $wpdb;
$cases=json_decode(file_get_contents($root.'/cases.json'),true);$results=array();
foreach ($cases as $case) {
    $id=meta_fixture($case['file']);$path=get_attached_file($id);$hash=hash_file('sha256',$path);$editorial=meta_editorial($id);
    $count=(int)$wpdb->get_var('SELECT COUNT(*) FROM '.WP_Seed_Pixel_Job_Store::table('jobs'));
    $a=WP_Seed_Pixel_Metadata_Admin::analyze($id);
    $r=WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified','metadata'=>'anonymize'),1073741824,'',is_wp_error($a)?'unapproved':$a['signature']);
    if ($case['error'] || (!is_wp_error($a)&&!$a['master']['categories'])) {
        meta_check(is_wp_error($r),'refused/no-op '.$case['file']);
        meta_check(hash_file('sha256',$path)===$hash,'refused bytes exact '.$case['file']);
        meta_check((int)$wpdb->get_var('SELECT COUNT(*) FROM '.WP_Seed_Pixel_Job_Store::table('jobs'))===$count,'no unnecessary job '.$case['file']);
        $results[$case['file']]=array('error'=>$r->get_error_code(),'source_unchanged'=>true,'jobs_added'=>0);
    } else {
        meta_check($r,'supported admission '.$case['file']);
        meta_check(WP_Seed_Pixel_Jobs::step((int)$r['id']),'supported transaction '.$case['file']);
        $item=meta_item($r['id']);
        meta_check($item['stage']==='retained','supported retained '.$case['file'].' '.($item['error_code']??''));
        meta_check(WP_Seed_Pixel_Metadata_Admin::state($id)==='anonymized','success state persisted '.$case['file']);
        meta_check(str_contains(WP_Seed_Pixel_Media::details($id),'data-operation="image_restore"'),'existing restore UI '.$case['file']);
        meta_check(WP_Seed_Pixel_Jobs::restore_master((int)$r['id']),'supported restore '.$case['file']);
        meta_check(hash_file('sha256',$path)===$hash,'supported exact restore '.$case['file']);
        meta_check(WP_Seed_Pixel_Metadata_Admin::state($id)==='restored','restored state persisted '.$case['file']);
        $results[$case['file']]=array('error'=>null,'restored_sha'=>$hash);
    }
    meta_check(meta_editorial($id)===$editorial,'editorial exact '.$case['file']);
}
foreach (array(false,true) as $dirty) {
    $id=meta_fixture('jpeg-exif.jpg');$before=WP_Seed_Pixel_Master_Adapter::snapshot($id);
    $path=dirname(get_attached_file($id)).'/graph-'.bin2hex(random_bytes(5)).'.jpg';
    copy($root.'/fixtures/'.($dirty?'jpeg-gps.jpg':'jpeg-clean.jpg'),$path);
    $m=wp_get_attachment_metadata($id);$i=getimagesize($path);$m['sizes']['graph']=array('file'=>basename($path),'width'=>$i[0],'height'=>$i[1],'mime-type'=>'image/jpeg');wp_update_attachment_metadata($id,$m);
    $copyhash=hash_file('sha256',$path);$a=WP_Seed_Pixel_Metadata_Admin::analyze($id);
    if ($dirty) {
        meta_check($a,'supported dirty public copy admitted');
        $job=meta_job($id); meta_check(WP_Seed_Pixel_Jobs::step($job),'whole dirty public graph switch');
        meta_check(meta_item($job)['stage']==='retained','whole dirty graph retained');
        meta_check(!WP_Seed_Pixel_Metadata::read($path)['categories'],'dirty derivative now clean');
        meta_check(WP_Seed_Pixel_Jobs::restore_master($job),'dirty public graph exact restore');
    } else {
        meta_check($a,'clean public-copy graph');$job=meta_job($id);meta_check(WP_Seed_Pixel_Jobs::step($job),'clean public graph switch');
        meta_check(meta_item($job)['stage']==='retained','all public copies checked');
        meta_check(hash_file('sha256',$path)===$copyhash,'clean derivative never re-encoded');
        meta_check(WP_Seed_Pixel_Jobs::restore_master($job),'public graph restore');
    }
    meta_check(hash_file('sha256',$path)===$copyhash,'derivative byte exact');
}
// Unknown metadata on ANY public member must still reject before job creation.
$id=meta_fixture('jpeg-exif.jpg'); $masterhash=hash_file('sha256',get_attached_file($id));
$unsafe=dirname(get_attached_file($id)).'/unsafe-'.bin2hex(random_bytes(5)).'.jpg';
copy($root.'/fixtures/jpeg-orientation6.jpg',$unsafe); $m=wp_get_attachment_metadata($id);$i=getimagesize($unsafe);
$m['sizes']['unsafe']=array('file'=>basename($unsafe),'width'=>$i[0],'height'=>$i[1],'mime-type'=>'image/jpeg');wp_update_attachment_metadata($id,$m);
$a=WP_Seed_Pixel_Metadata_Admin::analyze($id);
meta_check(is_wp_error($a)&&$a->get_error_code()==='METADATA_ORIENTATION','unsafe public derivative blocks whole graph');
meta_check(hash_file('sha256',get_attached_file($id))===$masterhash,'unsafe graph master unchanged');
file_put_contents($root.'/evidence/admission.json',wp_json_encode(array('passed'=>count($checks),'fixtures'=>count($cases),'checks'=>$checks,'results'=>$results),JSON_PRETTY_PRINT));
echo count($checks)." native admission/public-graph checks passed.\n";
