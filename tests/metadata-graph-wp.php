<?php
$argv[1] = 'library';
require __DIR__ . '/metadata-wp.php';
meta_check(WP_Seed_Pixel_Job_Store::install(), 'native SQL schema');
function graph_fixture($clean_master = false, $dirty_sizes = true) {
    global $root;
    $id = meta_fixture('jpeg-gps.jpg', true);
    $meta = wp_get_attachment_metadata($id); $master = get_attached_file($id);
    if ($clean_master) { $scan = WP_Seed_Pixel_Metadata::read($master); file_put_contents($master, $scan['data']); $meta['filesize'] = strlen($scan['data']); }
    // Generated derivatives may retain a clean EXIF orientation block: never add a second EXIF.
    $payload = 'Synthetic private author/contact comment';
    $segment = "\xff\xfe" . pack('n', strlen($payload) + 2) . $payload;
    foreach ($meta['sizes'] as $key => $size) {
        $path = dirname($master) . '/' . $size['file'];
        $scan = WP_Seed_Pixel_Metadata::read($path); meta_check($scan, 'generated derivative supported');
        $bytes = $scan['data'];
        if ($dirty_sizes) { $bytes = substr($bytes, 0, 2) . $segment . substr($bytes, 2); }
        file_put_contents($path, $bytes); $meta['sizes'][$key]['filesize'] = strlen($bytes);
    }
    wp_update_attachment_metadata($id, $meta);
    clearstatcache();
    return $id;
}
if (defined('WP_SEED_PIXEL_GRAPH_FIXTURES_ONLY') && WP_SEED_PIXEL_GRAPH_FIXTURES_ONLY) { return; }
$results = array();
foreach (array(array(false, false), array(true, true), array(false, true)) as $case) {
    $id = graph_fixture($case[0], $case[1]);
    $before = WP_Seed_Pixel_Master_Adapter::snapshot($id); meta_check($before, 'native graph snapshot');
    $editorial = meta_editorial($id); $srcset = wp_get_attachment_image_srcset($id, 'full');
    $plan = WP_Seed_Pixel_Metadata_Public_Graph::plan($before); meta_check($plan, 'native graph plan');
    meta_check($plan['admissible'], 'native graph admissible ' . wp_json_encode($plan['blockers']));
    $job = meta_job($id); meta_check(is_wp_error(meta_job_duplicate($id)), 'duplicate job blocked');
    meta_check(WP_Seed_Pixel_Jobs::step($job), 'native executor step');
    $item = meta_item($job); meta_check($item['stage'] === 'retained', 'native graph retained: ' . $item['error_code']);
    $dir = WP_Seed_Pixel_Master_Storage::directory($item); $r = WP_Seed_Pixel_Master_Storage::load($dir, $item); meta_check($r, 'native checksummed journal');
    meta_check($r['phase'] === 'graph_committed', 'native durable commit');
    meta_check(count($r['graph_files']) === $plan['modified_files'], 'exact dirty bundle count');
    foreach ($r['graph_files'] as $relative => $file) {
        meta_check(hash_file('sha256', $dir . '/' . $file['original']) === $before['files'][$relative]['sha256'], 'exact native recovery hash');
    }
    foreach ($before['files'] as $relative => $file) {
        $scan = WP_Seed_Pixel_Metadata::read(wp_upload_dir()['basedir'] . '/' . $relative); meta_check($scan, 'native filtered public file');
        meta_check(!$scan['categories'], 'every native public file clean');
    }
    meta_check(meta_editorial($id) === $editorial, 'native editorial unchanged');
    meta_check(wp_get_attachment_image_srcset($id, 'full') === $srcset, 'native srcset unchanged');
    $view = WP_Seed_Pixel_Quarantine::inspect($item); meta_check($view, 'native bundle view');
    meta_check($view['rollback_available'] && !$view['purge_available'], 'whole bundle restorable but not purgeable');
    meta_check($view['quarantine_bytes'] === $r['recovery_bytes'], 'exact bundle byte accounting');
    meta_check(WP_Seed_Pixel_Metadata_Admin::state($id) === 'anonymized', 'success only after native retain');
    meta_check(is_wp_error(WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824)), 'JPEG blocked while metadata recovery retained');
    meta_check(WP_Seed_Pixel_Jobs::restore_master($job), 'native whole graph restore');
    meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'native restore exact snapshot');
    meta_check(WP_Seed_Pixel_Metadata_Admin::state($id) === 'restored', 'native restored UI');
    meta_check(count(glob($dir . '/graph-original-*')) === 0, 'restored escrow reclaimed after exact verification');
    $again = meta_job($id); meta_check(WP_Seed_Pixel_Jobs::step($again), 'native re-anonymization');
    meta_check(meta_item($again)['stage'] === 'retained', 'native repeat retained');
    meta_check(WP_Seed_Pixel_Jobs::restore_master($again), 'native repeated whole restore');
    meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id) === $before, 'native repeated snapshot exact');
    $results[] = array('id' => $id, 'job' => $job, 'repeat' => $again, 'files' => count($before['files']), 'modified' => $plan['modified_files']);
}
file_put_contents($root . '/evidence/native-graph.json', wp_json_encode(array('passed' => count($checks), 'checks' => $checks, 'cases' => $results), JSON_PRETTY_PRINT));
echo count($checks) . " native graph runtime checks PASS\n";
