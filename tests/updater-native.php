<?php
require __DIR__ . '/runtime.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
$checks = array();
function update_check($v, $name) { global $checks; if (!$v) { throw new RuntimeException($name); } $checks[$name] = true; }
$out = dirname(__DIR__) . '/reports/productization'; wp_mkdir_p($out);
$zip = getenv('PIXEL_UPDATE_ZIP');
if (!is_file($zip)) { throw new RuntimeException('Explicit private candidate required'); }
$m = array('schema'=>1,'slug'=>'wp-seed-pixel','channel'=>'private','version'=>'0.4.0','requires'=>'6.6','tested'=>get_bloginfo('version'),'requires_php'=>'8.1',
    'package'=>'https://127.0.0.1/pixel.zip','sha256'=>hash_file('sha256',$zip),'released'=>'2026-10-06','notes_url'=>'https://127.0.0.1/notes.html');
$requests = array(); $mode = 'good';
// Transport fixtures isolate failure injection; actual core upgrader still downloads,
// validates, unpacks, replaces the installed plugin and records the result.
add_filter('pre_http_request', function($pre,$args,$url) use (&$mode,&$m,&$requests,$zip) {
    if (!str_starts_with($url,'https://127.0.0.1/')) { return new WP_Error('lab_external_blocked'); }
    $requests[] = array('url'=>$url,'cookies'=>$args['cookies'] ?? array(),'body'=>$args['body'] ?? null);
    if ($url === WP_SEED_PIXEL_UPDATE_MANIFEST) {
        if ($mode==='unavailable') { return new WP_Error('fixture_network'); }
        $body=$mode==='json' ? '{bad' : json_encode($m);
        return array('response'=>array('code'=>200),'headers'=>array(),'body'=>$body,'cookies'=>array());
    }
    if ($mode==='missing') { return array('response'=>array('code'=>404),'headers'=>array(),'body'=>'','cookies'=>array()); }
    if (!empty($args['stream'])) { file_put_contents($args['filename'],$mode==='zip' ? 'not a zip' : file_get_contents($zip)); }
    return array('response'=>array('code'=>200),'headers'=>array(),'body'=>'','cookies'=>array());
},10,3);
$GLOBALS['wp_version'] = get_bloginfo('version');
update_check(WP_Seed_Pixel_Updater::manifest(true)!==false,'fresh controlled manifest');
foreach(array('same'=>'0.4.0','older'=>'0.3.99','development'=>'0.5.0') as $case=>$current) {
    $t=(object)array('checked'=>array(WP_Seed_Pixel_Updater::ID=>$current),'response'=>array(),'no_update'=>array());
    $t=WP_Seed_Pixel_Updater::offer($t);
    update_check(isset($t->response[WP_Seed_Pixel_Updater::ID])===($case==='older'),'version '.$case);
}
$count=count($requests); WP_Seed_Pixel_Updater::manifest(); WP_Seed_Pixel_Updater::manifest();
update_check(count($requests)===$count,'cached manifest no repeated HTTP');
foreach(array('unavailable','json') as $mode) { update_check(WP_Seed_Pixel_Updater::manifest(true)===false,'manifest '.$mode.' fails safely'); }
$mode='good';
foreach(array('version'=>'nonsense','slug'=>'other','sha256'=>'bad','package'=>'https://other.invalid/pixel.zip','channel'=>'nightly') as $key=>$bad) {
    $v=$m; $v[$key]=$bad; update_check(WP_Seed_Pixel_Updater::validate($v,WP_SEED_PIXEL_UPDATE_MANIFEST)===false,'reject manifest '.$key);
}
update_check(WP_Seed_Pixel_Updater::validate($m,'http://127.0.0.1/manifest.json')===false,'reject non HTTPS trust');
$settings=array('automatic'=>true,'preset'=>'balanced','cleanup_on_uninstall'=>false);
update_option('wp_seed_pixel_settings',$settings,false);
update_option(WP_Seed_Pixel_Storage_Budget::OPTION,array('operational_ceiling_bytes'=>600000000),false);
update_option(WP_Seed_Pixel_Future_Uploads::OPTION,array('mode'=>'off','formats'=>array('jpeg','png'),'cutoff_id'=>99,'cutoff_utc'=>100,'generation'=>'','capacity_bytes'=>102400000,'actor_id'=>1),false);
update_option('pixel_lab_quarantine_witness',array('item'=>17,'state'=>'retained'),false);
global $wpdb;
$before=$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'wp_seed_pixel_%' OR option_name='pixel_lab_quarantine_witness' ORDER BY option_name",ARRAY_A);
$posts=hash('sha256',json_encode($wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY ID",ARRAY_A)));
$meta=hash('sha256',json_encode($wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",ARRAY_A)));
$tables=array(WP_Seed_Pixel_Job_Store::table('jobs'),WP_Seed_Pixel_Job_Store::table('items'));
$job_hashes=array();foreach($tables as $table) {$job_hashes[$table]=hash('sha256',json_encode($wpdb->get_results("SELECT * FROM $table ORDER BY id",ARRAY_A)));}
$media_hashes=array();$uploads=wp_get_upload_dir()['basedir'];
if(is_dir($uploads)) {foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploads,FilesystemIterator::SKIP_DOTS)) as $file) {if($file->isFile()) {$media_hashes[$file->getPathname()]=hash_file('sha256',$file->getPathname());}}}
$upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
// The installed fixture version is set by the lab launcher to0.3.99 before this process.
update_check(WP_SEED_PIXEL_VERSION==='0.3.99','older installed runtime loaded');
$m['sha256']=str_repeat('0',64);
$old_runtime=hash_file('sha256',WP_PLUGIN_DIR.'/'.WP_Seed_Pixel_Updater::ID);
$bad_t=(object)array('last_checked'=>time(),'checked'=>array(WP_Seed_Pixel_Updater::ID=>'0.3.99'),'response'=>array(),'no_update'=>array());
set_site_transient('update_plugins',WP_Seed_Pixel_Updater::offer($bad_t));
$bad=$upgrader->upgrade(WP_Seed_Pixel_Updater::ID,array('clear_update_cache'=>false));
update_check($bad!==true && is_wp_error($upgrader->skin->get_errors()) && hash_file('sha256',WP_PLUGIN_DIR.'/'.WP_Seed_Pixel_Updater::ID)===$old_runtime,'actual core rejects bad hash with old runtime intact');
$r=WP_Seed_Pixel_Updater::download(false,$m['package'],$upgrader,array('plugin'=>WP_Seed_Pixel_Updater::ID));
update_check(is_wp_error($r) && $r->get_error_code()==='pixel_update_integrity','bad hash rejected before install');
$m['sha256']=hash_file('sha256',$zip); $mode='missing';
update_check(is_wp_error(WP_Seed_Pixel_Updater::download(false,$m['package'],$upgrader,array('plugin'=>WP_Seed_Pixel_Updater::ID))),'missing package rejected');
$mode='zip'; $m['sha256']=hash('sha256','not a zip');
update_check(is_wp_error(WP_Seed_Pixel_Updater::download(false,$m['package'],$upgrader,array('plugin'=>WP_Seed_Pixel_Updater::ID))),'invalid ZIP rejected');
$mode='good';$m['sha256']=hash_file('sha256',$zip);
$wrong=$m;$wrong['version']='0.4.1';
update_check(is_wp_error(WP_Seed_Pixel_Updater::archive($zip,$wrong)),'wrong package version rejected');
$t=(object)array('last_checked'=>time(),'checked'=>array(WP_Seed_Pixel_Updater::ID=>'0.3.99'),'response'=>array(),'no_update'=>array());
set_site_transient('update_plugins',WP_Seed_Pixel_Updater::offer($t));
$r=$upgrader->upgrade(WP_Seed_Pixel_Updater::ID,array('clear_update_cache'=>false));
update_check($r===true,'actual Plugin_Upgrader upgrade succeeds');
// Core's single-upgrade UI reactivates after its replacement request.
$r=activate_plugin(WP_Seed_Pixel_Updater::ID);
update_check(!is_wp_error($r) && is_plugin_active(WP_Seed_Pixel_Updater::ID),'standard reactivation succeeds');
wp_cache_flush();
$after=$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'wp_seed_pixel_%' OR option_name='pixel_lab_quarantine_witness' ORDER BY option_name",ARRAY_A);
update_check($before===$after,'all Pixel settings cutoff schema and quarantine witness preserved');
update_check($posts===hash('sha256',json_encode($wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY ID",ARRAY_A))),'all posts preserved');
update_check($meta===hash('sha256',json_encode($wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",ARRAY_A))),'all postmeta preserved');
foreach($job_hashes as $table=>$hash) {update_check($hash===hash('sha256',json_encode($wpdb->get_results("SELECT * FROM $table ORDER BY id",ARRAY_A))),'persistent coordinator table preserved '.$table);}
foreach($media_hashes as $file=>$hash) {update_check(is_file($file) && hash_equals($hash,hash_file('sha256',$file)),'media file preserved '.count($checks));}
$installed=file_get_contents(WP_PLUGIN_DIR.'/'.WP_Seed_Pixel_Updater::ID);
update_check(str_contains($installed,"define('WP_SEED_PIXEL_BUILD', '".WP_SEED_PIXEL_BUILD."')"),'new build installed');
foreach($requests as $r){update_check(empty($r['cookies'])&&empty($r['body']),'minimal update request '.count($checks));}
file_put_contents($out.'/updater-native.json',json_encode(array('checks'=>$checks,'transport'=>'controlled HTTP fixture','actual_core_upgrader'=>true,'media_mutations'=>0),JSON_PRETTY_PRINT));
echo count($checks)." native WordPress update checks PASS\n";
