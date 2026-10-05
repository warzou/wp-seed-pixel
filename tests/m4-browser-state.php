<?php
$op = $argv[1] ?? 'prepare';
if ($op === 'enable') { require __DIR__ . '/runtime.php'; }
else { require __DIR__ . '/m4-runtime.php'; }
if ($op === 'prepare') {
    list($id, $job, $view) = m4_replaced();
    $original = m4_original(); WP_Seed_Pixel_Jobs::step($original[1]);
    $image_id = (int) get_option('pixel_m4_native_fixture');
    $page_id = wp_insert_post(array('post_title' => 'Synthetic M4 native image', 'post_status' => 'publish', 'post_content' => '<!-- wp:image {"id":' . $image_id . ',"sizeSlug":"full"} --><figure class="wp-block-image size-full">' . wp_get_attachment_image($image_id, 'full') . '</figure><!-- /wp:image -->'));
    echo wp_json_encode(array('id' => $id, 'job' => $job, 'original_job' => $original[1], 'url' => get_permalink($page_id), 'image_url' => wp_get_attachment_url($image_id))) . "\n";
} elseif ($op === 'state') {
    echo wp_json_encode(WP_Seed_Pixel_Quarantine::inspect(m4_item((int) $argv[2]))) . "\n";
} elseif ($op === 'remove') {
    $settings = get_option('wp_seed_pixel_settings');
    update_option('wp_seed_pixel_settings', array('cleanup_on_uninstall' => true));
    define('WP_UNINSTALL_PLUGIN', 'wp-seed-pixel/wp-seed-pixel.php');
    require dirname(__DIR__) . '/uninstall.php';
    update_option('wp_seed_pixel_settings', $settings);
    deactivate_plugins('wp-seed-pixel/wp-seed-pixel.php', true);
    if (!rename(WP_PLUGIN_DIR . '/wp-seed-pixel', getenv('PIXEL_M3_ROOT') . '/removed-pixel')) { throw new RuntimeException('Local removal failed'); }
    echo "{\"removed\":true}\n";
} elseif ($op === 'disable') {
    deactivate_plugins('wp-seed-pixel/wp-seed-pixel.php', true);
    echo "{\"disabled\":true}\n";
} elseif ($op === 'enable') {
    if (is_dir(getenv('PIXEL_M3_ROOT') . '/removed-pixel') && !is_dir(WP_PLUGIN_DIR . '/wp-seed-pixel')) {
        if (!rename(getenv('PIXEL_M3_ROOT') . '/removed-pixel', WP_PLUGIN_DIR . '/wp-seed-pixel')) { throw new RuntimeException('Local plugin restore failed'); }
    }
    $r = activate_plugin('wp-seed-pixel/wp-seed-pixel.php'); echo wp_json_encode(array('enabled' => !is_wp_error($r))) . "\n";
} else { throw new RuntimeException('Invalid fixture operation'); }
