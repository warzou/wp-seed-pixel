<?php
require __DIR__ . '/runtime.php';
$id = pixel_fixture('rgb.jpg');
wp_update_post(array('ID' => $id, 'post_title' => 'Balanced JPEG example'));
$r = wp_seed_pixel_optimize($id, 'balanced');
if (is_wp_error($r)) { throw new RuntimeException('Synthetic admin preview failed.'); }
echo "Synthetic Balanced result prepared for local admin capture.\n";
