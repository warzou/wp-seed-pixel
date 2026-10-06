<?php
require __DIR__ . '/m3-runtime.php';
$checks = array();
function product_ok($v, $name) { global $checks; if (!$v || is_wp_error($v)) { throw new RuntimeException($name . (is_wp_error($v) ? ': '.$v->get_error_code() : '')); } $checks[$name] = true; return $v; }
global $wpdb;
update_option(WP_Seed_Pixel_Storage_Budget::OPTION,array(),false);
$pending=WP_Seed_Pixel_Jobs::status();
if (!empty($pending['id']) && $pending['kind']==='plan') { $wpdb->update(WP_Seed_Pixel_Job_Store::table('jobs'),array('status'=>'cancelled'),array('id'=>$pending['id'])); }
elseif (!empty($pending['id']) && !in_array($pending['status'],array('completed','completed_errors','cancelled'),true)) { WP_Seed_Pixel_Jobs::control((int)$pending['id'],'cancel'); }
update_option('wp_seed_pixel_settings', array('automatic'=>false,'preset'=>'balanced','cleanup_on_uninstall'=>false), false);
product_ok(WP_Seed_Pixel_Future_Uploads::configure('off', 268435456), 'future safely off');
$im = imagecreatetruecolor(640,480);
imagefilledrectangle($im,0,0,639,479,imagecolorallocate($im,54,155,98));
imagejpeg($im,dirname(__DIR__).'/.runtime/fixtures/product.jpg',95);
imagepng($im,dirname(__DIR__).'/.runtime/fixtures/product.png',0);
imagegif($im,dirname(__DIR__).'/.runtime/fixtures/product.gif'); imagedestroy($im);
$jpg=m3_fixture('product.jpg'); $png=m3_fixture('product.png'); $other=m3_fixture('product.jpg');
$unsupported=m3_fixture('product.gif');
$posts=$wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY ID",ARRAY_A);
$meta=$wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",ARRAY_A);
$hash=hash_file('sha256',get_attached_file($png));
product_ok(WP_Seed_Pixel_Admin::save_settings(array('preset'=>'balanced','automatic'=>'on','png'=>'on')), 'main JPEG PNG enable');
$s=WP_Seed_Pixel_Future_Uploads::settings();
product_ok($s['mode']==='process' && $s['formats']===array('png') && WP_Seed_Pixel_Plugin::settings()['automatic'], 'PNG enrollment independent from legacy JPEG');
product_ok($s['cutoff_id'] >= $other && !get_post_meta($png,WP_Seed_Pixel_Future_Uploads::META,true), 'old media excluded by new cutoff');
product_ok(WP_Seed_Pixel_Admin::save_settings(array('preset'=>'balanced','png'=>'on')), 'JPEG disable independently');
product_ok(WP_Seed_Pixel_Future_Uploads::settings()===$s && !WP_Seed_Pixel_Plugin::settings()['automatic'], 'JPEG toggle preserves PNG cutoff');
product_ok(WP_Seed_Pixel_Admin::save_settings(array('preset'=>'balanced','automatic'=>'on')), 'PNG disable independently');
product_ok(WP_Seed_Pixel_Future_Uploads::settings()['mode']==='off' && WP_Seed_Pixel_Plugin::settings()['automatic'], 'PNG off leaves JPEG on');
product_ok(WP_Seed_Pixel_Future_Uploads::configure('process',268435456,null,array('jpeg','png')), 'native both formats fixture');
product_ok(WP_Seed_Pixel_Admin::save_settings(array('preset'=>'balanced','png'=>'on')), 'native JPEG disable');
product_ok(WP_Seed_Pixel_Future_Uploads::settings()['formats']===array('png'), 'native JPEG disabled without disabling PNG');
product_ok($posts===$wpdb->get_results("SELECT * FROM {$wpdb->posts} ORDER BY ID",ARRAY_A) && $meta===$wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",ARRAY_A) && hash_file('sha256',get_attached_file($png))===$hash, 'settings do not mutate old images or metadata');
wp_set_current_user(0);
product_ok(is_wp_error(WP_Seed_Pixel_Admin::save_settings(array('preset'=>'balanced','png'=>'on'))), 'anonymous settings denied');
product_ok(is_wp_error(WP_Seed_Pixel_Selected_Admin::start(array($jpg))), 'anonymous selected batch denied');
wp_set_current_user(1);
product_ok(is_wp_error(WP_Seed_Pixel_Selected_Admin::start(array())), 'empty selection denied');
product_ok(is_wp_error(WP_Seed_Pixel_Selected_Admin::start(array(99999999))), 'missing media denied');
product_ok(is_wp_error(WP_Seed_Pixel_Selected_Admin::start(array_fill(0,501,$jpg))), 'oversized selection denied');
$scan=product_ok(WP_Seed_Pixel_Scan::start(),'analysis start');
for($i=0;$scan['status']==='running' && $i<100;$i++) { $scan=product_ok(WP_Seed_Pixel_Scan::step($scan['id']),'analysis step '.$i); }
product_ok($scan['status']==='complete','analysis complete');
$plan=product_ok(WP_Seed_Pixel_Selected_Admin::start(array($jpg,$png,$unsupported)),'selected plan starts');
product_ok($plan['total']===3,'plan total exactly selected count');
for($i=0;$plan['phase']==='plan' && $i<100;$i++) {$plan=product_ok(WP_Seed_Pixel_Selected_Admin::command('native_step',$plan['id']),'plan step '.$i);}
product_ok($plan['phase']==='ready','plan ready');
$items=WP_Seed_Pixel_Job_Store::table('items');
$ids=array_map('intval',$wpdb->get_col($wpdb->prepare("SELECT attachment_id FROM $items WHERE job_id=%d AND kind='plan' ORDER BY attachment_id",$plan['id'])));
$expected=array($jpg,$png,$unsupported);sort($expected);
product_ok($ids===$expected,'no unselected IDs enter plan');
product_ok(is_wp_error(WP_Seed_Pixel_Selected_Admin::start(array($other))),'parallel selected start denied');
$job=product_ok(WP_Seed_Pixel_Selected_Admin::command('native_start',$plan['id']),'coordinator start');
product_ok(WP_Seed_Pixel_Selected_Admin::command('native_pause',$job['id'])['status']==='paused','pause');
product_ok(WP_Seed_Pixel_Selected_Admin::current()['status']==='paused','pause persists');
product_ok(WP_Seed_Pixel_Selected_Admin::command('native_resume',$job['id'])['status']==='running','resume');
for($i=0;$job['status']!=='complete' && $i<100;$i++) {$job=product_ok(WP_Seed_Pixel_Selected_Admin::command('native_step',$job['id']),'process step '.$i);}
product_ok($job['status']==='complete' && $job['failed']===0,'selected batch finishes without failures');
product_ok($job['skipped']>=1 && !get_post_meta($unsupported,'_seed_pixel_master_state',true),'mixed unsupported selection safely skipped');
product_ok(get_post_meta($other,'_seed_pixel_master_state',true)==='' && file_exists(get_attached_file($other)),'unselected media untouched');
product_ok(str_contains(WP_Seed_Pixel_Media::details($png),'Dimensions:') && str_contains(WP_Seed_Pixel_Media::details($png),'<summary>'),'technical details have real content');
product_ok(WP_Seed_Pixel_Admin::save_settings(array('preset'=>'balanced')),'UI fixture automation off');
$password=bin2hex(random_bytes(24));wp_set_password($password,1);
file_put_contents(dirname(__DIR__).'/.runtime/browser-login.json',json_encode(array('user'=>'pixel_qa','password'=>$password,'png'=>$png,'jpg'=>$jpg)));
$out=dirname(__DIR__).'/reports/productization';wp_mkdir_p($out);
file_put_contents($out.'/admin-native.json',json_encode(array('checks'=>$checks,'selection'=>$expected,'selected_job'=>$job,'real_site_mutations'=>0),JSON_PRETTY_PRINT));
echo count($checks)." native admin checks PASS\n";
