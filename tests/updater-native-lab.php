<?php
$root = getenv('PIXEL_UPDATER_LAB');
if ($root !== '/home/warzy/.cache/wp-seed-pixel-051-updater-lab') { throw new RuntimeException('Owned local lab required'); }
$wp_path=$root.'/project/.runtime/wordpress';
$source=getenv('PIXEL_UPDATER_SOURCE');
$mode=$argv[1] ?? '';
if ($mode==='install') {
    $config="<?php\ndefine('DB_NAME','pixel_updater');\ndefine('DB_USER','root');\ndefine('DB_PASSWORD','');\ndefine('DB_HOST','localhost:".$root."/mysql.sock');\ndefine('DB_CHARSET','utf8mb4');\n\$table_prefix='wp_';\ndefine('WP_HOME','http://127.0.0.1:8877');\ndefine('WP_SITEURL',WP_HOME);\ndefine('DISABLE_WP_CRON',true);\ndefine('FS_METHOD','direct');\ndefine('WP_HTTP_BLOCK_EXTERNAL',true);\ndefine('WP_ACCESSIBLE_HOSTS','raw.githubusercontent.com,github.com,release-assets.githubusercontent.com');\ndefine('WP_SEED_PIXEL_RECOVERY_ROOT','".$root."/private');\ndefine('WP_DEBUG',true);\ndefine('WP_DEBUG_DISPLAY',false);\nif(is_file(__DIR__.'/private-channel')){define('WP_SEED_PIXEL_UPDATE_MANIFEST','https://updates.example.invalid/manifest.json');}\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire ABSPATH.'wp-settings.php';\n";
    $config=str_replace("define('WP_DEBUG',true);", "define('WP_SEED_PIXEL_STORAGE_ENABLED',true);\ndefine('WP_DEBUG',true);", $config);
    file_put_contents($wp_path.'/wp-config.php',$config);
    define('WP_INSTALLING',true);
    require $wp_path.'/wp-load.php';
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    add_filter('pre_wp_mail','__return_false');
    wp_install('Disposable updater certification','pixel_qa','qa@example.invalid',false,'','local-updater-fixture','en_US');
    echo 'WordPress installed without mail.'; exit;
}
require $wp_path.'/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php';
require_once ABSPATH.'wp-admin/includes/file.php';
require_once ABSPATH.'wp-admin/includes/image.php';
require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
wp_set_current_user(1);
function check($v,$label){if(!$v||is_wp_error($v)){throw new RuntimeException($label.(is_wp_error($v)?': '.$v->get_error_code():''));}}
function state(){global $wpdb;$s=array();foreach(array('posts','postmeta') as $t){$key=$t==='posts'?'ID':'meta_id';$s[$t]=$wpdb->get_results("SELECT * FROM {$wpdb->$t} ORDER BY $key",ARRAY_A);}$s['options']=$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'wp_seed_pixel_%' ORDER BY option_name",ARRAY_A);foreach(array('jobs','items') as $t){$table=WP_Seed_Pixel_Job_Store::table($t);$s[$t]=$wpdb->get_results("SELECT * FROM $table ORDER BY id",ARRAY_A);}$s['files']=array();foreach(array(wp_get_upload_dir()['basedir'],WP_SEED_PIXEL_RECOVERY_ROOT) as $dir){if(is_dir($dir)){foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir,FilesystemIterator::SKIP_DOTS)) as $f){if($f->isFile()){$s['files'][$f->getPathname()]=hash_file('sha256',$f->getPathname());}}}}ksort($s['files']);return $s;}
if($mode==='setup') {
    $channel=$argv[2] ?? '';
    check(in_array($channel,array('stable','private'),true),'explicit fixture channel');
    $old=getenv('PIXEL_OLD_ZIP');$new=getenv('PIXEL_UPDATE_ZIP');
    check(hash_file('sha256',$old)==='b9013b0f4e9564b69563104fc7710d9789341ed615b147f9e476bc23fb1375f7','immutable official 0.5.0');
    $z=new ZipArchive();check($z->open($old)===true,'old archive');$z->extractTo(WP_PLUGIN_DIR);$z->close();
    // 0.5.0 cannot consume stable feeds: test the new adapter on a controlled
    // 0.5.0 bridge fixture, and separately test the unmodified release via private.
    if($channel==='stable'){copy($source.'/includes/class-updater.php',WP_PLUGIN_DIR.'/wp-seed-pixel/includes/class-updater.php');}
    if($channel==='private'){file_put_contents($wp_path.'/private-channel','fixture');}
    $m=json_decode(file_get_contents($source.'/updates/stable.json'),true);$m['version']='0.5.1';$m['sha256']=hash_file('sha256',$new);$m['channel']=$channel;
    $m['package']=$channel==='stable'?'https://github.com/warzou/wp-seed-pixel/releases/download/v0.5.1/wp-seed-pixel-0.5.1.zip':'https://updates.example.invalid/pixel.zip';
    $m['notes_url']=$channel==='stable'?'https://github.com/warzou/wp-seed-pixel/releases/tag/v0.5.1':'https://updates.example.invalid/notes';
    file_put_contents($root.'/manifest.json',wp_json_encode($m));copy($new,$root.'/fixture.zip');
    wp_mkdir_p(WPMU_PLUGIN_DIR);copy($source.'/tests/updater-http-fixture.php',WPMU_PLUGIN_DIR.'/pixel-updater-fixture.php');
    check(!is_wp_error(activate_plugin('wp-seed-pixel/wp-seed-pixel.php')),'fixture activation');
    echo 'Controlled '.$channel.' baseline activated.';
} elseif($mode==='seed') {
    check(WP_SEED_PIXEL_VERSION==='0.5.0','released baseline version');
    update_option('wp_seed_pixel_settings',array('automatic'=>false,'preset'=>'balanced','cleanup_on_uninstall'=>false),false);
    check(WP_Seed_Pixel_Future_Uploads::configure('off'),'future policy off');
    // Synthetic photograph: no consumer media or parallel recovery fields.
    $png=file_get_contents($source.'/.runtime/format-fixtures/photo.png');
    $u=wp_upload_bits('synthetic-retained-conversion.png',null,$png);check(!$u['error'],'synthetic PNG upload');
    $id=wp_insert_attachment(array('post_title'=>'Synthetic retained conversion witness','post_mime_type'=>'image/png'),$u['file']);
    wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$u['file']));
    check(WP_Seed_Pixel_Job_Store::install(),'existing schema ready before snapshot');
    $native=WP_Seed_Pixel_Master_Adapter::snapshot($id);check($native,'valid native baseline');
    $a=WP_Seed_Pixel_Format_Conversion::analyze($id);check($a,'real synthetic conversion analysis');
    check(WP_Seed_Pixel_Format_Conversion::convert($id,$a['generation'],true),'retained synthetic conversion');
    $before=array('state'=>state(),'id'=>$id,'native'=>$native,'generation'=>$a['generation'],'record'=>WP_Seed_Pixel_Format_Conversion::record($id)['record']);
    file_put_contents($root.'/before.json',wp_json_encode($before));
    echo 'Exact settings, jobs and retained synthetic conversion baseline saved.';
} elseif($mode==='delta-native') {
    $before=json_decode(file_get_contents($root.'/before.json'),true);$after=WP_Seed_Pixel_Master_Adapter::snapshot($before['id']);
    foreach($before['native'] as $k=>$v){if($v!==$after[$k]){echo $k.": ".wp_json_encode($v)." -> ".wp_json_encode($after[$k])."\n";}}
} elseif($mode==='delta') {
    $before=json_decode(file_get_contents($root.'/before.json'),true)['state'];$after=state();
    foreach($before as $key=>$v){if($v!==$after[$key]){echo $key." differs\n";if($key==='options'){foreach($after[$key] as $r){$old=array_values(array_filter($v,static function($a)use($r){return $a['option_name']===$r['option_name'];}));if(!$old||$old[0]!==$r){echo $r['option_name']."\n";}}}else{echo wp_json_encode($after[$key])."\n";}}}
} elseif($mode==='verify') {
    $before=json_decode(file_get_contents($root.'/before.json'),true);
    check(WP_SEED_PIXEL_VERSION==='0.5.1','candidate runtime installed');
    check(state()===$before['state'],'all settings posts postmeta jobs and files exact');
    check(WP_Seed_Pixel_Format_Conversion::record($before['id'])['record']===$before['record'],'retained journal exact');
    check(str_contains(WP_Seed_Pixel_Format_Admin::panel($before['id']),'pixel-format-result'),'retained state readable');
    check(WP_Seed_Pixel_Format_Conversion::restore($before['id'],$before['generation']),'exact retained conversion restore after update');
    check(WP_Seed_Pixel_Master_Adapter::snapshot($before['id'])===$before['native'],'native PNG graph restored');
    file_put_contents(getenv('PIXEL_UPDATER_REPORT'),wp_json_encode(array('status'=>'PASS','installed_version'=>WP_SEED_PIXEL_VERSION,'state_preserved'=>true,'retained_conversion_restored'=>true,'media_processing_during_update'=>0),JSON_PRETTY_PRINT));
    echo 'Native update and retained conversion preservation PASS.';
} elseif($mode==='transport') {
    // Actual public parser and transport/ZIP guards; never install the package.
    if (!class_exists('WP_Seed_Pixel_Updater')) { require $source.'/includes/class-updater.php'; }
    check(!defined('WP_SEED_PIXEL_UPDATE_MANIFEST'),'no private fixture override');
    check(!is_file(WPMU_PLUGIN_DIR.'/pixel-updater-fixture.php'),'no HTTP fixture');
    $response=wp_remote_get(WP_Seed_Pixel_Updater::endpoint(),array('timeout'=>20,'redirection'=>0));
    check(!is_wp_error($response)&&wp_remote_retrieve_response_code($response)===200,'public manifest HTTP 200');
    $m=WP_Seed_Pixel_Updater::manifest(true);
    check($m===json_decode(file_get_contents($source.'/updates/stable.json'),true),'actual parser exact published manifest');
    $file=wp_tempnam('pixel-real-github.zip');
    $method=new ReflectionMethod('WP_Seed_Pixel_Updater','package_request');
    try{$r=$method->invoke(null,$m['package'],$file,true);check(!is_wp_error($r)&&wp_remote_retrieve_response_code($r)===200,'real GitHub one-hop download');check(filesize($file)===218958,'real bytes');check(hash_file('sha256',$file)===$m['sha256'],'real hash');check(WP_Seed_Pixel_Updater::archive($file,$m),'real archive identity');file_put_contents(getenv('PIXEL_UPDATER_REPORT'),wp_json_encode(array('status'=>'PASS','manifest_http'=>200,'parser_exact'=>true,'version'=>$m['version'],'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file),'installed'=>false),JSON_PRETTY_PRINT));echo 'Real GitHub manifest and asset verified, not installed.';}finally{if(is_file($file)){unlink($file);}}
} else {throw new RuntimeException('Explicit mode required');}
