<?php
$job = (int) ($argv[1] ?? 0); $boundary = $argv[2] ?? ''; $graph_worker_action = $argv[3] ?? 'step';
$argv[1] = 'library'; require __DIR__ . '/metadata-wp.php';
add_action('wp_seed_pixel_metadata_graph_boundary', static function($name) use ($boundary) {
    if ($name === $boundary) { posix_kill(getmypid(), SIGKILL); }
});
if ($graph_worker_action === 'restore') { $result=WP_Seed_Pixel_Jobs::restore_master($job); }
else { $result=WP_Seed_Pixel_Jobs::step($job); }
$item=meta_item($job);
throw new RuntimeException('Requested graph SIGKILL boundary not reached: '.(is_wp_error($result)?$result->get_error_code():($item['stage'].':'.$item['error_code'])));
