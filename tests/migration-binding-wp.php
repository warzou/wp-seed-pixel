<?php
$root = getenv('SEED_PORTABILITY_LAB'); $mode = $argv[1] ?? '';
if (!$root || realpath($root) !== $root || trim(file_get_contents($root . '/synthetic-lab.marker')) !== 'wp-seed-portability-synthetic-v1') { throw new RuntimeException('Owned synthetic lab required'); }
$target = getenv('SEED_BIND_SITE') ?: (str_starts_with($mode,'isolation-') ? 'f' : (str_starts_with($mode,'backup-') ? 'c' : 'b'));
if (!in_array($target,array('b','c','f','g','h'),true)) { throw new RuntimeException('Synthetic target required'); }
if (str_starts_with($mode,'isolation-')) { $mode = substr($mode,10); }
$web = $root . '/' . $target . '/web'; $_SERVER['DOCUMENT_ROOT'] = $web;
require $web . '/wp-load.php'; wp_set_current_user(1);
$checks = array();
function check($value,$label) {
    global $checks; if (is_wp_error($value) || !$value) { throw new RuntimeException($label . ': ' . (is_wp_error($value) ? $value->get_error_code() : 'FAIL')); }
    $checks[] = $label;
}
function error_code($value,$code,$label) { check(is_wp_error($value) && $value->get_error_code() === $code,$label); }
function output($mode) { global $root,$checks; file_put_contents($root . '/evidence/binding-' . $mode . '.json',wp_json_encode(array('passed'=>count($checks),'checks'=>$checks),JSON_PRETTY_PRINT)); echo count($checks) . " $mode assertions PASS.\n"; }
$source = unserialize(file_get_contents($root . '/archives/source-state.bin'),array('allowed_classes'=>false));
$bundle = $root . '/' . $target . '/staging/' . basename($source['bundle']['directory']); $sha = $source['bundle']['manifest_sha256'];
$roots = array($root . '/a/web',$web);
if (!is_dir($roots[0])) { $roots = array($web); }
if ($mode === 'crosswrite') {
    $db = new wpdb('root','','seed_portability_a','localhost:' . $root . '/mysql.sock');
    foreach ($source['tables'] as $table=>$rows) { check($db->get_results("SELECT * FROM $table ORDER BY 1",ARRAY_A) === $rows,'source business table exact after target activity'); }
    $manifest = json_decode(file_get_contents($source['bundle']['directory'] . '/manifest.json'),true);
    foreach ($manifest['files'] as $name=>$file) {
        $path = $manifest['storage']['path'] . '/' . $name;
        check(hash_file('sha256',$path) === $file['sha256'] && lstat($path)['ino'] === $file['ino'],'source recovery bytes/inodes untouched');
    }
    foreach ($manifest['public'] as $snapshot) {
        foreach ($snapshot['files'] as $name=>$file) { check(hash_file('sha256',$manifest['context']['uploads'] . '/' . $name) === $file['sha256'],'source public file unchanged'); }
    }
    output($mode); exit;
}
if ($mode === 'collisions') {
    $option = get_option(WP_Seed_Pixel_Recovery_Setup::OPTION); $table = WP_Seed_Pixel_Job_Store::table('items');
    $row = $wpdb->get_row("SELECT * FROM $table ORDER BY id LIMIT 1",ARRAY_A);
    $wpdb->update($table,array('attachment_id'=>(int)$row['attachment_id'] + 1),array('id'=>$row['id']));
    error_code(WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,$roots),'MIGRATION_TARGET_SQL_CONFLICT','attachment ID collision refused before enrollment');
    $wpdb->update($table,array('attachment_id'=>$row['attachment_id']),array('id'=>$row['id']));
    update_option('upload_path',$source['storage']['webroot'] . '/wp-content/uploads'); wp_upload_dir(null,false,true);
    error_code(WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,$roots),'MIGRATION_SHARED_UPLOADS','source upload directory refused without touching a file');
    delete_option('upload_path'); wp_upload_dir(null,false,true);
    $candidate = WP_Seed_Pixel_Recovery_Setup::candidate(); check($candidate,'collision target candidate');
    mkdir($candidate['path'],0700); file_put_contents($candidate['path'] . '/foreign.bin','synthetic foreign owner');
    error_code(WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,$roots),'FOREIGN_DIRECTORY','foreign recovery directory refused');
    rename($candidate['path'],$candidate['path'] . '-preserved-foreign');
    check(get_option(WP_Seed_Pixel_Recovery_Setup::OPTION) === $option,'negative collision tests do not replace storage option');
    output('collisions'); exit;
}
if ($mode === 'negative') {
    $state = WP_Seed_Pixel_Recovery_Setup::verify(); $rows = $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' ORDER BY id',ARRAY_A);
    $manifest = $bundle . '/manifest.json'; $raw = file_get_contents($manifest);
    file_put_contents($manifest,$raw . ' '); error_code(WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,$roots),'MIGRATION_EVIDENCE_INVALID','altered external proof rejected'); file_put_contents($manifest,$raw);
    $item = $rows[0]; $dir = WP_Seed_Pixel_Master_Storage::directory($item,false); $r = WP_Seed_Pixel_Master_Storage::load($dir,$item);
    $file = $dir . '/' . reset($r['graph_files'])['original']; $bytes = file_get_contents($file);
    rename($file,$file . '.missing'); $q = WP_Seed_Pixel_Quarantine::inspect($item);
    check(is_wp_error($q) || !$q['rollback_available'],'missing target original refused'); rename($file . '.missing',$file);
    file_put_contents($file,str_repeat('x',strlen($bytes))); $q = WP_Seed_Pixel_Quarantine::inspect($item);
    check(is_wp_error($q) || !$q['rollback_available'],'corrupt target original refused'); file_put_contents($file,$bytes);
    $p = $state['path'] . '/migration-source/manifest.json'; $raw = file_get_contents($p); file_put_contents($p,$raw . ' ');
    check(!WP_Seed_Pixel_Recovery_Setup::gate(),'altered provenance disables normal storage gate'); file_put_contents($p,$raw);
    check(WP_Seed_Pixel_Recovery_Setup::gate(),'exact provenance backup restores gate');
    check($wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' ORDER BY id',ARRAY_A) === $rows,'negative read-only checks never change claims/rows');
    check(WP_Seed_Pixel_Quarantine::inspect($item)['rollback_available'],'exact recovery after negative tests');
    output($mode); exit;
}
if ($mode === 'backup') {
    $proof = WP_Seed_Pixel_Migration_Evidence::inspect(); check($proof,'bound installation can export again');
    $export = WP_Seed_Pixel_Migration_Evidence::export($root . '/' . $target . '/staging',$roots); check($export,'second-generation evidence export');
    $tables = array();
    foreach ($wpdb->get_col('SHOW TABLES') as $table) { $tables[$table] = $wpdb->get_results("SELECT * FROM `$table`",ARRAY_A); }
    $backup = array('tables'=>$tables,'storage'=>get_option(WP_Seed_Pixel_Recovery_Setup::OPTION),'bundle'=>$export);
    file_put_contents($root . '/archives/full-backup.bin',serialize($backup)); chmod($root . '/archives/full-backup.bin',0600);
    file_put_contents($root . '/archives/recovery-path.txt',$backup['storage']['path']); chmod($root . '/archives/recovery-path.txt',0600);
    output($mode); exit;
}
if ($mode === 'backup-clone') {
    $backup = unserialize(file_get_contents($root . '/archives/full-backup.bin'),array('allowed_classes'=>false));
    check(WP_Seed_Pixel_Job_Store::install(),'restored backup target schema');
    foreach ($backup['tables'] as $table=>$rows) {
        check($wpdb->query("TRUNCATE TABLE `$table`") !== false,'full backup table');
        foreach ($rows as $row) { check($wpdb->insert($table,$row) !== false,'full backup row'); }
    }
    wp_cache_flush(); error_code(WP_Seed_Pixel_Recovery_Setup::verify(),'IDENTITY_CHANGED','full copied storage correctly requires new identity');
    output($mode); exit;
}
if ($mode === 'backup-bind' || $mode === 'backup-restore') {
    $backup = unserialize(file_get_contents($root . '/archives/full-backup.bin'),array('allowed_classes'=>false));
    $bundle = $root . '/' . $target . '/staging/' . basename($backup['bundle']['directory']); $sha = $backup['bundle']['manifest_sha256'];
    if ($mode === 'backup-bind') {
        check(WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,array($web)),'full backup rebound on third independent installation');
    } else {
        foreach ($source['jobs'] as $n=>$job) {
            check(WP_Seed_Pixel_Jobs::restore_master($job),'full backup target official restore');
            $actual = WP_Seed_Pixel_Master_Adapter::snapshot($source['ids'][$n],false,true); check($actual,'backup native snapshot');
            $expected = $source['before'][$source['ids'][$n]]; $expected['url'] = wp_get_attachment_url($source['ids'][$n]);
            check($actual === $expected,'backup original bytes/metadata/derivatives exact');
        }
    }
    output($mode); exit;
}
if ($mode === 'clone') {
    check(WP_Seed_Pixel_Job_Store::install(),'fresh target schema');
    check(get_option(WP_Seed_Pixel_Recovery_Setup::OPTION,null) === null,'target never enrolled previously');
    foreach ($source['tables'] as $table=>$rows) {
        check($wpdb->query("TRUNCATE TABLE $table") !== false,'synthetic clone table');
        foreach ($rows as $row) { check($wpdb->insert($table,$row) !== false,'synthetic clone row'); }
    }
    wp_cache_flush(); update_option(WP_Seed_Pixel_Recovery_Setup::OPTION,$source['storage'],false);
    error_code(WP_Seed_Pixel_Recovery_Setup::verify(),'IDENTITY_CHANGED','copied source storage rejected');
    output($mode); exit;
}
if ($mode === 'bind' || $mode === 'resume') {
    $r = WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,$roots); check($r,'destination binding');
    check($r['state'] === 'BOUND_VERIFIED' && $r['restoration_authorized'],'binding certifies native runtime');
    $s = WP_Seed_Pixel_Recovery_Setup::verify(); check($s,'new target marker');
    check($s['site'] !== $source['storage']['site'] && $s['path'] !== $source['storage']['path'],'independent target identity/root');
    check(WP_Seed_Pixel_Recovery_Setup::gate(),'normal gate passes without override');
    foreach ($source['jobs'] as $j) {
        $item = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation'",$j),ARRAY_A);
        $q = WP_Seed_Pixel_Quarantine::inspect($item); check($q['rollback_available'],'official target recovery available');
        $r = WP_Seed_Pixel_Master_Storage::load(WP_Seed_Pixel_Master_Storage::directory($item,false),$item); check($r,'bound target journal validates');
        check(WP_Seed_Pixel_Metadata_Graph_Transaction::verify($item,$r),'target public graph certified CLEAN');
        check($r['before']['url'] !== $source['before'][$item['attachment_id']]['url'],'URL projected to target');
    }
    check(hash_file('sha256',$s['path'] . '/migration-source/manifest.json') === $sha,'source manifest byte exact');
    output($mode); exit;
}
if ($mode === 'interrupt') {
    add_action('wp_seed_pixel_migration_boundary',function($phase) { if ($phase === 'sql_committed') { throw new RuntimeException('SIMULATED_INTERRUPT'); } });
    try { WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,$roots); throw new RuntimeException('Expected interruption'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'SIMULATED_INTERRUPT','interruption at durable SQL boundary'); }
    check(!WP_Seed_Pixel_Recovery_Setup::gate(),'interrupted binding fails closed');
    check(get_option(WP_Seed_Pixel_Recovery_Setup::OPTION)['state'] === 'migration_pending','pending state persisted');
    output($mode); exit;
}
if ($mode === 'restore') {
    $state = WP_Seed_Pixel_Recovery_Setup::verify(); $hash = hash_file('sha256',$state['path'] . '/migration-source/manifest.json');
    foreach ($source['jobs'] as $n=>$j) {
        check(WP_Seed_Pixel_Jobs::restore_master($j),'official target restoration');
        $id = $source['ids'][$n]; $actual = WP_Seed_Pixel_Master_Adapter::snapshot($id,false,true); check($actual,'target restored snapshot');
        $expected = $source['before'][$id]; $expected['url'] = wp_get_attachment_url($id);
        $p = get_attached_file($id); $expected['uid'] = fileowner($p); $expected['gid'] = filegroup($p); $expected['mode'] = fileperms($p) & 0777;
        check($actual === $expected,'exact target original bytes/metadata/dimensions/roles and native SQL');
    }
    check(hash_file('sha256',$state['path'] . '/migration-source/manifest.json') === $hash,'source provenance not rewritten by restore');
    output($mode); exit;
}
throw new RuntimeException('Explicit binding mode required');
