<?php
declare(strict_types=1);
$root=getenv('PIXEL_RELEASE_LAB');$site=$argv[1]??'';$phase=$argv[2]??'';$report=getenv('PIXEL_RELEASE_REPORT');
if(!is_string($root)||realpath($root)!==$root||trim(file_get_contents($root.'/publication.marker'))!=='pixel-publication-synthetic-only-v1'
    ||$site!=='f'||!in_array($phase,['before','upgrade','after'],true)||!is_dir($report)){throw new RuntimeException('OWNED_SYNTHETIC_RELEASE_LAB_REQUIRED');}
$_SERVER['DOCUMENT_ROOT']=$root.'/'.$site.'/web';require $_SERVER['DOCUMENT_ROOT'].'/wp-load.php';wp_set_current_user(1);
$checks=[];
function stable_check($v,string $label):void {global $checks;if(is_wp_error($v)||!$v){throw new RuntimeException($label.(is_wp_error($v)?':'.$v->get_error_code():''));}$checks[]=$label;}
function stable_tree(string $path):array {$out=[];foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path,FilesystemIterator::SKIP_DOTS)) as $f){stable_check($f->isFile()&&!$f->isLink(),'plain protected file');$s=lstat($f->getPathname());$out[substr($f->getPathname(),strlen($path)+1)]=[hash_file('sha256',$f->getPathname()),$s['dev'],$s['ino'],$s['size']];}ksort($out);return $out;}
function stable_state():array {global $wpdb;$s=get_option(WP_Seed_Pixel_Recovery_Setup::OPTION);return ['options'=>$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'wp_seed_%' OR option_name IN ('home','siteurl','active_plugins') ORDER BY option_name",ARRAY_A),'posts'=>$wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY ID",ARRAY_A),'postmeta'=>$wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",ARRAY_A),'jobs'=>$wpdb->get_results('SELECT * FROM '.WP_Seed_Pixel_Job_Store::table('jobs').' ORDER BY id',ARRAY_A),'items'=>$wpdb->get_results('SELECT * FROM '.WP_Seed_Pixel_Job_Store::table('items').' ORDER BY id',ARRAY_A),'uploads'=>stable_tree(wp_upload_dir(null,false)['basedir']),'recovery'=>stable_tree($s['path'])];}
stable_check($wpdb->prefix==='lab_'&&DB_NAME==='seed_portability_f','synthetic SQL scope');
stable_check(WP_Seed_Pixel_Recovery_Setup::gate(),'recovery gate');$storage=WP_Seed_Pixel_Recovery_Setup::verify();stable_check($storage,'storage identity');stable_check(WP_Seed_Pixel_Migration_Binding::verify_store($storage),'immutable migration provenance');
$items=$wpdb->get_results('SELECT * FROM '.WP_Seed_Pixel_Job_Store::table('items')." WHERE kind='operation' AND stage='retained' ORDER BY id",ARRAY_A);foreach($items as $item){stable_check(WP_Seed_Pixel_Migration_Binding::availability($item)===true,'retained native recovery');}
$baseline=$root.'/archives/stable-070-before.bin';$zip=$report.'/candidate/wp-seed-pixel-0.7.0.zip';$manifest=json_decode(file_get_contents($report.'/candidate/stable.json'),true,32,JSON_THROW_ON_ERROR);
stable_check(WP_Seed_Pixel_Updater::validate($manifest,WP_Seed_Pixel_Updater::OFFICIAL_MANIFEST)===$manifest,'stable manifest validates');stable_check(hash_file('sha256',$zip)===$manifest['sha256'],'manifest ZIP SHA');stable_check(WP_Seed_Pixel_Updater::archive($zip,$manifest)===true,'native archive guard');
if($phase==='before'){
    stable_check(WP_SEED_PIXEL_VERSION==='0.7.0-rc.2','certified RC2 installed');stable_check(!file_exists($baseline),'fresh stable baseline');file_put_contents($baseline,serialize(stable_state()));chmod($baseline,0600);
}else{
    $before=unserialize(file_get_contents($baseline),['allowed_classes'=>false]);stable_check(stable_state()===$before,'exact SQL files inodes and proofs before action');
    if($phase==='upgrade'){
        define('FS_METHOD','direct');require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
        $skin=new Automatic_Upgrader_Skin();$u=new Plugin_Upgrader($skin);stable_check($u->install($zip,['overwrite_package'=>true]),'native stable ZIP replacement');stable_check(is_plugin_active('wp-seed-pixel/wp-seed-pixel.php'),'plugin remains active');stable_check(get_plugin_data(WP_PLUGIN_DIR.'/wp-seed-pixel/wp-seed-pixel.php',false,false)['Version']==='0.7.0','stable header installed');
    }else{
        stable_check(WP_SEED_PIXEL_VERSION==='0.7.0'&&WP_SEED_PIXEL_BUILD==='0.7.0','fresh stable runtime');stable_check(WP_SEED_PIXEL_ENGINE_VERSION==='0.3.1','engine generation preserved');
        $key='wp_seed_pixel_update_'.substr(hash('sha256',WP_Seed_Pixel_Updater::OFFICIAL_MANIFEST),0,24);$old=get_site_transient($key);set_site_transient($key,$manifest,60);
        try{foreach(['0.6.1'=>true,'0.7.0-rc.2'=>true,'0.7.0'=>false,'0.7.1'=>false] as $version=>$expected){$v=WP_Seed_Pixel_Updater::offer((object)['checked'=>[WP_Seed_Pixel_Updater::ID=>$version],'response'=>[],'no_update'=>[]]);stable_check(isset($v->response[WP_Seed_Pixel_Updater::ID])===$expected,'updater offer '.$version);}
            $bad=$manifest;$bad['version']='0.7.0-rc.2';stable_check(WP_Seed_Pixel_Updater::validate($bad,WP_Seed_Pixel_Updater::OFFICIAL_MANIFEST)===false,'RC cannot enter stable feed');$bad=$manifest;$bad['package']=str_replace('/v0.7.0/','/v0.6.1/',$bad['package']);stable_check(WP_Seed_Pixel_Updater::validate($bad,WP_Seed_Pixel_Updater::OFFICIAL_MANIFEST)===false,'mismatched tag refused');
        }finally{if($old===false){delete_site_transient($key);}else{set_site_transient($key,$old,60);}}
    }
    stable_check(stable_state()===$before,'promotion preserves exact protected state');
}
$result=['phase'=>$phase,'verdict'=>'PASS','version'=>WP_SEED_PIXEL_VERSION,'checks'=>count($checks),'native_recoveries'=>count($items),'no_image_operation'=>true];file_put_contents($report.'/'.$phase.'.json',wp_json_encode($result,JSON_PRETTY_PRINT));echo wp_json_encode($result)."\n";
