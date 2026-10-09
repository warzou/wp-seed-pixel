<?php
// Planning assertions only: no WordPress transaction, SQL or graph writer is certified here.
define('ABSPATH', __DIR__ . '/');
class WP_Error {
    private $code;
    public function __construct($code) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_json_encode($v) { return json_encode($v); }
function wp_upload_dir($a = null, $b = false) { return array('basedir' => $GLOBALS['root']); }
class WP_Seed_Pixel_Files {
    public static function path($p) {
        $r = realpath($p);
        return $r && strpos($r, realpath($GLOBALS['root']) . DIRECTORY_SEPARATOR) === 0 && !is_link($p)
            ? $r : new WP_Error('PATH_OUTSIDE_UPLOADS');
    }
}
require dirname(__DIR__) . '/includes/class-metadata.php';
require dirname(__DIR__) . '/includes/class-metadata-public-graph.php';
$root = realpath($argv[1] . '/fixtures'); $GLOBALS['root'] = $root;
if (!$root || basename(realpath($argv[1])) !== 'codex-pixel-060-metadata-audit-20261007') { throw new RuntimeException('Synthetic lab required'); }
$checks = 0;
function check($v, $label) { global $checks; if (!$v) { throw new RuntimeException($label); } $checks++; }
set_error_handler(static function($s, $m, $f, $l) { if (error_reporting() & $s) { throw new ErrorException($m, 0, $s, $f, $l); } });
function file_record($name, $role = 'wp_sizes') {
    global $root;
    return array('sha256' => hash_file('sha256', $root . '/' . $name), 'bytes' => filesize($root . '/' . $name), 'roles' => array($role));
}
function snapshot($names) {
    $files = array();
    foreach ($names as $i => $n) { $files[$n] = file_record($n, $i ? 'wp_sizes' : 'operational'); }
    return array('attachment_id' => 17, 'relative' => $names[0], 'files' => $files);
}
$before_hashes = array();
foreach (glob($root . '/*') as $p) { if (is_file($p)) { $before_hashes[$p] = hash_file('sha256', $p); } }
$s = snapshot(array('jpeg-gps.jpg', 'jpeg-orientation-1.jpg', 'png-text.png', 'jpeg-clean.jpg'));
$plan = WP_Seed_Pixel_Metadata_Public_Graph::plan($s);
check(!is_wp_error($plan) && $plan['admissible'], 'master and two dirty derivatives are a valid plan');
check($plan['modified_files'] === 3 && $plan['clean_files'] === 1, 'three independent B files plus one clean A');
check(!$plan['write_certified'] && !WP_Seed_Pixel_Metadata_Public_Graph::WRITE_CERTIFIED, 'no writer authority granted');
check(!$plan['no_op'], 'dirty graph is not a no-op');
check($plan['manifest']['files']['jpeg-clean.jpg']['classification'] === 'A', 'clean file classification');
check($plan['manifest']['files']['jpeg-clean.jpg']['before_sha256'] === $plan['manifest']['files']['jpeg-clean.jpg']['after_sha256'], 'clean bytes unchanged');
$sum = 0;
foreach ($plan['manifest']['files'] as $entry) {
    check(!isset($entry['data'], $entry['path']), 'no raw data or absolute path in manifest');
    $sum += $entry['removed_bytes'];
    check($entry['after_bytes'] + $entry['removed_bytes'] === $entry['before_bytes'], 'per-file bytes account exactly');
}
check($sum === $plan['metadata_bytes_removed'], 'graph metadata total');
$reordered = $s; $reordered['files'] = array_reverse($reordered['files'], true);
check(WP_Seed_Pixel_Metadata_Public_Graph::plan($reordered)['signature'] === $plan['signature'], 'mapping order does not alter approval');
$legacy = $s; $legacy['files']['jpeg-orientation-1.jpg']['roles'] = array('pixel_history');
$lp = WP_Seed_Pixel_Metadata_Public_Graph::plan($legacy);
check($lp['admissible'] && $lp['manifest']['files']['jpeg-orientation-1.jpg']['classification'] === 'B', 'known dirty legacy copy covered by plan');
check($lp['signature'] !== $plan['signature'], 'role ownership bound into signature');
check(WP_Seed_Pixel_Metadata_Public_Graph::plan(snapshot(array('jpeg-clean.jpg')))['no_op'], 'whole clean graph no-op');
$expected_classes = array('METADATA_ORIENTATION' => 'C', 'METADATA_PROVENANCE' => 'D', 'METADATA_REVIEW' => 'E', 'METADATA_INVALID' => 'F');
$cases = json_decode(file_get_contents($argv[1] . '/cases.json'), true);
foreach ($expected_classes as $code => $class) {
    $case = null; foreach ($cases as $c) { if ($c['error'] === $code) { $case = $c; break; } }
    check($case !== null, 'existing refusal fixture ' . $code);
    $blocked = $s; $blocked['files'][$case['file']] = file_record($case['file'], 'pixel_history');
    $p = WP_Seed_Pixel_Metadata_Public_Graph::plan($blocked);
    check(!is_wp_error($p) && !$p['admissible'] && !$p['no_op'], 'whole graph blocked ' . $code);
    check($p['blockers'][0]['classification'] === $class && $p['blockers'][0]['code'] === $code, 'precise classification ' . $class);
}
$missing = $s; $missing['files']['absent.jpg'] = array('sha256' => str_repeat('a', 64), 'bytes' => 1, 'roles' => array('wp_sizes'));
$p = WP_Seed_Pixel_Metadata_Public_Graph::plan($missing);
check(!$p['admissible'] && $p['blockers'][0]['classification'] === 'G', 'missing public copy blocks plan');
$stale = $s; $stale['files']['jpeg-clean.jpg']['sha256'] = str_repeat('0', 64);
check(WP_Seed_Pixel_Metadata_Public_Graph::plan($stale)->get_error_code() === 'SOURCE_CHANGED', 'stale approval refused');
$stale = $s; $stale['files']['jpeg-clean.jpg']['bytes']++;
check(WP_Seed_Pixel_Metadata_Public_Graph::plan($stale)->get_error_code() === 'SOURCE_CHANGED', 'stale byte inventory refused');
foreach (array('../outside.jpg', '/absolute.jpg', 'C:/absolute.jpg', 'nested/../outside.jpg') as $bad) {
    $invalid = $s; $invalid['files'][$bad] = file_record('jpeg-clean.jpg');
    check(WP_Seed_Pixel_Metadata_Public_Graph::plan($invalid)->get_error_code() === 'INVENTORY_INCOMPLETE', 'unsafe relative path blocked');
}
$ambiguous = $s; $ambiguous['files']['jpeg-clean.jpg']['roles'] = array('unattributed');
check(WP_Seed_Pixel_Metadata_Public_Graph::plan($ambiguous)->get_error_code() === 'INVENTORY_INCOMPLETE', 'unknown ownership blocks');
$empty = $s; $empty['files'] = array();
check(WP_Seed_Pixel_Metadata_Public_Graph::plan($empty)->get_error_code() === 'INVENTORY_INCOMPLETE', 'empty graph blocked');
$malformed = $s; $malformed['files']['jpeg-clean.jpg']['sha256'] = array('invalid');
check(WP_Seed_Pixel_Metadata_Public_Graph::plan($malformed)->get_error_code() === 'INVENTORY_INCOMPLETE', 'malformed hash type refused');
$malformed = $s; $malformed['files']['jpeg-clean.jpg']['roles'] = array(array('invalid'));
check(WP_Seed_Pixel_Metadata_Public_Graph::plan($malformed)->get_error_code() === 'INVENTORY_INCOMPLETE', 'malformed role type refused');
$over = $s; $over['files']['jpeg-clean.jpg']['bytes'] = WP_Seed_Pixel_Metadata_Public_Graph::MAX_BYTES;
check(WP_Seed_Pixel_Metadata_Public_Graph::plan($over)->get_error_code() === 'METADATA_LIMIT', 'bounded whole graph byte budget');
// The existing deployed admission must not be silently weakened by a read-only planner.
check(WP_Seed_Pixel_Metadata::graph($s)->get_error_code() === 'METADATA_PUBLIC_COPY', 'private.2 admission remains fail-closed');
foreach ($before_hashes as $p => $hash) { check(hash_file('sha256', $p) === $hash, 'fixture unchanged ' . basename($p)); }
file_put_contents($argv[1] . '/outputs/graph-plan-checks.json', json_encode(array('checks' => $checks, 'write_certified' => false, 'plan' => $plan), JSON_PRETTY_PRINT));
echo "$checks public graph planning assertions PASS; writer NOT CERTIFIED\n";
