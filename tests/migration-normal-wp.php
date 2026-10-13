<?php
$root = getenv('SEED_PORTABILITY_LAB'); $site = $argv[1] ?? '';
if (!$root || realpath($root) !== $root || trim(file_get_contents($root . '/synthetic-lab.marker')) !== 'wp-seed-portability-synthetic-v1'
    || !in_array($site,array('d','e'),true)) { throw new RuntimeException('Owned synthetic lab required'); }
$_SERVER['DOCUMENT_ROOT'] = $root . '/' . $site . '/web'; require $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php'; wp_set_current_user(1); $checks = array();
function check($v,$label) { global $checks; if (is_wp_error($v) || !$v) { throw new RuntimeException($label . ': ' . (is_wp_error($v) ? $v->get_error_code() : 'FAIL')); } $checks[] = $label; }
$state = WP_Seed_Pixel_Recovery_Setup::prepare(); check($state,'fresh normal storage'); WP_Seed_Pixel_Recovery_Setup::apply();
check(WP_Seed_Pixel_Job_Store::install(),'normal SQL schema');
foreach (array('jpeg-gps.jpg','png-text.png','jpeg-exif.jpg') as $name) {
    $u = wp_upload_bits('normal-' . $site . '-' . $name,null,file_get_contents(getenv('SEED_PORTABILITY_FIXTURES') . '/' . $name)); check(!$u['error'],'normal upload');
    $id = wp_insert_attachment(array('post_title'=>'Synthetic normal behavior','post_mime_type'=>wp_check_filetype($name)['type']),$u['file']);
    wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$u['file']));
    if ($site === 'e') {
        $info = getimagesize($u['file']);
        $m = array('attachment_id'=>$id,'generation'=>'synthetic-portability','master_sha256'=>hash_file('sha256',$u['file']),'master_bytes'=>filesize($u['file']),
            'files'=>array('view'=>array('kind'=>'master','path'=>$u['file'],'sha256'=>hash_file('sha256',$u['file']),'bytes'=>filesize($u['file']),'width'=>$info[0],'height'=>$info[1])));
        update_post_meta($id,'_seed_pixel_manifest',$m); update_post_meta($id,'_seed_pixel_history',array($m));
    }
    $before = WP_Seed_Pixel_Master_Adapter::snapshot($id,false,true); check($before,'normal source snapshot');
    $analysis = WP_Seed_Pixel_Metadata_Admin::analyze($id); check($analysis,'normal analysis');
    $job = WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified','metadata'=>'anonymize'),1073741824,'',$analysis['signature']); check($job,'normal admission');
    check(WP_Seed_Pixel_Jobs::step($job['id']),'normal execution');
    $again = WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified','metadata'=>'anonymize'),1073741824,'',$analysis['signature']);
    check(is_wp_error($again),'normal conflicting claim refused');
    check(WP_Seed_Pixel_Jobs::restore_master($job['id']),'normal official restore');
    check(WP_Seed_Pixel_Master_Adapter::snapshot($id,false,true) === $before,'normal exact restore');
}
if ($site === 'e') {
    $u = wp_upload_bits('normal-conversion.png',null,file_get_contents('/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/.runtime/format-fixtures/smooth.png')); check(!$u['error'],'synthetic conversion source');
    $id = wp_insert_attachment(array('post_title'=>'Synthetic conversion','post_mime_type'=>'image/png'),$u['file']); wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$u['file']));
    $a = WP_Seed_Pixel_Format_Conversion::analyze($id); check($a,'normal conversion analysis');
    $converted = WP_Seed_Pixel_Format_Conversion::convert($id,$a['generation'],true,false,$a['selected_profile']); check($converted,'normal conversion retained');
    $export = WP_Seed_Pixel_Migration_Evidence::inspect();
    check(is_wp_error($export) && $export->get_error_code() === 'MIGRATION_ENGINE_NOT_CERTIFIED','real retained conversion is explicitly outside migration certification');
}
file_put_contents($root . '/evidence/normal-' . $site . '.json',wp_json_encode(array('version'=>WP_SEED_PIXEL_VERSION,'passed'=>count($checks),'checks'=>$checks),JSON_PRETTY_PRINT));
echo count($checks) . ' normal assertions PASS on ' . WP_SEED_PIXEL_VERSION . ".\n";
