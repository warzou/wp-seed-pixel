<?php
require __DIR__ . '/m4-runtime.php';
WP_Seed_Pixel_Future_Uploads::configure('off');
update_option(WP_Seed_Pixel_Storage_Budget::OPTION, array());
$id = m3_fixture('m6-efficient.png');
$job = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824);
if (is_wp_error($job)) { throw new RuntimeException($job->get_error_code()); }
$boundary = $argv[1];
add_action('wp_seed_pixel_m3_boundary', static function ($name, $item_id) use ($boundary, $id, $job) {
    if ($name !== $boundary) { return; }
    echo wp_json_encode(array('attachment_id' => $id, 'job_id' => $job['id'], 'item_id' => $item_id,
        'sha256' => hash_file('sha256', get_attached_file($id)))) . "\n";
    flush(); posix_kill(getmypid(), SIGKILL);
}, 10, 2);
WP_Seed_Pixel_Jobs::step($job['id']);
throw new RuntimeException('Crash boundary not reached');
