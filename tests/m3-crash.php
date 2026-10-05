<?php
require __DIR__ . '/runtime.php';
error_reporting(E_ALL & ~E_DEPRECATED);
$boundary = $argv[2] ?? '';
add_action('wp_seed_pixel_m3_boundary', static function ($name) use ($boundary) {
    if ($name === 'intent' && $boundary === 'fatal_oom') {
        ini_set('memory_limit', ((int) ceil(memory_get_usage(true) / 1048576) + 16) . 'M');
        $held = str_repeat('x', 67108864);
    }
    if ($name === $boundary) { posix_kill(getmypid(), SIGKILL); }
});
if (($argv[3] ?? '') === 'restore') { WP_Seed_Pixel_Jobs::restore_master((int) $argv[1]); }
else { WP_Seed_Pixel_Jobs::step((int) $argv[1]); }
throw new RuntimeException('Requested crash boundary did not execute');
