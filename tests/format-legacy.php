<?php
require __DIR__ . '/format-bootstrap.php';
$id = format_fixture();
$before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
$a = WP_Seed_Pixel_Format_Conversion::analyze($id);
if (is_wp_error($a)) { throw new RuntimeException($a->get_error_code()); }
$converted = WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true);
if (is_wp_error($converted)) { throw new RuntimeException($converted->get_error_code()); }
$global = WP_Seed_Pixel_Authority::acquire(0); $owner = WP_Seed_Pixel_Authority::acquire($id);
if (is_wp_error($global) || is_wp_error($owner)) { throw new RuntimeException('Authority required'); }
try {
    $b = WP_Seed_Pixel_Format_Conversion::record($id); $r = $b['record'];
    unset($r['profiles'], $r['profile_slots'], $r['selected_profile'], $r['candidate']['profile'], $r['candidate']['accepted'], $r['candidate']['rejection']);
    unset($r['candidate']['selectable'], $r['candidate']['quality_passed'], $r['approved']['profile'], $r['approved']['quality'], $r['approved']['quality_passed'], $r['approved']['quality_override']);
    $saved = WP_Seed_Pixel_Master_Storage::save($b['directory'], $r);
    if (is_wp_error($saved)) { throw new RuntimeException($saved->get_error_code()); }
} finally { WP_Seed_Pixel_Authority::release($owner); WP_Seed_Pixel_Authority::release($global); }
$panel = WP_Seed_Pixel_Format_Admin::panel($id);
if (!str_contains($panel, 'pixel-format-result') || str_contains($panel,'pixel-format-profiles')) { throw new RuntimeException('Legacy panel changed'); }
$duplicate = WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true);
if (is_wp_error($duplicate) || !$duplicate['restore_available']) { throw new RuntimeException('Legacy retained state incompatible'); }
$restored = WP_Seed_Pixel_Format_Conversion::restore($id, $a['generation']);
if (is_wp_error($restored) || WP_Seed_Pixel_Master_Adapter::snapshot($id) !== $before) { throw new RuntimeException('Legacy exact restore failed'); }
$out = getenv('PIXEL_FORMAT_REPORT_DIR');
file_put_contents($out . '/legacy-' . ($argv[1] ?? 'dev') . '.json', wp_json_encode(array('count'=>3,'panel'=>true,'retained'=>true,'restore'=>true)));
echo "3 legacy journal checks PASS\n";
