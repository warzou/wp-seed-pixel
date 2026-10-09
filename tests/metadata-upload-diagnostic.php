<?php
$id=isset($argv[1])?(int)$argv[1]:0;
$argv[1]='library';require __DIR__.'/metadata-wp.php';
if($id<1||get_post_type($id)!=='attachment'){throw new RuntimeException('Synthetic local attachment required');}
$a=WP_Seed_Pixel_Analyzer::analyze($id);
if(is_wp_error($a)){echo $a->get_error_code()."\n";exit(1);}
foreach(array('health','reasons','warnings','issues','files') as $key){if(isset($a[$key])){echo $key.': '.wp_json_encode($a[$key])."\n";}}
