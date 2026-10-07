<?php
$lab = dirname(__DIR__);
if (realpath($lab) !== '/home/warzy/.cache/wp-seed-pixel-m3-environment/project') { throw new RuntimeException('Owned disposable lab required'); }
require $lab . '/.runtime/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
wp_set_current_user(1);
if (get_option('home') !== 'http://127.0.0.1:8877') { throw new RuntimeException('Local host only'); }
$mode = $argv[1] ?? '';
$label = $argv[2] ?? 'final';
$zip = getenv('PIXEL_RELEASE_ZIP');
$expected = getenv('PIXEL_RELEASE_VERSION') ?: '0.5.0';
$out = getenv('PIXEL_FORMAT_REPORT_DIR');
$checks = array();
function release_ok($value, $name) {
    global $checks;
    if (!$value || is_wp_error($value)) { throw new RuntimeException($name . (is_wp_error($value) ? ': ' . $value->get_error_code() : '')); }
    $checks[$name] = true;
}
function release_state() {
    global $wpdb;
    $result = array();
    foreach (array('posts','postmeta') as $table) {
        $order = $table === 'posts' ? 'ID' : 'meta_id';
        $result[$table] = $wpdb->get_results("SELECT * FROM {$wpdb->$table} ORDER BY $order", ARRAY_A);
    }
    $result['options'] = $wpdb->get_results("SELECT option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'wp_seed_pixel_%' ORDER BY option_name", ARRAY_A);
    foreach (array('jobs','items') as $suffix) {
        $table = WP_Seed_Pixel_Job_Store::table($suffix);
        $result[$suffix] = $wpdb->get_results("SELECT * FROM $table ORDER BY id", ARRAY_A);
    }
    $result['files'] = array();
    foreach (array(wp_get_upload_dir()['basedir'], WP_SEED_PIXEL_RECOVERY_ROOT) as $directory) {
        if (!is_dir($directory)) { continue; }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile()) { $result['files'][$file->getPathname()] = hash_file('sha256', $file->getPathname()); }
        }
    }
    ksort($result['files']);
    return $result;
}
if ($mode === 'install') {
    release_ok(is_file($zip), 'exact ZIP exists');
    deactivate_plugins(WP_Seed_Pixel_Updater::ID);
    WP_Filesystem();
    global $wp_filesystem;
    release_ok($wp_filesystem->delete(WP_PLUGIN_DIR . '/wp-seed-pixel', true), 'remove lab runtime without running uninstall');
    $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
    release_ok($upgrader->install($zip), 'native clean ZIP installation');
    release_ok(!is_wp_error(activate_plugin(WP_Seed_Pixel_Updater::ID)) && is_plugin_active(WP_Seed_Pixel_Updater::ID), 'native activation');
    release_ok(get_plugin_data(WP_PLUGIN_DIR . '/' . WP_Seed_Pixel_Updater::ID)['Version'] === $expected, 'installed header version');
} elseif ($mode === 'seed') {
    release_ok(WP_SEED_PIXEL_VERSION === $expected, 'old exact runtime loaded');
    update_option('wp_seed_pixel_settings', array('automatic'=>false,'preset'=>'balanced','cleanup_on_uninstall'=>false), false);
    release_ok(WP_Seed_Pixel_Future_Uploads::configure('off'), 'future policy remains off');
    if ($expected === '0.4.0') {
        release_ok(WP_Seed_Pixel_Job_Store::insert_job('plan', 0, WP_Seed_Pixel_Policy::normalize(), 0), 'persistent legacy job');
        $state = array('before'=>release_state());
    } else {
        $fixture = $expected === '0.5.0-private.5' ? 'noisy' : 'photo';
        $file = '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/.runtime/format-fixtures/' . $fixture . '.png';
        $u = wp_upload_bits('release-' . bin2hex(random_bytes(8)) . '.png', null, file_get_contents($file));
        release_ok(!$u['error'], 'synthetic upload');
        $id = wp_insert_attachment(array('post_title'=>'Synthetic release compatibility','post_mime_type'=>'image/png'), $u['file']);
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $u['file']));
        $native = WP_Seed_Pixel_Master_Adapter::snapshot($id);
        $a = WP_Seed_Pixel_Format_Conversion::analyze($id); release_ok($a, 'old runtime analysis');
        if ($expected === '0.5.0-private.5') {
            $s = WP_Seed_Pixel_Format_Conversion::select_profile($id, $a['generation'], 'web'); release_ok($s, 'old runtime explicit Q90');
            release_ok(!$s['candidate']['quality_passed'], 'Q90 quality failure recorded');
            $r = WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true, false, 'web', true);
        } else {
            $r = WP_Seed_Pixel_Format_Conversion::convert($id, $a['generation'], true);
        }
        release_ok($r, 'old runtime conversion');
        $record = WP_Seed_Pixel_Format_Conversion::record($id)['record'];
        $state = array('id'=>$id,'generation'=>$a['generation'],'native'=>$native,'record'=>$record,'before'=>release_state());
    }
    file_put_contents($lab . '/.runtime/release-' . $label . '.json', wp_json_encode($state));
} elseif ($mode === 'update') {
    $state = json_decode(file_get_contents($lab . '/.runtime/release-' . $label . '.json'), true);
    release_ok(release_state() === $state['before'], 'pre-update state unchanged');
    if (WP_SEED_PIXEL_VERSION !== '0.4.0') {
        // PHP orders the nonstandard private.N suffix after the final version.
        // Private builds therefore use the supported native upload-replace path.
        release_ok(!version_compare('0.5.0', WP_SEED_PIXEL_VERSION, '>'), 'private build requires manual ZIP replacement');
        release_ok(WP_Seed_Pixel_Updater::archive($zip, array('version'=>'0.5.0')), 'manual final archive identity verified');
        deactivate_plugins(WP_Seed_Pixel_Updater::ID);
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        release_ok($upgrader->install($zip, array('overwrite_package'=>true)), 'native upload replacement without uninstall');
        release_ok(!is_wp_error(activate_plugin(WP_Seed_Pixel_Updater::ID)), 'reactivate manual update');
        wp_cache_flush();
        release_ok(get_plugin_data(WP_PLUGIN_DIR . '/' . WP_Seed_Pixel_Updater::ID)['Version'] === '0.5.0', 'final manual header installed');
        release_ok(release_state() === $state['before'], 'manual update preserves settings jobs metadata and recovery bytes');
    } else {
    $manifest = array('schema'=>1,'slug'=>'wp-seed-pixel','channel'=>'private','version'=>'0.5.0','requires'=>'6.6','tested'=>get_bloginfo('version'),'requires_php'=>'8.1',
        'package'=>'https://127.0.0.1/pixel.zip','sha256'=>hash_file('sha256',$zip),'released'=>'2026-10-07','notes_url'=>'https://127.0.0.1/notes.html');
    $requests = array();
    add_filter('pre_http_request', function($pre, $args, $url) use (&$manifest, &$requests, $zip) {
        if (!str_starts_with($url, 'https://127.0.0.1/')) { return new WP_Error('external_transport_forbidden'); }
        $requests[] = array('cookies'=>$args['cookies'] ?? array(),'body'=>$args['body'] ?? null);
        if ($url === WP_SEED_PIXEL_UPDATE_MANIFEST) {
            return array('response'=>array('code'=>200),'headers'=>array(),'body'=>wp_json_encode($manifest),'cookies'=>array());
        }
        if ($url !== $manifest['package']) { return new WP_Error('unexpected_transport'); }
        if (!empty($args['stream'])) { copy($zip, $args['filename']); }
        return array('response'=>array('code'=>200),'headers'=>array(),'body'=>'','cookies'=>array());
    }, 10, 3);
    release_ok(WP_Seed_Pixel_Updater::manifest(true), 'final metadata accepted');
    $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
    $old = hash_file('sha256', WP_PLUGIN_DIR . '/' . WP_Seed_Pixel_Updater::ID);
    $manifest['sha256'] = str_repeat('0', 64);
    $t = (object) array('last_checked'=>time(),'checked'=>array(WP_Seed_Pixel_Updater::ID=>WP_SEED_PIXEL_VERSION),'response'=>array(),'no_update'=>array());
    set_site_transient('update_plugins', WP_Seed_Pixel_Updater::offer($t));
    release_ok($upgrader->upgrade(WP_Seed_Pixel_Updater::ID, array('clear_update_cache'=>false)) !== true && hash_file('sha256', WP_PLUGIN_DIR . '/' . WP_Seed_Pixel_Updater::ID) === $old, 'bad checksum refuses replacement');
    $manifest['sha256'] = hash_file('sha256', $zip);
    release_ok(WP_Seed_Pixel_Updater::manifest(true), 'fresh trusted checksum');
    set_site_transient('update_plugins', WP_Seed_Pixel_Updater::offer($t));
    $upgrader = new Plugin_Upgrader(new WP_Ajax_Upgrader_Skin());
    release_ok($upgrader->upgrade(WP_Seed_Pixel_Updater::ID, array('clear_update_cache'=>false)), 'native updater actual replacement');
    release_ok(!is_wp_error(activate_plugin(WP_Seed_Pixel_Updater::ID)), 'reactivate after native update');
    wp_cache_flush();
    release_ok(get_plugin_data(WP_PLUGIN_DIR . '/' . WP_Seed_Pixel_Updater::ID)['Version'] === '0.5.0', 'final header installed');
    release_ok(release_state() === $state['before'], 'settings jobs metadata and recovery bytes preserved');
    foreach ($requests as $request) { release_ok(!$request['cookies'] && !$request['body'], 'minimal transport ' . count($checks)); }
    }
} elseif ($mode === 'verify') {
    release_ok(WP_SEED_PIXEL_VERSION === '0.5.0' && WP_SEED_PIXEL_BUILD === '0.5.0', 'final runtime loaded in fresh process');
    release_ok(WP_SEED_PIXEL_ENGINE_VERSION === '0.3.1', 'legacy engine identity retained');
    $state = json_decode(file_get_contents($lab . '/.runtime/release-' . $label . '.json'), true);
    release_ok(release_state() === $state['before'], 'fresh process preserves old state');
    release_ok(WP_Seed_Pixel_Job_Store::install() && WP_Seed_Pixel_Job_Store::install(), 'schema idempotent');
    release_ok(release_state() === $state['before'], 'schema does not rewrite state');
    if (isset($state['id'])) {
        $record = WP_Seed_Pixel_Format_Conversion::record($state['id'])['record'];
        release_ok($record === $state['record'], 'persisted old conversion profile and approval exact');
        if ($label === 'private5') { release_ok($record['approved']['quality_override'] && $record['approved']['profile'] === 'web', 'private5 Q90 override read exactly'); }
        release_ok(str_contains(WP_Seed_Pixel_Format_Admin::panel($state['id']), 'pixel-format-result'), 'retained old conversion visible');
        release_ok(WP_Seed_Pixel_Format_Conversion::restore($state['id'], $state['generation']), 'restore old conversion under final runtime');
        release_ok(WP_Seed_Pixel_Master_Adapter::snapshot($state['id']) === $state['native'], 'exact original graph and metadata restored');
    }
} else { throw new RuntimeException('Explicit test mode required'); }
file_put_contents($out . '/release-' . $mode . '-' . $label . '.json', wp_json_encode(array('checks'=>$checks,'count'=>count($checks),'status'=>'PASS','transport'=>$mode === 'update' && $label !== 'v04' ? 'native manual ZIP replacement' : 'controlled local HTTP fixture','actual_core_upgrader'=>true)));
echo count($checks) . " release $mode $label checks PASS\n";
