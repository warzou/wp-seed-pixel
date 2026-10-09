<?php
define('ABSPATH',__DIR__.'/');
class WP_Error { private $code; public function __construct($code) {$this->code=$code;} public function get_error_code(){return $this->code;} }
function is_wp_error($v) { return $v instanceof WP_Error; }
function __($s,$domain=null) { return $s; }
function absint($s) { return abs((int)$s); }
function current_user_can($capability,$id=null) { return $GLOBALS['permissions'][$capability]??false; }
function get_post_type($id) { return $GLOBALS['type']; }
function is_multisite() { return $GLOBALS['multisite']; }
function check_ajax_referer($action,$name,$die) { return $GLOBALS['nonce']; }
function wp_send_json_error($data,$status=null) { throw new RuntimeException(json_encode(array($data,$status))); }
require dirname(__DIR__).'/includes/class-metadata.php';
require dirname(__DIR__).'/includes/class-metadata-admin.php';
require dirname(__DIR__).'/includes/class-jobs.php';
require dirname(__DIR__).'/includes/class-policy.php';
class WP_Seed_Pixel_Master_Storage { public static function enabled(){return true;} }
// Admit past the certification gate only to exercise the actual policy guards.
class WP_Seed_Pixel_Metadata_Graph_Transaction { public static function enabled(){return true;} }
function wp_json_encode($v) { return json_encode($v); }
$checks=0;
function verify($value,$name) { global $checks; if(!$value)throw new LogicException($name);$checks++; }
$permissions=array('manage_options'=>true,'edit_post'=>true);$type='attachment';$multisite=false;$nonce=true;
foreach (array('capability','edit_post','nonce','type','multisite','id') as $case) {
    $permissions=array('manage_options'=>true,'edit_post'=>true);$type='attachment';$multisite=false;$nonce=true;
    $_POST=array('attachment_id'=>'17','operation'=>'start','confirmed'=>'1','metadata'=>'anonymize');
    if($case==='capability')$permissions['manage_options']=false;
    if($case==='edit_post')$permissions['edit_post']=false;
    if($case==='nonce')$nonce=false;
    if($case==='type')$type='post';
    if($case==='multisite')$multisite=true;
    if($case==='id')$_POST['attachment_id']=array(17);
    try {WP_Seed_Pixel_Metadata_Admin::ajax();throw new LogicException('Unexpected admission');}
    catch(RuntimeException $e){
        list($data,$status)=json_decode($e->getMessage(),true);
        verify($status===403,$case.' denied before DB access');
        verify(($data['code']??$data['message'])==='PERMISSION_DENIED',$case.' explicit result');
    }
}
$permissions=array('manage_options'=>true,'edit_post'=>true);$multisite=false;
verify(WP_Seed_Pixel_Jobs::replace_one(17,array('master'=>'replace_verified','metadata'=>'anonymize'),100000,'future-generation')->get_error_code()==='POLICY_INVALID','future upload cannot anonymize');
verify(WP_Seed_Pixel_Jobs::bulk_plan(1,array('metadata'=>'anonymize'),100000)->get_error_code()==='POLICY_INVALID','bulk entry refuses metadata choice');
$permissions['manage_options']=false;
verify(WP_Seed_Pixel_Jobs::replace_one(17,array('master'=>'replace_verified','metadata'=>'anonymize'),100000)->get_error_code()==='PERMISSION_DENIED','direct entry unauthorized before database');
verify(WP_Seed_Pixel_Metadata::WRITE_CERTIFIED,'private.2 explicit transaction enabled');
echo "$checks actual admission/capability/nonce assertions PASS\n";
