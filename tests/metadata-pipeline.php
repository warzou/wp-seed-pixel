<?php
$argv[1]='library';require __DIR__.'/metadata-wp.php';
meta_check(WP_Seed_Pixel_Job_Store::install(),'schema');
require_once ABSPATH.WPINC.'/class-wp-image-editor.php';
require_once ABSPATH.WPINC.'/class-wp-image-editor-imagick.php';
require_once ABSPATH.WPINC.'/class-wp-image-editor-gd.php';
class Metadata_Counting_Imagick extends WP_Image_Editor_Imagick {
    public function save($destfilename=null,$mime_type=null) { $GLOBALS['metadata_encodes']++;return parent::save($destfilename,$mime_type); }
}
class Metadata_Counting_GD extends WP_Image_Editor_GD {
    public function save($destfilename=null,$mime_type=null) { $GLOBALS['metadata_encodes']++;return parent::save($destfilename,$mime_type); }
}
add_filter('wp_image_editors',static function(){return array('Metadata_Counting_Imagick','Metadata_Counting_GD');});
$source=getenv('PIXEL_METADATA_SOURCE');
$jpeg=file_get_contents($source.'/.runtime/fixtures/m3-detail.jpg');$text='Synthetic capture comment';
file_put_contents($root.'/fixtures/pipeline.jpg',substr($jpeg,0,2)."\xff\xfe".pack('n',strlen($text)+2).$text.substr($jpeg,2));
$png=file_get_contents($source.'/.runtime/format-fixtures/lossless.png');
$payload="Author\0Synthetic author";$chunk=pack('N',strlen($payload)).'tEXt'.$payload.hash('crc32b','tEXt'.$payload,true);
file_put_contents($root.'/fixtures/pipeline.png',substr($png,0,-12).$chunk.substr($png,-12));
function optimize_job($id) {
    $r=WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified'),1073741824);meta_check($r,'optimization admission');
    meta_check(WP_Seed_Pixel_Jobs::step($r['id']),'optimization transaction');
    meta_check(meta_item($r['id'])['stage']==='retained','optimization retained '.(meta_item($r['id'])['error_code']??''));return (int)$r['id'];
}
$results=array();
foreach (array('jpg','png') as $ext) {
    foreach (array('anonymize-first','optimize-first') as $order) {
        $id=meta_fixture('pipeline.'.$ext);$before=WP_Seed_Pixel_Master_Adapter::snapshot($id);$editorial=meta_editorial($id);
        $jobs=array();$encoded=0;$GLOBALS['metadata_encodes']=0;
        foreach ($order==='anonymize-first'?array('anonymize','optimize'):array('optimize','anonymize') as $operation) {
            if ($operation==='anonymize') { $job=meta_job($id);meta_check(WP_Seed_Pixel_Jobs::step($job),'pipeline metadata transaction'); }
            else { $job=optimize_job($id); }
            $item=meta_item($job);$r=WP_Seed_Pixel_Master_Storage::load(WP_Seed_Pixel_Master_Storage::directory($item),$item);
            meta_check($r,'pipeline journal');$encoded+=(int)(json_decode($item['receipt'],true)['encoded']??0);
            if ($operation==='anonymize') {meta_check($r['candidate']['encoded']===0,'metadata adds no encoding');}
            $jobs[]=$job;
            $next_policy=array('master'=>'replace_verified');
            if ($operation==='optimize') {$next_policy['metadata']='anonymize';}
            $blocked=WP_Seed_Pixel_Jobs::replace_one($id,$next_policy,1073741824);
            meta_check(is_wp_error($blocked) && $blocked->get_error_code()==='CLAIM_CONFLICT','independent recovery domains serialized');
            if ($operation==='anonymize') {
                $a=WP_Seed_Pixel_Metadata_Admin::analyze($id);meta_check($a,'anonymized public graph');meta_check(!$a['master']['categories'],'anonymized graph clean');
            }
            meta_check(WP_Seed_Pixel_Jobs::restore_master($job),'restore before next recovery domain');
            meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id)===$before,'each recovery exact');
        }
        meta_check(meta_editorial($id)===$editorial,'pipeline editorial stable');
        if ($ext==='jpg') {meta_check($encoded===1 && $GLOBALS['metadata_encodes']===1,'one actual lossy optimization');}
        else {meta_check($GLOBALS['metadata_encodes']===0,'PNG pipeline uses no image encoder');}
        meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id)===$before,'full pipeline exact restore');
        $results[$ext.'-'.$order]=array('jobs'=>$jobs,'committed_encodings'=>$encoded,'metadata_encodings'=>0);
    }
}
$png=file_get_contents($source.'/.runtime/format-fixtures/photo.png');file_put_contents($root.'/fixtures/conversion-pipeline.png',substr($png,0,-12).$chunk.substr($png,-12));
$id=meta_fixture('conversion-pipeline.png',true);$before=WP_Seed_Pixel_Master_Adapter::snapshot($id);$editorial=meta_editorial($id);
$job=meta_job($id);meta_check(WP_Seed_Pixel_Jobs::step($job),'PNG source anonymized');$clean=WP_Seed_Pixel_Master_Adapter::snapshot($id);
$blocked=WP_Seed_Pixel_Format_Conversion::analyze($id);meta_check(is_wp_error($blocked) && $blocked->get_error_code()==='CLAIM_CONFLICT','conversion blocked by metadata recovery');
meta_check(WP_Seed_Pixel_Jobs::restore_master($job),'restore metadata before conversion');
meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id)===$before,'original PNG restored before conversion');
$a=WP_Seed_Pixel_Format_Conversion::analyze($id);
meta_check(is_wp_error($a) && $a->get_error_code()==='TARGET_METADATA_UNSUPPORTED','restored private PNG still respects conversion metadata gate');
file_put_contents($root.'/fixtures/conversion-clean.png',$png);
$id=meta_fixture('conversion-clean.png',true);$before=WP_Seed_Pixel_Master_Adapter::snapshot($id);$editorial=meta_editorial($id);
$a=WP_Seed_Pixel_Format_Conversion::analyze($id);meta_check($a,'independent clean conversion analysis');
$r=WP_Seed_Pixel_Format_Conversion::convert($id,$a['generation'],true,false);meta_check($r,'pipeline B explicit conversion');
meta_check(get_post_mime_type($id)==='image/jpeg','target JPEG');
foreach($before['files'] as $relative=>$file){meta_check(hash_file('sha256',wp_upload_dir()['basedir'].'/'.$relative)===$file['sha256'],'legacy original copy exact');}
meta_check(WP_Seed_Pixel_Metadata_Admin::state($id)!=='anonymized','conversion does not claim graph anonymization');
meta_check(meta_editorial($id)===$editorial,'conversion editorial stable');
meta_check(is_wp_error(WP_Seed_Pixel_Metadata_Admin::analyze($id)),'metadata admission blocked by conversion recovery');
meta_check(WP_Seed_Pixel_Format_Conversion::restore($id,$a['generation']),'restore conversion first');
meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id)===$before,'exact pre-pipeline original returned');
file_put_contents($root.'/evidence/pipeline.json',wp_json_encode(array('passed'=>count($checks),'checks'=>$checks,'results'=>$results,'conversion_policy'=>'Independent recovery domains serialized; exact restore required before next operation','permanent_purge'=>0),JSON_PRETTY_PRINT));
echo count($checks)." real pipeline checks passed.\n";
