<?php
require __DIR__ . '/runtime.php';
$tests = array();
$check = function ($test, $condition) use (&$tests) { $tests[] = array('test' => $test, 'status' => $condition ? 'PASS' : 'FAIL'); };
foreach (array('small.jpg', 'rgb.jpg') as $fixture) {
    $id = pixel_fixture($fixture);
    $master = wp_get_original_image_path($id);
    $sha = hash_file('sha256', $master);
    $first = wp_seed_pixel_optimize($id, 'balanced');
    if (is_wp_error($first)) { throw new RuntimeException('Test setup failed.'); }
    $meta = wp_get_attachment_metadata($id, true);
    $command = array(PHP_BINARY, '-d', 'extension_dir=' . ini_get('extension_dir'), '-d', 'extension=gd', '-d', 'extension=exif', '-d', 'extension=mbstring', '-d', 'extension=pdo_sqlite', '-d', 'extension=mysqli', '-d', 'memory_limit=512M', __DIR__ . '/worker.php', (string) $id);
    foreach (array('crash' => 99, 'crash-after-native' => 98) as $mode => $expected) {
        $pipes = array();
        $p = proc_open(array_merge($command, array($mode, 'balanced')), array(1 => array('pipe','w'), 2 => array('pipe','w')), $pipes);
        stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $check($fixture . ' real child ' . $mode, proc_close($p) === $expected);
        wp_cache_delete($id, 'post_meta');
        $recovered = wp_seed_pixel_optimize($id, 'balanced');
        $check($fixture . ' recover ' . $mode, !is_wp_error($recovered));
        $check($fixture . ' preserve MASTER ' . $mode, hash_equals($sha, hash_file('sha256', $master)));
        $check($fixture . ' no staging residue ' . $mode, !glob(wp_upload_dir()['basedir'] . '/wp-seed-pixel/job-' . $id . '-*', GLOB_ONLYDIR));
    }
    $state = WP_Seed_Pixel_Store::manifest($id);
    if ($fixture === 'small.jpg') {
        $check('Small source both resources reused', $state['files']['thumb']['kind'] === 'master' && $state['files']['view']['kind'] === 'master');
        $check('Reuse adds zero bytes and zero encodes', $state['added_disk_bytes'] === 0 && $state['candidates'] === 0);
        $check('Explicit master kind cannot be deleted with alternate guard path', !WP_Seed_Pixel_Files::owned_delete($state['files']['view'], $id, dirname($master) . '/other.jpg'));
        $original = file_get_contents($master);
        file_put_contents($master, file_get_contents(dirname(__DIR__) . '/.runtime/fixtures/app0-private.jpg'));
        $check('Reused source getter refuses newly private header without source hashing', is_wp_error(wp_seed_pixel_get_derivative($id, 'view')));
        file_put_contents($master, $original);
    }
    $native = wp_get_attachment_metadata($id, true);
    $changed = $native;
    ++$changed['sizes']['seed-pixel-view']['width'];
    wp_update_attachment_metadata($id, $changed);
    $check($fixture . ' mutated dimensions rejected by getter', is_wp_error(wp_seed_pixel_get_derivative($id, 'view')));
    wp_update_attachment_metadata($id, $native);
    $check($fixture . ' native rendering available', (bool) wp_get_attachment_image($id, 'seed-pixel-view'));
    $pruned = wp_seed_pixel_prune_history($id, true);
    $check($fixture . ' pruning retains MASTER', !is_wp_error($pruned) && is_file($master) && hash_equals($sha, hash_file('sha256', $master)));
    WP_Seed_Pixel_Store::cleanup($id);
    $check($fixture . ' cleanup removes native reuse keys only', is_file($master) && !isset(wp_get_attachment_metadata($id)['sizes']['seed-pixel-view']));
    wp_delete_attachment($id, true);
}
file_put_contents(dirname(__DIR__) . '/reports/adaptive/data/adaptive-adversarial-tests.json', wp_json_encode($tests, JSON_PRETTY_PRINT));
echo wp_json_encode(array_count_values(array_column($tests, 'status')), JSON_PRETTY_PRINT);
exit(in_array('FAIL', array_column($tests, 'status'), true) ? 1 : 0);
