<?php
require __DIR__.'/runtime.php';
$r=activate_plugin('wp-seed-pixel/wp-seed-pixel.php');
if(is_wp_error($r)) {throw new RuntimeException($r->get_error_code());}
echo "Disposable Pixel activation PASS\n";
