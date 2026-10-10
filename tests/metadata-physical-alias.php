<?php
// Real Analyzer/parser/adapter; SQL is a deterministic CAS double, not an InnoDB certification.
define('ABSPATH', __DIR__ . '/'); define('ARRAY_A', 'ARRAY_A');
class WP_Error {
    private $code;
    public function __construct($code, $message = '') { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
function is_wp_error($v) { return $v instanceof WP_Error; }
function wp_normalize_path($v) { return str_replace('\\', '/', $v); }
function wp_upload_dir($a = null, $b = false) { return array('basedir' => $GLOBALS['root']); }
function wp_json_encode($v) { return json_encode($v); }
function maybe_serialize($v) { return is_array($v) ? serialize($v) : $v; }
function maybe_unserialize($v) { return is_string($v) && preg_match('/^[aObisdN]:/', $v) ? unserialize($v, array('allowed_classes' => false)) : $v; }
function get_post($id) { return (object) array('post_type' => 'attachment', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit', 'guid' => 'http://example.test/master.jpg'); }
function current_user_can($a, $id = null) { return true; }
function is_multisite() { return false; }
function get_post_meta($id, $key, $single = true) {
    $values = array_column($GLOBALS['wpdb']->rows[$key] ?? array(), 'meta_value');
    return $single ? maybe_unserialize($values[0] ?? '') : array_map('maybe_unserialize', $values);
}
function get_attached_file($id, $raw = false) { return $GLOBALS['root'] . '/' . get_post_meta($id, '_wp_attached_file'); }
function wp_get_attachment_metadata($id) { return get_post_meta($id, '_wp_attachment_metadata'); }
function get_post_mime_type($id) { return 'image/jpeg'; }
function get_the_title($id) { return 'Synthetic alias'; }
function wp_get_attachment_url($id) { return 'http://example.test/' . get_post_meta($id, '_wp_attached_file'); }
function wp_get_attachment_image_url($id, $size) { return wp_get_attachment_url($id); }
function wp_parse_url($url, $component) { return parse_url($url, $component); }
function home_url() { return 'http://example.test'; }
function apply_filters($name, $v, $id = null) { return $v; }
function wp_cache_delete($id, $group) {}
function clean_post_cache($id) {}
class WP_Seed_Pixel_Color { public static function available() { return false; } }
class WP_Seed_Pixel_Job_Store { public static function table($name) { return 'pixel_' . $name; } }
class WP_Seed_Pixel_Authority {
    public static $owned = true;
    public static function valid($id) { return self::$owned; }
    public static function valid_all() { return self::$owned; }
}
class Alias_DB {
    public $postmeta = 'postmeta'; public $posts = 'posts'; public $last_error = '';
    public $rows = array(); public $fail_key = ''; public $shared = false; private $backup;
    public function prepare($query, ...$args) { return array($query, $args); }
    public function esc_like($v) { return $v; }
    public function get_row($q, $format) { return array('Engine' => 'InnoDB'); }
    public function get_col($q) {
        return strpos($q[0], 'SELECT meta_value') === 0 ? array_column($this->rows[$q[1][1]] ?? array(), 'meta_value') : array();
    }
    public function get_var($q) { return $this->shared ? 99 : null; }
    public function get_results($q, $format) { return $this->rows[$q[1][1]] ?? array(); }
    public function query($q) {
        if ($q === 'START TRANSACTION') { $this->backup = $this->rows; }
        if ($q === 'ROLLBACK') { $this->rows = $this->backup; }
        return 1;
    }
    public function update($table, $data, $where) {
        foreach ($this->rows as $key => &$rows) {
            foreach ($rows as &$row) {
                if ($row['meta_id'] === $where['meta_id'] && $row['meta_value'] === $where['meta_value']) {
                    if ($this->fail_key === $key) { return false; }
                    $row['meta_value'] = $data['meta_value']; return 1;
                }
            }
        }
        return 0;
    }
    public function insert($table, $data) { $this->rows[$data['meta_key']] = array(array('meta_id' => 999, 'meta_value' => $data['meta_value'])); return 1; }
    public function delete($table, $where) {
        foreach ($this->rows as &$rows) { if (($rows[0]['meta_id'] ?? null) === $where['meta_id']) { $rows = array(); return 1; } }
        return 0;
    }
    public function seed($key, $value) { $this->rows[$key] = array(array('meta_id' => count($this->rows) + 1, 'meta_value' => maybe_serialize($value))); }
}
foreach (array('files', 'analyzer', 'metadata', 'metadata-public-graph', 'master-adapter', 'metadata-graph-transaction') as $class) { require dirname(__DIR__) . '/includes/class-' . $class . '.php'; }
$lab = realpath($argv[1]);
if (!$lab || basename($lab) !== 'codex-pixel-060-metadata-audit-20261007') { throw new RuntimeException('Synthetic laboratory required'); }
$case_root = wp_normalize_path($lab . '/alias-cases/run-' . bin2hex(random_bytes(5)));
mkdir($case_root, 0700, true); $checks = array();
function check($v, $label) { global $checks; if (is_wp_error($v) || !$v) { throw new RuntimeException($label . (is_wp_error($v) ? ': ' . $v->get_error_code() : '')); } $checks[] = $label; }
function error_is($v, $code) { return is_wp_error($v) && $v->get_error_code() === $code; }
set_error_handler(static function($s, $m, $f, $l) { if (error_reporting() & $s) { throw new ErrorException($m, 0, $s, $f, $l); } });
function fixture($name, $alias, $clean = false) {
    global $root, $lab, $wpdb, $case_root;
    $root = $case_root . '/' . $name; mkdir($root);
    $wpdb = new Alias_DB(); $source = file_get_contents($lab . '/fixtures/' . ($clean ? 'jpeg-clean.jpg' : 'jpeg-comment.jpg'));
    file_put_contents($root . '/master.jpg', $source);
    $info = getimagesize($root . '/master.jpg');
    $files = array(); $sizes = array();
    foreach (array('thumb', 'view', 'medium') as $role) {
        $file = $alias && $role === 'view' ? 'master.jpg' : $role . '.jpg';
        if ($file !== 'master.jpg') { file_put_contents($root . '/' . $file, $source); }
        $files[$role] = array('kind' => $file === 'master.jpg' ? 'master' : 'derived', 'path' => $root . '/' . $file,
            'sha256' => hash_file('sha256', $root . '/' . $file), 'bytes' => strlen($source), 'width' => $info[0], 'height' => $info[1]);
        $sizes['pixel-' . $role] = array('file' => $file, 'width' => $info[0], 'height' => $info[1], 'filesize' => strlen($source));
    }
    $wpdb->seed('_wp_attached_file', 'master.jpg');
    $wpdb->seed('_wp_attachment_metadata', array('file' => 'master.jpg', 'width' => $info[0], 'height' => $info[1], 'filesize' => strlen($source), 'sizes' => $sizes));
    $manifest = array('attachment_id' => 17, 'generation' => 'synthetic', 'master_sha256' => hash('sha256', $source), 'master_bytes' => strlen($source), 'files' => $files);
    $wpdb->seed('_seed_pixel_manifest', $manifest); $wpdb->seed('_seed_pixel_history', array($manifest));
    return $source;
}
foreach (array('alias' => true, 'distinct' => false) as $name => $alias) {
    $source = fixture($name, $alias);
    $legacy = WP_Seed_Pixel_Master_Adapter::snapshot(17);
    check($alias ? error_is($legacy, 'NEEDS_REVIEW') : !is_wp_error($legacy), 'replacement guard preserved ' . $name);
    $before = WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true); check($before, 'metadata snapshot ' . $name);
    check(count($before['files']) === ($alias ? 3 : 4), 'unique physical inventory ' . $name);
    check(in_array('pixel_current', $before['files']['master.jpg']['roles'], true) === $alias, 'all master roles retained ' . $name);
    $plan = WP_Seed_Pixel_Metadata_Public_Graph::plan($before); check($plan['admissible'], 'plan admissible ' . $name);
    check($plan['modified_files'] === count($before['files']), 'one candidate per physical file ' . $name);
    $references = WP_Seed_Pixel_Master_Adapter::graph_reference_rows($before, $plan['manifest']); check($references, 'deterministic SQL witnesses ' . $name);
    $after = maybe_unserialize($before['rows']['_wp_attachment_metadata'][0]);
    foreach ($after['sizes'] as &$size) { $size['filesize'] = $plan['manifest']['files'][$size['file']]['after_bytes']; } unset($size);
    $after['filesize'] = $plan['manifest']['files']['master.jpg']['after_bytes'];
    $record = array('before' => $before, 'graph_version' => 'metadata-graph-transaction-1', 'graph_plan' => $plan['manifest'], 'after' => $after,
        'candidate' => array('sha256' => $plan['manifest']['files']['master.jpg']['after_sha256']), 'witness' => array('synthetic' => 'anonymized'));
    $originals = array();
    foreach ($before['files'] as $relative => $file) {
        $path = $root . '/' . $relative; $originals[$relative] = file_get_contents($path);
        check(WP_Seed_Pixel_Metadata::create($path, $path . '.candidate'), 'metadata-only candidate ' . $relative . ' ' . $name);
        rename($path . '.candidate', $path); clearstatcache(true, $path);
    }
    check(WP_Seed_Pixel_Master_Adapter::observe($record), 'transitional rows bounded by frozen graph ' . $name);
    check(error_is(WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true), 'METADATA_CONFLICT'), 'stale manifest rejected before commit ' . $name);
    $old_rows = $wpdb->rows; $wpdb->fail_key = '_seed_pixel_manifest';
    check(error_is(WP_Seed_Pixel_Master_Adapter::reconcile($record), 'STORE_FAILED'), 'CAS failure stops ' . $name);
    check($wpdb->rows === $old_rows, 'CAS transaction rolls back all rows ' . $name); $wpdb->fail_key = '';
    WP_Seed_Pixel_Authority::$owned = false;
    check(error_is(WP_Seed_Pixel_Master_Adapter::reconcile($record), 'LOCKED'), 'authority refusal unchanged ' . $name);
    WP_Seed_Pixel_Authority::$owned = true;
    $result = WP_Seed_Pixel_Master_Adapter::reconcile($record); check($result, 'CAS metadata and Pixel witnesses ' . $name);
    check($result['meta_after'] && $result['witness_after'] && $result['references_after'], 'SQL read-back after ' . $name);
    $record['phase'] = 'graph_committed';
    check(WP_Seed_Pixel_Metadata_Graph_Transaction::verify(array('attachment_id' => 17), $record), 'durable committed graph verified ' . $name);
    $after_rows = $wpdb->rows; $wpdb->rows['_seed_pixel_manifest'] = $old_rows['_seed_pixel_manifest'];
    check(error_is(WP_Seed_Pixel_Metadata_Graph_Transaction::verify(array('attachment_id' => 17), $record), 'METADATA_CONFLICT'), 'durable commit rejects old alias witnesses ' . $name);
    $wpdb->rows = $after_rows;
    check(WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true), 'post-anonymization snapshot certified ' . $name);
    foreach ($before['files'] as $relative => $file) { check(!WP_Seed_Pixel_Metadata::read($root . '/' . $relative)['categories'], 'public copy clean ' . $relative . ' ' . $name); }
    $conflicting = get_post_meta(17, '_seed_pixel_manifest'); $conflicting['generation'] = 'foreign-change';
    $wpdb->seed('_seed_pixel_manifest', $conflicting);
    check(error_is(WP_Seed_Pixel_Master_Adapter::observe($record), 'METADATA_CONFLICT'), 'foreign SQL change refused ' . $name);
    $wpdb->rows['_seed_pixel_manifest'] = $old_rows['_seed_pixel_manifest'];
    $wpdb->rows['_seed_pixel_manifest'][0]['meta_value'] = $references['_seed_pixel_manifest'][0];
    foreach ($originals as $relative => $bytes) { file_put_contents($root . '/' . $relative, $bytes); clearstatcache(true, $root . '/' . $relative); }
    $result = WP_Seed_Pixel_Master_Adapter::reconcile($record, true); check($result, 'restore canonical rows ' . $name);
    check($result['meta_before'] && $result['witness_before'] && $result['references_before'], 'SQL read-back restored ' . $name);
    $record['phase'] = 'graph_restored';
    check(WP_Seed_Pixel_Metadata_Graph_Transaction::verify(array('attachment_id' => 17), $record, true), 'durable restored graph verified ' . $name);
    check(WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true) === $before, 'byte-exact files and SQL snapshot restored ' . $name);
    $wpdb->shared = true;
    check(error_is(WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true), 'SHARED_PATH'), 'cross-attachment ownership guard ' . $name);
    $wpdb->shared = false;
    $wpdb->rows['_seed_pixel_manifest'][] = $wpdb->rows['_seed_pixel_manifest'][0];
    check(error_is(WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true), 'METADATA_CONFLICT'), 'duplicate SQL row guard ' . $name);
}
fixture('clean-alias', true, true);
$before = WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true); check($before, 'clean alias accepted');
$plan = WP_Seed_Pixel_Metadata_Public_Graph::plan($before);
check($plan['admissible'] && $plan['no_op'] && $plan['modified_files'] === 0, 'already-clean alias requires no job or encoding');
$wrong = get_post_meta(17, '_seed_pixel_manifest'); $wrong['files']['view']['sha256'] = str_repeat('0', 64); $wpdb->seed('_seed_pixel_manifest', $wrong);
check(error_is(WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true), 'METADATA_CONFLICT'), 'conflicting alias SHA not bypassed');
fixture('multiple-aliases', true);
$manifest = get_post_meta(17, '_seed_pixel_manifest');
$manifest['files']['thumb'] = $manifest['files']['view'];
$wpdb->seed('_seed_pixel_manifest', $manifest); $wpdb->seed('_seed_pixel_history', array($manifest));
$before = WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true); check($before, 'two logical aliases accepted');
$plan = WP_Seed_Pixel_Metadata_Public_Graph::plan($before);
check(count($plan['manifest']['files']) === 3, 'logical aliases do not multiply physical files');
$references = WP_Seed_Pixel_Master_Adapter::graph_reference_rows($before, $plan['manifest']);
$manifest = maybe_unserialize($references['_seed_pixel_manifest'][0]);
check(array_keys($manifest['files']) === array('thumb', 'view', 'medium'), 'every logical resource name preserved');
check($manifest['files']['thumb'] === $manifest['files']['view'], 'two aliases receive same certified witness');
$manifest = get_post_meta(17, '_seed_pixel_manifest'); $manifest['files']['view']['width']++; $wpdb->seed('_seed_pixel_manifest', $manifest);
check(error_is(WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true), 'METADATA_CONFLICT'), 'alias dimension discrepancy refused');
fixture('clean-alias-no-history', true, true); unset($wpdb->rows['_seed_pixel_history']);
$before = WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true); check($before, 'alias without historical row accepted');
$plan = WP_Seed_Pixel_Metadata_Public_Graph::plan($before);
check(WP_Seed_Pixel_Master_Adapter::graph_reference_rows($before, $plan['manifest'])['_seed_pixel_history'] === array(), 'absent history remains absent');
$manifest = get_post_meta(17, '_seed_pixel_manifest'); $manifest['files']['medium']['height']++; $wpdb->seed('_seed_pixel_manifest', $manifest);
check(error_is(WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true), 'METADATA_CONFLICT'), 'derived reference dimension discrepancy refused');
fixture('relative-alias', true);
$manifest = get_post_meta(17, '_seed_pixel_manifest'); unset($manifest['files']['view']['path']); $manifest['files']['view']['relative'] = 'master.jpg';
$wpdb->seed('_seed_pixel_manifest', $manifest); $wpdb->seed('_seed_pixel_history', array($manifest));
check(error_is(WP_Seed_Pixel_Master_Adapter::snapshot(17), 'NEEDS_REVIEW'), 'relative alias keeps JPEG replacement guard');
check(WP_Seed_Pixel_Master_Adapter::snapshot(17, false, true), 'verified relative alias admitted to metadata graph');
file_put_contents($lab . '/outputs/physical-alias.json', json_encode(array('passed' => count($checks), 'sql_mode' => 'CAS_DOUBLE_NOT_INNODB', 'checks' => $checks), JSON_PRETTY_PRINT));
echo count($checks) . " physical alias assertions PASS (SQL double; no WordPress/InnoDB runtime claim).\n";
