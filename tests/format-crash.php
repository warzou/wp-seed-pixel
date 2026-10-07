<?php
require __DIR__ . '/format-bootstrap.php';
$checks = array();
function crash_ok($value, $name) { global $checks; if (!$value || is_wp_error($value)) { throw new RuntimeException($name . (is_wp_error($value) ? ': ' . $value->get_error_code() : '')); } $checks[$name] = true; }
foreach (array('analyze' => array('intent', 'escrow', 'profile_intent', 'profile_encoded', 'encoding_intent', 'candidate_encoded', 'derivative_intent', 'derivative_encoded', 'ready'), 'convert' => array('publish_intent', 'files_published', 'database_switched', 'profile_cleanup', 'retained'), 'restore' => array('restore_database'), 'purge' => array('purge_intent', 'purge_file')) as $command => $boundaries) {
    foreach ($boundaries as $boundary) {
        $id = format_fixture(); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
        if ($command !== 'analyze') { $a = WP_Seed_Pixel_Format_Conversion::analyze($id); crash_ok($a, "$boundary preparation"); }
        if (in_array($command, array('restore', 'purge'), true)) { crash_ok(WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true), "$boundary conversion"); }
        list($p, $pipes) = format_worker(array((string) $id, $command, $boundary));
        $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($p);
        crash_ok($exit !== 0 && !$err && str_contains($out, 'BOUNDARY:' . $boundary), "$boundary actual SIGKILL");
        clean_post_cache($id); wp_cache_delete($id, 'post_meta');
        $b = WP_Seed_Pixel_Format_Conversion::record($id); $candidate = $b['record']['candidate']['sha256'] ?? null;
        if ($command === 'analyze') { $result = WP_Seed_Pixel_Format_Conversion::analyze($id); }
        elseif ($command === 'convert') { $result = WP_Seed_Pixel_Format_Conversion::convert($id, $b['record']['generation'], true, true); }
        elseif ($command === 'restore') { $result = WP_Seed_Pixel_Format_Conversion::restore($id, $b['record']['generation']); }
        else { $result = WP_Seed_Pixel_Format_Conversion::purge($id, $b['record']['generation'], true, true); }
        crash_ok($result, "$boundary safe resumed");
        $after = WP_Seed_Pixel_Format_Conversion::record($id);
        crash_ok(!$candidate || $candidate === $after['record']['candidate']['sha256'], "$boundary no re-encoding");
        if ($command === 'analyze') { crash_ok(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, "$boundary untouched PNG"); crash_ok(WP_Seed_Pixel_Format_Conversion::discard($id, $result['generation']), "$boundary discarded"); }
        elseif ($command === 'restore') { crash_ok(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'crash restore exact state'); }
        else { crash_ok(get_post_mime_type($id) === 'image/jpeg' && wp_get_attachment_image_srcset($id, 'large'), "$boundary coherent JPEG"); }
        echo "$boundary PASS\n";
    }
}
$id = format_fixture();
list($p1, $s1) = format_worker(array((string) $id, 'analyze'));
list($p2, $s2) = format_worker(array((string) $id, 'analyze'));
foreach (array(array($p1, $s1), array($p2, $s2)) as $worker) {
    list($p, $pipes) = $worker; $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    crash_ok(proc_close($p) === 0 && !$err, 'concurrent worker clean ' . count($checks));
    $r = json_decode($out, true); crash_ok(isset($r['item_id']) || in_array($r['error'] ?? '', array('LOCKED', 'pixel_locked'), true), 'concurrent request safely fenced ' . count($checks) . ': ' . $out);
}
global $wpdb;
crash_ok((int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE attachment_id=%d AND action='convert'", $id)) === 1, 'exactly one authoritative concurrent conversion');
$dir = getenv('PIXEL_FORMAT_REPORT_DIR') ?: '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/reports/png-jpeg';
file_put_contents($dir . '/crash-' . ($argv[1] ?? 'dev') . '.json', wp_json_encode(array('checks' => $checks, 'count' => count($checks)), JSON_PRETTY_PRINT));
echo count($checks) . " crash and concurrency checks PASS\n";
