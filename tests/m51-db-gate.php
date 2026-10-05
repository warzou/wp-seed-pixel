<?php
require __DIR__ . '/m3-runtime.php';
global $wpdb;
if (getenv('PIXEL_M3_ROOT') !== '/home/warzy/.cache/wp-seed-pixel-m3-environment'
    || $wpdb->get_var('SELECT DATABASE()') !== 'pixel_m3') { throw new RuntimeException('Disposable lab required'); }
$checks = array();
function gate_check($value, $label) { global $checks; $checks[$label] = (bool) $value; if (!$value) { throw new RuntimeException($label); } }
$jobs = WP_Seed_Pixel_Job_Store::table('jobs'); $items = WP_Seed_Pixel_Job_Store::table('items');
$wpdb->query("DROP TABLE IF EXISTS $items"); $wpdb->query("DROP TABLE IF EXISTS $jobs");
delete_option('wp_seed_pixel_job_schema'); delete_option('wp_seed_pixel_scan_schema');
gate_check(!is_wp_error(WP_Seed_Pixel_Job_Store::install()), 'Fresh schema installs against actual SQL engine');
gate_check((int) get_option('wp_seed_pixel_job_schema') === 2, 'Fresh schema version is 2');
$create_before = $wpdb->get_row("SHOW CREATE TABLE $items", ARRAY_N)[1];
gate_check(!is_wp_error(WP_Seed_Pixel_Job_Store::install()) && $wpdb->get_row("SHOW CREATE TABLE $items", ARRAY_N)[1] === $create_before, 'Current migration is idempotent');
$wpdb->query("DROP TABLE $items"); $wpdb->query("DROP TABLE $jobs");
delete_option('wp_seed_pixel_job_schema'); delete_option('wp_seed_pixel_scan_schema');
gate_check(!is_wp_error(WP_Seed_Pixel_Scan::install()), 'Previous M1 schema installs');
$wpdb->insert($jobs, array('kind'=>'scan','status'=>'complete','actor'=>get_current_user_id(),'created'=>time()));
$legacy_job = (int) $wpdb->insert_id;
$wpdb->insert($items, array('job_id'=>$legacy_job,'kind'=>'attachment','item_key'=>'legacy','stage'=>'analyzed','data'=>'{"historical":true}'));
$legacy_item = (int) $wpdb->insert_id;
gate_check(!is_wp_error(WP_Seed_Pixel_Job_Store::install()), 'Previous schema upgrades to M2 without authority table');
gate_check($wpdb->get_var($wpdb->prepare("SELECT data FROM $items WHERE id=%d", $legacy_item)) === '{"historical":true}'
    && $wpdb->get_var($wpdb->prepare("SELECT kind FROM $jobs WHERE id=%d", $legacy_job)) === 'scan', 'Migration preserves historical scan state');
