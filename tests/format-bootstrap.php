<?php
$lab = dirname(__DIR__);
if (!str_contains(realpath($lab), 'wp-seed-pixel-m3-environment')) { throw new RuntimeException('Disposable lab required'); }
require $lab . '/.runtime/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_set_current_user(1);
$fixtures = '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/.runtime/format-fixtures';
function format_fixture($name = 'photo') {
    global $fixtures;
    $u = wp_upload_bits('format-' . bin2hex(random_bytes(8)) . '.png', null, file_get_contents($fixtures . '/' . $name . '.png'));
    if ($u['error']) { throw new RuntimeException('Synthetic upload failed'); }
    $id = wp_insert_attachment(array('post_title' => 'Synthetic conversion fixture', 'post_mime_type' => 'image/png'), $u['file']);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $u['file']));
    return $id;
}
function format_worker($args) {
    $root = getenv('PIXEL_M3_ROOT'); $ext = "$root/root/usr/lib/php/20250925";
    $cmd = array_merge(array(PHP_BINARY, '-d', "extension=$ext/gd.so", '-d', "extension=$ext/mysqlnd.so", '-d', "extension=$ext/mysqli.so", '-d', "extension=$ext/imagick.so", '-d', 'memory_limit=512M', __DIR__ . '/format-worker.php'), $args);
    $p = proc_open($cmd, array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    fclose($pipes[0]); return array($p, $pipes);
}
