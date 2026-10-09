<?php
// Synthetic native WordPress lifecycle; no remote endpoint or existing media.
$argv[1]='library';
require __DIR__.'/metadata-wp.php';
require_once ABSPATH.'wp-admin/includes/file.php';
meta_check(WP_Seed_Pixel_Job_Store::install(),'native SQL schema');
WP_Seed_Pixel_Metadata_Uploads::configure(true);
meta_check(WP_Seed_Pixel_Metadata_Uploads::settings()['enabled'],'fresh default enabled');
$existing=meta_fixture('jpeg-exif.jpg');
$old_hash=hash_file('sha256',get_attached_file($existing));
meta_check(WP_Seed_Pixel_Metadata_Uploads::configure(false),'explicit OFF');
WP_Seed_Pixel_Metadata_Uploads::initialize();
meta_check(!WP_Seed_Pixel_Metadata_Uploads::settings()['enabled'],'initialization preserves OFF');
function native_upload($name) {
    global $root;
    $u=wp_upload_bits('new-upload-'.bin2hex(random_bytes(6)).'-'.$name,null,file_get_contents($root.'/fixtures/'.$name));
    meta_check(!$u['error'],'new synthetic upload accepted');
    $u=apply_filters('wp_handle_upload',$u,'upload');
    $id=wp_insert_attachment(array('post_title'=>'Synthetic privacy upload','post_excerpt'=>'Caption unchanged','post_content'=>'Description unchanged','post_mime_type'=>wp_check_filetype($name)['type']),$u['file']);
    update_post_meta($id,'_wp_attachment_image_alt','Alternative unchanged');
    $source=hash_file('sha256',$u['file']);
    $meta=wp_generate_attachment_metadata($id,$u['file']);
    wp_update_attachment_metadata($id,$meta);
    return array((int)$id,$source);
}
list($off,$off_hash)=native_upload('jpeg-exif.jpg');
meta_check(hash_file('sha256',get_attached_file($off))===$off_hash,'OFF leaves master exact');
meta_check(WP_Seed_Pixel_Metadata_Admin::state($off)==='filterable','OFF manual action available');
meta_check(WP_Seed_Pixel_Metadata_Uploads::configure(true),'explicit ON');
foreach(array('jpeg-exif.jpg','png-text.png') as $name) {
    list($id,$before)=native_upload($name);
    $marker=get_post_meta($id,WP_Seed_Pixel_Metadata_Uploads::META,true);
    meta_check(($marker['state']??'')==='anonymized','automatic result '.wp_json_encode($marker));
    meta_check(WP_Seed_Pixel_Metadata_Admin::state($id)==='anonymized','current graph verified');
    $panel=WP_Seed_Pixel_Metadata_Admin::panel($id);
    meta_check(strpos($panel,'data-operation="start"')===false,'no redundant action');
    meta_check(strpos($panel,'value="anonymize"')===false,'no redundant radio');
    $item=WP_Seed_Pixel_Media::operation($id);
    $view=WP_Seed_Pixel_Quarantine::inspect($item);
    meta_check(!is_wp_error($view)&&$view['rollback_available'],'exact recovery available');
    $path=get_attached_file($id);$public=file_get_contents($path);
    // Simulate an external replacement only on this disposable fixture.
    file_put_contents($path,file_get_contents($root.'/fixtures/'.$name));clearstatcache(true,$path);
    meta_check(WP_Seed_Pixel_Metadata_Admin::state($id)!=='anonymized','external file change invalidates cached success');
    file_put_contents($path,$public);clearstatcache(true,$path);
    meta_check(WP_Seed_Pixel_Metadata_Admin::state($id)==='anonymized','exact public graph certifies again');
    $r=WP_Seed_Pixel_Jobs::quarantine_action((int)$item['job_id'],'restore',array(),(int)$item['id']);
    meta_check($r,'restore upload original');
    meta_check(hash_file('sha256',get_attached_file($id))===$before,'original hash restored');
    meta_check(WP_Seed_Pixel_Metadata_Admin::state($id)==='restored','restored state current');
    meta_check(!WP_Seed_Pixel_Metadata_Uploads::blocks_optimizer($id),'restored marker no longer blocks');
}
meta_check(hash_file('sha256',get_attached_file($existing))===$old_hash,'existing media not processed');
meta_check(!get_post_meta($existing,WP_Seed_Pixel_Metadata_Uploads::META,true),'old media not enrolled');
foreach(array('jpeg-clean.jpg','png-clean.png') as $name) {
    list($id,$hash)=native_upload($name);
    meta_check(get_post_meta($id,WP_Seed_Pixel_Metadata_Uploads::META,true)['state']==='clean','clean upload no transaction');
    meta_check(!WP_Seed_Pixel_Media::operation($id),'clean upload no recovery/job');
    meta_check(hash_file('sha256',get_attached_file($id))===$hash,'clean master unchanged');
}
foreach(array('jpeg-provenance.jpg','jpeg-orientation6.jpg','jpeg-unknown-app.jpg','png-provenance.png') as $name) {
    list($id,$hash)=native_upload($name);
    $m=get_post_meta($id,WP_Seed_Pixel_Metadata_Uploads::META,true);
    meta_check(($m['state']??'')==='review','unsafe upload review '.$name);
    meta_check(!WP_Seed_Pixel_Media::operation($id),'unsafe upload no Pixel transaction');
    meta_check(strpos(WP_Seed_Pixel_Metadata_Admin::panel($id),'data-operation="start"')===false,'unsafe upload no ordinary action');
}
$limits=get_option(WP_Seed_Pixel_Storage_Budget::OPTION,null);
update_option(WP_Seed_Pixel_Storage_Budget::OPTION,array('provider_quota_bytes'=>1048576,'operational_ceiling_bytes'=>1048576,'uncertainty_reserve_bytes'=>0,'safety_reserve_bytes'=>0,'max_usage_age'=>60));
$usage=function(){return array('complete'=>true,'live'=>true,'includes_recovery'=>true,'bytes'=>1048576,'measured_at'=>time(),'uncertainty_bytes'=>0);};
add_filter('wp_seed_pixel_storage_usage',$usage);
list($id,$hash)=native_upload('jpeg-exif.jpg');
meta_check(hash_file('sha256',get_attached_file($id))===$hash,'storage refusal no mutation');
meta_check(get_post_meta($id,WP_Seed_Pixel_Metadata_Uploads::META,true)['state']==='review','storage refusal review');
meta_check(get_post_meta($id,WP_Seed_Pixel_Metadata_Uploads::META,true)['reason']==='CEILING_EXCEEDED','storage refusal exact Job reason');
meta_check(WP_Seed_Pixel_Metadata_Admin::state($id)==='review','storage current UI review');
meta_check(strpos(WP_Seed_Pixel_Metadata_Admin::panel($id),'data-operation="start"')===false,'storage no misleading action');
remove_filter('wp_seed_pixel_storage_usage',$usage);
if($limits===null){delete_option(WP_Seed_Pixel_Storage_Budget::OPTION);}else{update_option(WP_Seed_Pixel_Storage_Budget::OPTION,$limits);}
$legacy=get_option('wp_seed_pixel_settings',null);$future=get_option(WP_Seed_Pixel_Future_Uploads::OPTION,null);
try {
    meta_check(WP_Seed_Pixel_Future_Uploads::configure('off'),'legacy scheduler mode');
    $setting=WP_Seed_Pixel_Plugin::settings();$setting['automatic']=true;update_option('wp_seed_pixel_settings',$setting);
    list($id,$hash)=native_upload('jpeg-clean.jpg');
    meta_check((bool)wp_next_scheduled('wp_seed_pixel_auto',array($id)),'clean upload preserves legacy JPEG scheduling');
    meta_check(hash_file('sha256',get_attached_file($id))===$hash,'legacy scheduling no synchronous image change');
    meta_check(WP_Seed_Pixel_Future_Uploads::configure('process',1073741824,null,array('jpeg','png')),'future optimization enabled');
    foreach(array('jpeg-exif.jpg','png-text.png') as $name){
        list($id,$unused)=native_upload($name);$hash=hash_file('sha256',get_attached_file($id));
        WP_Seed_Pixel_Future_Uploads::run($id);
        meta_check(hash_file('sha256',get_attached_file($id))===$hash,'privacy blocks future encoding '.$name);
        $marker=get_post_meta($id,WP_Seed_Pixel_Future_Uploads::META,true);
        meta_check(($marker['state']??'')==='review'&&empty($marker['job_id']),'no parallel recovery domain '.$name);
    }
} finally {
    if($legacy===null){delete_option('wp_seed_pixel_settings');}else{update_option('wp_seed_pixel_settings',$legacy);}
    if($future===null){delete_option(WP_Seed_Pixel_Future_Uploads::OPTION);}else{update_option(WP_Seed_Pixel_Future_Uploads::OPTION,$future);}
}
file_put_contents($root.'/evidence/uploads.json',wp_json_encode(array('count'=>count($checks),'checks'=>$checks,'actualMultipart'=>false),JSON_PRETTY_PRINT));
echo count($checks)." new-upload lifecycle checks PASS\n";
