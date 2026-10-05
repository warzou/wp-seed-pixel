<?php
require __DIR__ . '/runtime.php';
$tests = array();
function verify($name, $condition, $detail = '') {
    global $tests;
    $tests[] = array('test' => $name, 'status' => $condition ? 'PASS' : 'FAIL', 'detail' => $detail);
}
$id = pixel_fixture('rgb.jpg');
$result = wp_seed_pixel_optimize($id, 'participant_album');
$before = wp_get_attachment_metadata($id, true);
$master = wp_get_original_image_path($id);
$master_hash = hash_file('sha256', $master);
$command = pixel_worker_command($id);
$pipes = array();
$process = proc_open(array_merge($command, array('crash')), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
$child_output = stream_get_contents($pipes[1]);
$child_error = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$exit = proc_close($process);
verify('Real process exit at pre-commit', $exit === 99, $child_error);
wp_cache_delete($id, 'post_meta');
verify('Crash preserves metadata generation', wp_get_attachment_metadata($id, true) === $before);
$stages = glob(wp_upload_dir()['basedir'] . '/wp-seed-pixel/job-' . $id . '-*', GLOB_ONLYDIR);
verify('Crash leaves recoverable journal', count($stages) === 1);
$recovered = wp_seed_pixel_optimize($id, 'participant_album');
verify('Recovery resumes safely', !is_wp_error($recovered) && $recovered['generation'] === $result['generation']);
verify('Recovery removes abandoned stages', !glob(wp_upload_dir()['basedir'] . '/wp-seed-pixel/job-' . $id . '-*', GLOB_ONLYDIR));
verify('Committed current derivative cannot be deleted', !WP_Seed_Pixel_Files::owned_delete($result['files']['thumb'], $id, $master));
verify('Recovery preserves master SHA', hash_file('sha256', $master) === $master_hash);

$process = proc_open(array_merge($command, array('hold')), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
$held = trim(fgets($pipes[1]));
$concurrent = wp_seed_pixel_optimize($id, 'participant_album', true);
verify('Separate process owns flock', $held === 'LOCK_HELD' && is_wp_error($concurrent) && $concurrent->get_error_code() === 'pixel_locked', wp_json_encode(array('held' => $held, 'concurrent' => is_wp_error($concurrent) ? $concurrent->get_error_code() : 'not_error')));
$held_output = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
$held_exit = proc_close($process);
verify('Lock owner completes', $held_exit === 0, wp_json_encode(array('exit' => $held_exit, 'output' => $held_output, 'error' => $err)));
wp_cache_delete($id, 'post_meta');

$filter = function ($map) { $map['image/jpeg'] = 'image/webp'; return $map; };
add_filter('image_editor_output_format', $filter, 10);
$jpeg = wp_seed_pixel_optimize($id, 'participant_album', true);
verify('Scoped output remains JPEG despite global WebP mapping', !is_wp_error($jpeg) && getimagesize($jpeg['files']['thumb']['path'])[2] === IMAGETYPE_JPEG);
verify('External format mapping remains installed', has_filter('image_editor_output_format', $filter) === 10);
remove_filter('image_editor_output_format', $filter, 10);

$foreign = function ($meta) { $meta['other_optimizer_test'] = 'changed'; return $meta; };
add_filter('wp_update_attachment_metadata', $foreign);
$conflict = wp_seed_pixel_optimize($id, 'participant_album', true);
verify('Foreign metadata filter is fail-closed', is_wp_error($conflict) && $conflict->get_error_code() === 'pixel_metadata_filter');
remove_filter('wp_update_attachment_metadata', $foreign);

$callback = function () { throw new RuntimeException('Completion observer error'); };
add_action('wp_seed_pixel_after', $callback);
$completed = wp_seed_pixel_optimize($id, 'participant_album', true);
remove_action('wp_seed_pixel_after', $callback);
verify('Post-commit callback cannot make valid output disappear', !is_wp_error($completed) && isset($completed['warning']) && file_exists($completed['files']['thumb']['path']));

$protected = pixel_fixture('small.jpg');
$m = wp_get_attachment_metadata($protected, true);
$m['sizes']['seed-pixel-web'] = array('file' => 'foreign.jpg', 'width' => 1, 'height' => 1, 'mime-type' => 'image/jpeg');
wp_update_attachment_metadata($protected, $m);
$collision = wp_seed_pixel_optimize($protected);
verify('Foreign native size key is never overwritten', is_wp_error($collision) && $collision->get_error_code() === 'pixel_size_collision');

$exclude = function ($excluded, $attachment) use ($id) { return $attachment === $id; };
add_filter('wp_seed_pixel_exclude', $exclude, 10, 2);
verify('Exclusion executes before decode', wp_seed_pixel_optimize($id)['status'] === 'skipped');
remove_filter('wp_seed_pixel_exclude', $exclude, 10);

$data = file_get_contents($master);
$changed = function ($phase) use ($master) { if ($phase === 'before_commit') { file_put_contents($master, "synthetic-source-change", FILE_APPEND); } };
add_action('wp_seed_pixel_checkpoint', $changed);
$mutation = wp_seed_pixel_optimize($id, 'participant_album', true);
remove_action('wp_seed_pixel_checkpoint', $changed);
verify('Concurrent master mutation aborts commit', is_wp_error($mutation) && $mutation->get_error_code() === 'pixel_source_changed');
file_put_contents($master, $data);
verify('Synthetic test restores its master', hash_file('sha256', $master) === $master_hash);

$memory = ini_get('memory_limit');
ini_set('memory_limit', (string) (memory_get_usage(true) + 8388608));
$budget = wp_seed_pixel_optimize($id, 'participant_album', true);
verify('Memory preflight rejects unsafe decode', is_wp_error($budget) && $budget->get_error_code() === 'pixel_memory_budget', is_wp_error($budget) ? $budget->get_error_code() : 'Unexpected success');
ini_set('memory_limit', $memory);

$custom = WP_Seed_Pixel_Presets::get('web');
$custom['sizes']['web']['width'] = 0;
verify('Invalid dimension rejected', is_wp_error(wp_seed_pixel_register_preset('invalid', $custom)));
$custom = WP_Seed_Pixel_Presets::get('web');
$custom['upscale'] = true;
verify('Unsupported upscaling policy rejected', is_wp_error(wp_seed_pixel_register_preset('invalid', $custom)));
$clean = WP_Seed_Pixel_Store::cleanup($id);
verify('Owned cleanup retains master', $clean === true && is_file($master) && hash_file('sha256', $master) === $master_hash);
verify('Owned cleanup preserves foreign native sizes', isset(wp_get_attachment_metadata($id, true)['sizes']['thumbnail']));
verify('Owned cleanup removes plugin manifest', !WP_Seed_Pixel_Store::manifest($id));
$late = pixel_fixture('late-icc.jpg', false);
verify('ICC after 2 MB of JPEG metadata detected', wp_seed_pixel_optimize($late)['status'] === 'skipped');
$gps = pixel_fixture('gps.jpg', false);
$gps_source = @exif_read_data(wp_get_original_image_path($gps));
$gps_result = wp_seed_pixel_optimize($gps, 'participant_album');
$gps_out = !is_wp_error($gps_result) ? @exif_read_data($gps_result['files']['thumb']['path']) : array();
verify('Fixture contains actual GPS tags', isset($gps_source['GPSLatitude']) && isset($gps_source['GPSLongitude']));
verify('GPS tags absent from derivative', !is_wp_error($gps_result) && !isset($gps_out['GPSLatitude'], $gps_out['GPSLongitude']));
verify('Malformed getter arguments return WP_Error', is_wp_error(wp_seed_pixel_get_derivative($gps, array('thumb'))));
$meta = wp_get_attachment_metadata($gps, true);
$meta['sizes']['seed-pixel-thumb']['file'] = 'foreign-mapping.jpg';
wp_update_attachment_metadata($gps, $meta);
verify('Getter rejects corrupted native mapping', is_wp_error(wp_seed_pixel_get_derivative($gps, 'thumb')));
verify('Idempotency cannot hide a native mapping collision', is_wp_error(wp_seed_pixel_optimize($gps, 'participant_album')));

class Pixel_Test_Failing_Editor extends WP_Image_Editor_GD {
    public function save($destfilename = null, $mime_type = null) {
        return new WP_Error('pixel_injected_disk_full', 'Synthetic ENOSPC from the image editor.');
    }
}
$fail_editor = function () { return array('Pixel_Test_Failing_Editor'); };
add_filter('wp_image_editors', $fail_editor);
$meta_before_failure = wp_get_attachment_metadata($id, true);
$no_space = wp_seed_pixel_optimize($id, 'participant_album', true);
remove_filter('wp_image_editors', $fail_editor);
verify('Injected disk-full error returned', is_wp_error($no_space) && $no_space->get_error_code() === 'pixel_injected_disk_full');
verify('Injected disk-full preserves prior metadata', wp_get_attachment_metadata($id, true) === $meta_before_failure);
$invalid_orientation = pixel_fixture('invalid-orientation.jpg', false);
$invalid = wp_seed_pixel_optimize($invalid_orientation);
verify('Invalid EXIF orientation rejected explicitly', is_wp_error($invalid) && $invalid->get_error_code() === 'pixel_exif_invalid');
class Pixel_Test_Metadata_Leak_Editor extends WP_Image_Editor_GD {
    public function save($destfilename = null, $mime_type = null) {
        $saved = parent::save($destfilename, $mime_type);
        if (!is_wp_error($saved)) {
            $data = file_get_contents($saved['path']);
            $comment = 'synthetic-private-metadata';
            file_put_contents($saved['path'], substr($data, 0, 2) . "\xff\xfe" . pack('n', strlen($comment) + 2) . $comment . substr($data, 2));
        }
        return $saved;
    }
}
$leaking_editor = function () { return array('Pixel_Test_Metadata_Leak_Editor'); };
add_filter('wp_image_editors', $leaking_editor);
$leak = wp_seed_pixel_optimize($id, 'participant_album', true);
remove_filter('wp_image_editors', $leaking_editor);
verify('Sensitive JPEG comments cannot escape output validation', is_wp_error($leak) && $leak->get_error_code() === 'pixel_metadata_output');
$public = pixel_fixture('rgb.jpg');
$generated = wp_seed_pixel_optimize($public, 'participant_album');
wp_set_current_user(0);
$response = rest_do_request(new WP_REST_Request('GET', '/wp/v2/media/' . $public));
$public_data = wp_json_encode($response->get_data());
verify('Public REST hides protected manifest', strpos($public_data, '_seed_pixel_manifest') === false && strpos($public_data, 'master_sha256') === false);
verify('Public REST contains no local filesystem path', strpos($public_data, wp_normalize_path(wp_upload_dir()['basedir'])) === false && strpos($public_data, 'C:') === false);
verify('Public REST keeps native derivative sizes usable', strpos($public_data, 'seed-pixel-thumb') !== false);
wp_set_current_user(1);
$after_commit_command = $command;
$after_commit_command[count($after_commit_command) - 1] = (string) $public;
$process = proc_open(array_merge($after_commit_command, array('crash-after-native')), array(1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
fclose($pipes[1]); fclose($pipes[2]);
verify('Real process crashes between native and private commits', proc_close($process) === 98);
wp_cache_delete($public, 'post_meta');
$native_after_crash = wp_get_attachment_metadata($public, true);
$recovered = wp_seed_pixel_optimize($public, 'participant_album');
verify('Two-phase manifest recovers without replacing valid native files', !is_wp_error($recovered) && wp_get_attachment_metadata($public, true) === $native_after_crash && WP_Seed_Pixel_Store::manifest($public)['generation'] === $recovered['generation']);
verify('History prune requires explicit confirmation', is_wp_error(wp_seed_pixel_prune_history($public)));
$referencing_post = wp_insert_post(array('post_title' => 'Synthetic derivative reference', 'post_status' => 'draft', 'post_content' => '<img src="https://example.invalid/' . basename($generated['files']['thumb']['path']) . '">'));
$pruned = wp_seed_pixel_prune_history($public, true);
verify('Prune retains a file referenced by WordPress content', !is_wp_error($pruned) && is_file($generated['files']['thumb']['path']));
wp_delete_post($referencing_post, true);
$pruned = wp_seed_pixel_prune_history($public, true);
verify('Confirmed prune removes unreferenced proven-owned history', !is_wp_error($pruned) && !is_file($generated['files']['thumb']['path']) && $pruned['retained_generations'] === 0);
update_post_meta($public, '_seed_pixel_history', 'malformed-synthetic-value');
verify('Malformed history cannot crash or delete files', is_wp_error(wp_seed_pixel_prune_history($public, true)) && is_wp_error(WP_Seed_Pixel_Store::cleanup($public)) && is_file($recovered['files']['thumb']['path']));
delete_post_meta($public, '_seed_pixel_history');
$numeric_generation = WP_Seed_Pixel_Store::manifest($public);
$numeric_generation['generation'] = '1234567890123456';
update_post_meta($public, '_seed_pixel_history', array(1234567890123456 => $numeric_generation));
verify('Numeric-looking hexadecimal generation keys survive PHP coercion', !is_wp_error(WP_Seed_Pixel_Store::history($public)));
delete_post_meta($public, '_seed_pixel_history');
$uncalibrated = pixel_fixture('uncalibrated.jpg', false);
verify('Declared uncalibrated EXIF color is skipped', wp_seed_pixel_optimize($uncalibrated)['status'] === 'skipped');
$link = wp_upload_dir()['basedir'] . '/qa-link-' . bin2hex(random_bytes(8)) . '.jpg';
if (@symlink(dirname(__DIR__) . '/.runtime/fixtures/rgb.jpg', $link)) {
    verify('Real filesystem symlink rejected', is_wp_error(WP_Seed_Pixel_Files::path($link)));
    unlink($link);
} else {
    $tests[] = array('test' => 'Real filesystem symlink rejected', 'status' => 'SKIP', 'detail' => 'Windows symlink creation was not permitted; lexical boundary tests pass.');
}
file_put_contents(dirname(__DIR__) . '/reports/final/adversarial-results.json', wp_json_encode(array('tests' => $tests), JSON_PRETTY_PRINT));
$failed = array_filter($tests, function ($row) { return $row['status'] === 'FAIL'; });
echo wp_json_encode(array('tests' => count($tests), 'pass' => count(array_filter($tests, function ($row) { return $row['status'] === 'PASS'; })), 'skip' => count(array_filter($tests, function ($row) { return $row['status'] === 'SKIP'; })), 'failed' => array_values($failed)), JSON_PRETTY_PRINT);
exit($failed ? 1 : 0);
