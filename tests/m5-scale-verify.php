<?php
require __DIR__ . '/m4-runtime.php';
global $wpdb;
$job = WP_Seed_Pixel_Jobs::status();
if (is_wp_error($job) || empty($job['bulk']) || $job['kind'] !== 'replace' || (int) $job['total'] < 2000) { throw new RuntimeException('Existing synthetic large bulk job required'); }
$checks = array('large_frozen_membership' => (int) $job['planned'] === (int) $job['total'],
    'shared_sources_review' => ($job['states']['needs_review'] ?? 0) >= 2001);
$wpdb->num_queries = 0;
$page = WP_Seed_Pixel_Jobs::results($job['id'], 50); $queries = $wpdb->num_queries;
$checks['twenty_rows'] = !is_wp_error($page) && count($page['items']) === 20;
$checks['bounded_queries'] = $queries < 15;
$checks['at_least_101_pages'] = !is_wp_error($page) && $page['pages'] >= 101;
$cancel = WP_Seed_Pixel_Jobs::control($job['id'], 'cancel');
$checks['pending_cancel'] = !is_wp_error($cancel) && $cancel['status'] === 'cancelled';
$out = dirname(__DIR__) . '/reports/storage-m5'; wp_mkdir_p($out);
file_put_contents($out . '/scale-verify.json', wp_json_encode(array('checks' => $checks, 'relations' => $job['total'], 'page_queries' => $queries,
    'states' => $job['states'], 'real_encodings_in_this_check' => 0), JSON_PRETTY_PRINT));
if (in_array(false, $checks, true)) { throw new RuntimeException('Large-job verification failed'); }
echo 'M5 scale verification: ' . count($checks) . ' PASS; relations=' . $job['total'] . '; page queries=' . $queries . "\n";
