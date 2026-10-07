<?php
$lab = dirname(__DIR__);
if (!str_contains(realpath($lab), 'wp-seed-pixel-m3-environment')) { throw new RuntimeException('Disposable lab required'); }
require $lab . '/.runtime/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_set_current_user(1);
$fixtures = '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/.runtime/format-fixtures';
$checks = array(); $matrix = array();
function fmt_ok($value, $name) {
    global $checks;
    if (!$value || is_wp_error($value)) { throw new RuntimeException($name . (is_wp_error($value) ? ': ' . $value->get_error_code() : '')); }
    $checks[$name] = true;
}
function fmt_upload($path) {
    $u = wp_upload_bits('format-' . bin2hex(random_bytes(8)) . '.png', null, file_get_contents($path));
    fmt_ok(!$u['error'], 'upload ' . basename($path));
    $id = wp_insert_attachment(array('post_title' => 'Synthetic conversion fixture', 'post_mime_type' => 'image/png'), $u['file']);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $u['file']));
    return $id;
}
WP_Seed_Pixel_Job_Store::install();
$lossless = $lab . '/lossless-output.png';
$lp = WP_Seed_Pixel_Master_Processor::create($fixtures . '/lossless.png', $lossless, WP_Seed_Pixel_Policy::normalize());
fmt_ok($lp, 'frozen PNG lossless processor remains available');
fmt_ok(getimagesize($lossless)[2] === IMAGETYPE_PNG, 'lossless PNG remains PNG');
unlink($lossless);
global $wpdb;
$count = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE action='convert'");
fmt_ok(WP_Seed_Pixel_Future_Uploads::configure('process', 268435456, null, array('png')), 'existing future PNG policy configured');
$auto = fmt_upload($fixtures . '/photo.png');
fmt_ok(get_post_mime_type($auto) === 'image/png' && (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE action='convert'") === $count, 'future upload never converts automatically');
wp_clear_scheduled_hook('wp_seed_pixel_future', array($auto));
WP_Seed_Pixel_Future_Uploads::configure('off');
foreach (glob($fixtures . '/*.png') as $p) {
    $name = basename($p, '.png'); $out = $lab . '/' . $name . '-candidate.jpg';
    if ($name === 'lossless') { continue; }
    $result = WP_Seed_Pixel_Format_Processor::create($p, $out);
    $matrix[$name] = is_wp_error($result) ? $result->get_error_code() : $result;
    echo $name . ': ' . (is_wp_error($result) ? $result->get_error_code() : $result['bytes'] . ' bytes Q' . $result['quality']) . "\n";
    if (is_file($out)) { unlink($out); }
    if (in_array($name, array('transparent', 'palette'), true)) { fmt_ok(is_wp_error($result) && $result->get_error_code() === 'TRANSPARENCY_OR_PALETTE', $name . ' preserved'); }
    if ($name === 'icc') { fmt_ok(is_wp_error($result) && $result->get_error_code() === 'TARGET_METADATA_UNSUPPORTED', 'ICC unsupported fails closed'); }
    if (in_array($name, array('logo', 'line', 'small', 'text'), true)) { fmt_ok(is_wp_error($result), $name . ' no recommendation'); }
    if ($name === 'gray') { fmt_ok(!is_wp_error($result) || in_array($result->get_error_code(), array('NO_CONVERSION_BENEFIT', 'QUALITY_REJECTED'), true), 'gray preserved unless meaningful guarded candidate'); }
    if (in_array($name, array('photo', 'large-photo', 'provenance'), true)) { fmt_ok(!is_wp_error($result), $name . ' meaningful guarded candidate'); }
}
$id = fmt_upload($fixtures . '/provenance.png');
update_post_meta($id, '_wp_attachment_image_alt', 'Synthetic alt preserved');
update_post_meta($id, '_synthetic_album', 2023);
$before = WP_Seed_Pixel_Master_Adapter::snapshot($id); fmt_ok($before, 'native snapshot');
$analysis = WP_Seed_Pixel_Format_Conversion::analyze($id); fmt_ok($analysis, 'analysis ready');
fmt_ok(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'analysis no attachment mutation');
$again = WP_Seed_Pixel_Format_Conversion::analyze($id); fmt_ok($again['item_id'] === $analysis['item_id'], 'analysis reuse exact candidate');
fmt_ok(is_wp_error(WP_Seed_Pixel_Format_Conversion::convert($id, $analysis['generation'])), 'explicit approval mandatory');
fmt_ok(is_wp_error(WP_Seed_Pixel_Format_Conversion::convert($id, $analysis['generation'], true, false)), 'provenance acknowledgement mandatory');
$page = wp_insert_post(array('post_title' => 'Synthetic hardcoded reference', 'post_content' => '<img src="' . esc_url($before['url']) . '">', 'post_status' => 'publish'));
$converted = WP_Seed_Pixel_Format_Conversion::convert($id, $analysis['generation'], true, true); fmt_ok($converted, 'conversion committed');
fmt_ok($converted['phase'] === 'retained' && $converted['restore_available'], 'complete restore offered');
fmt_ok($converted['freed_bytes'] === 0 && $converted['recovery_bytes'] > 0 && $converted['compatibility_bytes'] > 0, 'honest retained accounting');
$panel = WP_Seed_Pixel_Format_Admin::panel($id);
fmt_ok(str_contains($panel, 'pixel-format-result') && str_contains($panel, 'pixel-format-provenance'), 'converted comparison and provenance visible');
fmt_ok(str_contains($panel, size_format($before['bytes'], 2)), 'original measured bytes visible');
$record = WP_Seed_Pixel_Format_Conversion::record($id)['record'];
$saved = $before['bytes'] - $record['candidate']['bytes'];
fmt_ok(str_contains($panel, size_format($record['candidate']['bytes'], 2)) && str_contains($panel, size_format($saved, 2) . ' · ' . number_format_i18n(100 * $saved / $before['bytes'], 1) . '%'), 'JPEG bytes and master-only saving measured exactly');
fmt_ok(str_contains($panel, $before['width'] . ' × ' . $before['height']) && str_contains($panel, $record['candidate']['width'] . ' × ' . $record['candidate']['height']), 'both measured dimensions visible');
fmt_ok(get_post_mime_type($id) === 'image/jpeg' && str_ends_with(get_attached_file($id), '.jpg'), 'MIME and attached file JPEG');
$meta = wp_get_attachment_metadata($id);
foreach ($meta['sizes'] as $name => $size) { fmt_ok($size['mime-type'] === 'image/jpeg' && str_ends_with($size['file'], '.jpg'), 'JPEG derivative ' . $name); }
$srcset = wp_get_attachment_image_srcset($id, 'large'); fmt_ok($srcset && !str_contains($srcset, '.png') && str_contains($srcset, '.jpg'), 'coherent JPEG srcset');
fmt_ok(get_post_meta($id, '_wp_attachment_image_alt', true) === 'Synthetic alt preserved' && (int) get_post_meta($id, '_synthetic_album', true) === 2023, 'ID alt membership stable');
foreach ($before['files'] as $relative => $expected) { fmt_ok(hash_file('sha256', wp_upload_dir()['basedir'] . '/' . $relative) === $expected['sha256'], 'old URL exact ' . basename($relative)); }
$duplicate = WP_Seed_Pixel_Format_Conversion::convert($id, $analysis['generation'], true, true); fmt_ok($duplicate['item_id'] === $converted['item_id'], 'duplicate conversion idempotent');
$blocked = WP_Seed_Pixel_Format_Conversion::purge($id, $analysis['generation'], true, true);
fmt_ok(is_wp_error($blocked) && $blocked->get_error_code() === 'OLD_URL_REFERENCED', 'known URL blocks purge');
$restored = WP_Seed_Pixel_Format_Conversion::restore($id, $analysis['generation']); fmt_ok($restored, 'restore');
fmt_ok(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'complete original state restored');
fmt_ok($restored['recovery_bytes'] === 0 && $restored['temporary_bytes'] === 0, 'restore unnecessary copies cleaned');
$panel = WP_Seed_Pixel_Format_Admin::panel($id);
fmt_ok(!str_contains((string) $panel, 'pixel-format-result') && !str_contains((string) $panel, 'pixel-format-provenance'), 'restored panel has no stale JPEG comparison or provenance');
wp_delete_post($page, true);
$next = WP_Seed_Pixel_Format_Conversion::analyze($id); fmt_ok($next, 'new analysis after restore');
$next = WP_Seed_Pixel_Format_Conversion::convert($id, $next['generation'], true, true); fmt_ok($next, 'second conversion');
$purged = WP_Seed_Pixel_Format_Conversion::purge($id, $next['generation'], true, true); fmt_ok($purged, 'explicit final purge');
fmt_ok($purged['recovery_bytes'] === 0 && $purged['compatibility_bytes'] === 0 && $purged['freed_bytes'] > 0 && !$purged['restore_available'], 'purge accounting and restore unavailable');
fmt_ok(is_wp_error(WP_Seed_Pixel_Format_Conversion::restore($id, $next['generation'])), 'purge removes rollback');
$panel = WP_Seed_Pixel_Format_Admin::panel($id);
fmt_ok(str_contains($panel, 'pixel-format-result') && !str_contains($panel, 'data-operation="restore"') && !str_contains($panel, esc_html__('Original retained for restoration', 'wp-seed-pixel')), 'purged comparison truthful with no restore or retained claim');
$discard_id = fmt_upload($fixtures . '/photo.png'); $d = WP_Seed_Pixel_Format_Conversion::analyze($discard_id); fmt_ok($d, 'discard analysis');
$discarded = WP_Seed_Pixel_Format_Conversion::discard($discard_id, $d['generation']); fmt_ok($discarded, 'discard');
fmt_ok($discarded['phase'] === 'discarded' && $discarded['recovery_bytes'] === 0 && $discarded['temporary_bytes'] === 0 && get_post_mime_type($discard_id) === 'image/png', 'discard no source mutation');
$plain = WP_Seed_Pixel_Format_Conversion::analyze($discard_id); fmt_ok($plain, 'plain source reanalysis');
$plain = WP_Seed_Pixel_Format_Conversion::convert($discard_id, $plain['generation'], true, false); fmt_ok($plain, 'plain source conversion');
fmt_ok(!str_contains(WP_Seed_Pixel_Format_Admin::panel($discard_id), 'pixel-format-provenance'), 'no provenance warning for plain PNG');
$charlotte = getenv('PIXEL_PRIVATE_PNG_FIXTURE');
if ($charlotte) {
$private = WP_Seed_Pixel_Format_Processor::create($charlotte, $lab . '/private-candidate.jpg'); fmt_ok($private, 'external Charlotte local regression');
$matrix['external_charlotte'] = $private;
fmt_ok($private['quality'] === 98 && abs($private['bytes'] - 805265) < 8192, 'Charlotte accepted profile retained');
unlink($lab . '/private-candidate.jpg');
}
$manifest = array('schema' => 1, 'slug' => 'wp-seed-pixel', 'channel' => 'private', 'version' => WP_SEED_PIXEL_VERSION, 'requires' => '6.6', 'tested' => get_bloginfo('version'), 'requires_php' => '8.1',
    'package' => 'https://127.0.0.1/wp-seed-pixel.zip', 'sha256' => str_repeat('a', 64), 'released' => '2026-10-07', 'notes_url' => 'https://127.0.0.1/notes.html');
fmt_ok(WP_Seed_Pixel_Updater::validate($manifest, 'https://127.0.0.1/manifest.json') === $manifest, 'updater accepts scoped private development version');
fmt_ok(WP_Seed_Pixel_Updater::validate($manifest, 'http://127.0.0.1/manifest.json') === false, 'updater insecure transport rejected');
$manifest['sha256'] = 'wrong'; fmt_ok(WP_Seed_Pixel_Updater::validate($manifest, 'https://127.0.0.1/manifest.json') === false, 'updater bad checksum rejected');
$dir = getenv('PIXEL_FORMAT_REPORT_DIR') ?: '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/reports/png-jpeg';
if (!is_dir($dir)) { mkdir($dir, 0700, true); }
file_put_contents($dir . '/cycle-' . ($argv[1] ?? 'dev') . '.json', wp_json_encode(array('checks' => $checks, 'matrix' => $matrix, 'wordpress' => get_bloginfo('version'), 'php' => PHP_VERSION, 'count' => count($checks)), JSON_PRETTY_PRINT));
echo count($checks) . " conversion checks PASS\n";
