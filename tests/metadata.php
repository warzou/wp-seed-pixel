<?php
// Pure parser and candidate-file tests: these do not claim WordPress transaction certification.
define('ABSPATH',__DIR__.'/');
class WP_Error {
    private $code;
    public function __construct($code) { $this->code=$code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_convert_hr_to_bytes($value) { return $value==='-1'?0:(int)$value*1048576; }
function wp_json_encode($value,$flags=0) { return json_encode($value,$flags); }
function wp_upload_dir($unused=null,$refresh=false) { return array('basedir'=>$GLOBALS['lab'].'/fixtures'); }
class WP_Seed_Pixel_Authority { public static $valid=true; public static function valid_all() { return self::$valid; } }
class WP_Seed_Pixel_Files {
    public static function path($path) {
        $root=realpath($GLOBALS['lab'].'/fixtures'); $actual=realpath($path);
        return $actual && str_starts_with($actual,$root.DIRECTORY_SEPARATOR) && !is_link($path)?$actual:new WP_Error('PATH_OUTSIDE_UPLOADS');
    }
}
require dirname(__DIR__).'/includes/class-metadata.php';
require dirname(__DIR__).'/includes/class-policy.php';
require dirname(__DIR__).'/includes/class-png-processor.php';
$lab=$argv[1]; $GLOBALS['lab']=$lab; $results=array(); $checks=0;
function check($condition,$name) { global $checks; if (!$condition) { throw new RuntimeException($name); } $checks++; }
set_error_handler(static function($severity,$message,$file,$line) {
    if (error_reporting() & $severity) { throw new ErrorException($message,0,$severity,$file,$line); }
});
foreach (json_decode(file_get_contents($lab.'/cases.json'),true) as $case) {
    $path=$lab.'/fixtures/'.$case['file']; $before=hash_file('sha256',$path);
    $result=WP_Seed_Pixel_Metadata::read($path);
    $error=is_wp_error($result)?$result->get_error_code():null;
    check($error===$case['error'],$case['file'].' expected '.($case['error']??'success').' got '.($error??'success'));
    check(hash_file('sha256',$path)===$before,$case['file'].' input untouched');
    if ($error) { $results[]=array('file'=>$case['file'],'error'=>$error); continue; }
    $again=WP_Seed_Pixel_Metadata::transform($result['data']);
    check(!is_wp_error($again) && !$again['categories'] && $again['removed_bytes']===0,$case['file'].' idempotent and clean');
    check($again['image_sha256']===$result['image_sha256'],$case['file'].' compressed image exact');
    check($again['color_sha256']===$result['color_sha256'],$case['file'].' color declarations exact');
    file_put_contents($lab.'/outputs/'.$case['file'],$result['data']);
    if ($result['categories']) {
        $target=$lab.'/outputs/candidate-'.$case['file'];
        $created=WP_Seed_Pixel_Metadata::create($path,$target);
        check(!is_wp_error($created) && $created['encoded']===0,$case['file'].' no encoder');
        check(hash_file('sha256',$target)===$result['filtered_sha256'],$case['file'].' exact candidate');
        check(hash_file('sha256',$path)===$before,$case['file'].' source untouched after construction');
        check(is_wp_error(WP_Seed_Pixel_Metadata::create($path,$target)),$case['file'].' refuses overwrite');
        unlink($target);
    }
    unset($result['data']); $result['file']=$case['file']; $results[]=$result;
}
$snapshot=array('attachment_id'=>17,'relative'=>'jpeg-gps.jpg','files'=>array());
foreach (array('jpeg-gps.jpg','jpeg-clean.jpg') as $name) { $snapshot['files'][$name]=array('sha256'=>hash_file('sha256',$lab.'/fixtures/'.$name)); }
$graph=WP_Seed_Pixel_Metadata::graph($snapshot);
check(!is_wp_error($graph) && in_array('gps',$graph['master']['categories'],true),'complete clean-derivative public graph');
$snapshot['files']['png-text.png']=array('sha256'=>hash_file('sha256',$lab.'/fixtures/png-text.png'));
check(WP_Seed_Pixel_Metadata::graph($snapshot)->get_error_code()==='METADATA_PUBLIC_COPY','dirty public derivative blocks');
unset($snapshot['files']['png-text.png']); $snapshot['files']['jpeg-clean.jpg']['sha256']=str_repeat('0',64);
check(WP_Seed_Pixel_Metadata::graph($snapshot)->get_error_code()==='SOURCE_CHANGED','stale graph blocks');
$snapshot['files']=array('../cases.json'=>array('sha256'=>'invalid'));
check(WP_Seed_Pixel_Metadata::graph($snapshot)->get_error_code()==='PATH_OUTSIDE_UPLOADS','path containment');
WP_Seed_Pixel_Authority::$valid=false;
check(WP_Seed_Pixel_Metadata::create($lab.'/fixtures/jpeg-gps.jpg',$lab.'/outputs/denied.jpg')->get_error_code()==='CLAIM_CONFLICT','authority required');
check(!file_exists($lab.'/outputs/denied.jpg'),'denied write absent');
check(WP_Seed_Pixel_Metadata::WRITE_CERTIFIED===true,'private.2 single-image transaction enabled');
$default=WP_Seed_Pixel_Policy::normalize();
// Force an old access timestamp so a normal read can advance it on Windows.
$access_path=$lab.'/fixtures/jpeg-gps.jpg';
clearstatcache(true,$access_path);$access_stat=lstat($access_path);$access_sha=hash_file('sha256',$access_path);
check(touch($access_path,$access_stat['mtime'],$access_stat['atime']-60),'synthetic old access timestamp');
$access_read=WP_Seed_Pixel_Metadata::read($access_path);
check(!is_wp_error($access_read),'read-driven atime changes are not source mutations');
check(hash_file('sha256',$access_path)===$access_sha,'access timestamp test preserves exact bytes');
check($default['intent']['metadata']==='preserve_required','default metadata behavior unchanged');
check(is_wp_error(WP_Seed_Pixel_Policy::normalize(array('metadata'=>'anonymize','dimensions'=>'max_edge','max_edge'=>800))),'no combined resize');
check(is_wp_error(WP_Seed_Pixel_Policy::normalize(array('metadata'=>'anonymize','original'=>'retire_verified'))),'no destructive anonymization');
// Actual PNG lossless processor; this is not a transaction/WordPress test.
foreach (array('png-text.png','png-alpha-text.png','png-icc-text.png','png-color-text.png') as $name) {
    $source=$lab.'/fixtures/'.$name; $filtered=$lab.'/outputs/'.$name;
    $optimized=$lab.'/outputs/optimized-'.$name; $optimized_filtered=$lab.'/outputs/filtered-optimized-'.$name;
    $a=WP_Seed_Pixel_PNG_Processor::create($source,$optimized,$default);
    $b=WP_Seed_Pixel_PNG_Processor::create($filtered,$optimized_filtered,$default);
    check(!is_wp_error($a) && !is_wp_error($b),$name.' existing PNG optimization succeeds both orders');
    $clean=WP_Seed_Pixel_Metadata::read($optimized);
    check(!is_wp_error($clean) && !empty($clean['categories']),$name.' optimize alone preserves privacy policy: '.(is_wp_error($clean)?$clean->get_error_code():json_encode($clean['categories'])));
    check($clean['filtered_sha256']===hash_file('sha256',$optimized_filtered),$name.' filter/optimize commute without encoder');
    $png_a=WP_Seed_Pixel_PNG_Processor::inspect($source,true); $png_b=WP_Seed_Pixel_PNG_Processor::inspect($optimized_filtered,true);
    check($png_a['pixels']===$png_b['pixels'],$name.' original scanline bytes preserved');
    unlink($optimized); unlink($optimized_filtered);
}
$hardlink=$lab.'/outputs/hardlink.jpg';
check(link($lab.'/fixtures/jpeg-gps.jpg',$hardlink),'synthetic hardlink created');
check(WP_Seed_Pixel_Metadata::read($hardlink)->get_error_code()==='METADATA_LIMIT','hardlinks refused');
unlink($hardlink);
check(is_wp_error(WP_Seed_Pixel_Metadata::read($lab.'/fixtures')),'directory not an image');
file_put_contents($lab.'/outputs/results.json',json_encode($results,JSON_PRETTY_PRINT));
file_put_contents($lab.'/outputs/parser-checks.json',json_encode(array('checks'=>$checks,'fixtures'=>count($results),'write_certified'=>WP_Seed_Pixel_Metadata::WRITE_CERTIFIED),JSON_PRETTY_PRINT));
echo "$checks parser/candidate/graph assertions PASS; ".count($results)." fixtures\n";
