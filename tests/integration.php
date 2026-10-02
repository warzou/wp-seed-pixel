<?php
require __DIR__ . '/runtime.php';
$results = array();
function check($name, $condition, $detail = '') {
    global $results;
    $results[] = array('test' => $name, 'status' => $condition ? 'PASS' : 'FAIL', 'detail' => $detail);
}
function is_error_code($result, $code) {
    return is_wp_error($result) && $result->get_error_code() === $code;
}
$preset = WP_Seed_Pixel_Presets::get('participant_album');
foreach (array(0, 101, '80', -1) as $quality) {
    $invalid = $preset;
    $invalid['sizes']['thumb']['quality'] = $quality;
    check('Reject quality ' . json_encode($quality), is_wp_error(WP_Seed_Pixel_Presets::validate($invalid)));
}
check('Reject unknown preset', is_error_code(wp_seed_pixel_optimize(1, 'not_a_preset'), 'pixel_unknown_preset'));
check('Reject noninteger ID', is_error_code(wp_seed_pixel_optimize('1'), 'pixel_arguments'));
check('Reject outside uploads', is_wp_error(WP_Seed_Pixel_Files::path(__FILE__)));
check('Reject traversal', is_wp_error(WP_Seed_Pixel_Files::path(wp_upload_dir()['basedir'] . '/../outside.jpg')));

$id = pixel_fixture('rgb.jpg');
$master = wp_get_original_image_path($id);
$hash = hash_file('sha256', $master);
$before = wp_get_attachment_metadata($id, true);
$first = wp_seed_pixel_optimize($id, 'participant_album');
check('RGB pipeline succeeds', !is_wp_error($first) && $first['status'] === 'success', is_wp_error($first) ? $first->get_error_code() . ': ' . $first->get_error_message() : '');
check('Master remains byte-identical', hash_file('sha256', $master) === $hash);
if (!is_wp_error($first) && $first['status'] === 'success') {
    $after = wp_get_attachment_metadata($id, true);
    check('Native metadata preserved', $before['file'] === $after['file'] && $before['sizes']['thumbnail'] === $after['sizes']['thumbnail']);
    check('THUMB bounded 640 Q80', $first['files']['thumb']['width'] <= 640 && $first['files']['thumb']['quality'] === 80);
    check('VIEW bounded 2048 Q90', $first['files']['view']['width'] <= 2048 && $first['files']['view']['quality'] === 90);
    check('Disk growth is explicit', $first['added_disk_bytes'] === $first['files']['thumb']['bytes'] + $first['files']['view']['bytes']);
    check('Native image API can use derivative', (bool) wp_get_attachment_image_src($id, 'seed-pixel-thumb'));
    check('Native srcset includes derivative', strpos((string) wp_get_attachment_image_srcset($id, 'seed-pixel-thumb'), basename($first['files']['thumb']['path'])) !== false);
    $second = wp_seed_pixel_optimize($id, 'participant_album');
    check('Identical job is idempotent', !is_wp_error($second) && !empty($second['unchanged']) && $second['generation'] === $first['generation']);
    $fail = function ($phase) { if ($phase === 'before_commit') { throw new RuntimeException('Synthetic disk/commit interruption'); } };
    add_action('wp_seed_pixel_checkpoint', $fail);
    $failed = wp_seed_pixel_optimize($id, 'participant_album', true);
    remove_action('wp_seed_pixel_checkpoint', $fail);
    check('Failure after publication returns error', is_wp_error($failed));
    check('Failure preserves old generation', wp_get_attachment_metadata($id, true) === $after && file_exists($first['files']['thumb']['path']));
    $tamper = function ($phase, $attachment) { if ($phase === 'before_commit') { $m = wp_get_attachment_metadata($attachment, true); $m['foreign_optimizer'] = 'keep'; wp_update_attachment_metadata($attachment, $m); } };
    add_action('wp_seed_pixel_checkpoint', $tamper, 10, 2);
    $conflict = wp_seed_pixel_optimize($id, 'participant_album', true);
    remove_action('wp_seed_pixel_checkpoint', $tamper, 10);
    check('Concurrent metadata update rejected', is_error_code($conflict, 'pixel_metadata_conflict'));
    check('Foreign optimizer data preserved', wp_get_attachment_metadata($id, true)['foreign_optimizer'] === 'keep');
    $regen = wp_seed_pixel_optimize($id, 'participant_album', true);
    check('Regeneration starts from same master', !is_wp_error($regen) && $regen['master_sha256'] === $hash && $regen['generation'] !== $first['generation']);
    check('Old generation retained for cached/static URLs', file_exists($first['files']['thumb']['path']) && isset(WP_Seed_Pixel_Store::history($id)[$first['generation']]));
    $shared = pixel_fixture('small.jpg');
    update_post_meta($shared, '_wp_attachment_metadata', array('file' => basename($regen['files']['thumb']['path'])));
    check('Shared referenced derivative is not deleted', !WP_Seed_Pixel_Files::owned_delete($regen['files']['thumb'], $id, $master));
    check('Foreign file is never deleted', !WP_Seed_Pixel_Files::owned_delete(array('path' => $master, 'sha256' => $hash), $id, $master));
    $changed = $regen['files']['thumb'];
    $changed['sha256'] = str_repeat('0', 64);
    check('Ownership hash mismatch is not deleted', !WP_Seed_Pixel_Files::owned_delete($changed, $id, $master));
}
$lock = WP_Seed_Pixel_Files::lock($id);
check('Concurrent attachment job blocked', is_error_code(wp_seed_pixel_optimize($id), 'pixel_locked'));
WP_Seed_Pixel_Files::unlock($lock);

