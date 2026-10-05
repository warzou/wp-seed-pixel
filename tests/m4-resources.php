<?php
require __DIR__ . '/m4-runtime.php';
$checks = array();
list($id, $job) = m4_original();
memory_reset_peak_usage();
WP_Seed_Pixel_Jobs::step($job);
$v = WP_Seed_Pixel_Quarantine::inspect(m4_item($job));
$php_peak = memory_get_peak_usage(true); $usage = getrusage();
m4_check($v['rollback_available'], 'resource probe original quarantined');
$p = WP_Seed_Pixel_Jobs::quarantine_action($job, 'purge', m4_approval($v));
m4_check(!is_wp_error($p) && !$p['rollback_available'], 'resource probe explicit purge complete');
m4_report('resources', array('php_peak_bytes_retirement' => $php_peak, 'process_peak_rss_kib_including_fixture_setup' => $usage['ru_maxrss'],
    'retained_original_bytes' => $v['source_bytes'], 'reserved_file_budget_bytes' => $v['source_bytes'] * 2 + 16777216,
    'continuous_disk_peak_measured' => false, 'provider_quota_measured' => false));
