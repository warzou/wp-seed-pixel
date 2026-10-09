<?php
$root=getenv('PIXEL_METADATA_LAB');
if($root!=='/home/warzy/.cache/wp-seed-pixel-060-metadata-lab'){throw new RuntimeException('Owned local lab required');}
$version=getenv('PIXEL_RELEASE_VERSION')?:'0.6.0-private.4';
$package=getenv('PIXEL_RELEASE_ZIP')?:'/reports/metadata-private4-20261008/candidate/wp-seed-pixel-0.6.0-private.4.zip';
$mode=$argv[1]??'';$wordpressPath=$root.'/clean/wp-seed-pixel-m3-environment/.runtime/wordpress';
if($mode==='install'){
    if(is_file($wordpressPath.'/wp-config.php')){throw new RuntimeException('Fresh install required');}
    $config="<?php\ndefine('DB_NAME','pixel_metadata_clean');\ndefine('DB_USER','root');\ndefine('DB_PASSWORD','');\ndefine('DB_HOST','localhost:$root/mysql.sock');\ndefine('DB_CHARSET','utf8mb4');\n\$table_prefix='wp_';\ndefine('WP_HOME','http://127.0.0.1:8877');\ndefine('WP_SITEURL',WP_HOME);\ndefine('DISABLE_WP_CRON',true);\ndefine('FS_METHOD','direct');\ndefine('WP_HTTP_BLOCK_EXTERNAL',true);\ndefine('WP_SEED_PIXEL_STORAGE_ENABLED',true);\ndefine('WP_SEED_PIXEL_RECOVERY_ROOT','$root/clean/private');\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire ABSPATH.'wp-settings.php';\n";
    file_put_contents($wordpressPath.'/wp-config.php',$config);define('WP_INSTALLING',true);
}
require $wordpressPath.'/wp-load.php';require_once ABSPATH.'wp-admin/includes/upgrade.php';
require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/plugin.php';require_once ABSPATH.'wp-admin/includes/class-wp-upgrader.php';
add_filter('pre_wp_mail','__return_false');
if($mode==='install'){
    wp_install('Synthetic clean install','pixel_qa','qa@example.invalid',false,'',bin2hex(random_bytes(24)));
    wp_set_current_user(1);$skin=new Automatic_Upgrader_Skin();$u=new Plugin_Upgrader($skin);
    $zip=getenv('PIXEL_METADATA_SOURCE').$package;
    $r=$u->install($zip);if(!$r||is_wp_error($r)){throw new RuntimeException('Native clean package installation failed');}
    $r=activate_plugin('wp-seed-pixel/wp-seed-pixel.php');if(is_wp_error($r)){throw new RuntimeException('Native activation failed');}
    echo "Native clean installation completed.\n";exit;
}
if($mode!=='verify'){throw new RuntimeException('Explicit mode required');}
wp_set_current_user(1);
$checks=array('version'=>WP_SEED_PIXEL_VERSION===$version,'active'=>is_plugin_active('wp-seed-pixel/wp-seed-pixel.php'),
    'runtime_graph_loaded'=>class_exists('WP_Seed_Pixel_Metadata_Graph_Transaction'),
    'explicit_graph_enabled'=>WP_Seed_Pixel_Metadata_Graph_Transaction::enabled(),
    'no_media_created'=>count(get_posts(array('post_type'=>'attachment','posts_per_page'=>-1)))===0,
    'default_conserve'=>WP_Seed_Pixel_Policy::normalize(array())['intent']['metadata']==='preserve_required',
    'new_upload_privacy_default_on'=>WP_Seed_Pixel_Metadata_Uploads::settings()['enabled'],
    'new_upload_privacy_actor_initialized'=>WP_Seed_Pixel_Metadata_Uploads::settings()['actor_id']===1);
foreach($checks as $name=>$pass){if(!$pass){throw new RuntimeException('Clean install: '.$name);}}
file_put_contents($root.'/evidence/clean-install.json',wp_json_encode(array('count'=>count($checks),'checks'=>$checks,'native_core_upgrader'=>true,'remote'=>false),JSON_PRETTY_PRINT));
echo count($checks)." native clean installation checks PASS\n";
