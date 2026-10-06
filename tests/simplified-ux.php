<?php
require __DIR__ . '/m3-runtime.php';
$_SERVER['DOCUMENT_ROOT'] = rtrim(ABSPATH, '/');
global $wpdb;
$checks = array();
function simple_ok($v, $name) {
    global $checks;
    if (!$v || is_wp_error($v)) { throw new RuntimeException($name . (is_wp_error($v) ? ': ' . $v->get_error_code() : '')); }
    $checks[$name] = true; return $v;
}
function simple_run(array $ids) {
    $r = simple_ok(WP_Seed_Pixel_Selected_Admin::start($ids), 'selection '.count($ids).' accepted');
    for ($n = 0; $r['status'] !== 'complete' && $n < 1000; $n++) {
        $r = WP_Seed_Pixel_Selected_Admin::command($r['phase'] === 'ready' ? 'native_start' : 'native_step', $r['id']);
        if (is_wp_error($r)) { throw new RuntimeException($r->get_error_code()); }
    }
    simple_ok($r['status'] === 'complete' && $r['total'] === count($ids) && $r['failed'] === 0, 'selection '.count($ids).' complete');
    global $wpdb;
    $got = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT attachment_id FROM '.WP_Seed_Pixel_Job_Store::table('items')." WHERE job_id=%d AND kind='operation' ORDER BY attachment_id", $r['id'])));
    sort($ids); simple_ok($got === $ids, 'selection '.count($ids).' exact IDs');
    return $r;
}
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array(), false);
require_once ABSPATH.'wp-admin/includes/template.php';
$options_before=$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'wp_seed_pixel_%' ORDER BY option_name",ARRAY_A);
ob_start();WP_Seed_Pixel_Admin::page();ob_end_clean();
simple_ok($options_before===$wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'wp_seed_pixel_%' ORDER BY option_name",ARRAY_A), 'main rendering leaves Pixel options untouched');
simple_ok(!WP_Seed_Pixel_Quarantine::enabled(), 'main rendering does not prepare recovery');
$im = imagecreatetruecolor(1023, 1537);
imagefilledrectangle($im, 0, 0, 1022, 1536, imagecolorallocate($im, 44, 123, 90));
imagejpeg($im, dirname(__DIR__).'/.runtime/fixtures/simple.jpg', 100);
for ($y=0;$y<1537;$y+=4) { for($x=0;$x<1023;$x+=4) { $c=imagecolorallocate($im,($x+$y)%256,($x*3+$y)%256,($x+$y*5)%256); imagefilledrectangle($im,$x,$y,$x+3,$y+3,$c); } }
imagejpeg($im, dirname(__DIR__).'/.runtime/fixtures/simple.jpg', 100);
imagedestroy($im); $im=imagecreatetruecolor(640,480);
imagefilledrectangle($im,0,0,639,479,imagecolorallocate($im,44,123,90));
imagepng($im, dirname(__DIR__).'/.runtime/fixtures/simple.png', 0);
imagegif($im, dirname(__DIR__).'/.runtime/fixtures/simple.gif'); imagedestroy($im);
$jpg = m3_fixture('simple.jpg'); $png = m3_fixture('simple.png'); $other = m3_fixture('simple.jpg');
$other_hash = hash_file('sha256', get_attached_file($other));
$all_meta = $wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A);
simple_ok(WP_Seed_Pixel_Admin::save_settings(array('preset'=>'balanced','automatic'=>'on','png'=>'on')), 'automatic JPEG PNG save without budget');
$future = WP_Seed_Pixel_Future_Uploads::settings();
simple_ok($future['mode'] === 'process' && $future['formats'] === array('jpeg','png') && $future['capacity_bytes'] === 0, 'automatic per-item admission');
simple_ok($all_meta === $wpdb->get_results("SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id", ARRAY_A), 'settings leave old metadata untouched');
simple_ok(get_option(WP_Seed_Pixel_Storage_Budget::OPTION) === array(), 'no optional ceiling invented');
$restricted=$future;$restricted['capacity_bytes']=12345678;update_option(WP_Seed_Pixel_Future_Uploads::OPTION,$restricted,false);
$chosen=array('operational_ceiling_bytes'=>600000000);update_option(WP_Seed_Pixel_Storage_Budget::OPTION,$chosen,false);
simple_ok(WP_Seed_Pixel_Admin::save_settings(array('preset'=>'balanced','png'=>'on')), 'format change with existing limits');
simple_ok(WP_Seed_Pixel_Future_Uploads::settings()['capacity_bytes']===12345678 && get_option(WP_Seed_Pixel_Storage_Budget::OPTION)===$chosen, 'existing operation and site ceilings preserved');
update_option(WP_Seed_Pixel_Storage_Budget::OPTION,array(),false);WP_Seed_Pixel_Future_Uploads::configure('off',0);
simple_ok(str_contains(WP_Seed_Pixel_Media::details($png), 'Not yet optimized.') && str_contains(WP_Seed_Pixel_Media::details($png), 'Optimize this image'), 'unprocessed PNG clear and actionable');
simple_ok(WP_Seed_Pixel_Admin::save_settings(array('preset'=>'balanced')), 'automatic off');
simple_run(array($png));
$w = get_post_meta($png, '_seed_pixel_master_state', true);
simple_ok(is_array($w), 'PNG optimized via coordinator');
$item = WP_Seed_Pixel_Job_Store::item($w['item_id']);
$record = WP_Seed_Pixel_Quarantine::record($item);
$view = WP_Seed_Pixel_Quarantine::inspect($item);
$html = WP_Seed_Pixel_Media::details($png);
simple_ok($record['before']['bytes'] > $record['candidate']['bytes'] && str_contains($html, size_format($record['before']['bytes'],2)) && str_contains($html, size_format($record['candidate']['bytes'],2)), 'original and optimized actual bytes');
simple_ok($record['before']['width'] === $record['candidate']['width'] && $record['before']['height'] === $record['candidate']['height'], 'PNG dimensions preserved');
simple_ok($view['rollback_available'] && str_contains($html,'Its space has not yet been freed.'), 'retained original storage truth');
simple_ok(str_contains($html,'pixel-delete-approval') && str_contains($html,'disabled'), 'irreversible confirmation initially disabled');
simple_ok(is_wp_error(WP_Seed_Pixel_Workflow::original($png,'purge',$view['generation'],false)), 'purge without confirmation denied');
simple_ok(is_wp_error(WP_Seed_Pixel_Workflow::original($png,'purge','stale',true)), 'stale purge generation denied');
simple_ok(WP_Seed_Pixel_Workflow::original($png,'restore'), 'restore through M4');
simple_ok(hash_file('sha256',get_attached_file($png)) === $record['before']['sha256'], 'restore exact original hash');
simple_run(array($jpg));
$w = get_post_meta($jpg,'_seed_pixel_master_state',true);
simple_ok(is_array($w), 'JPEG optimized via coordinator');
$view = WP_Seed_Pixel_Quarantine::inspect(WP_Seed_Pixel_Job_Store::item($w['item_id']));
simple_ok(WP_Seed_Pixel_Workflow::original($jpg,'purge',$view['generation'],true), 'confirmed purge through M4');
$html = WP_Seed_Pixel_Media::details($jpg);
simple_ok(str_contains($html,'Original permanently deleted.') && !str_contains($html,'data-operation="image_restore"'), 'purged original cannot restore');
simple_ok(hash_file('sha256',get_attached_file($other)) === $other_hash && !get_post_meta($other,'_seed_pixel_master_state',true), 'unselected media unchanged');
foreach (array(5,50) as $count) {
    $ids = array(); for ($n=0;$n<$count;$n++) { $ids[] = m3_fixture($n === 0 ? 'simple.gif' : 'simple.jpg'); }
    $r = simple_run($ids); simple_ok($r['skipped'] >= 1 && $r['success'] > 0, 'mixed '.$count.' optimized and skipped cleanly');
}
$cancel = WP_Seed_Pixel_Selected_Admin::start(array($other));
simple_ok(WP_Seed_Pixel_Selected_Admin::command('native_pause',$cancel['id'])['status'] === 'paused', 'analysis pause');
simple_ok(WP_Seed_Pixel_Selected_Admin::command('native_resume',$cancel['id'])['status'] === 'running', 'analysis resume');
simple_ok(WP_Seed_Pixel_Selected_Admin::command('native_cancel',$cancel['id'])['status'] === 'complete', 'analysis cancel');
wp_set_current_user(0);
simple_ok(is_wp_error(WP_Seed_Pixel_Workflow::original($jpg,'purge','',true)), 'anonymous media action denied');
simple_ok(is_wp_error(WP_Seed_Pixel_Selected_Admin::start(array($other))), 'anonymous selection denied');
wp_set_current_user(1);
$retained = m3_fixture('simple.png'); simple_run(array($retained));
$efficient = imagecreatefrompng(dirname(__DIR__).'/.runtime/fixtures/simple.png');
imagepng($efficient,dirname(__DIR__).'/.runtime/fixtures/simple-efficient.png',9); imagedestroy($efficient);
$no_gain=m3_fixture('simple-efficient.png'); $before_hash=hash_file('sha256',get_attached_file($no_gain));
$no_gain_result=simple_run(array($no_gain));
simple_ok($no_gain_result['skipped']===1 && hash_file('sha256',get_attached_file($no_gain))===$before_hash, 'no-gain image preserved');
simple_ok(str_contains(WP_Seed_Pixel_Media::details($no_gain),'No useful saving'), 'no-gain clearly presented');
$user = wp_update_user(array('ID'=>1,'locale'=>'fr_FR'));
simple_ok(!is_wp_error($user), 'French owner locale');
$password = bin2hex(random_bytes(24)); wp_set_password($password,1);
file_put_contents(dirname(__DIR__).'/.runtime/browser-login.json', json_encode(array('user'=>'pixel_qa','password'=>$password,'png'=>$png,'jpg'=>$jpg,'unprocessed'=>$other,'retained'=>$retained)));
$out=dirname(__DIR__).'/reports/simplified-ux'; wp_mkdir_p($out);
file_put_contents($out.'/workflow.json',json_encode(array('checks'=>$checks,'count'=>count($checks),'real_site_mutations'=>0),JSON_PRETTY_PRINT));
echo count($checks)." simplified workflow checks PASS\n";
