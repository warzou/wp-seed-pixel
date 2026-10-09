<?php
require __DIR__ . '/runtime.php';
$checks = array();
function job_check($name, $pass) { global $checks; $checks[$name] = (bool) $pass; if (!$pass) { throw new RuntimeException($name); } }
function job_ok($value) { if (is_wp_error($value)) { throw new RuntimeException($value->get_error_code()); } return $value; }
function job_rows($id) { global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' WHERE job_id=%d ORDER BY id', $id), ARRAY_A); }
function job_plan($scan, $policy = array()) {
    $p = job_ok(WP_Seed_Pixel_Jobs::plan($scan, $policy));
    while ($p['status'] === 'queued') { $p = job_ok(WP_Seed_Pixel_Jobs::step($p['id'])); }
    return $p;
}
function job_start($scan) { return job_ok(WP_Seed_Pixel_Jobs::start(job_plan($scan)['id'])); }
function job_complete($id) { do { $s = job_ok(WP_Seed_Pixel_Jobs::step($id)); } while ($s['status'] === 'running'); return $s; }
function job_snapshot() {
    global $wpdb;
    $root = wp_upload_dir(null, false)['basedir']; $files = array();
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && strpos(wp_normalize_path($file->getPathname()), '/wp-seed-pixel/lock-') === false) { $files[wp_normalize_path(substr($file->getPathname(), strlen($root)))] = hash_file('sha256', $file->getPathname()); }
    }
    ksort($files);
    return hash('sha256', serialize(array($files, $wpdb->get_results("SELECT * FROM $wpdb->posts ORDER BY ID", ARRAY_A), $wpdb->get_results("SELECT * FROM $wpdb->postmeta ORDER BY meta_id", ARRAY_A), WP_Seed_Pixel_Plugin::settings(), get_option('wp_seed_pixel_batch'), get_option('cron'))));
}
final class Pixel_Fault_Executor implements WP_Seed_Pixel_Job_Executor {
    private $stage; private $code;
    public function __construct($stage, $code) { $this->stage = $stage; $this->code = $code; }
    public function reconcile(array $item, array $policy) { return (new WP_Seed_Pixel_Simulated_Executor())->reconcile($item, $policy); }
    public function execute($stage, array $item, array $policy) { return $stage === $this->stage ? new WP_Error($this->code) : (new WP_Seed_Pixel_Simulated_Executor())->execute($stage, $item, $policy); }
}
global $wpdb;
$jobs = WP_Seed_Pixel_Job_Store::table('jobs'); $items = WP_Seed_Pixel_Job_Store::table('items');
$scan_before = WP_Seed_Pixel_Scan::current();
$m1_rows = $wpdb->get_results("SELECT * FROM $items ORDER BY id", ARRAY_A);
job_check('M2 lazy schema absent before explicit plan', !get_option('wp_seed_pixel_job_schema'));
job_ok(WP_Seed_Pixel_Job_Store::install());
job_check('schema upgrade version 2 with M1 marker unchanged', (int) get_option('wp_seed_pixel_job_schema') === 2 && (int) get_option('wp_seed_pixel_scan_schema') === 1);
job_check('M1 status unchanged after upgrade', WP_Seed_Pixel_Scan::current() === $scan_before);
$count = $wpdb->get_var("SELECT COUNT(*) FROM $items");
job_ok(WP_Seed_Pixel_Job_Store::install());
job_check('repeated schema install does not duplicate rows', (int) $wpdb->get_var("SELECT COUNT(*) FROM $items") === count($m1_rows));
$indices = $wpdb->get_results("SHOW INDEX FROM $items", ARRAY_A);
job_check('resume and attachment conflict indexes present', in_array('attachment_stage', array_column($indices, 'Key_name')) && in_array('job_cursor', array_column($indices, 'Key_name')));
$p = job_ok(WP_Seed_Pixel_Policy::normalize());
job_check('safe defaults independent of quality', $p['intent']['master'] === 'keep' && !$p['intent']['purge'] && !$p['effective']['replace']);
$future = job_ok(WP_Seed_Pixel_Policy::normalize(array('quality' => 'storage_saver', 'master' => 'replace_verified', 'original' => 'retire_verified')));
job_check('future intent cannot enable M2 mutation', !$future['effective']['replace'] && !$future['effective']['retire'] && !$future['effective']['purge']);
foreach (array(array('format' => 'webp'), array('purge' => true), array('dimensions' => 'max_edge', 'max_edge' => 10), array('path' => '../anything'), array('max_edge' => '2560')) as $i => $bad) { job_check('invalid policy rejected ' . $i, is_wp_error(WP_Seed_Pixel_Policy::normalize($bad))); }
job_check('policy identity deterministic', WP_Seed_Pixel_Policy::hash($p) === WP_Seed_Pixel_Policy::hash(WP_Seed_Pixel_Policy::normalize()));
// All image construction happens before the M2 invariance snapshot.
$first = pixel_fixture('rgb.jpg', false); $second = pixel_fixture('rgb.jpg', false); $third = pixel_fixture('rgb.jpg', false);
$scan = job_ok(WP_Seed_Pixel_Scan::start());
$wpdb->update($jobs, array('scan_cursor' => $first - 1, 'total' => 3), array('id' => $scan['id']));
do { $scan = job_ok(WP_Seed_Pixel_Scan::step($scan['id'])); } while ($scan['status'] === 'running');
$before = job_snapshot();
$plan = job_plan($scan['id']);
job_check('analysis plan and simulation are distinct', $plan['kind'] === 'plan' && count(job_rows($plan['id'])) === 3);
job_check('normalized frozen policy stored without path', json_decode(WP_Seed_Pixel_Job_Store::job($plan['id'])['policy'], true) === $p && strpos(wp_json_encode(job_rows($plan['id'])), wp_normalize_path(ABSPATH)) === false);
$job = job_ok(WP_Seed_Pixel_Jobs::start($plan['id']));
job_check('repeated Start is idempotent', WP_Seed_Pixel_Jobs::start($plan['id'])['id'] === $job['id']);
$policy_hash = $job['policy_hash'];
$other_policy = job_plan($scan['id'], array('quality' => 'quality'));
job_check('later policy change cannot change existing job', WP_Seed_Pixel_Jobs::status($job['id'])['policy_hash'] === $policy_hash && $other_policy['policy_hash'] !== $policy_hash);
job_ok(WP_Seed_Pixel_Jobs::step($job['id']));
$completed_item = job_rows($job['id'])[0];
job_check('one complete item per bounded step', WP_Seed_Pixel_Jobs::status($job['id'])['states']['retained'] === 1);
job_check('durable intent and verified stages journaled', array_column(json_decode($completed_item['journal'], true)['events'], 'stage') === array('preparing', 'ready', 'switch_intent', 'switched', 'verified', 'retained'));
job_check('journal checksum valid', WP_Seed_Pixel_Job_Store::journal_valid($completed_item));
job_check('CAS wrong token refused', is_wp_error(WP_Seed_Pixel_Job_Store::transition($completed_item, 'wrong-token', 'queued')));
job_ok(WP_Seed_Pixel_Jobs::control($job['id'], 'pause'));
job_check('pause cannot advance simulation', WP_Seed_Pixel_Jobs::step($job['id'])['done'] === 1);
job_ok(WP_Seed_Pixel_Jobs::control($job['id'], 'resume'));
job_complete($job['id']);
job_check('resume does not rerun completed item', job_rows($job['id'])[0] === $completed_item);
$rows = job_rows($job['id']); job_ok(WP_Seed_Pixel_Jobs::step($job['id']));
job_check('completed job never rerun', job_rows($job['id']) === $rows);
job_check('actual reclaimed and encoded remain zero', WP_Seed_Pixel_Jobs::status($job['id'])['reclaimed_bytes'] === 0 && json_decode($rows[0]['receipt'], true)['encoded'] === 0);
$cancel = job_start($scan['id']); job_ok(WP_Seed_Pixel_Jobs::step($cancel['id'])); job_ok(WP_Seed_Pixel_Jobs::control($cancel['id'], 'cancel'));
job_check('cancel affects pending only', WP_Seed_Pixel_Jobs::status($cancel['id'])['states'] === array('cancelled' => 2, 'retained' => 1));
$retry = job_start($scan['id']);
job_ok(WP_Seed_Pixel_Jobs::step($retry['id'], new Pixel_Fault_Executor('queued', 'CANDIDATE_INVALID')));
job_complete($retry['id']); $good = job_rows($retry['id'])[1];
job_check('item failure taxonomy persisted', job_rows($retry['id'])[0]['error_class'] === 'retryable' && WP_Seed_Pixel_Jobs::status($retry['id'])['status'] === 'completed_errors');
job_ok(WP_Seed_Pixel_Jobs::control($retry['id'], 'retry')); job_ok(WP_Seed_Pixel_Jobs::control($retry['id'], 'resume')); job_complete($retry['id']);
job_check('retry failed only preserves successes', job_rows($retry['id'])[1] === $good && WP_Seed_Pixel_Jobs::status($retry['id'])['status'] === 'completed');
job_check('retry count persisted', (int) job_rows($retry['id'])[0]['attempts'] === 1);
$repeated = job_start($scan['id']);
job_ok(WP_Seed_Pixel_Jobs::step($repeated['id'], new Pixel_Fault_Executor('queued', 'BACKUP_FAILED')));
job_ok(WP_Seed_Pixel_Jobs::step($repeated['id'], new Pixel_Fault_Executor('queued', 'BACKUP_FAILED')));
job_check('repeated failures pause before third item', WP_Seed_Pixel_Jobs::status($repeated['id'])['status'] === 'paused' && job_rows($repeated['id'])[2]['stage'] === 'queued');
$systemic = job_start($scan['id']); job_ok(WP_Seed_Pixel_Jobs::step($systemic['id'], new Pixel_Fault_Executor('queued', 'LOW_DISK')));
job_check('systemic failure stops future work', WP_Seed_Pixel_Jobs::status($systemic['id'])['status'] === 'failed_systemic' && job_rows($systemic['id'])[1]['stage'] === 'queued');
job_check('systemic stopped job not runnable', WP_Seed_Pixel_Jobs::step($systemic['id'])['status'] === 'failed_systemic');
job_check('taxonomy covers unsupported conflict and review', WP_Seed_Pixel_Job_Store::error_class('UNSUPPORTED_FORMAT') === 'unsupported' && WP_Seed_Pixel_Job_Store::error_class('LOCKED') === 'conflict' && WP_Seed_Pixel_Job_Store::error_class('SOURCE_CHANGED') === 'review');
$post_intent = job_start($scan['id']); job_ok(WP_Seed_Pixel_Jobs::step($post_intent['id'], new Pixel_Fault_Executor('switch_intent', 'VERIFY_FAILED')));
job_check('post-intent failure requires explicit reconciliation', job_rows($post_intent['id'])[0]['stage'] === 'recovery_required' && WP_Seed_Pixel_Jobs::status($post_intent['id'])['status'] === 'paused');
job_check('incomplete state cannot be hidden by cancel', WP_Seed_Pixel_Jobs::control($post_intent['id'], 'cancel')->get_error_code() === 'RECOVERY_REQUIRED');
$conflict = job_start($scan['id']);
job_check('second job cannot take reserved attachment', WP_Seed_Pixel_Jobs::step($conflict['id'])->get_error_code() === 'CLAIM_CONFLICT');
job_ok(WP_Seed_Pixel_Jobs::control($post_intent['id'], 'resume')); job_complete($post_intent['id']);
job_check('recovery completes deterministic simulation', WP_Seed_Pixel_Jobs::status($post_intent['id'])['status'] === 'completed');
job_complete($conflict['id']);
$legacy_lock = WP_Seed_Pixel_Files::lock($first); $locked = job_start($scan['id']);
job_check('legacy attachment flock excludes M2', WP_Seed_Pixel_Jobs::step($locked['id'])->get_error_code() === 'LOCKED'); WP_Seed_Pixel_Files::unlock($legacy_lock);
$site_lock = WP_Seed_Pixel_Files::lock(0);
job_check('one site worker and pause serialization', WP_Seed_Pixel_Jobs::step($locked['id'])->get_error_code() === 'LOCKED' && WP_Seed_Pixel_Jobs::control($locked['id'], 'pause')->get_error_code() === 'LOCKED'); WP_Seed_Pixel_Files::unlock($site_lock);
job_complete($locked['id']);
$incompatible = job_start($scan['id']); $wpdb->update($jobs, array('engine' => 'future-unreadable'), array('id' => $incompatible['id']));
job_check('incompatible engine safe review stop', WP_Seed_Pixel_Jobs::step($incompatible['id'])->get_error_code() === 'ENGINE_INCOMPATIBLE' && WP_Seed_Pixel_Jobs::status($incompatible['id'])['status'] === 'paused');
$tampered = job_start($scan['id']); $row = job_rows($tampered['id'])[0]; $wpdb->update($items, array('data' => '{}'), array('id' => $row['id'])); job_ok(WP_Seed_Pixel_Jobs::step($tampered['id']));
job_check('tampered snapshot never executed', job_rows($tampered['id'])[0]['stage'] === 'needs_review');
$badplan = job_plan($scan['id']); $wpdb->update($items, array('data' => '{}'), array('id' => job_rows($badplan['id'])[0]['id']));
job_check('tampered plan cannot start', WP_Seed_Pixel_Jobs::start($badplan['id'])->get_error_code() === 'PLAN_CHANGED');
// Metadata change is synthetic fixture mutation, excluded from the invariance proof.
job_check('canonical state unchanged by all engine operations', job_snapshot() === $before);
$stale = job_start($scan['id']); $meta = get_post_meta($first, '_wp_attachment_metadata', true); update_post_meta($first, '_wp_attachment_metadata', array_merge($meta, array('fixture_revision' => 2)));
$changed_before = job_snapshot(); job_ok(WP_Seed_Pixel_Jobs::step($stale['id']));
job_check('stale source is review not execution', job_rows($stale['id'])[0]['error_code'] === 'SOURCE_CHANGED');
job_check('stale detection does not write canonical data', job_snapshot() === $changed_before);
update_post_meta($first, '_wp_attachment_metadata', $meta);
$large_scan = json_decode(file_get_contents(dirname(__DIR__) . '/reports/storage-m1/integration.json'), true)['large_scan'];
$start = microtime(true); $large = job_plan($large_scan); $large_job = job_ok(WP_Seed_Pixel_Jobs::start($large['id']));
job_check('2008 structured M1 relations consumed without giant option', $large_job['total'] == 2008 && count(job_rows($large_job['id'])) === 2008);
job_check('review and unsupported excluded from execution', WP_Seed_Pixel_Jobs::status($large_job['id'])['states']['skipped'] > 2000);
job_check('large pagination 20 rows and final 8', count(WP_Seed_Pixel_Jobs::results($large_job['id'])['items']) === 20 && count(WP_Seed_Pixel_Jobs::results($large_job['id'], 101)['items']) === 8);
foreach (WP_Seed_Pixel_Jobs::results($large_job['id'], 50)['items'] as $row) { wp_cache_delete((int) $row['attachment_id'], 'posts'); }
$n = $wpdb->num_queries; WP_Seed_Pixel_Jobs::results($large_job['id'], 50); $listing_queries = $wpdb->num_queries - $n;
job_check('listing uses bounded queries even with cold post cache', $listing_queries <= 4);
job_complete($large_job['id']);
job_check('large simulation has no reclaimed savings', WP_Seed_Pixel_Jobs::status($large_job['id'])['reclaimed_bytes'] === 0);
$old = job_start($scan['id']); job_complete($old['id']); $wpdb->update($jobs, array('updated' => time() - 86400 * 40), array('id' => $old['id']));
$active = job_start($scan['id']); $wpdb->update($jobs, array('updated' => time() - 86400 * 40), array('id' => $active['id']));
job_check('retention prunes only safe old terminal simulations', WP_Seed_Pixel_Job_Store::prune(time()) === 1 && WP_Seed_Pixel_Job_Store::job($active['id']) && !WP_Seed_Pixel_Job_Store::job($old['id']));
wp_set_current_user(0);
job_check('unauthorized PHP entry points rejected', is_wp_error(WP_Seed_Pixel_Jobs::plan($scan['id'])) && is_wp_error(WP_Seed_Pixel_Jobs::step($active['id'])) && is_wp_error(WP_Seed_Pixel_Jobs::results($active['id'])) && is_wp_error(WP_Seed_Pixel_Job_Store::prune(time())));
wp_set_current_user(1);
job_check('release preserves derivative engine identity', WP_SEED_PIXEL_VERSION === '0.6.0' && WP_SEED_PIXEL_ENGINE_VERSION === '0.3.1');
$report = array('checks' => $checks, 'passed' => count($checks), 'large_seconds' => microtime(true) - $start, 'large_relations' => 2008, 'queries_for_page' => $listing_queries, 'scan_id' => $scan['id'], 'fixture_ids' => array($first, $second, $third), 'canonical_before' => $before, 'canonical_after' => job_snapshot(), 'php' => PHP_VERSION, 'wordpress' => $GLOBALS['wp_version']);
$dir = dirname(__DIR__) . '/reports/storage-m2'; if (!is_dir($dir)) { mkdir($dir, 0755, true); }
file_put_contents($dir . '/integration.json', wp_json_encode($report, JSON_PRETTY_PRINT));
echo wp_json_encode(array('passed' => count($checks), 'large_relations' => 2008, 'scan_id' => $scan['id']));
