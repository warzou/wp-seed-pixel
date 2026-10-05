<?php
require __DIR__ . '/m3-runtime.php';
$s = WP_Seed_Pixel_Future_Uploads::configure('process', 1073741824, array());
if (is_wp_error($s)) { throw new RuntimeException($s->get_error_code()); }
$file = dirname(__DIR__) . '/.runtime/fixtures/m3-small.jpeg';
$sub = '/m51-crash-' . bin2hex(random_bytes(8));
$filter = static function ($u) use ($sub) { $u['path'] .= $sub; $u['url'] .= $sub; $u['subdir'] .= $sub; return $u; };
add_filter('upload_dir', $filter);
$upload = wp_upload_bits('m51-crash-' . bin2hex(random_bytes(8)) . '.jpeg', null, file_get_contents($file));
remove_filter('upload_dir', $filter);
apply_filters('wp_handle_upload', $upload, 'upload');
$id = wp_insert_attachment(array('post_title' => 'M5.1 crash synthetic', 'post_mime_type' => 'image/jpeg'), $upload['file']);
wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $upload['file']));
echo wp_json_encode(array('id' => $id, 'before_sha256' => hash_file('sha256', get_attached_file($id)))) . "\n"; flush();
add_action('wp_seed_pixel_m3_boundary', static function ($name) { if ($name === 'candidate') { posix_kill(getmypid(), SIGKILL); } });
WP_Seed_Pixel_Future_Uploads::run($id);
throw new RuntimeException('Expected real process death');
