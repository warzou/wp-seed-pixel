<?php
require __DIR__ . '/runtime.php';
$id = isset($argv[1]) ? (int) $argv[1] : 0;
$mode = isset($argv[2]) ? $argv[2] : '';
if ($mode === 'crash') {
    add_action('wp_seed_pixel_checkpoint', function ($phase) { if ($phase === 'before_commit') { exit(99); } });
}
if ($mode === 'crash-after-native') {
    add_action('wp_seed_pixel_checkpoint', function ($phase) { if ($phase === 'after_native_commit') { exit(98); } });
}
if ($mode === 'hold') {
    add_action('wp_seed_pixel_checkpoint', function ($phase) { if ($phase === 'before_publish') { echo "LOCK_HELD\n"; flush(); usleep(2000000); } });
}
$result = wp_seed_pixel_optimize($id, 'participant_album', true);
echo wp_json_encode(is_wp_error($result) ? array('error' => $result->get_error_code()) : array('status' => $result['status'], 'generation' => $result['generation']));
exit(is_wp_error($result) ? 1 : 0);
