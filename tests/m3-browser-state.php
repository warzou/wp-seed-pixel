<?php
require __DIR__ . '/m3-runtime.php';
$op = $argv[1] ?? 'prepare';
$key = 'wp_seed_pixel_m3_browser';
if ($op === 'prepare') {
    update_option('show_avatars', false);
    $id = m3_fixture('m3-detail.jpg', false); $path = get_attached_file($id); $i = getimagesize($path);
    wp_update_attachment_metadata($id, array('file' => _wp_relative_upload_path($path), 'width' => $i[0], 'height' => $i[1], 'sizes' => array()));
    $job = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified', 'dimensions' => 'max_edge', 'max_edge' => 1920), 1073741824);
    WP_Seed_Pixel_Jobs::step($job['id']);
    if (empty(WP_Seed_Pixel_Jobs::status($job['id'])['states']['retained'])) { throw new RuntimeException('Browser master not retained'); }
    $block = '<!-- wp:image {"id":' . $id . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full">' . wp_get_attachment_image($id, 'full') . '</figure><!-- /wp:image -->';
    $post = wp_insert_post(array('post_title' => 'Synthetic M3 retained WordPress image', 'post_status' => 'publish', 'post_content' => $block));
    $state = array('attachment_id' => $id, 'post_id' => $post, 'job' => $job['id'], 'url' => get_permalink($post), 'image' => wp_get_attachment_url($id), 'sha256' => hash_file('sha256', $path), 'width' => 1920, 'height' => 1280);
    update_option($key, $state);
    $expires = time() + 1800; $token = WP_Session_Tokens::get_instance(1)->create($expires);
    $state['cookies'] = array(array('name' => AUTH_COOKIE, 'value' => wp_generate_auth_cookie(1, $expires, 'auth', $token)), array('name' => LOGGED_IN_COOKIE, 'value' => wp_generate_auth_cookie(1, $expires, 'logged_in', $token)));
    $_COOKIE[LOGGED_IN_COOKIE] = $state['cookies'][1]['value']; $state['nonce'] = wp_create_nonce('wp_rest');
    echo wp_json_encode($state);
} elseif ($op === 'deactivate') {
    deactivate_plugins('wp-seed-pixel/wp-seed-pixel.php'); echo '{}';
} elseif ($op === 'cold') {
    $state = get_option($key); $path = get_attached_file($state['attachment_id']);
    $state['pixel_loaded'] = class_exists('WP_Seed_Pixel_Master_Storage', false);
    $state['sha256_current'] = hash_file('sha256', $path);
    $state['src'] = wp_get_attachment_image_src($state['attachment_id'], 'full');
    $state['native_media'] = wp_prepare_attachment_for_js($state['attachment_id'])['sizes']['full'];
    echo wp_json_encode($state);
} elseif ($op === 'remove') {
    uninstall_plugin('wp-seed-pixel/wp-seed-pixel.php');
    if (!rename(WP_PLUGIN_DIR . '/wp-seed-pixel', dirname(__DIR__) . '/.runtime/removed-pixel')) { throw new RuntimeException('Owned plugin removal failed'); }
    echo '{}';
} elseif ($op === 'activate') {
    if (is_dir(dirname(__DIR__) . '/.runtime/removed-pixel')) { rename(dirname(__DIR__) . '/.runtime/removed-pixel', WP_PLUGIN_DIR . '/wp-seed-pixel'); }
    $result = activate_plugin('wp-seed-pixel/wp-seed-pixel.php');
    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_code()); } echo '{}';
} else { throw new RuntimeException('Unsupported owned browser action'); }
