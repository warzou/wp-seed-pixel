<?php
require __DIR__ . '/runtime.php';
error_reporting(E_ALL & ~E_DEPRECATED);
global $wpdb;
$jobs_table = $wpdb->prefix . 'seed_pixel_jobs';
$job_count = static function () use ($wpdb, $jobs_table) {
    return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($jobs_table)))
        ? (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $jobs_table) : 0;
};
$state = dirname(__DIR__) . '/.runtime/m5-upgrade-state.json';
if (($argv[1] ?? '') === 'seed') {
    if (WP_SEED_PIXEL_VERSION !== '0.3.2') { throw new RuntimeException('Stable 0.3.2 required'); }
    $id = pixel_fixture('rgb.jpg');
    $manifest = wp_seed_pixel_optimize($id, 'participant_album');
    if (is_wp_error($manifest)) { throw new RuntimeException('Stable fixture failed'); }
    $manifest = WP_Seed_Pixel_Store::manifest($id);
    file_put_contents($state, wp_json_encode(array('id' => $id, 'manifest' => $manifest, 'meta' => wp_get_attachment_metadata($id),
        'settings' => get_option('wp_seed_pixel_settings'), 'sha' => hash_file('sha256', wp_get_original_image_path($id)),
        'jobs' => $job_count())));
    echo "Stable 0.3.2 upgrade baseline created.\n"; exit;
}
$before = json_decode(file_get_contents($state), true); $id = $before['id']; $checks = array();
$check = static function ($v, $n) use (&$checks) { $checks[$n] = (bool) $v; if (!$v) { throw new RuntimeException($n); } };
$check(WP_SEED_PIXEL_VERSION === '0.4.0' && WP_SEED_PIXEL_ENGINE_VERSION === '0.3.1' && class_exists('WP_Seed_Pixel_Bulk_Admin'), 'V1 arrival preserves stable derivative engine identity');
$check(WP_Seed_Pixel_Store::manifest($id) === $before['manifest'], 'No implicit derivative regeneration');
$check(wp_get_attachment_metadata($id) === $before['meta'], 'No native metadata mutation on arrival');
$check(get_option('wp_seed_pixel_settings') === $before['settings'], 'No settings reinterpretation');
$check(hash_file('sha256', wp_get_original_image_path($id)) === $before['sha'], 'Original byte identity preserved');
$check($job_count() === $before['jobs'], 'No automatic job or purge');
$wpdb->last_error = ''; ob_start(); WP_Seed_Pixel_Bulk_Admin::page(); WP_Seed_Pixel_Quarantine_Admin::page(); $ui = ob_get_clean();
$check($wpdb->last_error === '' && strpos($ui, 'pixel-bulk-policy') !== false, 'Fresh admin pages render without missing-table queries');
$check($job_count() === $before['jobs'], 'Admin display starts no processing');
$check(!is_wp_error(wp_seed_pixel_get_derivative($id, 'view')), 'Albums derivative getter remains compatible');
$next = wp_seed_pixel_optimize($id, 'balanced');
$check(!is_wp_error($next) && $next['strategy'] === 'bounded-adaptive', 'Explicit adaptive opt-in works');
$check(is_file($before['manifest']['files']['view']['path']), 'Legacy URLs retained after explicit opt-in');
$check(hash_file('sha256', wp_get_original_image_path($id)) === $before['sha'], 'Explicit opt-in preserves original');
$out = dirname(__DIR__) . '/reports/storage-m5/regression'; wp_mkdir_p($out);
file_put_contents($out . '/upgrade.json', wp_json_encode(array('checks' => $checks), JSON_PRETTY_PRINT) . "\n");
unlink($state); echo count($checks) . " M5 upgrade checks PASS\n";
