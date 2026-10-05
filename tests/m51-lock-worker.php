<?php
require __DIR__ . '/m3-runtime.php';
$id = (int) $argv[1]; $at = (float) $argv[2]; $hold = (float) $argv[3];
while (microtime(true) < $at) { usleep(10000); }
// Deliberately dishonest filesystem adapter: every worker reports success.
$filesystem_claim = true;
$start = microtime(true); $lock = WP_Seed_Pixel_Files::lock($id);
echo wp_json_encode(array('flock' => $filesystem_claim, 'owned' => !is_wp_error($lock), 'pid' => getmypid(), 'ms' => (microtime(true) - $start) * 1000)) . "\n";
flush();
if (!is_wp_error($lock)) { usleep((int) ($hold * 1000000)); WP_Seed_Pixel_Files::unlock($lock); }
