<?php
$root=getenv('PIXEL_METADATA_LAB');
if($root!=='/home/warzy/.cache/wp-seed-pixel-060-metadata-lab'){throw new RuntimeException('Owned local lab required');}
require $root.'/project/wp-seed-pixel-m3-environment/.runtime/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
wp_set_current_user(1);
function install_check($v,$s){if(!$v||is_wp_error($v)){throw new RuntimeException($s.(is_wp_error($v)?': '.$v->get_error_code():''));}}
function install_state(){
    global $wpdb;$s=array();
    foreach(array('posts','postmeta') as $t){$s[$t]=$wpdb->get_results("SELECT * FROM {$wpdb->$t} ORDER BY ".($t==='posts'?'ID':'meta_id'),ARRAY_A);}
    foreach(array('jobs','items') as $t){$s[$t]=$wpdb->get_results('SELECT * FROM '.WP_Seed_Pixel_Job_Store::table($t).' ORDER BY id',ARRAY_A);}
    $s['settings']=get_option('wp_seed_pixel_settings');$s['future']=get_option('wp_seed_pixel_future_uploads');$s['privacy']=get_option('wp_seed_pixel_metadata_uploads');$s['files']=array();
    foreach(array(wp_get_upload_dir()['basedir'],WP_SEED_PIXEL_RECOVERY_ROOT) as $dir){if(is_dir($dir)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile()){$s['files'][$f->getPathname()]=hash_file('sha256',$f->getPathname());}}}}
    ksort($s['files']);return $s;
}
$version=getenv('PIXEL_RELEASE_VERSION')?:'0.6.0-private.4';
$package=getenv('PIXEL_RELEASE_ZIP')?:'/reports/metadata-private4-20261008/candidate/wp-seed-pixel-0.6.0-private.4.zip';
$privacyPolicy=getenv('PIXEL_RELEASE_POLICY')?:'preserve';
$mode=$argv[1]??'';$source=getenv('PIXEL_METADATA_SOURCE');$before_file=$root.'/installation-before.json';
if($mode==='old'){
    install_check(WP_SEED_PIXEL_VERSION===$version,'candidate baseline');
    if($privacyPolicy==='off'){install_check(WP_Seed_Pixel_Metadata_Uploads::configure(false),'explicit OFF setup');}
    elseif($privacyPolicy==='absent'){delete_option('wp_seed_pixel_metadata_uploads');}
    $state=install_state();file_put_contents($before_file,wp_json_encode($state));
    $zip=$source.'/reports/release-0.5.1-20261007/final/candidate/wp-seed-pixel-0.5.1.zip';
} elseif($mode==='update'){
    install_check(WP_SEED_PIXEL_VERSION==='0.5.1','official old runtime');
    install_check(install_state()===json_decode(file_get_contents($before_file),true),'old runtime preserves metadata journals/settings/files');
    $zip=$source.$package;
} elseif($mode==='verify'){
    install_check(WP_SEED_PIXEL_VERSION===$version,'native candidate installed');
    $expected=json_decode(file_get_contents($before_file),true);$actual=install_state();
    install_check($actual===$expected,'native update preserves exact posts/meta/jobs/settings/files');
    WP_Seed_Pixel_Metadata_Uploads::initialize();$after=install_state();
    install_check(WP_Seed_Pixel_Metadata_Uploads::settings()['enabled']===($privacyPolicy!=='off'),'upgrade privacy choice');
    unset($expected['privacy'],$after['privacy']);
    install_check($after===$expected,'privacy initialization leaves existing media/jobs/recovery unchanged');
    file_put_contents($root.'/evidence/install-'.$privacyPolicy.'.json',wp_json_encode(array('count'=>7,'native_core_upgrader'=>true,'official_old'=>'0.5.1','new'=>WP_SEED_PIXEL_VERSION,'privacy_policy'=>$privacyPolicy,'exact_preservation'=>true,'existing_media_jobs_created'=>0,'image_processing_during_install'=>0),JSON_PRETTY_PRINT));
    unlink($before_file);echo "7 native installation/update preservation checks PASS\n";exit;
}else{throw new RuntimeException('Explicit mode required');}
$skin=new Automatic_Upgrader_Skin();$upgrader=new Plugin_Upgrader($skin);
$r=$upgrader->install($zip,array('overwrite_package'=>true));
install_check($r,'native WordPress installation');
echo "Local native package replacement completed.\n";
