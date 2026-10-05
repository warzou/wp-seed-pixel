<?php
require __DIR__ . '/runtime.php';
$id = (int) ($argv[2] ?? 0); global $wpdb;
$jobs = WP_Seed_Pixel_Job_Store::table('jobs'); $items = WP_Seed_Pixel_Job_Store::table('items');
switch ($argv[1] ?? '') {
    case 'kill-worker':
        $cmd = file_get_contents('/proc/' . $id . '/cmdline');
        $owned = strpos($cmd, __DIR__ . '/jobs-crash.php') !== false
            || (readlink('/proc/' . $id . '/cwd') === dirname(__DIR__) && strpos($cmd, "tests/jobs-crash.php\0") !== false);
        if (!getenv('PIXEL_M3_ROOT') || fileowner('/proc/' . $id) !== posix_getuid() || !$owned) { throw new RuntimeException('Owned local worker required'); }
        $result = posix_kill($id, SIGKILL); break;
    case 'create': case 'create-large':
        $report = json_decode(file_get_contents(dirname(__DIR__) . '/reports/storage-m2/integration.json'), true);
        $scan = ($argv[1] === 'create-large') ? json_decode(file_get_contents(dirname(__DIR__) . '/.runtime/m1-integration.json'), true)['large_scan'] : $report['scan_id'];
        $p = WP_Seed_Pixel_Jobs::plan($scan);
        while (!is_wp_error($p) && $p['status'] === 'queued') { $p = WP_Seed_Pixel_Jobs::step($p['id']); }
        $result = is_wp_error($p) ? $p : WP_Seed_Pixel_Jobs::start($p['id']); break;
    case 'expire':
        $wpdb->update($jobs, array('lease_until' => time() - 2), array('id' => $id));
        $wpdb->update($items, array('lease_until' => time() - 2), array('job_id' => $id));
        $result = WP_Seed_Pixel_Jobs::status($id); break;
    case 'step': $result = WP_Seed_Pixel_Jobs::step($id); break;
    case 'status': $result = WP_Seed_Pixel_Jobs::status($id); break;
    case 'pause': case 'resume': case 'cancel': case 'retry': $result = WP_Seed_Pixel_Jobs::control($id, $argv[1]); break;
    case 'row': $result = $wpdb->get_row($wpdb->prepare("SELECT stage,revision,lease_until,journal,receipt FROM $items WHERE job_id=%d ORDER BY id LIMIT 1", $id), ARRAY_A); break;
    case 'failed':
        $wpdb->query($wpdb->prepare("UPDATE $items SET stage='failed',error_code='CANDIDATE_INVALID',error_class='retryable' WHERE job_id=%d AND id=(SELECT id FROM (SELECT id FROM $items WHERE job_id=%d ORDER BY id LIMIT 1) AS fixture)", $id, $id));
        $wpdb->update($jobs, array('status' => 'completed_errors'), array('id' => $id)); $result = WP_Seed_Pixel_Jobs::status($id); break;
    case 'scan-small': case 'scan-large':
        $report = json_decode(file_get_contents(dirname(__DIR__) . '/reports/storage-m2/integration.json'), true);
        $source = ($argv[1] === 'scan-large') ? json_decode(file_get_contents(dirname(__DIR__) . '/.runtime/m1-integration.json'), true)['large_scan'] : $report['scan_id'];
        $wpdb->query($wpdb->prepare("INSERT INTO $jobs (kind,status,scan_cursor,ceiling,total,actor,lease,lease_until,created) SELECT kind,status,scan_cursor,ceiling,total,actor,'',0,%d FROM $jobs WHERE id=%d", time(), $source));
        $copy = $wpdb->insert_id;
        $wpdb->query($wpdb->prepare("INSERT INTO $items (job_id,kind,item_key,attachment_id,stage,bytes,role,owners,data) SELECT %d,kind,item_key,attachment_id,stage,bytes,role,owners,data FROM $items WHERE job_id=%d", $copy, $source));
        $result = WP_Seed_Pixel_Scan::status($copy); break;
    default: throw new RuntimeException('Unknown local fixture operation');
}
if (is_wp_error($result)) { $result = array('error' => $result->get_error_code()); }
echo wp_json_encode($result);
