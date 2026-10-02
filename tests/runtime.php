<?php
require dirname(__DIR__) . '/.runtime/wordpress/wp-load.php';
if (get_option('home') !== 'http://127.0.0.1:8877' || strpos(wp_normalize_path(ABSPATH), '/.runtime/wordpress/') === false) {
    throw new RuntimeException('This test only operates on the disposable local runtime.');
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
add_filter('pre_wp_mail', '__return_false');
wp_set_current_user(1);

function pixel_fixture($name, $native = true) {
    $source = dirname(__DIR__) . '/.runtime/fixtures/' . $name;
    $type = wp_check_filetype($name);
    $upload = wp_upload_bits('qa-' . bin2hex(random_bytes(4)) . '-' . $name, null, file_get_contents($source));
    if ($upload['error']) {
        throw new RuntimeException($upload['error']);
    }
    $id = wp_insert_attachment(array('post_title' => 'Synthetic ' . $name, 'post_mime_type' => $type['type'], 'post_status' => 'inherit'), $upload['file']);
    $metadata = $native ? wp_generate_attachment_metadata($id, $upload['file']) : array('file' => _wp_relative_upload_path($upload['file']), 'width' => 2400, 'height' => 1600, 'sizes' => array());
    wp_update_attachment_metadata($id, $metadata);
    return (int) $id;
}
