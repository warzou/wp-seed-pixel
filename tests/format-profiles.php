<?php
require __DIR__ . '/format-bootstrap.php';
$checks = array(); $matrix = array();
function profile_ok($value, $name) {
    global $checks;
    if (!$value || is_wp_error($value)) { throw new RuntimeException($name . (is_wp_error($value) ? ': ' . $value->get_error_code() : '')); }
    $checks[$name] = true;
}
foreach (array('photo','portrait','smooth','noisy','logo','text','line','small','large-photo') as $name) {
    $profiles = array();
    foreach (WP_Seed_Pixel_Format_Processor::profiles() as $key => $definition) {
        $out = $lab . '/profile-test-' . $key . '.jpg';
        $p = WP_Seed_Pixel_Format_Processor::create($fixtures . '/' . $name . '.png', $out, null, $key);
        profile_ok($p, "$name $key measured");
        profile_ok($p['quality'] === $definition['quality'] && $p['bytes'] === filesize($out), "$name $key exact encoder and size");
        profile_ok($p['accepted'] === ($p['metric']['ssim'] >= .995 && $p['metric']['psnr'] >= 40 && WP_Seed_Pixel_Format_Processor::benefit(filesize($fixtures . '/' . $name . '.png'), $p['bytes'])), "$name $key guards unchanged");
        $profiles[$key] = $p; unlink($out);
    }
    $selected = WP_Seed_Pixel_Format_Processor::lightest($profiles);
    profile_ok($selected === null || ($profiles[$selected]['accepted'] && $profiles[$selected]['bytes'] === min(array_column(array_filter($profiles, static function ($p) { return $p['accepted']; }), 'bytes'))), "$name lightest passing default");
    $matrix[$name] = array('profiles' => $profiles, 'default' => $selected);
}
foreach (array(array('web'=>array('accepted'=>false,'bytes'=>1),'good'=>array('accepted'=>true,'bytes'=>2),'best'=>array('accepted'=>true,'bytes'=>3)), array('web'=>array('accepted'=>false,'bytes'=>1),'good'=>array('accepted'=>false,'bytes'=>2),'best'=>array('accepted'=>true,'bytes'=>3))) as $i => $p) {
    profile_ok(WP_Seed_Pixel_Format_Processor::lightest($p) === ($i === 0 ? 'good' : 'best'), 'dynamic fallback ' . $i);
}
profile_ok(WP_Seed_Pixel_Format_Processor::lightest(array('web'=>array('accepted'=>false,'bytes'=>1))) === null, 'no passing candidate no default');
$id = format_fixture('smooth'); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
$a = WP_Seed_Pixel_Format_Conversion::analyze($id); profile_ok($a, 'profiles analysis');
$b = WP_Seed_Pixel_Format_Conversion::record($id); $hashes = array_column($a['profiles'], 'sha256');
profile_ok(count($a['profiles']) === 3, 'all measured profiles');
$passing = array_keys(array_filter($a['profiles'], static function ($p) { return $p['accepted']; }));
profile_ok(count($passing) >= 2, 'multiple passing synthetic choices');
foreach ($passing as $key) {
    $selected = WP_Seed_Pixel_Format_Conversion::select_profile($id, $a['generation'], $key); profile_ok($selected, 'select ' . $key);
    profile_ok($selected['candidate']['profile'] === $key && $selected['candidate']['sha256'] === $a['profiles'][$key]['sha256'], 'verified cached choice ' . $key);
    profile_ok(array_column($selected['profiles'], 'sha256') === $hashes && WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'no reencode or attachment mutation ' . $key);
}
profile_ok(is_wp_error(WP_Seed_Pixel_Format_Conversion::select_profile($id, $a['generation'], '../escape')), 'unknown profile rejected');
profile_ok(is_wp_error(WP_Seed_Pixel_Format_Conversion::select_profile($id, 'stale', $passing[0])), 'stale selection rejected');
profile_ok(is_wp_error(WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true, false, 'wrong')), 'stale selected profile cannot convert');
$b = WP_Seed_Pixel_Format_Conversion::record($id);
$actual = array_sum(array_map('filesize', glob($b['directory'] . '/*.jpg')));
profile_ok($selected['temporary_bytes'] === $actual && $selected['peak_reserved_bytes'] > $actual, 'multi-profile temporary and peak accounting');
$result = WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true, false, $selected['selected_profile']); profile_ok($result, 'selected conversion');
profile_ok($result['candidate']['profile'] === $selected['selected_profile'] && hash_file('sha256', get_attached_file($id)) === $selected['candidate']['sha256'], 'active profile persisted exactly');
profile_ok(!glob($b['directory'] . '/profile-*.jpg') && $result['temporary_bytes'] === 0, 'all cached profiles cleaned after switch');
profile_ok(!str_contains(WP_Seed_Pixel_Format_Admin::panel($id), 'pixel-format-profiles'), 'result panel keeps passed hierarchy');
profile_ok(WP_Seed_Pixel_Format_Conversion::restore($id, $a['generation']), 'selected profile restore');
profile_ok(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'selected profile exact original restore');
$a = WP_Seed_Pixel_Format_Conversion::analyze($id); profile_ok($a, 'discard preparation');
$b = WP_Seed_Pixel_Format_Conversion::record($id);
profile_ok(WP_Seed_Pixel_Format_Conversion::discard($id, $a['generation']), 'discard profiles');
profile_ok(!glob($b['directory'] . '/*.jpg'), 'discard cleans all profiles');
foreach (array('profile_selection','encoding_intent','candidate_encoded','ready') as $boundary) {
    $target = format_fixture('smooth');
    $a = WP_Seed_Pixel_Format_Conversion::analyze($target); profile_ok($a, "$boundary selection prepared");
    profile_ok($a['selected_profile'] !== 'best', "$boundary selection changes profile");
    list($process, $pipes) = format_worker(array((string) $target, 'select', $boundary));
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    profile_ok(proc_close($process) !== 0 && !$stderr && str_contains($stdout, 'BOUNDARY:' . $boundary), "$boundary selection SIGKILL");
    $resumed = WP_Seed_Pixel_Format_Conversion::analyze($target); profile_ok($resumed, "$boundary selection safe resume");
    profile_ok($resumed['selected_profile'] === 'best' && $resumed['candidate']['sha256'] === $a['profiles']['best']['sha256'], "$boundary selected cached artifact preserved");
    profile_ok(WP_Seed_Pixel_Format_Conversion::discard($target, $a['generation']), "$boundary selection cleanup");
}
$target = format_fixture('noisy'); $a = WP_Seed_Pixel_Format_Conversion::analyze($target);
if (!is_wp_error($a)) {
    foreach ($a['profiles'] as $key => $p) {
        if (!WP_Seed_Pixel_Format_Processor::selectable($p)) { profile_ok(is_wp_error(WP_Seed_Pixel_Format_Conversion::select_profile($target, $a['generation'], $key)), 'insufficient benefit profile unavailable ' . $key); }
    }
    profile_ok(WP_Seed_Pixel_Format_Conversion::discard($target, $a['generation']), 'noisy cleanup');
} else { profile_ok(get_post_mime_type($target) === 'image/png', 'noisy no eligible recommendation preserves PNG'); }
$target = format_fixture('smooth'); $a = WP_Seed_Pixel_Format_Conversion::analyze($target); profile_ok($a, 'profile tamper preparation');
$b = WP_Seed_Pixel_Format_Conversion::record($target); $path = $b['directory'] . '/profile-web.jpg';
rename($path, $path . '.safe'); symlink(get_attached_file($target), $path);
profile_ok(is_wp_error(WP_Seed_Pixel_Format_Conversion::convert($target, $a['generation'], true)), 'foreign cached profile blocks before publication');
profile_ok(get_post_mime_type($target) === 'image/png', 'foreign profile leaves PNG authoritative');
unlink($path); rename($path . '.safe', $path);
profile_ok(WP_Seed_Pixel_Format_Conversion::discard($target, $a['generation']), 'tamper cleanup after identity restored');
$dir = getenv('PIXEL_FORMAT_REPORT_DIR') ?: '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/reports/png-jpeg-profiles-private4';
file_put_contents($dir . '/profiles-' . ($argv[1] ?? 'dev') . '.json', wp_json_encode(array('checks'=>$checks,'count'=>count($checks),'matrix'=>$matrix), JSON_PRETTY_PRINT));
echo count($checks) . " profile checks PASS\n";
