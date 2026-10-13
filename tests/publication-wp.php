<?php
declare(strict_types=1);
$root = getenv('SEED_PORTABILITY_LAB');
$site = $argv[1] ?? '';
$mode = $argv[2] ?? '';
if ($root !== '/home/warzy/.cache/wp-seed-pixel-publication-20261013'
    || realpath($root) !== $root || trim(file_get_contents($root . '/publication.marker')) !== 'pixel-publication-synthetic-only-v1'
    || !in_array($site, ['a', 'b', 'd', 'e', 'f', 'g'], true)) { throw new RuntimeException('OWNED_PUBLICATION_LAB_REQUIRED'); }
$_SERVER['DOCUMENT_ROOT'] = $root . '/' . $site . '/web';
require $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_set_current_user(1);
$checks = [];
function pub_check($value, string $label): void {
    global $checks;
    if (is_wp_error($value) || !$value) { throw new RuntimeException($label . ': ' . (is_wp_error($value) ? $value->get_error_code() : 'FAIL')); }
    $checks[] = $label;
}
function pub_tree(string $path): array {
    $out = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $f) {
        if (!$f->isFile() || $f->isLink()) { throw new RuntimeException('NON_PLAIN_LAB_FILE'); }
        $s = lstat($f->getPathname());
        $out[substr($f->getPathname(), strlen($path) + 1)] = [hash_file('sha256', $f->getPathname()), $s['dev'], $s['ino'], $s['size']];
    }
    ksort($out); return $out;
}
function pub_state(): array {
    global $wpdb;
    $state = get_option(WP_Seed_Pixel_Recovery_Setup::OPTION);
    $out = ['options' => $wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'wp_seed_pixel_%' ORDER BY option_name", ARRAY_A),
        'attachments' => $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type='attachment' ORDER BY ID", ARRAY_A),
        'attachment_metadata' => $wpdb->get_results("SELECT m.* FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='attachment' ORDER BY m.meta_id", ARRAY_A),
        'jobs' => $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . ' ORDER BY id', ARRAY_A),
        'items' => $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' ORDER BY id', ARRAY_A),
        'uploads' => pub_tree(wp_upload_dir(null, false)['basedir'])];
    if (is_array($state) && isset($state['path'])) { $out['recovery'] = pub_tree($state['path']); }
    return $out;
}
function pub_out(array $extra = []): void {
    global $root, $checks, $site, $mode;
    $r = ['version' => WP_SEED_PIXEL_VERSION, 'passed' => count($checks), 'checks' => $checks] + $extra;
    file_put_contents($root . '/evidence/' . $site . '-' . $mode . '.json', wp_json_encode($r, JSON_PRETTY_PRINT));
    echo wp_json_encode(['site' => $site, 'mode' => $mode, 'version' => WP_SEED_PIXEL_VERSION, 'passed' => count($checks)]) . "\n";
}
function pub_fixture(string $name, bool $native = false): int {
    $u = wp_upload_bits('publication-' . bin2hex(random_bytes(5)) . '-' . $name, null, file_get_contents(getenv('SEED_PORTABILITY_FIXTURES') . '/' . $name));
    pub_check(!$u['error'], 'native upload accepts synthetic ' . $name);
    if ($native) { $u = apply_filters('wp_handle_upload', $u, 'upload'); }
    $id = wp_insert_attachment(['post_title' => 'Synthetic publication', 'post_excerpt' => 'Synthetic caption', 'post_content' => 'Synthetic description', 'post_mime_type' => wp_check_filetype($name)['type']], $u['file']);
    pub_check(!is_wp_error($id), 'attachment created');
    update_post_meta($id, '_wp_attachment_image_alt', 'Synthetic alt');
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $u['file']));
    return (int) $id;
}
function pub_editorial(int $id): array {
    $p = get_post($id);
    return [$p->ID, $p->post_title, $p->post_excerpt, $p->post_content, get_post_meta($id, '_wp_attachment_image_alt', true), $p->post_status, $p->guid];
}
pub_check(WP_Seed_Pixel_Job_Store::install(), 'native schema available');
if ($mode === 'compatibility') {
    pub_check(WP_Seed_Pixel_Recovery_Setup::gate(), 'mixed lifecycle gate');
    pub_check(WP_Seed_Pixel_Migration_Binding::verify_store(WP_Seed_Pixel_Recovery_Setup::verify()), 'mixed immutable provenance');
    $rows = $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE kind='operation' AND stage='retained' ORDER BY id", ARRAY_A);
    foreach ($rows as $item) { pub_check(WP_Seed_Pixel_Migration_Binding::availability($item) === true, 'retained recovery after normal operations'); }
    $baseline = $root . '/archives/current-full-' . $site . '.bin'; $current = pub_state();
    if (!file_exists($baseline)) { file_put_contents($baseline, serialize($current)); chmod($baseline, 0600); }
    else { pub_check(unserialize(file_get_contents($baseline), ['allowed_classes' => false]) === $current, 'full native metadata SQL files identities exact through rollback'); }
    pub_out(['retained' => count($rows), 'protected_state_sha256' => hash('sha256', serialize($current))]); exit;
}
if ($mode === 'diagnose-uploads') {
    $id = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='attachment'");
    $a = WP_Seed_Pixel_Metadata_Admin::analyze($id);
    $item = WP_Seed_Pixel_Media::operation($id);
    $inspection = $item ? WP_Seed_Pixel_Quarantine::inspect($item) : null;
    $record = $item ? WP_Seed_Pixel_Master_Storage::load(WP_Seed_Pixel_Master_Storage::directory($item, false), $item) : null;
    pub_out(['attachment' => $id, 'marker_state' => get_post_meta($id, WP_Seed_Pixel_Metadata_Uploads::META, true)['state'] ?? null,
        'ui_state' => WP_Seed_Pixel_Metadata_Admin::state($id), 'analysis_error' => is_wp_error($a) ? $a->get_error_code() : null,
        'item_stage' => $item['stage'] ?? null, 'recovery_inspection_error' => is_wp_error($inspection) ? $inspection->get_error_code() : null,
        'actual_metadata' => wp_get_attachment_metadata($id),
        'witness_metadata' => is_array($record) ? ($record['witness']['metadata'] ?? null) : null]); exit;
}
if ($mode === 'state') {
    pub_check(WP_Seed_Pixel_Recovery_Setup::gate(), 'recovery gate');
    $state = WP_Seed_Pixel_Recovery_Setup::verify(); pub_check($state, 'recovery identity');
    pub_check(WP_Seed_Pixel_Migration_Binding::verify_store($state), 'source evidence unchanged');
    $rows = $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE kind='operation' AND stage='retained' ORDER BY id", ARRAY_A);
    foreach ($rows as $item) { pub_check(WP_Seed_Pixel_Migration_Binding::availability($item) === true, 'migrated recovery available'); }
    pub_check(count($rows) === 3, 'three migrated recoveries');
    $current = pub_state();
    $baseline = $root . '/archives/publication-before-' . $site . '.bin';
    if (!file_exists($baseline)) { file_put_contents($baseline, serialize($current)); chmod($baseline, 0600); }
    else { pub_check(unserialize(file_get_contents($baseline), ['allowed_classes' => false]) === $current, 'upgrade preserves exact SQL files hashes inodes provenance'); }
    pub_out(['recoveries' => count($rows), 'protected_state_sha256' => hash('sha256', serialize($current))]); exit;
}
if ($mode === 'upgrade') {
    $version = $argv[3] ?? '';
    pub_check(in_array($version, ['0.7.0-dev.3', '0.7.0-rc.1', '0.7.0-rc.2'], true), 'explicit upgrade target');
    $file = $version === '0.7.0-dev.3' ? $root . '/packages/wp-seed-pixel-' . $version . '.zip' : getenv('PIXEL_PUBLICATION_REPORT') . '/candidate/wp-seed-pixel-' . $version . '.zip';
    $before = pub_state();
    define('FS_METHOD', 'direct');
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $skin = new Automatic_Upgrader_Skin(); $u = new Plugin_Upgrader($skin);
    $r = $u->install($file, ['overwrite_package' => true]);
    pub_check($r, 'WordPress native ZIP replacement');
    pub_check(is_plugin_active('wp-seed-pixel/wp-seed-pixel.php'), 'plugin remains active');
    pub_check(get_plugin_data(WP_PLUGIN_DIR . '/wp-seed-pixel/wp-seed-pixel.php', false, false)['Version'] === $version, 'installed version header');
    pub_check(pub_state() === $before, 'native replacement has zero protected data mutation');
    pub_out(['installed' => $version]); exit;
}
if ($mode === 'restore') {
    $source = unserialize(file_get_contents($root . '/archives/source-state.bin'), ['allowed_classes' => false]);
    foreach ($source['jobs'] as $i => $job) {
        pub_check(WP_Seed_Pixel_Jobs::restore_master($job), 'candidate native migrated restore');
        $id = $source['ids'][$i];
        $snapshot = WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true);
        $expected = $source['before'][$id];
        foreach ($expected['files'] as $relative => $file) {
            pub_check(isset($snapshot['files'][$relative]) && $snapshot['files'][$relative]['sha256'] === $file['sha256'] && $snapshot['files'][$relative]['bytes'] === $file['bytes'], 'restored source bytes exact');
        }
    }
    pub_check(WP_Seed_Pixel_Migration_Binding::verify_store(WP_Seed_Pixel_Recovery_Setup::verify()), 'immutable provenance survives restores');
    pub_out(); exit;
}
if ($mode === 'normal') {
    $state = WP_Seed_Pixel_Recovery_Setup::prepare(); pub_check($state, 'normal recovery storage'); WP_Seed_Pixel_Recovery_Setup::apply();
    pub_check(WP_Seed_Pixel_Metadata_Uploads::configure(false), 'manual mode explicit');
    $results = [];
    foreach (['jpeg-gps.jpg', 'png-text.png', 'jpeg-exif.jpg'] as $name) {
        $id = pub_fixture($name); $editorial = pub_editorial($id);
        $before = WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true); pub_check($before, 'ordinary source snapshot');
        $a = WP_Seed_Pixel_Metadata_Admin::analyze($id); pub_check($a, 'ordinary privacy analysis');
        $job = WP_Seed_Pixel_Jobs::replace_one($id, ['master' => 'replace_verified', 'metadata' => 'anonymize'], 1073741824, '', $a['signature']); pub_check($job, 'ordinary metadata admission');
        pub_check(WP_Seed_Pixel_Jobs::step($job['id']), 'ordinary metadata transaction');
        pub_check(WP_Seed_Pixel_Metadata_Admin::state($id) === 'anonymized', 'public graph CLEAN');
        pub_check(pub_editorial($id) === $editorial, 'all editorial fields preserved');
        $results[$name] = ['sha256' => hash_file('sha256', get_attached_file($id)), 'dimensions' => array_slice(getimagesize(get_attached_file($id)), 0, 2)];
        pub_check(WP_Seed_Pixel_Jobs::restore_master($job['id']), 'ordinary exact restore');
        pub_check(WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true) === $before, 'ordinary complete snapshot restored');
    }
    $id = pub_fixture('jpeg-clean.jpg');
    $r = wp_seed_pixel_optimize($id, 'web'); pub_check($r, 'ordinary JPEG optimization');
    $p = get_attached_file($id); pub_check(is_array(getimagesize($p)), 'optimized JPEG readable');
    $results['optimization'] = ['sha256' => hash_file('sha256', $p), 'dimensions' => array_slice(getimagesize($p), 0, 2)];
    pub_out(['results' => $results]); exit;
}
if ($mode === 'uploads') {
    pub_check(WP_Seed_Pixel_Metadata_Uploads::configure(true), 'automatic privacy enabled');
    $originals = [];
    foreach (['jpeg-exif.jpg', 'png-text.png', 'jpeg-clean.jpg', 'png-clean.png'] as $name) {
        $id = pub_fixture($name, true);
        $state = get_post_meta($id, WP_Seed_Pixel_Metadata_Uploads::META, true);
        $expected = str_contains($name, 'clean') ? 'clean' : 'anonymized';
        pub_check(($state['state'] ?? null) === $expected, 'automatic native upload ' . $expected);
        pub_check(WP_Seed_Pixel_Metadata_Admin::state($id) === $expected || $expected === 'clean', 'upload public graph verified');
        if ($expected === 'anonymized') {
            $item = WP_Seed_Pixel_Media::operation($id);
            pub_check(WP_Seed_Pixel_Quarantine::inspect($item)['rollback_available'], 'automatic recovery available');
            pub_check(WP_Seed_Pixel_Jobs::quarantine_action((int) $item['job_id'], 'restore', [], (int) $item['id']), 'automatic upload original restored');
        } else { pub_check(!WP_Seed_Pixel_Media::operation($id), 'clean upload avoids recompression and job'); }
        $originals[$name] = hash_file('sha256', get_attached_file($id));
        pub_check($originals[$name] === hash_file('sha256', getenv('SEED_PORTABILITY_FIXTURES') . '/' . $name), 'upload original exact independent fixture ' . $name);
    }
    pub_check(WP_Seed_Pixel_Metadata_Uploads::configure(false), 'explicit OFF remains available');
    pub_out(['originals' => $originals]); exit;
}
if ($mode === 'security') {
    $state = WP_Seed_Pixel_Recovery_Setup::verify(); pub_check($state, 'candidate storage identity');
    $before = pub_state();
    $manifest = $state['path'] . '/migration-source/manifest.json'; $raw = file_get_contents($manifest);
    try {
        file_put_contents($manifest, $raw . ' ');
        pub_check(!WP_Seed_Pixel_Recovery_Setup::gate(), 'altered provenance refuses processing');
        pub_check(is_wp_error(WP_Seed_Pixel_Migration_Binding::verify_store($state)), 'altered provenance never certified');
    } finally { file_put_contents($manifest, $raw); }
    pub_check(pub_state() === $before, 'negative provenance test restores exact bytes and SQL');
    pub_check(WP_Seed_Pixel_Recovery_Setup::gate(), 'exact provenance restores gate');
    wp_set_current_user(0);
    pub_check(is_wp_error(WP_Seed_Pixel_Recovery_Setup::prepare()), 'anonymous storage mutation refused');
    pub_check(is_wp_error(WP_Seed_Pixel_Metadata_Uploads::configure(true)), 'anonymous privacy configuration refused');
    wp_set_current_user(1);
    $m = ['schema' => 1, 'slug' => 'wp-seed-pixel', 'channel' => 'stable', 'version' => '0.6.1', 'requires' => '6.6', 'tested' => '7.1', 'requires_php' => '8.1',
        'package' => 'https://github.com/warzou/wp-seed-pixel/releases/download/v0.6.1/wp-seed-pixel-0.6.1.zip', 'sha256' => str_repeat('a', 64), 'released' => '2026-10-10', 'notes_url' => 'https://github.com/warzou/wp-seed-pixel/releases/tag/v0.6.1'];
    $key = 'wp_seed_pixel_update_' . substr(hash('sha256', WP_Seed_Pixel_Updater::OFFICIAL_MANIFEST), 0, 24);
    set_site_transient($key, $m, 60);
    $offer = WP_Seed_Pixel_Updater::offer((object) ['checked' => [WP_Seed_Pixel_Updater::ID => WP_SEED_PIXEL_VERSION], 'response' => [], 'no_update' => []]);
    pub_check(!isset($offer->response[WP_Seed_Pixel_Updater::ID]), 'stable 0.6.1 never downgrades RC');
    pub_check(WP_Seed_Pixel_Updater::archive(getenv('PIXEL_PUBLICATION_REPORT') . '/candidate/wp-seed-pixel-0.7.0-rc.2.zip', ['version' => '0.7.0-rc.2']) === true, 'updater validates RC archive');
    pub_check(pub_state() === $before, 'security and updater checks do not mutate protected state');
    pub_out(); exit;
}
if ($mode === 'admin-future') {
    pub_check(current_user_can('manage_options'), 'native administrator context');
    require_once ABSPATH . 'wp-admin/includes/template.php';
    $level = ob_get_level();
    try { ob_start(); WP_Seed_Pixel_Admin::page(); $html = ob_get_clean(); }
    catch (Throwable $e) { while (ob_get_level() > $level) { ob_end_clean(); } throw $e; }
    pub_check(strlen($html) > 1000 && str_contains($html, 'WP Seed Pixel'), 'native admin page renders');
    $legacy = get_option('wp_seed_pixel_settings');
    $future = get_option(WP_Seed_Pixel_Future_Uploads::OPTION);
    pub_check(WP_Seed_Pixel_Metadata_Uploads::configure(true), 'automatic privacy enabled for scheduled workflow');
    try {
        pub_check(WP_Seed_Pixel_Future_Uploads::configure('off'), 'legacy future mode');
        $s = WP_Seed_Pixel_Plugin::settings(); $s['automatic'] = true; update_option('wp_seed_pixel_settings', $s);
        $id = pub_fixture('jpeg-clean.jpg', true); $hash = hash_file('sha256', get_attached_file($id));
        pub_check((bool) wp_next_scheduled('wp_seed_pixel_auto', [$id]), 'native legacy optimization scheduled');
        do_action('wp_seed_pixel_auto', $id);
        pub_check(is_array(getimagesize(get_attached_file($id))), 'scheduled optimizer executes safely');
        pub_check(WP_Seed_Pixel_Future_Uploads::configure('process', 1073741824, null, ['jpeg', 'png']), 'future processing configuration');
        foreach (['jpeg-exif.jpg', 'png-text.png'] as $name) {
            $id = pub_fixture($name, true); $hash = hash_file('sha256', get_attached_file($id));
            WP_Seed_Pixel_Future_Uploads::run($id);
            pub_check(hash_file('sha256', get_attached_file($id)) === $hash, 'privacy prevents extra encoding ' . $name);
            $marker = get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true);
            pub_check(($marker['state'] ?? '') === 'review' && empty($marker['job_id']), 'no conflicting future recovery ' . $name);
        }
    } finally {
        update_option('wp_seed_pixel_settings', $legacy);
        if ($future === false) { delete_option(WP_Seed_Pixel_Future_Uploads::OPTION); } else { update_option(WP_Seed_Pixel_Future_Uploads::OPTION, $future); }
        WP_Seed_Pixel_Metadata_Uploads::configure(false);
    }
    pub_out(); exit;
}
if ($mode === 'formats') {
    pub_check(WP_Seed_Pixel_Metadata_Uploads::configure(false), 'explicit formats workflow');
    $id = pub_fixture('png-clean.png'); $file = get_attached_file($id);
    $before = WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true);
    $pixels = WP_Seed_Pixel_PNG_Processor::inspect($file, true); pub_check($pixels, 'native PNG pixel decoding');
    $j = WP_Seed_Pixel_Jobs::replace_one($id, ['master' => 'replace_verified'], 1073741824); pub_check($j, 'native PNG optimizer admission');
    pub_check(WP_Seed_Pixel_Jobs::step($j['id']), 'native PNG lossless optimization');
    $after = WP_Seed_Pixel_PNG_Processor::inspect(get_attached_file($id), true); pub_check($after, 'optimized PNG decodes');
    pub_check($after['header'] === $pixels['header'] && $after['pixels'] === $pixels['pixels'], 'PNG pixels and dimensions exact');
    pub_check(WP_Seed_Pixel_Jobs::restore_master($j['id']), 'PNG optimizer native restoration');
    pub_check(WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true) === $before, 'PNG original snapshot exact');
    $fixture = '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/.runtime/format-fixtures/smooth.png';
    pub_check(is_file($fixture), 'existing synthetic photographic fixture');
    $u = wp_upload_bits('publication-format-' . bin2hex(random_bytes(5)) . '.png', null, file_get_contents($fixture));
    pub_check(!$u['error'], 'synthetic conversion upload');
    $id = wp_insert_attachment(['post_title' => 'Synthetic conversion', 'post_mime_type' => 'image/png'], $u['file']);
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $u['file']));
    $before = WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true);
    $a = WP_Seed_Pixel_Format_Conversion::analyze($id); pub_check($a, 'native conversion analysis');
    $r = WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true, false, $a['selected_profile']); pub_check($r, 'native PNG to JPEG conversion');
    pub_check(get_post_mime_type($id) === 'image/jpeg', 'conversion native MIME');
    pub_check(!empty($r['restore_available']), 'converted recovery available');
    pub_check(WP_Seed_Pixel_Format_Conversion::restore($id, $a['generation']), 'native PNG conversion restore');
    pub_check(WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true) === $before, 'PNG conversion exact original restore');
    pub_out(); exit;
}
throw new RuntimeException('UNKNOWN_PUBLICATION_MODE');
