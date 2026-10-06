<?php
$file=dirname(__DIR__).'/.runtime/wordpress/wp-content/plugins/wp-seed-pixel/wp-seed-pixel.php';
if (!str_contains(realpath($file),'wp-seed-pixel-m3-environment')) { throw new RuntimeException('Disposable plugin required'); }
$s=file_get_contents($file);
$s=str_replace(array('Version: 0.4.0',"define('WP_SEED_PIXEL_VERSION', '0.4.0')"),array('Version: 0.3.99',"define('WP_SEED_PIXEL_VERSION', '0.3.99')"),$s);
file_put_contents($file,$s);
echo "Disposable older-version fixture prepared\n";
