<?php
// Presentation contracts only: no WordPress bootstrap, database or image processing.
define('ABSPATH', __DIR__ . '/');
$translations=json_decode(file_get_contents(dirname(__DIR__).'/tools/i18n-fr.json'),true,512,JSON_THROW_ON_ERROR);
$locale='en_US';$mime='image/png';$storage=false;$meta=[];$manifest=false;$operation=null;$future=['mode'=>'off','cutoff_id'=>0];$schema=0;
function __($s,$d){global $locale,$translations;if($d!=='wp-seed-pixel'){throw new RuntimeException('Domain');}return $locale==='fr_FR'?($translations[$s]??$s):$s;}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function esc_html_e($s,$d){echo esc_html(__($s,$d));}
function esc_html__($s,$d){return esc_html(__($s,$d));}
function esc_url($s){return esc_html($s);}
function current_user_can(...$args){return true;}
function admin_url($p){return 'https://example.invalid/wp-admin/'.$p;}
function get_post_mime_type($id){global $mime;return $mime;}
function wp_get_attachment_metadata($id){return ['width'=>1023,'height'=>1537];}
function get_attached_file($id,$unfiltered=false){return null;}
function get_post_meta($id,$key,$single){global $meta;return $meta[$key]??[];}
function get_option($key,$default=0){global $schema;return $key==='wp_seed_pixel_job_schema'?$schema:$default;}
class WP_Seed_Pixel_Quarantine {static function enabled(){global $storage;return $storage;}}
class WP_Seed_Pixel_Store {static function manifest($id){global $manifest;return $manifest;}}
class WP_Seed_Pixel_Future_Uploads {const META='_seed_pixel_future_upload';static function settings(){global $future;return $future;}}
class WP_Seed_Pixel_Plugin {static function settings(){return ['preset'=>'balanced'];}}
class WP_Seed_Pixel_Job_Store {const SCHEMA=2;const TERMINAL=['retained','purged','rolled_back','skipped','cancelled','failed','needs_review'];static function table($s){return 'synthetic_'.$s;}}
class WP_Seed_Pixel_Host_Admin {static function reason($s){return $s;}}
class WP_Seed_Pixel_Workflow {static function message($s){return $s;}}
$wpdb=new class {function prepare($q,...$a){return $q;}function get_row($q,$mode){global $operation;return $operation;}};
define('ARRAY_A','ARRAY_A');
require dirname(__DIR__).'/includes/class-media.php';
$checks=0;function check($pass,$name){global $checks;if(!$pass){throw new RuntimeException($name);}++$checks;}
foreach(['en_US','fr_FR'] as $locale){
 $mime='image/png';$storage=false;$meta=[];$manifest=false;$schema=0;$operation=null;
 check(WP_Seed_Pixel_Media::state(12)==='png_inactive','PNG inactive classification');
 $html=WP_Seed_Pixel_Media::details(12);
 check(str_contains($html,$locale==='fr_FR'?'n’est pas activé':'is not enabled'),'Inactive reason');
 check(!str_contains($html,'page=wp-seed-pixel-bulk'),'No inaccessible action');
 check(str_contains($html,'data-operation="image_start"'),'Explicit manual PNG action without settings prerequisite');
 check(str_contains($html,$locale==='fr_FR'?'Pas encore optimisée.':'Not yet optimized.'),'Clear native unprocessed state');
 check($meta===[] && $manifest===false,'Rendering inert');
 check(substr_count($html,'class="pixel-media-panel"')===1,'One attachment panel');
 check(substr_count($html,'<details>')===1,'One collapsed technical details');
 check(!str_contains($html,'<details open'),'Technical details collapsed by default');
 $storage=true;check(WP_Seed_Pixel_Media::state(12)==='png_lossless','PNG active classification');
 $html=WP_Seed_Pixel_Media::details(12);check(str_contains($html,'data-operation="image_start"'),'Enabled native action');
 check(!str_contains($html,'is not enabled')&&!str_contains($html,'n’est pas activé'),'No inactive reason when enabled');
 $mime='image/jpeg';check(WP_Seed_Pixel_Media::state(12)==='new','JPEG legacy unchanged');
 $future=['mode'=>'manual','cutoff_id'=>12];check(WP_Seed_Pixel_Media::state(12)==='protected','JPEG protected');$future=['mode'=>'off','cutoff_id'=>0];
 $mime='image/webp';check(WP_Seed_Pixel_Media::state(12)==='unsupported','Unsupported unchanged');
 foreach(['failed','skipped','pending','processing'] as $state){$meta=['_seed_pixel_job'=>['status'=>$state]];check(WP_Seed_Pixel_Media::state(12)===$state,'Legacy history '.$state);}
 $meta=[];$manifest=['algorithm_version'=>'0.3.1','preset'=>'balanced'];check(WP_Seed_Pixel_Media::state(12)==='success','Optimized preserved');
 $manifest=['preset'=>'old'];check(WP_Seed_Pixel_Media::state(12)==='update','New optimization preserved');$manifest=false;$schema=2;
 foreach(['NO_BENEFIT'=>'no_benefit','EXCLUDED_BY_POLICY'=>'excluded','ICC_UNSAFE'=>'unsupported_profile'] as $code=>$state){$operation=['error_code'=>$code,'stage'=>'skipped'];check(WP_Seed_Pixel_Media::state(12)===$state,'Reason '.$code);}
 foreach(['failed','needs_review','recovery_required'] as $stage){$operation=['stage'=>$stage];check(WP_Seed_Pixel_Media::state(12)==='failed','Review '.$stage);}
 $mime='image/png';$operation=['id'=>1,'job_id'=>2,'stage'=>'needs_review','error_code'=>'','data'=>json_encode(['reason'=>'INVENTORY_INCOMPLETE'])];
 $html=WP_Seed_Pixel_Media::details(12);
 check(str_contains($html,'INVENTORY_INCOMPLETE'),'Plan reason reaches primary panel');
 check(str_contains($html,$locale==='fr_FR'?'Réessayer':'Retry'),'Failure explicit retry label');
 check(!str_contains($html,'page=wp-seed-pixel-bulk'),'No legacy selected media link');
 check(substr_count($html,'class="pixel-media-panel"')===1 && substr_count($html,'<details>')===1,'Failure one coherent panel');
 $operation['data']=json_encode(['reason'=>'NO_BENEFIT']);$operation['stage']='skipped';
 check(WP_Seed_Pixel_Media::state(12)==='no_benefit','Planned no-benefit classification');
 $html=WP_Seed_Pixel_Media::details(12);
 check(str_contains($html,$locale==='fr_FR'?'déjà suffisamment optimisée':'already sufficiently optimized'),'No gain is not alarming');
 $operation=['stage'=>'rolled_back'];check(WP_Seed_Pixel_Media::state(12)==='restored','Restored');
 $operation=['stage'=>'claimed'];check(WP_Seed_Pixel_Media::state(12)==='processing','Processing');$operation=null;
 foreach(['NO_BENEFIT'=>'no_benefit','EXCLUDED_BY_POLICY'=>'excluded'] as $code=>$state){$meta=['_seed_pixel_future_upload'=>['reason'=>$code]];check(WP_Seed_Pixel_Media::state(12)===$state,'Future reason '.$code);}
 $meta=['_seed_pixel_master_state'=>['item_id'=>1]];check(WP_Seed_Pixel_Media::state(12)==='master_optimized','Master wins');
}
echo "$checks/$checks media status/i18n contracts PASS (stubbed presentation; no image or DB writes)\n";
