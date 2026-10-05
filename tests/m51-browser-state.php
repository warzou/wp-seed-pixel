<?php
require __DIR__ . '/m3-runtime.php';
if (($argv[1] ?? '') === 'prepare') {
    $r = WP_Seed_Pixel_Future_Uploads::configure('analyze', 1073741824, array());
    if (is_wp_error($r)) { throw new RuntimeException($r->get_error_code()); }
    echo wp_json_encode(array('mode' => $r['mode']));
} elseif (($argv[1] ?? '') === 'state') {
    $id = (int) $argv[2]; WP_Seed_Pixel_Future_Uploads::run($id);
    echo wp_json_encode(array('marker' => get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true), 'metadata' => wp_get_attachment_metadata($id), 'exists' => is_file(get_attached_file($id))));
} elseif (($argv[1] ?? '') === 'cleanup') {
    WP_Seed_Pixel_Future_Uploads::configure('off');
    WP_Session_Tokens::get_instance(1)->destroy_all();
    echo wp_json_encode(array('off' => true));
} else { throw new RuntimeException('Explicit disposable command required'); }
