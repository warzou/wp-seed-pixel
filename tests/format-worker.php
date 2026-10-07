<?php
require __DIR__ . '/format-bootstrap.php';
$id = (int) $argv[1]; $command = $argv[2]; $at = $argv[3] ?? '';
if ($command === 'hold') {
    $owner = WP_Seed_Pixel_Authority::acquire($id); if (is_wp_error($owner)) { exit(1); }
    echo "HELD\n"; fflush(STDOUT); usleep(1500000);
    WP_Seed_Pixel_Authority::release($owner); exit(0);
}
add_action('wp_seed_pixel_conversion_boundary', static function ($name) use ($at) {
    if ($name === $at) { echo "BOUNDARY:$name\n"; fflush(STDOUT); posix_kill(getmypid(), SIGKILL); }
});
if ($command === 'analyze') { $result = WP_Seed_Pixel_Format_Conversion::analyze($id); }
else {
    $b = WP_Seed_Pixel_Format_Conversion::record($id); $g = $b['record']['generation'];
    if ($command === 'convert') { $result = WP_Seed_Pixel_Format_Conversion::convert($id, $g, true, true); }
    elseif ($command === 'override') { $result = WP_Seed_Pixel_Format_Conversion::convert($id, $g, true, true, $b['record']['selected_profile'], true); }
    elseif ($command === 'select') { $result = WP_Seed_Pixel_Format_Conversion::select_profile($id, $g, 'best'); }
    elseif ($command === 'restore') { $result = WP_Seed_Pixel_Format_Conversion::restore($id, $g); }
    else { $result = WP_Seed_Pixel_Format_Conversion::purge($id, $g, true, true); }
}
echo json_encode(is_wp_error($result) ? array('error' => $result->get_error_code()) : $result);
