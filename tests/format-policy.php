<?php
require __DIR__ . '/format-bootstrap.php';
$checks = array(); $measured = array();
function policy_ok($condition, $label) { global $checks; if (!$condition || is_wp_error($condition)) { throw new RuntimeException($label); } $checks[$label] = true; }
// External regression bytes never enter Git or the plugin package.
$source = getenv('PIXEL_PRIVATE_PNG_FIXTURE');
if (!$source || !is_file($source)) { throw new RuntimeException('External profile regression fixture required'); }
foreach (WP_Seed_Pixel_Format_Processor::profiles() as $key => $definition) {
    $target = $lab . '/external-policy-' . $key . '.jpg';
    try {
        $candidate = WP_Seed_Pixel_Format_Processor::create($source, $target, null, $key);
        policy_ok($candidate, "$key external candidate valid");
        policy_ok($candidate['quality'] === $definition['quality'] && $candidate['selectable'], "$key safe manual choice with exact quality");
        policy_ok($candidate['quality_passed'] === ($key === 'best') && $candidate['accepted'] === ($key === 'best'), "$key quality floor unchanged");
        $measured[$key] = $candidate;
    } finally { if (is_file($target)) { unlink($target); } }
}
policy_ok(WP_Seed_Pixel_Format_Processor::lightest($measured) === 'best', 'only Q98 passes recommended default');
policy_ok(array_column($measured, 'bytes') === array(363563, 480781, 805265), 'exact historical three profile byte sizes');
file_put_contents(getenv('PIXEL_FORMAT_REPORT_DIR') . '/policy-' . ($argv[1] ?? 'dev') . '.json', wp_json_encode(array('checks'=>$checks,'count'=>count($checks),'profiles'=>$measured), JSON_PRETTY_PRINT));
echo count($checks) . " external profile policy checks PASS\n";
