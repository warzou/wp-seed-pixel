<?php
$root=getenv('PIXEL_METADATA_LAB');
if($root!=='/home/warzy/.cache/wp-seed-pixel-060-metadata-lab'){throw new RuntimeException('Owned local lab required');}
$mode=$argv[1]??'';
if(!in_array($mode,array('main','clean'),true)){throw new RuntimeException('Explicit local target required');}
$site=$root.($mode==='clean'?'/clean':'/project').'/wp-seed-pixel-m3-environment/.runtime/wordpress';
require $site.'/wp-load.php';
$source=getenv('PIXEL_METADATA_SOURCE');
$manifest=json_decode(file_get_contents($source.'/reports/release-0.6.0-20261009/candidate/runtime-manifest.json'),true);
$plugin=ABSPATH.'wp-content/plugins/wp-seed-pixel/';
$count=0;
foreach($manifest['files'] as $f){
    $name=$f['file'];
    if(strpos($name,'..')!==false||$name[0]==='/'){throw new RuntimeException('Manifest path refused');}
    if(!is_file($plugin.$name)||hash_file('sha256',$plugin.$name)!==$f['sha256']){throw new RuntimeException('Installed runtime identity: '.$name);}
    $count++;
}
if(WP_SEED_PIXEL_VERSION!=='0.6.0'||WP_SEED_PIXEL_BUILD!=='0.6.0'){throw new RuntimeException('Installed release identity');}
$count++;
require_once ABSPATH.'wp-admin/includes/plugin.php';
if(get_plugin_data($plugin.'wp-seed-pixel.php',false,false)['Version']!=='0.6.0'){throw new RuntimeException('Installed header identity');}
$count++;
file_put_contents($root.'/evidence/release-runtime-'.$mode.'.json',wp_json_encode(array('count'=>$count,'version'=>WP_SEED_PIXEL_VERSION,'files'=>count($manifest['files']),'exact_package_identity'=>true,'remote'=>false),JSON_PRETTY_PRINT));
echo "$count installed release runtime identity checks PASS\n";
