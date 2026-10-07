<?php
require __DIR__ . '/format-bootstrap.php';
$archive = getenv('PIXEL_FORMAT_ZIP');
if (!$archive || !is_file($archive)) { throw new RuntimeException('Explicit local candidate required'); }
$checks = array();
$version = WP_SEED_PIXEL_VERSION;
$valid = WP_Seed_Pixel_Updater::archive($archive, array('version' => $version));
if ($valid !== true) { throw new RuntimeException('Private candidate rejected by native updater'); }
$checks['native archive identity accepted'] = true;
$wrong = WP_Seed_Pixel_Updater::archive($archive, array('version' => '0.4.0'));
if (!is_wp_error($wrong)) { throw new RuntimeException('Wrong package identity accepted'); }
$checks['wrong version rejected'] = true;
if (get_option('home') !== 'http://127.0.0.1:8877' || WP_SEED_PIXEL_VERSION !== $version) { throw new RuntimeException('Unexpected installation change'); }
$checks['no installation or runtime replacement'] = true;
$out = (getenv('PIXEL_FORMAT_REPORT_DIR') ?: '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/reports/png-jpeg') . '/updater-package.json';
file_put_contents($out, wp_json_encode(array('checks' => $checks, 'count' => count($checks)), JSON_PRETTY_PRINT));
echo count($checks) . " updater package checks PASS\n";
