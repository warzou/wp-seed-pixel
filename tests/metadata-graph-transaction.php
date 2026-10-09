<?php
// Real filesystem/filter checks with explicit authority/WordPress doubles, not SQL certification.
define('ABSPATH', __DIR__ . '/');
define('WP_SEED_PIXEL_METADATA_GRAPH_TESTING', true);
class WP_Error {
    private $code;
    public function __construct($code) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_json_encode($v) { return json_encode($v); }
function wp_get_environment_type() { return 'local'; }
function home_url() { return 'http://localhost'; }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function wp_upload_dir($a = null, $b = false) { return array('basedir' => $GLOBALS['root']); }
function do_action($hook, $name, $id) {
    if ($hook === 'wp_seed_pixel_metadata_graph_boundary' && $name === ($GLOBALS['crash'] ?? '')) { throw new RuntimeException('Injected interruption'); }
}
class WP_Seed_Pixel_Authority {
    public static function valid($id) { return $GLOBALS['authority']; }
    public static function valid_all() { return $GLOBALS['authority']; }
}
class WP_Seed_Pixel_Policy {
    public static function hash($p) { return hash('sha256', json_encode($p)); }
}
class WP_Seed_Pixel_Storage_Budget {
    public static function admit($bytes, $dir) { return true; }
}
class WP_Seed_Pixel_Files {
    public static function path($p) {
        $r = realpath($p);
        return $r && strpos($r, realpath($GLOBALS['root']) . DIRECTORY_SEPARATOR) === 0 && !is_link($p)
            ? $r : new WP_Error('PATH_OUTSIDE_UPLOADS');
    }
}
class WP_Seed_Pixel_Master_Storage {
    public static function enabled() { return true; }
    public static function directory($item, $create = true) {
        $dir = $GLOBALS['recovery'];
        if (!is_dir($dir) && $create) { mkdir($dir, 0700); }
        return is_dir($dir) ? $dir : new WP_Error('RECOVERY_REQUIRED');
    }
    public static function save($dir, $r) {
        file_put_contents($dir . '/journal.next', serialize($r));
        rename($dir . '/journal.next', $dir . '/journal');
        return unserialize(file_get_contents($dir . '/journal'));
    }
    public static function load($dir, $item) { return file_exists($dir . '/journal') ? unserialize(file_get_contents($dir . '/journal')) : null; }
    public static function sync_directory($dir) { return true; }
}
class WP_Seed_Pixel_Master_Adapter {
    public static function snapshot($id, $transitional = false) {
        $s = $GLOBALS['before'];
        foreach ($s['files'] as $relative => &$f) {
            $p = $GLOBALS['root'] . '/' . $relative;
            if (!is_file($p)) { return new WP_Error('SOURCE_MISSING'); }
            clearstatcache(true, $p); $f['bytes'] = filesize($p); $f['sha256'] = hash_file('sha256', $p);
        }
        unset($f);
        return $s;
    }
}
require dirname(__DIR__) . '/includes/class-metadata.php';
require dirname(__DIR__) . '/includes/class-metadata-public-graph.php';
require dirname(__DIR__) . '/includes/class-metadata-graph-transaction.php';
$lab = realpath($argv[1]);
if (!$lab || basename($lab) !== 'wp-seed-pixel-060-metadata-lab' || !is_dir($lab . '/fixtures')) { throw new RuntimeException('Owned synthetic lab required'); }
$run = $argv[2] ?? 'initial';
if (!preg_match('/^[a-z0-9-]{1,32}$/D', $run) || file_exists($lab . '/graph-run-' . $run)) { throw new RuntimeException('Fresh bounded run required'); }
mkdir($lab . '/graph-run-' . $run, 0700);
$checks = 0; $cases = 0; $GLOBALS['authority'] = true;
function check($v, $label) { global $checks; if (!$v) { throw new RuntimeException($label); } $checks++; }
set_error_handler(static function($s, $m, $f, $l) { if (error_reporting() & $s) { throw new ErrorException($m, 0, $s, $f, $l); } });
$policy = array('intent' => array('metadata' => 'anonymize'), 'capacity_bytes' => 100000000);
function setup_case(array $names) {
    global $lab, $cases, $run;
    $base = $lab . '/graph-run-' . $run . '/case-' . (++$cases);
    mkdir($base, 0700); mkdir($base . '/uploads', 0700);
    $GLOBALS['root'] = $base . '/uploads'; $GLOBALS['recovery'] = $base . '/recovery'; $GLOBALS['crash'] = '';
    $files = array();
    foreach ($names as $i => $name) {
        copy($lab . '/fixtures/' . $name, $GLOBALS['root'] . '/' . $name);
        $files[$name] = array('sha256' => hash_file('sha256', $GLOBALS['root'] . '/' . $name), 'bytes' => filesize($GLOBALS['root'] . '/' . $name), 'roles' => array($i ? 'wp_sizes' : 'operational'));
    }
    ksort($files);
    $GLOBALS['before'] = array('attachment_id' => 17, 'relative' => $names[0], 'files' => $files, 'rows' => array('editorial' => 'untouched'), 'url' => 'http://localhost/image', 'guid' => 'http://localhost/image');
    $plan = WP_Seed_Pixel_Metadata_Public_Graph::plan($GLOBALS['before']);
    return array('id' => $cases, 'job_id' => $cases, 'attachment_id' => 17, 'data' => json_encode(array('before' => $GLOBALS['before'], 'metadata_graph' => $plan)));
}
function assert_original(array $item) {
    $b = json_decode($item['data'], true)['before'];
    foreach ($b['files'] as $n => $f) { check(hash_file('sha256', $GLOBALS['root'] . '/' . $n) === $f['sha256'], 'exact restored hash ' . $n); }
}
function assert_clean(array $item) {
    $p = json_decode($item['data'], true)['metadata_graph'];
    foreach ($p['manifest']['files'] as $n => $e) {
        $v = WP_Seed_Pixel_Metadata::read($GLOBALS['root'] . '/' . $n);
        check(!is_wp_error($v) && !$v['categories'], 'whole graph clean ' . $n);
        check($v['image_sha256'] === $e['image_sha256'] && $v['color_sha256'] === $e['color_sha256'], 'payload and color identical ' . $n);
    }
}
$graphs = array(
    array('jpeg-clean.jpg', 'jpeg-gps.jpg', 'png-text.png'),
    array('jpeg-gps.jpg', 'jpeg-clean.jpg', 'png-clean.png'),
    array('jpeg-gps.jpg', 'png-text.png', 'jpeg-clean.jpg', 'jpeg-orientation-1.jpg')
);
foreach ($graphs as $names) {
    $item = setup_case($names); $r = WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $policy);
    check(!is_wp_error($r) && $r['phase'] === 'graph_prepared', 'all candidates prepared');
    $plan = json_decode($item['data'], true)['metadata_graph'];
    check(count(glob($GLOBALS['recovery'] . '/graph-original-*')) === $plan['modified_files'], 'only dirty originals recovered');
    foreach ($r['graph_files'] as $f) { check(hash_file('sha256', $GLOBALS['recovery'] . '/' . $f['original']) === $f['entry']['before_sha256'], 'recovery exact'); }
    assert_original($item);
    $r = WP_Seed_Pixel_Metadata_Graph_Transaction::switch_graph($item, $policy);
    check(!is_wp_error($r) && $r['phase'] === 'graph_committed', 'whole graph committed'); assert_clean($item);
    check(!is_wp_error(WP_Seed_Pixel_Metadata_Graph_Transaction::reconcile($item)), 'committed reconcile');
    $r = WP_Seed_Pixel_Metadata_Graph_Transaction::restore($item);
    check(!is_wp_error($r) && $r['phase'] === 'graph_restored', 'whole graph restore'); assert_original($item);
    check(WP_Seed_Pixel_Metadata_Graph_Transaction::reconcile($item)['phase'] === 'graph_restored', 'restored reconcile idempotent');
}
$boundaries = array('after_plan', 'after_candidates', 'after_recovery', 'after_prepared', 'after_rename_1', 'after_file_1', 'after_file_2', 'after_file_3', 'before_verify', 'before_commit', 'after_commit');
foreach ($boundaries as $boundary) {
    $item = setup_case($graphs[2]); $GLOBALS['crash'] = $boundary;
    $interrupted = false;
    try {
        $r = WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $policy);
        if (!is_wp_error($r)) { WP_Seed_Pixel_Metadata_Graph_Transaction::switch_graph($item, $policy); }
    } catch (RuntimeException $e) { $interrupted = $e->getMessage() === 'Injected interruption'; }
    check($interrupted, 'boundary reached ' . $boundary); $GLOBALS['crash'] = '';
    $r = WP_Seed_Pixel_Metadata_Graph_Transaction::reconcile($item); check(!is_wp_error($r), 'first reconciliation ' . $boundary);
    $second = WP_Seed_Pixel_Metadata_Graph_Transaction::reconcile($item);
    check($r === $second, 'second reconciliation no-op ' . $boundary);
    if ($boundary === 'after_commit') { assert_clean($item); } else { assert_original($item); }
}
foreach (array('after_restore_1', 'after_restore_2') as $boundary) {
    $item = setup_case($graphs[2]); WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $policy); WP_Seed_Pixel_Metadata_Graph_Transaction::switch_graph($item, $policy);
    $GLOBALS['crash'] = $boundary; $interrupted = false;
    try { WP_Seed_Pixel_Metadata_Graph_Transaction::restore($item); } catch (RuntimeException $e) { $interrupted = true; }
    check($interrupted, 'restore interruption reached'); $GLOBALS['crash'] = '';
    $r = WP_Seed_Pixel_Metadata_Graph_Transaction::reconcile($item); check(!is_wp_error($r), 'restore resumed'); assert_original($item);
    check(WP_Seed_Pixel_Metadata_Graph_Transaction::reconcile($item) === $r, 'second restore no-op');
}
foreach (array('changed', 'missing') as $mode) {
    $item = setup_case($graphs[0]); WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $policy);
    $target = $GLOBALS['root'] . '/jpeg-gps.jpg';
    if ($mode === 'changed') { file_put_contents($target, 'changed synthetic bytes'); } else { rename($target, $target . '.missing'); }
    $r = WP_Seed_Pixel_Metadata_Graph_Transaction::switch_graph($item, $policy);
    check(is_wp_error($r) && $r->get_error_code() === 'METADATA_GRAPH_CHANGED', 'freshness abort ' . $mode);
    check(hash_file('sha256', $GLOBALS['root'] . '/png-text.png') === $GLOBALS['before']['files']['png-text.png']['sha256'], 'other dirty file untouched');
}
$item = setup_case($graphs[0]); $data = json_decode($item['data'], true); $data['metadata_graph']['modified_files']++; $item['data'] = json_encode($data);
check(WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $policy)->get_error_code() === 'EVIDENCE_INVALID', 'inconsistent counters refused');
$item = setup_case($graphs[0]); $small = $policy; $small['capacity_bytes'] = 1;
check(WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $small)->get_error_code() === 'QUOTA_UNKNOWN', 'insufficient declared capacity refuses before switch'); assert_original($item);
foreach (array('foreign-public', 'corrupt-recovery') as $failure) {
    $item = setup_case($graphs[2]); WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $policy); WP_Seed_Pixel_Metadata_Graph_Transaction::switch_graph($item, $policy);
    $r = WP_Seed_Pixel_Master_Storage::load($GLOBALS['recovery'], $item); $key = array_key_first($r['graph_files']);
    $target = $failure === 'foreign-public' ? $GLOBALS['root'] . '/' . $key : $GLOBALS['recovery'] . '/' . $r['graph_files'][$key]['original'];
    file_put_contents($target, 'foreign synthetic bytes'); $hash = hash_file('sha256', $target);
    $v = WP_Seed_Pixel_Metadata_Graph_Transaction::restore($item);
    check(is_wp_error($v) && $v->get_error_code() === 'RECOVERY_REQUIRED', 'failed rollback refuses success ' . $failure);
    check(WP_Seed_Pixel_Master_Storage::load($GLOBALS['recovery'], $item)['phase'] === 'graph_needs_review', 'failed rollback retains repair state');
    check(hash_file('sha256', $target) === $hash, 'foreign evidence not overwritten');
    check(count(glob($GLOBALS['recovery'] . '/graph-original-*')) === 3, 'all recovery evidence retained');
    check(WP_Seed_Pixel_Metadata_Graph_Transaction::reconcile($item)->get_error_code() === 'RECOVERY_REQUIRED', 'review reconciliation refuses automatic overwrite');
}
// A fresh operation after an exact restore has a distinct recovery directory.
$item = setup_case($graphs[2]); WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $policy); WP_Seed_Pixel_Metadata_Graph_Transaction::switch_graph($item, $policy); WP_Seed_Pixel_Metadata_Graph_Transaction::restore($item);
$old = $GLOBALS['recovery']; $GLOBALS['recovery'] .= '-again'; $again = $item; $again['id'] += 1000; $again['job_id'] += 1000;
check(!is_wp_error(WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($again, $policy)), 'restored graph may prepare a fresh operation');
check(!is_wp_error(WP_Seed_Pixel_Metadata_Graph_Transaction::switch_graph($again, $policy)), 'restored graph may anonymize again'); assert_clean($again);
check(is_file($old . '/journal'), 'prior recovery authority retained independently');
$item = setup_case($graphs[0]); WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $policy);
$r = WP_Seed_Pixel_Master_Storage::load($GLOBALS['recovery'], $item); $key = array_key_first($r['graph_files']); $r['graph_files'][$key]['original'] = '../foreign'; WP_Seed_Pixel_Master_Storage::save($GLOBALS['recovery'], $r);
check(WP_Seed_Pixel_Metadata_Graph_Transaction::switch_graph($item, $policy)->get_error_code() === 'EVIDENCE_INVALID', 'recovery manifest path tamper refused'); assert_original($item);
$item = setup_case($graphs[0]); $GLOBALS['authority'] = false;
check(WP_Seed_Pixel_Metadata_Graph_Transaction::prepare($item, $policy)->get_error_code() === 'METADATA_CERTIFICATION_REQUIRED', 'authority required'); assert_original($item);
check(WP_Seed_Pixel_Metadata_Graph_Transaction::WRITE_CERTIFIED, 'integrated candidate writer enabled; artefact still subject to final gates');
$result = array('checks' => $checks, 'cases' => $cases, 'crash_boundaries' => $boundaries, 'scope' => 'filesystem/filter with authority and WordPress doubles; NOT SQL/native/UI certification');
file_put_contents($lab . '/evidence/graph-transaction-unit-' . $run . '.json', json_encode($result, JSON_PRETTY_PRINT));
echo "$checks graph transaction filesystem assertions PASS; SQL/native/UI NOT CERTIFIED\n";
