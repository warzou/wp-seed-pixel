<?php
$browser_mode=$argv[1]??'';$argv[1]='library';require __DIR__.'/metadata-wp.php';
if ($browser_mode==='cleanup') {
    $p=$root.'/browser-private.json';
    if(is_file($p)){$s=json_decode(file_get_contents($p),true);WP_Session_Tokens::get_instance(1)->destroy($s['token']);unlink($p);}
    echo "Owned browser session removed.\n";exit;
}
if($browser_mode!=='prepare'){throw new RuntimeException('Explicit mode required');}
meta_check(WP_Seed_Pixel_Job_Store::install(),'schema');
update_user_meta(1,'locale','fr_FR');
$ids=array();foreach(array('success'=>'jpeg-exif.jpg','orientation'=>'jpeg-orientation6.jpg','provenance'=>'jpeg-provenance.jpg') as $name=>$file){$ids[$name]=meta_fixture($file);}
$id=$ids['success'];$master=get_attached_file($id);$meta=wp_get_attachment_metadata($id);
$copy=dirname($master).'/browser-graph-'.bin2hex(random_bytes(5)).'.jpg';copy($root.'/fixtures/jpeg-gps.jpg',$copy);$info=getimagesize($copy);
$meta['sizes']['graph']=array('file'=>basename($copy),'width'=>$info[0],'height'=>$info[1],'mime-type'=>'image/jpeg','filesize'=>filesize($copy));wp_update_attachment_metadata($id,$meta);
$token=WP_Session_Tokens::get_instance(1)->create(time()+600);$cookies=array();
foreach(array(AUTH_COOKIE=>'auth',LOGGED_IN_COOKIE=>'logged_in') as $name=>$scheme){$cookies[]=array('name'=>$name,'value'=>wp_generate_auth_cookie(1,time()+600,$scheme,$token),'domain'=>'127.0.0.1','path'=>'/','httpOnly'=>true,'secure'=>false,'sameSite'=>'Lax');}
$p=$root.'/browser-private.json';file_put_contents($p,wp_json_encode(array('token'=>$token,'cookies'=>$cookies,'ids'=>$ids)));chmod($p,0600);
file_put_contents($root.'/evidence/browser-fixtures.json',wp_json_encode(array('ids'=>$ids,'synthetic_only'=>true,'session'=>'ephemeral, excluded from evidence'),JSON_PRETTY_PRINT));
echo "Three synthetic admin fixtures and an ephemeral local session prepared.\n";
