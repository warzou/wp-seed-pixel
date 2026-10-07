<?php
define('ABSPATH', __DIR__);
define('WP_SEED_PIXEL_UPDATE_MANIFEST', $argv[1] ?? '');
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
require dirname(__DIR__) . '/includes/class-updater.php';
$expected = $argv[2] ?? '';
if (WP_Seed_Pixel_Updater::endpoint() !== $expected) { throw new RuntimeException('Override isolation failed'); }
echo "Explicit override, no fallback: PASS\n";
