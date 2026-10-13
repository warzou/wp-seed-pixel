<?php
$root = getenv('SEED_PORTABILITY_LAB');
if (!$root || realpath($root) !== $root || !is_file($root . '/synthetic-lab.marker')
    || trim(file_get_contents($root . '/synthetic-lab.marker')) !== 'wp-seed-portability-synthetic-v1'
    || !in_array($argv[1] ?? '', array('a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm', 'n', 'o'), true)) { throw new RuntimeException('Owned synthetic laboratory required'); }
$site = $argv[1]; $mode = $argv[2]; $web = "$root/$site/web";
$_SERVER['DOCUMENT_ROOT'] = $web;
if ($mode === 'install' || $mode === 'backup-config') {
    $config = "<?php\ndefine('DB_NAME','seed_portability_$site');\ndefine('DB_USER','root');\ndefine('DB_PASSWORD','');\ndefine('DB_HOST','localhost:$root/mysql.sock');\ndefine('DB_CHARSET','utf8mb4');\n\$table_prefix='lab_';\ndefine('WP_HOME','http://127.0.0.1/$site');\ndefine('WP_SITEURL',WP_HOME);\ndefine('DISABLE_WP_CRON',true);\ndefine('WP_ENVIRONMENT_TYPE','local');\ndefine('WP_HTTP_BLOCK_EXTERNAL',true);\ndefine('WP_DEBUG',true);\ndefine('WP_DEBUG_DISPLAY',false);\ndefine('WP_SEED_PIXEL_METADATA_GRAPH_TESTING',true);\nif(!defined('ABSPATH')){define('ABSPATH',__DIR__.'/');}\nrequire ABSPATH.'wp-settings.php';\n";
    file_put_contents($web . '/wp-config.php', $config);
    if ($mode === 'backup-config') { echo "Independent backup target configured without bootstrap.\n"; exit; }
    define('WP_INSTALLING', true); require $web . '/wp-load.php';
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    wp_install('Synthetic portability ' . $site, 'local_qa', 'qa@example.invalid', false, '', bin2hex(random_bytes(24)));
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    $r = activate_plugin('wp-seed-pixel/wp-seed-pixel.php'); if (is_wp_error($r)) { throw new RuntimeException($r->get_error_code()); }
    echo "Independent WordPress $site installed, outbound mail/HTTP/cron blocked.\n"; exit;
}
require $web . '/wp-load.php'; require_once ABSPATH . 'wp-admin/includes/image.php'; wp_set_current_user(1);
$checks = array();
function check($v, $label) {
    global $checks;
    if (is_wp_error($v) || !$v) { throw new RuntimeException($label . ': ' . (is_wp_error($v) ? $v->get_error_code() : 'FAIL')); }
    $checks[] = $label;
}
function code($v, $code, $label) { check(is_wp_error($v) && $v->get_error_code() === $code, $label); }
function output($mode, array $extra = array()) {
    global $root, $checks;
    file_put_contents("$root/evidence/$mode.json", wp_json_encode(array_merge(array('passed'=>count($checks), 'checks'=>$checks), $extra), JSON_PRETTY_PRINT));
    echo count($checks) . " $mode assertions PASS.\n";
}
if ($mode === 'source') {
    $s = WP_Seed_Pixel_Recovery_Setup::prepare(); check($s, 'new source recovery root prepared');
    WP_Seed_Pixel_Recovery_Setup::apply(); check(WP_Seed_Pixel_Recovery_Setup::gate(), 'source storage gate');
    check(WP_Seed_Pixel_Job_Store::install(), 'native InnoDB schema');
    $ids = array(); $before = array(); $jobs = array();
    $existing = $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE kind='operation' ORDER BY id", ARRAY_A);
    foreach ($existing as $item) {
        check($item['stage'] === 'retained', 'resume existing synthetic retained fixture');
        $ids[] = (int)$item['attachment_id']; $jobs[] = (int)$item['job_id'];
        $before[(int)$item['attachment_id']] = json_decode($item['data'], true)['before'];
    }
    foreach ($existing ? array() : array('jpeg-gps.jpg', 'png-text.png', 'jpeg-exif.jpg') as $n => $fixture) {
        $bytes = file_get_contents(getenv('SEED_PORTABILITY_FIXTURES') . '/' . $fixture);
        $u = wp_upload_bits('synthetic-portability-' . $n . '-' . $fixture, null, $bytes); check(!$u['error'], 'synthetic upload');
        $id = wp_insert_attachment(array('post_title'=>'Synthetic ' . $n, 'post_mime_type'=>wp_check_filetype($fixture)['type']), $u['file']);
        wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $u['file']));
        if ($n === 0) {
            $m = wp_get_attachment_metadata($id); $i = getimagesize($u['file']);
            $m['sizes']['seed-pixel-view'] = array('file'=>basename($u['file']), 'width'=>$i[0], 'height'=>$i[1], 'filesize'=>filesize($u['file']));
            wp_update_attachment_metadata($id, $m);
        }
        $ids[] = $id; $before[$id] = WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true); check($before[$id], 'source before snapshot');
        $a = WP_Seed_Pixel_Metadata_Admin::analyze($id); check($a, 'source analysis');
        $j = WP_Seed_Pixel_Jobs::replace_one($id, array('master'=>'replace_verified', 'metadata'=>'anonymize'), 1073741824, '', $a['signature']); check($j, 'official metadata job');
        check(WP_Seed_Pixel_Jobs::step($j['id']), 'official metadata execution'); $jobs[] = (int)$j['id'];
    }
    $proof = WP_Seed_Pixel_Migration_Evidence::inspect(); check($proof, 'source transport admission');
    $bundle = WP_Seed_Pixel_Migration_Evidence::export("$root/a/staging", array($web, "$root/b/web")); check($bundle, 'immutable archive exported');
    $v = WP_Seed_Pixel_Migration_Evidence::verify_bundle($bundle['directory'], $bundle['manifest_sha256'], array($web, "$root/b/web"));
    check($v['state'] === 'VERIFIED_UNBOUND' && !$v['restoration_authorized'], 'transport never authorizes restoration');
    check(WP_Seed_Pixel_Migration_Evidence::inspect() === $proof, 'source SQL/public graph/identities unchanged');
    code(WP_Seed_Pixel_Migration_Evidence::export($web, array($web)), 'MIGRATION_UNSAFE_DESTINATION', 'public archive refused');
    $tables = array();
    foreach (array($wpdb->posts, $wpdb->postmeta, WP_Seed_Pixel_Job_Store::table('jobs'), WP_Seed_Pixel_Job_Store::table('items')) as $table) {
        $tables[$table] = $wpdb->get_results("SELECT * FROM $table ORDER BY 1", ARRAY_A);
    }
    $transport = array('tables'=>$tables, 'storage'=>$s, 'ids'=>$ids, 'jobs'=>$jobs, 'before'=>$before, 'bundle'=>$bundle);
    file_put_contents("$root/archives/source-state.bin", serialize($transport)); chmod("$root/archives/source-state.bin", 0600);
    output($mode, array('jobs'=>count($jobs), 'files'=>count($proof['files']), 'transport_only'=>true)); exit;
}
if ($mode === 'target' || $mode === 'target-review') {
    $source = unserialize(file_get_contents("$root/archives/source-state.bin"), array('allowed_classes'=>false));
    if ($mode === 'target') {
    $fresh = WP_Seed_Pixel_Recovery_Setup::prepare(); check($fresh, 'new independent target storage');
    check($fresh['path'] !== $source['storage']['path'] && $fresh['site'] !== $source['storage']['site'] && $fresh['token'] !== $source['storage']['token'], 'target identity/token/root isolated');
    check(WP_Seed_Pixel_Job_Store::install(), 'target native schema');
    foreach ($source['tables'] as $table => $rows) {
        check($wpdb->query("TRUNCATE TABLE $table") !== false, 'laboratory-only clone table');
        foreach ($rows as $row) { check($wpdb->insert($table, $row) !== false, 'laboratory clone row'); }
    }
    wp_cache_flush(); update_option(WP_Seed_Pixel_Recovery_Setup::OPTION, $source['storage'], false);
    }
    code(WP_Seed_Pixel_Recovery_Setup::verify(), 'IDENTITY_CHANGED', 'copied source identity rejected');
    check(!WP_Seed_Pixel_Recovery_Setup::gate(), 'clone cannot enable processing');
    $bundle = "$root/b/staging/" . basename($source['bundle']['directory']); $sha = $source['bundle']['manifest_sha256']; $roots = array("$root/a/web", $web);
    $v = WP_Seed_Pixel_Migration_Evidence::verify_bundle($bundle, $sha, $roots); check($v, 'copied evidence verified');
    $assessment = WP_Seed_Pixel_Migration_Evidence::assess_target($bundle, $sha, $roots); check($assessment, 'target SQL and public bytes exact');
    check($assessment['state'] === 'AWAITING_DESTINATION_BINDING' && !$assessment['restoration_authorized'] && !$assessment['public_graph_certified'], 'target remains safely unbound');
    $manifest = json_decode(file_get_contents($bundle . '/manifest.json'), true); $name = null;
    foreach (array_keys($manifest['files']) as $file) { if (str_contains($file, '/graph-original-')) { $name = $file; break; } }
    check($name !== null, 'actual recovery original selected for corruption test');
    $p = "$bundle/files/$name"; $bytes = file_get_contents($p); $stat = lstat($p);
    check($stat['ino'] !== $manifest['files'][$name]['ino'], 'copied evidence inode changed');
    rename($p, $p . '.missing'); code(WP_Seed_Pixel_Migration_Evidence::verify_bundle($bundle, $sha, $roots), 'MIGRATION_FILE_SET_CHANGED', 'missing original rejected'); rename($p . '.missing', $p);
    file_put_contents($p, str_repeat('x', strlen($bytes))); code(WP_Seed_Pixel_Migration_Evidence::verify_bundle($bundle, $sha, $roots), 'MIGRATION_ORIGINAL_CHANGED', 'corrupt original rejected'); file_put_contents($p, $bytes);
    link($p, $p . '.link'); code(WP_Seed_Pixel_Migration_Evidence::verify_bundle($bundle, $sha, $roots), 'MIGRATION_FOREIGN_FILE', 'hardlink rejected'); unlink($p . '.link');
    symlink($source['bundle']['directory'] . '/files/' . $name, $p . '.symlink'); code(WP_Seed_Pixel_Migration_Evidence::verify_bundle($bundle, $sha, $roots), 'MIGRATION_FOREIGN_FILE', 'source-store symlink rejected'); unlink($p . '.symlink');
    $item = $wpdb->get_row('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE kind='operation' ORDER BY id LIMIT 1", ARRAY_A);
    $wpdb->update(WP_Seed_Pixel_Job_Store::table('items'), array('data'=>$item['data'] . ' '), array('id'=>$item['id']));
    code(WP_Seed_Pixel_Migration_Evidence::assess_target($bundle, $sha, $roots), 'MIGRATION_TARGET_SQL_CONFLICT', 'target SQL conflict rejected');
    $wpdb->update(WP_Seed_Pixel_Job_Store::table('items'), array('data'=>$item['data']), array('id'=>$item['id']));
    check(WP_Seed_Pixel_Migration_Evidence::assess_target($bundle, $sha, $roots), 'exact backup restored without rewriting provenance');
    check(hash_file('sha256', $bundle . '/manifest.json') === $sha, 'source manifest immutable');
    check(is_wp_error(WP_Seed_Pixel_Jobs::restore_master($source['jobs'][0])), 'official target restore refuses unbound storage');
    output($mode, array('state'=>$assessment['state'], 'target_restore_certified'=>false)); exit;
}
if ($mode === 'restore') {
    $source = unserialize(file_get_contents("$root/archives/source-state.bin"), array('allowed_classes'=>false));
    foreach ($source['jobs'] as $n => $job) {
        check(WP_Seed_Pixel_Jobs::restore_master($job), 'official source restore');
        $id = $source['ids'][$n];
        check(WP_Seed_Pixel_Master_Adapter::snapshot($id, false, true) === $source['before'][$id], 'source master/derivatives/SQL exactly restored');
    }
    check(WP_Seed_Pixel_Migration_Evidence::verify_bundle($source['bundle']['directory'], $source['bundle']['manifest_sha256'], array($web, "$root/b/web")), 'export survives source lifecycle restore');
    output($mode, array('source_restore_certified'=>true, 'target_restore_certified'=>false)); exit;
}
if ($mode === 'admission') {
    $state = unserialize(file_get_contents("$root/archives/source-state.bin"), array('allowed_classes'=>false));
    $proof = json_decode(file_get_contents($state['bundle']['directory'] . '/manifest.json'), true);
    $method = new ReflectionMethod(WP_Seed_Pixel_Migration_Evidence::class, 'validate_rows');
    $rows = $proof['sql']; check($method->invoke(null, $rows), 'original SQL admitted');
    $modified = $rows; $modified['jobs'][0]['lease'] = 'synthetic-live-lease';
    code($method->invoke(null, $modified), 'MIGRATION_ACTIVE_JOB', 'live job refused');
    $modified = $rows; $modified['jobs'][0]['status'] = 'queued';
    code($method->invoke(null, $modified), 'MIGRATION_ACTIVE_JOB', 'queued job refused');
    $modified = $rows; $modified['items'][0]['lease_until'] = time() - 60;
    code($method->invoke(null, $modified), 'MIGRATION_SQL_CONFLICT', 'expired lease not cleared implicitly');
    $modified = $rows; $modified['items'][0]['stage'] = 'needs_review';
    code($method->invoke(null, $modified), 'MIGRATION_REVIEW_REQUIRED', 'unreconciled review refused');
    $modified = $rows; $modified['items'][0]['snapshot_hash'] = str_repeat('0',64);
    code($method->invoke(null, $modified), 'MIGRATION_SQL_CONFLICT', 'corrupt snapshot refused');
    $modified = $rows; $modified['jobs'][0]['policy_hash'] = str_repeat('0',64);
    code($method->invoke(null, $modified), 'MIGRATION_SQL_CONFLICT', 'corrupt policy refused');
    $modified = $rows; $modified['jobs'][0]['schema_version'] = '900';
    code($method->invoke(null, $modified), 'MIGRATION_SQL_CONFLICT', 'unknown schema refused');
    $modified = $rows; $modified['jobs'][0]['engine'] = 'unknown-engine';
    code($method->invoke(null, $modified), 'MIGRATION_ENGINE_NOT_CERTIFIED', 'unknown engine refused');
    $modified = $rows; $modified['jobs'][0]['kind'] = 'convert';
    code($method->invoke(null, $modified), 'MIGRATION_ENGINE_NOT_CERTIFIED', 'conversion migration explicitly uncertified');
    $modified = $rows; $modified['items'][1]['attachment_id'] = $modified['items'][0]['attachment_id'];
    code($method->invoke(null, $modified), 'MIGRATION_CLAIM_CONFLICT', 'two retained owners refused');
    $proof2 = WP_Seed_Pixel_Migration_Evidence::inspect(); check($proof2, 'post-restore source evidence intact');
    check(WP_Seed_Pixel_Migration_Evidence::inspect() === $proof2, 'read-only inspection repeat exact');
    code(WP_Seed_Pixel_Migration_Evidence::assess_target($state['bundle']['directory'], $state['bundle']['manifest_sha256'], array($web, "$root/b/web")), 'MIGRATION_SHARED_AUTHORITY', 'same source SQL authority refused');
    $export = WP_Seed_Pixel_Migration_Evidence::export("$root/a/staging", array($web, "$root/b/web")); check($export, 'post-restore export');
    code(WP_Seed_Pixel_Migration_Evidence::verify_bundle($export['directory'], str_repeat('0',64), array($web, "$root/b/web")), 'MIGRATION_EVIDENCE_INVALID', 'external digest required');
    file_put_contents($proof2['storage']['path'] . '/unclassified.bin', 'synthetic'); chmod($proof2['storage']['path'] . '/unclassified.bin',0600);
    code(WP_Seed_Pixel_Migration_Evidence::inspect(), 'MIGRATION_UNCLASSIFIED_RECOVERY', 'orphan private evidence refused');
    unlink($proof2['storage']['path'] . '/unclassified.bin');
    output($mode); exit;
}
if ($mode === 'cli') {
    if (!class_exists('WP_Seed_Pixel_Migration_CLI')) { require dirname(__DIR__) . '/includes/class-migration-cli.php'; }
    class WP_CLI {
        public static $lines = array();
        public static function line($line) { self::$lines[] = $line; }
        public static function error($message) { throw new RuntimeException($message); }
    }
    WP_Seed_Pixel_Migration_CLI::run(array('inspect'), array());
    $summary = json_decode(WP_CLI::$lines[0], true);
    check($summary['state'] === 'SOURCE_VERIFIED' && !$summary['restoration_authorized'], 'CLI summary explicitly unbound');
    check(!isset($summary['sql'], $summary['storage'], $summary['public'], $summary['context']), 'CLI does not print private evidence');
    $rejected = false;
    try { WP_Seed_Pixel_Migration_CLI::run(array('import'), array('webroots'=>wp_json_encode(array($web)))); }
    catch (RuntimeException $e) { $rejected = true; }
    check($rejected, 'CLI cannot import or bind');
    $rejected = false;
    try { WP_Seed_Pixel_Migration_CLI::run(array('bind'), array('webroots'=>wp_json_encode(array($web)),'bundle'=>'/not-a-bundle','sha256'=>str_repeat('0',64))); }
    catch (RuntimeException $e) { $rejected = str_contains($e->getMessage(),'--confirm'); }
    check($rejected,'CLI binding requires explicit confirmation before access');
    check(wp_mail('qa@example.invalid','Synthetic blocked mail','Synthetic') === false, 'native WordPress mail blocked before transport');
    code(wp_remote_get('https://example.invalid'), 'offline_lab', 'native outbound HTTP blocked');
    check(get_option('blog_public') === '0', 'laboratory noindex');
    output($mode); exit;
}
if ($mode === 'albums-a' || $mode === 'albums-b') {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    check(!is_wp_error(activate_plugin('wp-seed-albums/wp-seed-albums.php')), 'generic Albums activation');
    WPSeed\Albums\register();
    $data = unserialize(file_get_contents("$root/archives/source-state.bin"), array('allowed_classes'=>false));
    $ids = array_reverse($data['ids']);
    $hashes = array(); foreach ($ids as $id) { $hashes[$id] = hash_file('sha256', get_attached_file($id)); }
    $jobs = $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . ' ORDER BY id', ARRAY_A);
    if ($site === 'a') {
        $album = wp_insert_post(array('post_type'=>WPSeed\Albums\TYPE, 'post_title'=>'Synthetic portable album', 'post_status'=>'publish'));
        check(WPSeed\Albums\attach($album, $ids), 'native membership insertion');
        check(WPSeed\Albums\media_ids($album) === $ids, 'source order');
        $removed = WPSeed\Albums\remove_many($album, array($ids[1])); check($removed, 'source removal receipt');
        check(WPSeed\Albums\undo_many($album, $removed['receipt']), 'source undo exact');
        $snapshot = array('album'=>$album, 'post'=>get_post($album, ARRAY_A), 'ids'=>$ids, 'receipt'=>$removed['receipt']);
        file_put_contents("$root/archives/album.bin", serialize($snapshot)); chmod("$root/archives/album.bin",0600);
    } else {
        $snapshot = unserialize(file_get_contents("$root/archives/album.bin"), array('allowed_classes'=>false));
        $album = $snapshot['album']; check(get_post($album) === null, 'target album ID available');
        $post = $snapshot['post']; unset($post['ID']); $post['import_id'] = $album;
        check(wp_insert_post($post) === $album, 'preserved album identity on target');
        check(WPSeed\Albums\attach($album, $snapshot['ids']), 'target native relations');
        check(WPSeed\Albums\media_ids($album) === $snapshot['ids'], 'target order exact');
        check(WPSeed\Albums\read_receipt($snapshot['receipt'], $album) === false, 'source undo token rejected by target salt');
        $remove = WPSeed\Albums\remove_many($album, array($ids[1])); check($remove, 'target own removal');
        check(WPSeed\Albums\undo_many($album, $remove['receipt']), 'target own undo');
        check(WPSeed\Albums\media_ids($album) === $snapshot['ids'], 'target restore order exact');
    }
    foreach ($ids as $id) {
        $r = WPSeed\Albums\resource($id, 'thumb'); check($r, 'native attachment resource');
        check(str_starts_with($r['url'], home_url() . '/wp-content/uploads/'), 'URL resolves to own WordPress installation');
        check(hash_file('sha256', get_attached_file($id)) === $hashes[$id], 'Album operations do not process images');
    }
    check($wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . ' ORDER BY id', ARRAY_A) === $jobs, 'no new Pixel job');
    output($mode); exit;
}
throw new RuntimeException('Explicit test mode required');
