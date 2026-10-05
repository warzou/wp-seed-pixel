<?php
require __DIR__ . '/m3-runtime.php';
if (!WP_Seed_Pixel_Quarantine::enabled()) { throw new RuntimeException('Disposable M4 opt-in required'); }
function m4_item($job) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation'", $job), ARRAY_A);
}
function m4_check($v, $label) {
    global $checks; $checks[$label] = (bool) $v;
    if (!$v) { throw new RuntimeException('FAIL: ' . $label); }
}
function m4_replaced($enroll = true) {
    $id = m3_fixture('m3-detail.jpg', false); $p = get_attached_file($id); $i = getimagesize($p);
    wp_update_attachment_metadata($id, array('file' => _wp_relative_upload_path($p), 'width' => $i[0], 'height' => $i[1], 'filesize' => filesize($p), 'sizes' => array()));
    $job = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), 1073741824);
    if (is_wp_error($job)) { throw new RuntimeException($job->get_error_code()); }
    WP_Seed_Pixel_Jobs::step($job['id']);
    $view = $enroll ? WP_Seed_Pixel_Jobs::quarantine_action($job['id'], 'retain') : null;
    if (is_wp_error($view)) { throw new RuntimeException($view->get_error_code()); }
    return array($id, (int) $job['id'], $view);
}
function m4_approval(array $view) {
    return array('version' => WP_Seed_Pixel_Quarantine::AUTHORIZATION, 'generation' => $view['generation'], 'irreversible' => true);
}
function m4_original() {
    $id = m3_fixture('m3-large.jpg');
    $job = WP_Seed_Pixel_Jobs::retire_original($id, 1073741824, true);
    if (is_wp_error($job)) { throw new RuntimeException('retire plan ' . $job->get_error_code()); }
    return array($id, (int) $job['id']);
}
function m4_report($name, array $details = array()) {
    global $checks;
    $out = dirname(__DIR__) . '/reports/storage-m4'; wp_mkdir_p($out);
    file_put_contents($out . '/' . $name . '.json', wp_json_encode(array('checks' => $checks, 'details' => $details), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo $name . ': ' . count($checks) . " PASS\n";
}
function m4_fault_journal($id, $dir, array $record) {
    $site = WP_Seed_Pixel_Files::lock(0); $media = WP_Seed_Pixel_Files::lock((int) $id);
    if (is_wp_error($site) || is_wp_error($media)) { throw new RuntimeException('Fault injection authority unavailable'); }
    try {
        $r = WP_Seed_Pixel_Master_Storage::save($dir, $record);
        if (is_wp_error($r)) { throw new RuntimeException('Fault injection not applied'); }
    } finally { WP_Seed_Pixel_Files::unlock($media); WP_Seed_Pixel_Files::unlock($site); }
}