$small = pixel_fixture('small.jpg');
$small_result = wp_seed_pixel_optimize($small, 'participant_album');
check('Small image succeeds without upscaling', !is_wp_error($small_result) && $small_result['files']['view']['width'] === 200 && $small_result['files']['view']['height'] === 120, is_wp_error($small_result) ? $small_result->get_error_message() : '');
$big = pixel_fixture('big.jpg');
$big_original = wp_get_original_image_path($big);
$big_hash = hash_file('sha256', $big_original);
$big_result = wp_seed_pixel_optimize($big, 'participant_album');
check('WordPress scaled fixture has separate original', $big_original !== get_attached_file($big));
check('Scaled attachment derives from original master', !is_wp_error($big_result) && $big_result['master_sha256'] === $big_hash);
check('Original scaled-model master remains immutable', hash_file('sha256', $big_original) === $big_hash);
foreach (range(1, 8) as $orientation) {
    $oriented = pixel_fixture('orientation-' . $orientation . '.jpg', false);
    $oriented_hash = hash_file('sha256', wp_get_original_image_path($oriented));
    $result = wp_seed_pixel_optimize($oriented, 'participant_album');
    $expected = $orientation >= 5 ? array(427, 640) : array(640, 427);
    check('EXIF orientation ' . $orientation . ' dimensions', !is_wp_error($result) && abs($result['files']['thumb']['width'] - $expected[0]) <= 1 && abs($result['files']['thumb']['height'] - $expected[1]) <= 1);
    check('EXIF orientation ' . $orientation . ' master untouched', hash_file('sha256', wp_get_original_image_path($oriented)) === $oriented_hash);
}
foreach (array('cmyk.jpg', 'icc.jpg', 'png.png', 'animated.gif') as $name) {
    $unsupported = pixel_fixture($name, false);
    $unsupported_hash = hash_file('sha256', get_attached_file($unsupported));
    $result = wp_seed_pixel_optimize($unsupported);
    check($name . ' safely skipped', !is_wp_error($result) && $result['status'] === 'skipped');
    check($name . ' byte-identical after skip', hash_file('sha256', get_attached_file($unsupported)) === $unsupported_hash);
}
foreach (array('corrupt.jpg', 'spoof.jpg') as $name) {
    $bad = pixel_fixture($name, false);
    check($name . ' rejected', is_wp_error(wp_seed_pixel_optimize($bad)));
}
$settings = WP_Seed_Pixel_Plugin::settings();
check('Automation defaults OFF', $settings['automatic'] === false);
$settings['automatic'] = true;
update_option('wp_seed_pixel_settings', $settings, false);
$auto = pixel_fixture('small.jpg');
check('Upload automation schedules real event', (bool) wp_next_scheduled('wp_seed_pixel_auto', array($auto)));
WP_Seed_Pixel_Plugin::automatic($auto);
check('Automation uses central engine', (bool) WP_Seed_Pixel_Store::manifest($auto));
$settings['automatic'] = false;
update_option('wp_seed_pixel_settings', $settings, false);
WP_Seed_Pixel_Plugin::deactivate();
check('Deactivation clears automatic events', !wp_next_scheduled('wp_seed_pixel_auto', array($auto)));

delete_option(WP_Seed_Pixel_Batch::OPTION);
check('Whole-library confirmation required', is_error_code(WP_Seed_Pixel_Batch::start('web', false), 'pixel_confirmation'));
$batch = WP_Seed_Pixel_Batch::start('web', true);
check('Batch starts with fixed ceiling', !is_wp_error($batch) && $batch['ceiling'] > 0);
check('Duplicate batch rejected', is_error_code(WP_Seed_Pixel_Batch::start('web', true), 'pixel_batch_exists'));
WP_Seed_Pixel_Batch::pause(true);
check('Paused batch does not process', WP_Seed_Pixel_Batch::step()['processed'] === 0);
WP_Seed_Pixel_Batch::pause(false);
$first_step = WP_Seed_Pixel_Batch::step();
check('Small batch step processes one attachment', $first_step['processed'] === 1);
for ($i = 0; $i < 200 && $first_step['status'] === 'running'; ++$i) {
    $first_step = WP_Seed_Pixel_Batch::step();
}
check('Batch actually completes', $first_step['status'] === 'complete');
check('Batch counters represent actual results', $first_step['processed'] === $first_step['success'] + $first_step['failed'] + $first_step['skipped']);
if ($first_step['failed_ids']) {
    $retry = WP_Seed_Pixel_Batch::retry_one();
    check('Retry has persistent bounded count', !is_wp_error($retry) && max($retry['retry_counts']) === 1);
}
file_put_contents(dirname(__DIR__) . '/reports/final/integration-results.json', wp_json_encode(array('wordpress' => $GLOBALS['wp_version'], 'php' => PHP_VERSION, 'engine' => 'GD', 'tests' => $results), JSON_PRETTY_PRINT));
$fails = array_filter($results, function ($row) { return $row['status'] === 'FAIL'; });
echo json_encode(array('tests' => count($results), 'pass' => count($results) - count($fails), 'failed' => array_values($fails), 'primary_attachment' => $id), JSON_PRETTY_PRINT);
exit($fails ? 1 : 0);