$indexes = $wpdb->get_results("SHOW INDEX FROM $items", ARRAY_A);
$names = array_unique(array_column($indexes, 'Key_name'));
gate_check(!array_diff(array('PRIMARY','item_identity','job_kind','job_stage','attachment_stage','job_cursor'), $names), 'Actual item indexes present');
$job_indexes = array_unique(array_column($wpdb->get_results("SHOW INDEX FROM $jobs", ARRAY_A), 'Key_name'));
gate_check(!array_diff(array('PRIMARY','status_updated','kind_status'), $job_indexes), 'Actual job indexes present');
$engines = $wpdb->get_col($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (%s,%s)', $jobs, $items));
gate_check(count($engines) === 2 && !array_diff(array_map('strtolower',$engines), array('innodb')), 'Both lifecycle tables use InnoDB');
$unique = array_values(array_filter($indexes, static function ($r) { return $r['Key_name'] === 'item_identity'; }));
gate_check(count($unique) === 3 && (int) $unique[0]['Non_unique'] === 0, 'Item identity is an enforced unique three-column index');
$old_errors = $wpdb->suppress_errors(true);
$duplicate = $wpdb->insert($items, array('job_id'=>$legacy_job,'kind'=>'attachment','item_key'=>'legacy','stage'=>'analyzed','data'=>'{}'));
gate_check($duplicate === false && $wpdb->last_error !== '', 'Database rejects duplicate item identity');
$wpdb->suppress_errors($old_errors);
$columns = array_column($wpdb->get_results("SHOW COLUMNS FROM $items", ARRAY_A), null, 'Field');
gate_check(stripos($columns['revision']['Type'],'unsigned') !== false && $columns['revision']['Null'] === 'NO', 'Revision is unsigned and non-null');
gate_check(!is_wp_error(WP_Seed_Pixel_Job_Store::install()), 'Migrated schema remains idempotent');

$policy = WP_Seed_Pixel_Policy::normalize(array());
$job = WP_Seed_Pixel_Job_Store::insert_job('simulation', 0, $policy, 1);
$token = bin2hex(random_bytes(24));
$wpdb->insert($items, array('job_id'=>$job,'kind'=>'operation','item_key'=>'cas','attachment_id'=>99001,'stage'=>'queued','action'=>'simulate','data'=>'{}','lease'=>$token,'lease_until'=>time()+60));
$id = (int) $wpdb->insert_id; $before = WP_Seed_Pixel_Job_Store::item($id);
$ext = getenv('PIXEL_M3_ROOT') . '/root/usr/lib/php/20250925';
$workers = array(); $at = microtime(true) + 3;
for ($i=0;$i<2;$i++) {
    $cmd = array(PHP_BINARY,'-d',"extension=$ext/gd.so",'-d',"extension=$ext/mysqli.so",'-d',"extension=$ext/imagick.so",__DIR__.'/m51-db-gate-worker.php',(string)$id,$token,(string)$at);
    $p = proc_open($cmd,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes); fclose($pipes[0]); $workers[]=array($p,$pipes);
}
$won=0;
foreach ($workers as [$p,$pipes]) { $out=json_decode(stream_get_contents($pipes[1]),true);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);gate_check(proc_close($p)===0 && !$error,'Concurrent CAS worker exits cleanly '.count($checks));$won+=(int)($out['won']??false); }
gate_check($won===1 && (int)WP_Seed_Pixel_Job_Store::item($id)['revision']===1,'Actual two-worker CAS grants exactly one durable transition');
gate_check(is_wp_error(WP_Seed_Pixel_Job_Store::transition($before,$token,'preparing')),'Stale revision cannot replay transition');
$fresh=WP_Seed_Pixel_Job_Store::item($id);
gate_check(is_wp_error(WP_Seed_Pixel_Job_Store::transition($fresh,'wrong-token','ready')),'Wrong token cannot transition');
$site=WP_Seed_Pixel_Files::lock(0);$attachment=WP_Seed_Pixel_Files::lock(99001);
gate_check(!is_wp_error($site)&&!is_wp_error($attachment),'Real database allows coordinator and attachment ownership together');
$wpdb->query('START TRANSACTION');
$r=WP_Seed_Pixel_Job_Store::transition($fresh,$token,'ready');
gate_check(!is_wp_error($r),'Transition executes inside actual transaction');
$wpdb->query('ROLLBACK');
gate_check(WP_Seed_Pixel_Job_Store::item($id)===$fresh,'Rollback restores exact durable item state');
gate_check(WP_Seed_Pixel_Authority::valid(0)&&WP_Seed_Pixel_Authority::valid(99001),'Rollback does not release advisory ownership');
$wpdb->query('START TRANSACTION');$wpdb->update($items,array('attempts'=>99),array('id'=>$id));
$old_errors=$wpdb->suppress_errors(true);
$error=$wpdb->query("INSERT INTO $items (job_id,kind,item_key,stage,data) VALUES ($job,'operation','cas','queued','{}')");
$wpdb->query('ROLLBACK');$wpdb->suppress_errors($old_errors);
gate_check($error===false && WP_Seed_Pixel_Job_Store::item($id)===$fresh,'Real SQL constraint error plus rollback leaves no split state');
$wpdb->query('START TRANSACTION');$wpdb->query('COMMIT');
gate_check(WP_Seed_Pixel_Authority::valid_all(),'Commit does not release advisory ownership');
WP_Seed_Pixel_Files::unlock($attachment);WP_Seed_Pixel_Files::unlock($site);
gate_check(!WP_Seed_Pixel_Authority::valid_all(),'Owner release clears local authority');
$wpdb->insert($items,array('job_id'=>$job,'kind'=>'operation','item_key'=>'deadlock','attachment_id'=>99002,'stage'=>'queued','action'=>'simulate','data'=>'{}'));
$second=(int)$wpdb->insert_id;$wpdb->update($items,array('attempts'=>0),array('id'=>$id));
$workers=array();$at=microtime(true)+3;
foreach (array(array($id,$second),array($second,$id)) as $pair) {
    $cmd=array(PHP_BINARY,'-d',"extension=$ext/gd.so",'-d',"extension=$ext/mysqli.so",'-d',"extension=$ext/imagick.so",__DIR__.'/m51-db-gate-worker.php','deadlock',(string)$pair[0],(string)$pair[1],(string)$at);
    $p=proc_open($cmd,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);fclose($pipes[0]);$workers[]=array($p,$pipes);
}
$committed=0;$victims=0;
foreach($workers as [$p,$pipes]){$r=json_decode(stream_get_contents($pipes[1]),true);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);gate_check(proc_close($p)===0&&!$error,'Deadlock worker exits cleanly '.count($checks));$committed+=(int)$r['committed'];$victims+=(int)($r['errno']===1213);}
gate_check($committed===1&&$victims===1,'Real InnoDB deadlock has one committed transaction and one aborted victim');
gate_check((int)WP_Seed_Pixel_Job_Store::item($id)['attempts']===1&&(int)WP_Seed_Pixel_Job_Store::item($second)['attempts']===1,'Aborted deadlock transaction leaves no partial increment');
wp_mkdir_p(dirname(__DIR__).'/reports/storage-m5.1/authority-gate');
file_put_contents(dirname(__DIR__).'/reports/storage-m5.1/authority-gate/schema-cas.json',wp_json_encode(array('checks'=>$checks,'database'=>$wpdb->get_var('SELECT VERSION()'),'wordpress'=>get_bloginfo('version')),JSON_PRETTY_PRINT));
echo count($checks)." database schema/CAS/transaction checks passed.\n";
