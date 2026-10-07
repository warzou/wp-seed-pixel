<?php
require __DIR__ . '/format-bootstrap.php';
$checks = array();
function ineligible_ok($condition, $label) { global $checks; if (!$condition || is_wp_error($condition)) { throw new RuntimeException($label . (is_wp_error($condition) ? ': ' . $condition->get_error_code() : '')); } $checks[$label] = true; }
foreach (array('web', 'good') as $key) {
    $id = format_fixture('noisy'); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
    $a = WP_Seed_Pixel_Format_Conversion::analyze($id); ineligible_ok($a, "$key no passing analysis retained");
    ineligible_ok(WP_Seed_Pixel_Format_Processor::lightest($a['profiles']) === null && $a['selected_profile'] === null && $a['candidate'] === null, "$key no silent failing default");
    $panel = WP_Seed_Pixel_Format_Admin::panel($id);
    ineligible_ok(!str_contains($panel, 'data-operation="convert"') && !str_contains($panel, 'checked=') && !str_contains($panel, ' - recommended'), "$key neutral panel without conversion");
    ineligible_ok(is_wp_error(WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true, false, null, true)), "$key cannot convert before selecting");
    $selected = WP_Seed_Pixel_Format_Conversion::select_profile($id, $a['generation'], $key); ineligible_ok($selected, "$key failing choice selectable");
    ineligible_ok(!$selected['candidate']['quality_passed'] && $selected['candidate']['quality'] === ($key === 'web' ? 90 : 94), "$key guard and actual quality recorded");
    $panel = WP_Seed_Pixel_Format_Admin::panel($id);
    ineligible_ok(str_contains($panel, 'pixel-format-quality-warning') && str_contains($panel, 'data-format-approval="quality_override"') && str_contains($panel, 'pixel-format-comparison" open'), "$key warning acknowledgement and open comparison");
    $b = WP_Seed_Pixel_Format_Conversion::record($id); $record = $b['record'];
    $rejected = WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true, false, $key);
    ineligible_ok(is_wp_error($rejected) && $rejected->get_error_code() === 'QUALITY_CONFIRMATION_REQUIRED', "$key server requires extra acknowledgement");
    ineligible_ok(WP_Seed_Pixel_Format_Conversion::record($id)['record'] === $record && WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, "$key cancelled override preserves PNG and evidence");
    wp_set_current_user(0);
    ineligible_ok(is_wp_error(WP_Seed_Pixel_Format_Conversion::select_profile($id, $a['generation'], $key)), "$key anonymous choice denied");
    wp_set_current_user(1);
    $result = WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true, false, $key, true); ineligible_ok($result, "$key explicit override conversion");
    $r = WP_Seed_Pixel_Format_Conversion::record($id)['record'];
    ineligible_ok($r['approved']['quality_override'] === true && $r['approved']['quality_passed'] === false && $r['approved']['profile'] === $key && $r['approved']['quality'] === $selected['candidate']['quality'], "$key durable override evidence");
    ineligible_ok(hash_file('sha256', get_attached_file($id)) === $a['profiles'][$key]['sha256'] && $result['temporary_bytes'] === 0 && !glob($b['directory'] . '/profile-*.jpg'), "$key exact candidate and cleanup");
    ineligible_ok(WP_Seed_Pixel_Format_Conversion::resume($id, $a['generation']), "$key recorded acknowledgement reused for idempotent resume");
    ineligible_ok(WP_Seed_Pixel_Format_Conversion::restore($id, $a['generation']), "$key restore override");
    ineligible_ok(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, "$key exact PNG restored");
}
$id = format_fixture('photo'); $a = WP_Seed_Pixel_Format_Conversion::analyze($id); ineligible_ok($a, 'quality-passing default analysis');
ineligible_ok($a['selected_profile'] === 'good' && !str_contains(WP_Seed_Pixel_Format_Admin::panel($id), 'data-format-approval="quality_override"'), 'passing selection needs no extra acknowledgement');
ineligible_ok(WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true, false, 'good', true), 'passing conversion tolerates unused override flag');
ineligible_ok(WP_Seed_Pixel_Format_Conversion::record($id)['record']['approved']['quality_override'] === false, 'passing candidate never recorded as override');
ineligible_ok(WP_Seed_Pixel_Format_Conversion::restore($id, $a['generation']), 'passing restore');
foreach (array('publish_intent', 'database_switched', 'profile_cleanup') as $boundary) {
    $id = format_fixture('photo'); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
    $a = WP_Seed_Pixel_Format_Conversion::analyze($id);
    ineligible_ok(WP_Seed_Pixel_Format_Conversion::select_profile($id, $a['generation'], 'web'), "$boundary failing choice prepared");
    list($worker, $pipes) = format_worker(array((string) $id, 'override', $boundary));
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    ineligible_ok(proc_close($worker) !== 0 && !$err && str_contains($out, 'BOUNDARY:' . $boundary), "$boundary override SIGKILL");
    clean_post_cache($id); wp_cache_delete($id, 'post_meta');
    ineligible_ok(WP_Seed_Pixel_Format_Conversion::resume($id, $a['generation']), "$boundary resume preserves explicit acknowledgement");
    ineligible_ok(WP_Seed_Pixel_Format_Conversion::restore($id, $a['generation']) && WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, "$boundary exact override restoration");
}
$id = format_fixture('photo'); $a = WP_Seed_Pixel_Format_Conversion::analyze($id);
$global = WP_Seed_Pixel_Authority::acquire(0); $owner = WP_Seed_Pixel_Authority::acquire($id);
try {
    $b = WP_Seed_Pixel_Format_Conversion::record($id); $r = $b['record'];
    foreach ($r['profiles'] as &$p) { unset($p['selectable'], $p['quality_passed']); } unset($p);
    ineligible_ok(WP_Seed_Pixel_Master_Storage::save($b['directory'], $r), 'private4 profile journal simulated');
} finally { WP_Seed_Pixel_Authority::release($owner); WP_Seed_Pixel_Authority::release($global); }
ineligible_ok(WP_Seed_Pixel_Format_Conversion::select_profile($id, $a['generation'], 'web'), 'private4 safe quality-failing choice supported');
ineligible_ok(WP_Seed_Pixel_Format_Conversion::discard($id, $a['generation']), 'private4 comparison safely discarded');
file_put_contents(getenv('PIXEL_FORMAT_REPORT_DIR') . '/ineligible-' . ($argv[1] ?? 'dev') . '.json', wp_json_encode(array('checks'=>$checks,'count'=>count($checks))));
echo count($checks) . " profile policy checks PASS\n";
