<?php
require __DIR__ . '/runtime.php';
$rows = array();
$ids = get_posts(array('post_type' => 'attachment', 'posts_per_page' => -1, 'fields' => 'ids'));
foreach ($ids as $id) {
    $title = get_the_title($id);
    $meta = wp_get_attachment_metadata($id, true);
    if (preg_match('/^Synthetic orientation-([1-8])\.jpg$/D', $title, $matches) && !isset($rows[$matches[1]])) {
        wp_seed_pixel_optimize((int) $id, 'participant_album');
        $state = WP_Seed_Pixel_Store::manifest($id);
        $rows[$matches[1]] = array('source' => wp_get_original_image_path($id), 'output' => $state['files']['thumb']['path']);
    }
    if ($title === 'Synthetic rgb.jpg' && !isset($rows['quality'])) {
        wp_seed_pixel_optimize((int) $id, 'participant_album');
        $state = WP_Seed_Pixel_Store::manifest($id);
        $rows['quality'] = $state['files'];
    }
}
echo wp_json_encode($rows);
