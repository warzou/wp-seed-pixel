<?php
// Uses the real WordPress helpers, but intercepts transport for bounded failure injection.
$root = getenv('PIXEL_UPDATER_LAB');
$metadata_lab = $root === '/home/warzy/.cache/wp-seed-pixel-060-metadata-lab';
if (!$metadata_lab && $root !== '/home/warzy/.cache/wp-seed-pixel-051-updater-lab') { throw new RuntimeException('Owned local lab required'); }
if ($metadata_lab) { define('WP_INSTALLING',true); }
require $root . ($metadata_lab ? '/project/wp-seed-pixel-m3-environment' : '/project') . '/.runtime/wordpress/wp-load.php';
$source = getenv('PIXEL_UPDATER_SOURCE');
require $source . '/includes/class-updater.php';
define('WP_SEED_PIXEL_VERSION', '0.5.0');
$checks = array();
function ok($v, $label) { global $checks; if (!$v) { throw new RuntimeException($label); } $checks[$label] = true; }
function response($code, $body = '', $headers = array()) { return array('response'=>array('code'=>$code), 'body'=>$body, 'headers'=>$headers, 'cookies'=>array()); }
$official = WP_Seed_Pixel_Updater::OFFICIAL_MANIFEST;
$m = json_decode(file_get_contents($source . '/updates/stable.json'), true);
$m['version'] = '0.5.1';
$m['package'] = 'https://github.com/warzou/wp-seed-pixel/releases/download/v0.5.1/wp-seed-pixel-0.5.1.zip';
$m['notes_url'] = 'https://github.com/warzou/wp-seed-pixel/releases/tag/v0.5.1';
$zip = getenv('PIXEL_UPDATE_ZIP');
if ($zip && is_file($zip)) { $m['sha256'] = hash_file('sha256', $zip); }
ok(WP_Seed_Pixel_Updater::endpoint() === $official, 'built-in endpoint');
ok(WP_Seed_Pixel_Updater::validate($m, $official) === $m, 'valid stable');
$p = $m; $p['channel']='private'; $p['version']='0.5.1-private.1'; $p['package']='https://updates.example.invalid/pixel.zip'; $p['notes_url']='https://updates.example.invalid/notes';
ok(WP_Seed_Pixel_Updater::validate($p, 'https://updates.example.invalid/manifest.json') === $p, 'valid explicit private override');
foreach (array('channel'=>'nightly','version'=>'0.5.1-rc.1','package'=>'http://github.com/warzou/wp-seed-pixel/releases/download/v0.5.1/wp-seed-pixel-0.5.1.zip','notes_url'=>'https://github.com/warzou/wp-seed-pixel/releases','sha256'=>'wrong','schema'=>2,'slug'=>'other') as $key=>$value) {
    $v=$m; $v[$key]=$value; ok(WP_Seed_Pixel_Updater::validate($v,$official)===false,'invalid '.$key);
}
foreach (array('wrong owner'=>str_replace('warzou','other',$m['package']), 'wrong repo'=>str_replace('wp-seed-pixel/releases','other/releases',$m['package']), 'wrong filename'=>str_replace('0.5.1.zip','0.5.2.zip',$m['package']), 'wrong tag'=>str_replace('/v0.5.1/','/v0.5.2/',$m['package']), 'query'=>$m['package'].'?x=1', 'fragment'=>$m['package'].'#x', 'userinfo'=>str_replace('github.com','user@github.com',$m['package']), 'port'=>str_replace('github.com','github.com:444',$m['package'])) as $label=>$value) {
    $v=$m; $v['package']=$value; ok(WP_Seed_Pixel_Updater::validate($v,$official)===false,$label);
}
foreach (array('http://raw.githubusercontent.com/warzou/wp-seed-pixel/main/updates/stable.json',str_replace('/stable.json','/other.json',$official),str_replace('/warzou/','/other/',$official)) as $i=>$url) { ok(WP_Seed_Pixel_Updater::validate($m,$url)===false,'wrong manifest '.$i); }
$v=$m; $v['extra']=true; ok(WP_Seed_Pixel_Updater::validate($v,$official)===false,'unknown key');
$v=$m; unset($v['tested']); ok(WP_Seed_Pixel_Updater::validate($v,$official)===false,'missing key');
ok(WP_Seed_Pixel_Updater::validate($p,$official)===false,'private cannot replace official channel');
ok(WP_Seed_Pixel_Updater::validate($m,'https://updates.example.invalid/manifest.json')===false,'stable cannot use alternate source');
$mode='good'; $calls=array(); $location='https://release-assets.githubusercontent.com/github-production-release-asset/123/abc-456?signed=fixture';
add_filter('pre_http_request',function($pre,$args,$url) use (&$m,&$mode,&$calls,&$location,$zip,$official) {
    $calls[] = array('host'=>wp_parse_url($url,PHP_URL_HOST),'redirects'=>$args['redirection'],'cookies'=>$args['cookies'],'body'=>$args['body'] ?? null,'limit'=>$args['limit_response_size']);
    if ($url===$official) {
        $body=wp_json_encode($m); $headers=array();
        if($mode==='unavailable'){return new WP_Error('fixture_offline');}
        if($mode==='oversize'){$body.=str_repeat(' ',WP_Seed_Pixel_Updater::MANIFEST_LIMIT); $body=substr($body,0,$args['limit_response_size']);}
        if($mode==='truncated'){$headers['content-length']=strlen($body)+100;}
        if($mode==='json'){$body='{broken';}
        if($mode==='partial-json'){$body=substr($body,0,-1);}
        return response(200,$body,$headers);
    }
    if ($url===$m['package']) {
        if($mode==='missing-package'){return response(404);}
        return response(302,'',array('location'=>$location));
    }
    if($url===$location) {
        if($mode==='two-hops'){return response(302,'',array('location'=>$location));}
        if(!empty($args['stream'])){file_put_contents($args['filename'],$mode==='bad-zip' ? 'not a zip' : ($mode==='large-package' ? str_repeat('x',WP_Seed_Pixel_Updater::PACKAGE_LIMIT+1) : file_get_contents($zip)));}
        return response(200);
    }
    return new WP_Error('unexpected_url');
},10,3);
foreach(array('oversize','truncated','json','partial-json','unavailable') as $mode){ok(WP_Seed_Pixel_Updater::manifest(true)===false,'manifest rejects '.$mode);}
$mode='good';ok(WP_Seed_Pixel_Updater::manifest(true)===$m,'manifest transport accepted');
$count=count($calls);WP_Seed_Pixel_Updater::manifest();WP_Seed_Pixel_Updater::manifest();ok(count($calls)===$count,'cached no redundant requests');
foreach(array('0.5.0'=>true,'0.5.1'=>false,'0.5.2'=>false) as $current=>$expected){$t=WP_Seed_Pixel_Updater::offer((object)array('checked'=>array(WP_Seed_Pixel_Updater::ID=>$current)));ok(isset($t->response[WP_Seed_Pixel_Updater::ID])===$expected,'offer '.$current);}
foreach(array('requires'=>'99.0','requires_php'=>'99.0') as $key=>$value){$saved=$m;$m[$key]=$value;WP_Seed_Pixel_Updater::manifest(true);$t=WP_Seed_Pixel_Updater::offer((object)array());ok(empty($t->response),'compatibility '.$key);$m=$saved;}
WP_Seed_Pixel_Updater::manifest(true);
ok(WP_Seed_Pixel_Updater::details(null,'plugin_information',(object)array('slug'=>'wp-seed-pixel'))->version==='0.5.1','plugin details');
if($zip && is_file($zip)) {
    foreach(array('evil'=>'https://evil.example/path','http'=>'http://release-assets.githubusercontent.com/github-production-release-asset/123/abc','IP'=>'https://127.0.0.1/path','localhost'=>'https://localhost/path','userinfo'=>'https://user@release-assets.githubusercontent.com/github-production-release-asset/123/abc','port'=>'https://release-assets.githubusercontent.com:444/github-production-release-asset/123/abc','fragment'=>'https://release-assets.githubusercontent.com/github-production-release-asset/123/abc#x','github'=>'https://github.com/warzou/wp-seed-pixel/other','path'=>'https://release-assets.githubusercontent.com/other','empty'=>'','array'=>array('bad'), 'long'=>'https://release-assets.githubusercontent.com/'.str_repeat('a',8192)) as $label=>$location) {
        $before=count($calls);$r=WP_Seed_Pixel_Updater::download(false,$m['package'],null,array('plugin'=>WP_Seed_Pixel_Updater::ID));ok(is_wp_error($r),'redirect rejected '.$label);ok(count($calls)===$before+2,'no second request '.$label);
    }
    $location='https://release-assets.githubusercontent.com/github-production-release-asset/123/abc-456?signed=fixture';
    foreach(array('two-hops','missing-package','bad-zip','large-package') as $mode){ok(is_wp_error(WP_Seed_Pixel_Updater::download(false,$m['package'],null,array('plugin'=>WP_Seed_Pixel_Updater::ID))),'download rejects '.$mode);}
    $mode='good';$saved=$m['sha256'];$m['sha256']=str_repeat('0',64);ok(is_wp_error(WP_Seed_Pixel_Updater::download(false,$m['package'],null,array('plugin'=>WP_Seed_Pixel_Updater::ID))),'bad checksum');$m['sha256']=$saved;
    $r=WP_Seed_Pixel_Updater::download(false,$m['package'],null,array('plugin'=>WP_Seed_Pixel_Updater::ID));ok(is_string($r)&&is_file($r),'verified release-assets flow');unlink($r);
    $v=$m;$v['version']='0.5.2';ok(is_wp_error(WP_Seed_Pixel_Updater::archive($zip,$v)),'wrong embedded version');
    foreach(array('wrong-plugin','traversal','symlink','unsafe-name','encrypted','oversized') as $case){
        $file=wp_tempnam('bad-pixel.zip');$z=new ZipArchive();$z->open($file,ZipArchive::OVERWRITE);$z->addFromString(WP_Seed_Pixel_Updater::ID,"<?php\n/*\nPlugin Name: ".($case==='wrong-plugin'?'Wrong':'WP Seed Pixel')."\nVersion: 0.5.1\n*/");$z->addFromString('wp-seed-pixel/includes/class-updater.php','<?php');
        if($case==='traversal'){$z->addFromString('wp-seed-pixel/../evil.php','x');}
        if($case==='symlink'){$z->addFromString('wp-seed-pixel/link','x');$z->setExternalAttributesName('wp-seed-pixel/link',ZipArchive::OPSYS_UNIX,0120777<<16);}
        if($case==='unsafe-name'){$z->addFromString('wp-seed-pixel/bad..php','x');}
        if($case==='encrypted'){$z->setEncryptionName(WP_Seed_Pixel_Updater::ID,ZipArchive::EM_AES_256,'synthetic-fixture');}
        if($case==='oversized'){$z->addFromString('wp-seed-pixel/huge',str_repeat('x',WP_Seed_Pixel_Updater::PACKAGE_LIMIT));}
        $z->close();ok(is_wp_error(WP_Seed_Pixel_Updater::archive($file,$m)),'archive rejects '.$case);unlink($file);
    }
    $duplicate=getenv('PIXEL_DUPLICATE_ZIP');if($duplicate){ok(is_wp_error(WP_Seed_Pixel_Updater::archive($duplicate,$m)),'archive rejects duplicate entries');}
    foreach(array('requires'=>'99.0','requires_php'=>'99.0') as $key=>$bad){$saved=$m;$m[$key]=$bad;ok(is_wp_error(WP_Seed_Pixel_Updater::download(false,$m['package'],null,array('plugin'=>WP_Seed_Pixel_Updater::ID))),'download compatibility '.$key);$m=$saved;}
    $saved=$m;$m['version']='0.5.0';$m['package']=str_replace('0.5.1','0.5.0',$m['package']);$m['notes_url']=str_replace('0.5.1','0.5.0',$m['notes_url']);ok(is_wp_error(WP_Seed_Pixel_Updater::download(false,$m['package'],null,array('plugin'=>WP_Seed_Pixel_Updater::ID))),'download refuses equal version');$m=$saved;
}
foreach($calls as $i=>$call){ok($call['redirects']===0 && !$call['cookies'] && !$call['body'],'minimal request '.$i);}
$out=getenv('PIXEL_UPDATER_REPORT');
file_put_contents($out,wp_json_encode(array('status'=>'PASS','count'=>count($checks),'checks'=>$checks,'critical'=>0,'major'=>0),JSON_PRETTY_PRINT));
echo count($checks)." updater checks PASS\n";
