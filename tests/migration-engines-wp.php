<?php
$root = getenv('SEED_PORTABILITY_LAB'); $site = $argv[1] ?? ''; $mode = $argv[2] ?? '';
if (!$root || realpath($root) !== $root || trim(file_get_contents($root . '/synthetic-lab.marker')) !== 'wp-seed-portability-synthetic-v1'
    || !in_array($site,array('e','i','j','k','l','m','n','o'),true)) { throw new RuntimeException('Owned synthetic lab required'); }
$_SERVER['DOCUMENT_ROOT'] = "$root/$site/web"; require $_SERVER['DOCUMENT_ROOT'] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php'; wp_set_current_user(1); $checks = array();
function check($v,$label) { global $checks; if (is_wp_error($v) || !$v) { throw new RuntimeException($label . ': ' . (is_wp_error($v) ? $v->get_error_code() : 'FAIL')); } $checks[] = $label; }
function tables() { global $wpdb; $out = array(); foreach ($wpdb->get_col('SHOW TABLES') as $table) { $out[$table] = $wpdb->get_results("SELECT * FROM `$table` ORDER BY 1",ARRAY_A); } return $out; }
function items() { global $wpdb; return $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . ' ORDER BY id',ARRAY_A); }
function dump_result($mode) { global $root,$site,$checks; file_put_contents("$root/evidence/engines-$site-$mode.json",wp_json_encode(array('passed'=>count($checks),'checks'=>$checks),JSON_PRETTY_PRINT)); echo count($checks) . " engines $site/$mode assertions PASS.\n"; }
function fixture($large) {
    $source = '/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/.runtime/format-fixtures/large-photo.png';
    $image = imagecreatefrompng($source); check($image,'synthetic PNG decoder');
    if ($large && max(imagesx($image),imagesy($image)) <= 2560) {
        $scaled = imagescale($image,3000); unset($image); $image = $scaled;
    }
    ob_start(); imagejpeg($image,null,100); $bytes = ob_get_clean(); unset($image);
    $u = wp_upload_bits('migration-engine-' . bin2hex(random_bytes(6)) . '-' . ($large ? 'retire' : 'jpeg') . '.jpg',null,$bytes); check(!$u['error'],'synthetic JPEG upload');
    $id = wp_insert_attachment(array('post_title'=>'Synthetic migration engine','post_mime_type'=>'image/jpeg'),$u['file']);
    wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$u['file'])); return $id;
}
if ($mode === 'inspect') {
    $method = new ReflectionMethod(WP_Seed_Pixel_Migration_Evidence::class,'validate_rows');
    foreach ($wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . ' ORDER BY id',ARRAY_A) as $job) {
        $v = $method->invoke(null,array('jobs'=>array($job),'items'=>array_values(array_filter(items(),fn($item)=>$item['job_id'] === $job['id']))));
        echo 'Validation ' . $job['id'] . ': ' . (is_wp_error($v) ? $v->get_error_code() : 'PASS') . "\n";
    }
    foreach ($wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . ' ORDER BY id',ARRAY_A) as $row) { echo wp_json_encode(array_merge(array_intersect_key($row,array_flip(array('id','kind','engine','schema_version','status','lease','lease_until'))),array('policy_valid'=>$row['policy_hash'] === hash('sha256',$row['policy'])))) . "\n"; }
    foreach (items() as $item) { echo wp_json_encode(array_merge(array_intersect_key($item,array_flip(array('id','attachment_id','action','stage','error_code','receipt','lease','lease_until'))),array('journal_valid'=>WP_Seed_Pixel_Job_Store::journal_valid($item),'data_valid'=>$item['snapshot_hash'] === hash('sha256',$item['data'])))) . "\n"; } exit;
}
if ($mode === 'prepare-source') {
    check($site === 'e','existing synthetic source only');
    foreach (array(false,true) as $large) {
        $title = 'Migration fixture ' . ($large ? 'retire' : 'replace');
        $id = (int)$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title=%s LIMIT 1",$title));
        if (!$id) { $id = fixture($large); wp_update_post(array('ID'=>$id,'post_title'=>$title)); }
        $existing = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE attachment_id=%d AND kind='operation' ORDER BY id DESC LIMIT 1",$id),ARRAY_A);
        if (!$existing) {
            $job = $large ? WP_Seed_Pixel_Jobs::retire_original($id,1073741824,true)
                : WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified'),1073741824);
            check($job,'ordinary engine admission'); check(WP_Seed_Pixel_Jobs::step($job['id']),'ordinary engine execution');
            check(WP_Seed_Pixel_Jobs::quarantine_action($job['id'],'retain'),'ordinary quarantine enrollment');
        } elseif ($existing['stage'] === 'recovery_required' && $existing['error_code'] === 'VERIFY_FAILED') {
            check(WP_Seed_Pixel_Jobs::control((int)$existing['job_id'],'resume'),'official paused job resume');
            check(WP_Seed_Pixel_Jobs::step((int)$existing['job_id']),'resume genuine local HTTP verification');
            check(WP_Seed_Pixel_Jobs::quarantine_action((int)$existing['job_id'],'retain'),'ordinary resumed quarantine enrollment');
        }
    }
    foreach (items() as $item) {
        if ($item['stage'] === 'retained') { check(WP_Seed_Pixel_Migration_Binding::availability($item),'native retained engine recoverable before migration'); }
    }
    dump_result($mode); exit;
}
if ($mode === 'prepare-mixed' || $mode === 'finish-mixed') {
    check($site === 'k','fresh synthetic mixed source only');
    check(WP_Seed_Pixel_Recovery_Setup::prepare(),'mixed source storage'); WP_Seed_Pixel_Recovery_Setup::apply();
    check(WP_Seed_Pixel_Job_Store::install(),'mixed native schema before first read');
    if ($mode === 'prepare-mixed') {
    $already = items(); check(!$already,'one-shot fresh mixed fixture generation');
    foreach (array('jpeg-gps.jpg','png-text.png') as $name) {
        $id = (int)$wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value LIKE %s LIMIT 1",'%/mixed-metadata-' . $name));
        if (!$id) {
            $u = wp_upload_bits('mixed-metadata-' . $name,null,file_get_contents(getenv('SEED_PORTABILITY_FIXTURES') . '/' . $name)); check(!$u['error'],'metadata synthetic upload');
            $id = wp_insert_attachment(array('post_title'=>'Synthetic active graph','post_mime_type'=>wp_check_filetype($name)['type']),$u['file']);
            wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$u['file']));
        }
        $a = WP_Seed_Pixel_Metadata_Admin::analyze($id); check($a,'mixed graph analysis');
        $j = WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified','metadata'=>'anonymize'),1073741824,'',$a['signature']); check($j,'mixed graph admission');
        check(WP_Seed_Pixel_Jobs::step($j['id']),'mixed graph execution');
    }
    $png = file_get_contents('/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/.runtime/format-fixtures/smooth.png');
    $text = "tEXtAuthor\0Synthetic private witness"; $chunk = pack('N',strlen($text)-4) . $text . pack('N',crc32($text));
    $png = substr($png,0,33) . $chunk . substr($png,33);
    $u = wp_upload_bits('mixed-chain.png',null,$png); check(!$u['error'],'same attachment synthetic chain');
    $id = wp_insert_attachment(array('post_title'=>'Synthetic mixed history','post_mime_type'=>'image/png'),$u['file']);
    wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$u['file']));
    $before = WP_Seed_Pixel_Master_Adapter::snapshot($id,false,true); check($before,'chain exact native before');
    $a = WP_Seed_Pixel_Metadata_Admin::analyze($id); check($a,'chain privacy analysis');
    $j = WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified','metadata'=>'anonymize'),1073741824,'',$a['signature']); check($j,'chain privacy admission');
    check(WP_Seed_Pixel_Jobs::step($j['id']),'chain anonymization');
    check(WP_Seed_Pixel_Jobs::restore_master($j['id']),'chain official privacy restore');
    check(WP_Seed_Pixel_Master_Adapter::snapshot($id,false,true) === $before,'chain original PNG exact');
    $a = WP_Seed_Pixel_Format_Conversion::analyze($id);
    check(is_wp_error($a) && $a->get_error_code() === 'TARGET_METADATA_UNSUPPORTED','private text PNG correctly refused by conversion engine');
    }
    $png = file_get_contents('/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/.runtime/format-fixtures/smooth.png');
    $u = wp_upload_bits('mixed-clean-chain.png',null,$png); check(!$u['error'],'supported clean conversion chain');
    $id = wp_insert_attachment(array('post_title'=>'Synthetic supported history','post_mime_type'=>'image/png'),$u['file']);
    wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$u['file']));
    $before = WP_Seed_Pixel_Master_Adapter::snapshot($id,false,true); check($before,'conversion chain initial bytes');
    $a = WP_Seed_Pixel_Format_Conversion::analyze($id); check($a,'first chain conversion analysis');
    check(WP_Seed_Pixel_Format_Conversion::convert($id,$a['generation'],true,false,$a['selected_profile']),'first official conversion');
    check(WP_Seed_Pixel_Format_Conversion::restore($id,$a['generation']),'official source conversion restore');
    check(WP_Seed_Pixel_Master_Adapter::snapshot($id,false,true) === $before,'source conversion restored exact PNG/derivatives');
    $a = WP_Seed_Pixel_Format_Conversion::analyze($id); check($a,'second supported conversion analysis');
    check(WP_Seed_Pixel_Format_Conversion::convert($id,$a['generation'],true,false,$a['selected_profile']),'second conversion with same-ID terminal history');
    $ids = array(fixture(false),fixture(false));
    $scan = WP_Seed_Pixel_Scan::start($ids); check($scan,'bounded selected scan');
    for ($n=0;$scan['status'] === 'running' && $n<10;$n++) { $scan = WP_Seed_Pixel_Scan::step($scan['id']); check($scan,'bounded scan step'); }
    check($scan['status'] === 'complete','completed scan preserved as history');
    $plan = WP_Seed_Pixel_Jobs::bulk_plan($scan['id'],array('master'=>'replace_verified'),1073741824,'replace',2,false,$ids); check($plan,'M5 selected plan');
    for ($n=0;$plan['status'] === 'queued' && $n<10;$n++) { $plan = WP_Seed_Pixel_Jobs::bulk_step($plan['id']); check($plan,'M5 plan step'); }
    $job = WP_Seed_Pixel_Jobs::start($plan['id']); check($job,'M5 execution');
    for ($n=0;$job['status'] === 'running' && $n<10;$n++) { $job = WP_Seed_Pixel_Jobs::bulk_step($job['id']); check($job,'M5 bounded engine step'); }
    check(in_array($job['status'],array('completed','completed_errors'),true),'M5 completed with inert reviews preserved');
    foreach (items() as $item) { if ($item['kind'] === 'operation' && $item['stage'] === 'retained') { check(WP_Seed_Pixel_Migration_Binding::availability($item),'mixed native recovery available'); } }
    dump_result($mode); exit;
}
if ($mode === 'mixed-state') {
    foreach (items() as $item) {
        if ($item['kind'] !== 'operation') { continue; }
        if ($item['stage'] === 'retained') { check(WP_Seed_Pixel_Migration_Binding::availability($item),'mixed engine native recovery available'); }
        if ($item['stage'] === 'needs_review') {
            $d = json_decode($item['data'],true);
            check($d['before'] === null && !empty($d['reason']) && empty($item['journal']),'non-mutating planning review remains blocked without recovery');
        }
    }
    dump_result($mode); exit;
}
if ($mode === 'prepare-png') {
    check($site === 'm','fresh synthetic PNG source');
    check(WP_Seed_Pixel_Recovery_Setup::prepare(),'PNG source storage'); WP_Seed_Pixel_Recovery_Setup::apply(); check(WP_Seed_Pixel_Job_Store::install(),'PNG schema'); check(!items(),'one-shot PNG fixtures');
    $image = imagecreatetruecolor(960,640);
    for ($y=0;$y<640;$y++) { $color = imagecolorallocate($image,40+(int)($y/5),80+(int)($y/8),150+(int)($y/8)); imagefilledrectangle($image,0,$y,959,$y,$color); }
    ob_start(); imagepng($image,null,0); $bytes = ob_get_clean(); unset($image);
    $u = wp_upload_bits('migration-lossless.png',null,$bytes); check(!$u['error'],'uncompressed synthetic PNG');
    $id = wp_insert_attachment(array('post_title'=>'Synthetic lossless optimization','post_mime_type'=>'image/png'),$u['file']); wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$u['file']));
    $j = WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified'),1073741824); check($j,'native lossless PNG admission'); check(WP_Seed_Pixel_Jobs::step($j['id']),'native lossless PNG execution');
    check(WP_Seed_Pixel_Jobs::quarantine_action($j['id'],'retain'),'native lossless PNG recovery');
    $id = fixture(false); $j = WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified'),1073741824); check($j,'JPEG tombstone preparation'); check(WP_Seed_Pixel_Jobs::step($j['id']),'JPEG native execution'); check(WP_Seed_Pixel_Jobs::quarantine_action($j['id'],'retain'),'JPEG native recovery');
    $u = wp_upload_bits('migration-tombstone.png',null,file_get_contents('/mnt/c/Dev/git-worktrees/wp-seed-pixel-png-jpeg-explicit-conversion/.runtime/format-fixtures/smooth.png')); check(!$u['error'],'synthetic tombstone PNG');
    $id = wp_insert_attachment(array('post_title'=>'Synthetic format tombstone','post_mime_type'=>'image/png'),$u['file']); wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$u['file']));
    $a = WP_Seed_Pixel_Format_Conversion::analyze($id); check($a,'tombstone conversion analysis'); check(WP_Seed_Pixel_Format_Conversion::convert($id,$a['generation'],true,false,$a['selected_profile']),'tombstone native conversion');
    dump_result($mode); exit;
}
if ($mode === 'legacy') {
    check($site === 'm','synthetic legacy source only'); $id = fixture(false);
    $a = WP_Seed_Pixel_Engine::process($id,'balanced',false); check($a,'native adaptive derivative processing'); check($a['status'] === 'success','legacy adaptive success');
    $b = WP_Seed_Pixel_Engine::process($id,'balanced',true); check($b,'native adaptive second generation');
    check(count(WP_Seed_Pixel_Store::history($id)) === 1,'legacy previous generation retained');
    check(WP_Seed_Pixel_Master_Adapter::snapshot($id,false,true),'legacy current and history graph valid');
    dump_result($mode); exit;
}
if ($mode === 'simulation') {
    check($site === 'm','synthetic simulation only');
    $id = (int)$wpdb->get_var("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_seed_pixel_manifest' LIMIT 1");
    $scan = WP_Seed_Pixel_Scan::start(array($id)); check($scan,'simulation selected scan');
    for ($n=0;$scan['status'] === 'running' && $n<5;$n++) { $scan = WP_Seed_Pixel_Scan::step($scan['id']); check($scan,'simulation scan step'); }
    $plan = WP_Seed_Pixel_Jobs::plan($scan['id'],array('master'=>'replace_verified')); check($plan,'native simulation plan');
    for ($n=0;$plan['status'] === 'queued' && $n<5;$n++) { $plan = WP_Seed_Pixel_Jobs::step($plan['id']); check($plan,'native simulation plan step'); }
    $job = WP_Seed_Pixel_Jobs::start($plan['id']); check($job,'native simulation execution');
    for ($n=0;$job['status'] === 'running' && $n<5;$n++) { $job = WP_Seed_Pixel_Jobs::step($job['id']); check($job,'native simulation step'); }
    check($job['status'] === 'completed','simulation completed without image processing'); dump_result($mode); exit;
}
if ($mode === 'admission-negative') {
    $rows = array('jobs'=>$wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . ' ORDER BY id',ARRAY_A),'items'=>items());
    $v = new ReflectionMethod(WP_Seed_Pixel_Migration_Evidence::class,'validate_rows');
    check($v->invoke(null,$rows),'real terminal rows validate');
    $bad = $rows; $bad['jobs'][0]['status'] = 'running'; check(is_wp_error($v->invoke(null,$bad)),'active job refused');
    $bad = $rows; $bad['items'][0]['lease_until'] = (string)(time()+3600); check(is_wp_error($v->invoke(null,$bad)),'active lease refused');
    $bad = $rows; $bad['items'][] = $bad['items'][0]; check(is_wp_error($v->invoke(null,$bad)),'duplicate SQL identity refused');
    $bad = $rows; $bad['items'][0]['snapshot_hash'] = str_repeat('0',64); check(is_wp_error($v->invoke(null,$bad)),'altered data hash refused');
    $bad = $rows; $bad['jobs'][0]['engine'] = 'unknown'; check(is_wp_error($v->invoke(null,$bad)),'unknown engine refused');
    $bad = $rows; $bad['items'][0]['stage'] = 'needs_review'; check(is_wp_error($v->invoke(null,$bad)),'review after operational snapshot refused');
    $bad = $rows; $bad['items'][0]['stage'] = 'queued'; check(is_wp_error($v->invoke(null,$bad)),'unfinished operation refused');
    dump_result($mode); exit;
}
if ($mode === 'absence-negative') {
    foreach (items() as $item) {
        if ($item['kind'] !== 'operation' || $item['stage'] !== 'purged' || $item['action'] !== 'convert') { continue; }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item,false); $r = WP_Seed_Pixel_Master_Storage::load($dir,$item); check($r,'purged target journal valid');
        $relative = array_key_first($r['before']['files']); $path = wp_upload_dir(null,false)['basedir'] . '/' . $relative;
        check(!file_exists($path),'purged public original absent');
        file_put_contents($path,'synthetic unexpected object');
        $v = WP_Seed_Pixel_Migration_Binding::check_absences($item,$r,$dir); check(is_wp_error($v) && $v->get_error_code() === 'MIGRATION_UNEXPECTED_PUBLIC_ORIGINAL','unexpected original reappearance refused');
        rename($path,"$root/archives/unexpected-public-original-$site.bin");
        check(WP_Seed_Pixel_Migration_Binding::check_absences($item,$r,$dir),'expected absence reestablished without destroying fixture evidence');
    }
    dump_result($mode); exit;
}
if ($mode === 'tombstones') {
    check($site === 'm','synthetic tombstones only');
    $capsule = WP_Seed_Pixel_Migration_Evidence::export("$root/m/staging",array($_SERVER['DOCUMENT_ROOT'])); check($capsule,'all synthetic originals archived before tombstone lifecycle');
    foreach (items() as $item) {
        $id = (int)$item['attachment_id'];
        if (get_post($id)->post_title === 'Synthetic lossless optimization') { continue; }
        if ($item['action'] === 'convert') {
            $b = WP_Seed_Pixel_Format_Conversion::record($id); check($b,'native conversion record');
            check(WP_Seed_Pixel_Format_Conversion::purge($id,$b['record']['generation'],true,true),'native synthetic format tombstone');
        } else {
            $q = WP_Seed_Pixel_Quarantine::inspect($item); check($q,'native JPEG recovery view');
            check(WP_Seed_Pixel_Jobs::quarantine_action((int)$item['job_id'],'purge',array('version'=>WP_Seed_Pixel_Quarantine::AUTHORIZATION,'generation'=>$q['generation'],'irreversible'=>true)),'native synthetic JPEG tombstone');
        }
    }
    check(WP_Seed_Pixel_Migration_Evidence::verify_bundle($capsule['directory'],$capsule['manifest_sha256'],array($_SERVER['DOCUMENT_ROOT'])),'pre-tombstone evidence and all original bytes remain intact');
    dump_result($mode); exit;
}
$control = in_array($site,array('m','n'),true) ? "$root/archives/remaining-source-m.bin"
    : (in_array($site,array('k','l'),true) ? "$root/archives/remaining-source-k.bin" : "$root/archives/remaining-source-state.bin");
if ($mode === 'export' || $mode === 'backup') {
    if (!is_dir("$root/$site/staging")) { check(mkdir("$root/$site/staging",0700),'fresh private staging'); }
    $proof = WP_Seed_Pixel_Migration_Evidence::inspect(); check($proof,'mixed source admission');
    $export = WP_Seed_Pixel_Migration_Evidence::export("$root/$site/staging",array($_SERVER['DOCUMENT_ROOT'])); check($export,'immutable mixed engine export');
    $state = array('tables'=>tables(),'bundle'=>$export,'proof'=>$proof,'site'=>$site);
    $file = $mode === 'backup' ? "$root/archives/remaining-backup-$site.bin" : $control;
    check(!file_exists($file),'new immutable test checkpoint name'); file_put_contents($file,serialize($state)); chmod($file,0600);
    check(WP_Seed_Pixel_Migration_Evidence::inspect() === $proof,'export leaves source exact');
    dump_result($mode); exit;
}
$s = unserialize(file_get_contents($site === 'j' ? "$root/archives/remaining-backup-i.bin" : $control),array('allowed_classes'=>false));
$bundle = "$root/$site/staging/" . basename($s['bundle']['directory']); $sha = $s['bundle']['manifest_sha256']; $roots = array($_SERVER['DOCUMENT_ROOT']);
if ($mode === 'clone') {
    check(get_option(WP_Seed_Pixel_Recovery_Setup::OPTION,null) === null,'fresh independent target');
    check(WP_Seed_Pixel_Job_Store::install(),'fresh native schema');
    foreach ($s['tables'] as $table=>$rows) {
        check($wpdb->query("TRUNCATE TABLE `$table`") !== false,'synthetic clone table');
        foreach ($rows as $row) { check($wpdb->insert($table,$row) !== false,'exact copied SQL row'); }
    }
    wp_cache_flush(); check(!WP_Seed_Pixel_Recovery_Setup::gate(),'cloned identity fails closed'); dump_result($mode); exit;
}
if ($mode === 'collisions') {
    $old = items(); $item = end($old); $table = WP_Seed_Pixel_Job_Store::table('items'); $option = get_option(WP_Seed_Pixel_Recovery_Setup::OPTION);
    $wpdb->update($table,array('attachment_id'=>(int)$item['attachment_id']+1000),array('id'=>$item['id']));
    $r = WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,$roots); check(is_wp_error($r) && $r->get_error_code() === 'MIGRATION_TARGET_SQL_CONFLICT','identity/SQL collision refused');
    $wpdb->update($table,array('attachment_id'=>$item['attachment_id']),array('id'=>$item['id']));
    check(items() === $old && get_option(WP_Seed_Pixel_Recovery_Setup::OPTION) === $option,'collision preserves storage and rows');
    dump_result($mode); exit;
}
if ($mode === 'interrupt') {
    add_action('wp_seed_pixel_migration_boundary',function($phase) { if ($phase === 'sql_committed') { throw new RuntimeException('SIMULATED_INTERRUPT'); } });
    try { $v = WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,$roots); check($v,'binding must reach interruption'); throw new RuntimeException('Expected interruption'); }
    catch (RuntimeException $e) { check($e->getMessage() === 'SIMULATED_INTERRUPT','mixed interruption at committed SQL boundary'); }
    check(!WP_Seed_Pixel_Recovery_Setup::gate(),'interrupted migration fails closed'); dump_result($mode); exit;
}
if ($mode === 'bind') {
    $r = WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,$roots); check($r,'all engine target binding'); check($r['state'] === 'BOUND_VERIFIED','ordinary runtime certification');
    foreach (items() as $item) { if ($item['kind'] === 'operation' && $item['stage'] === 'retained' && WP_Seed_Pixel_Job_Store::job($item['job_id'])['kind'] !== 'simulation') { check(WP_Seed_Pixel_Migration_Binding::availability($item),'each target engine recoverable'); } }
    $jobs = $wpdb->get_results('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . ' ORDER BY id',ARRAY_A);
    check($jobs === $s['proof']['sql']['jobs'],'jobs/policies/leases preserved exactly'); dump_result($mode); exit;
}
if ($mode === 'legacy-check') {
    foreach ($s['proof']['public'] as $id=>$before) {
        if (!$before['rows']['_seed_pixel_manifest']) { continue; }
        $actual = WP_Seed_Pixel_Master_Adapter::snapshot((int)$id,false,true); check($actual,'legacy projected graph validates');
        check($actual['files'] === $before['files'] && $actual['sha256'] === $before['sha256'],'legacy master/current/history bytes unchanged');
        foreach (WP_Seed_Pixel_Store::manifest((int)$id)['files'] as $file) { check(str_starts_with($file['path'],wp_upload_dir(null,false)['basedir'] . '/'),'legacy paths belong exclusively to destination'); }
        check(WP_Seed_Pixel_Store::history((int)$id),'legacy generations remain valid');
    }
    dump_result($mode); exit;
}
if ($mode === 'negative') {
    $old = items();
    foreach ($old as $item) {
        if ($item['kind'] !== 'operation' || $item['stage'] !== 'retained') { continue; }
        if (WP_Seed_Pixel_Job_Store::job($item['job_id'])['kind'] === 'simulation') { continue; }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item,false); $r = WP_Seed_Pixel_Master_Storage::load($dir,$item); check($r,'normal bound journal load');
        $private = $item['action'] === 'convert' ? reset($r['backup']) : (isset($r['graph_files']) ? reset($r['graph_files'])['original'] : 'recovery.jpg');
        $path = "$dir/$private"; $bytes = file_get_contents($path);
        rename($path,$path . '.missing'); $v = WP_Seed_Pixel_Migration_Binding::availability($item); check(is_wp_error($v) || !$v,'missing original refuses recovery'); rename($path . '.missing',$path);
        file_put_contents($path,str_repeat('x',strlen($bytes))); $v = WP_Seed_Pixel_Migration_Binding::availability($item); check(is_wp_error($v) || !$v,'corrupt original refuses recovery'); file_put_contents($path,$bytes);
        check(WP_Seed_Pixel_Migration_Binding::availability($item),'exact backup restores availability');
        $journal = $dir . '/journal.json'; $raw = file_get_contents($journal); $altered = json_decode($raw,true);
        $altered['candidate'] = array('sha256'=>str_repeat('0',64)); unset($altered['checksum']); $altered['checksum'] = hash('sha256',wp_json_encode($altered));
        file_put_contents($journal,wp_json_encode($altered)); $v = WP_Seed_Pixel_Master_Storage::load($dir,$item);
        check(is_wp_error($v),'self-rehashed target journal cannot alter source candidate proof'); file_put_contents($journal,$raw);
    }
    $manifest = "$bundle/manifest.json"; $raw = file_get_contents($manifest); file_put_contents($manifest,$raw . ' ');
    $v = WP_Seed_Pixel_Migration_Binding::bind($bundle,$sha,$roots); check(is_wp_error($v) && $v->get_error_code() === 'MIGRATION_EVIDENCE_INVALID','altered proof refused'); file_put_contents($manifest,$raw);
    check(items() === $old,'negative inspections preserve claims/SQL'); dump_result($mode); exit;
}
if ($mode === 'restore') {
    $state = WP_Seed_Pixel_Recovery_Setup::verify(); check($state,'target identity verified');
    foreach (array_reverse(items()) as $item) {
        if ($item['kind'] !== 'operation' || $item['stage'] !== 'retained') { continue; }
        if (WP_Seed_Pixel_Job_Store::job($item['job_id'])['kind'] === 'simulation') { continue; }
        $dir = WP_Seed_Pixel_Master_Storage::directory($item,false); $r = WP_Seed_Pixel_Master_Storage::load($dir,$item); check($r,'restore journal');
        $expected = $r['before'];
        $result = $item['action'] === 'convert' ? WP_Seed_Pixel_Format_Conversion::restore((int)$item['attachment_id'],$r['generation'])
            : ($item['action'] === 'retire' || isset(json_decode(WP_Seed_Pixel_Job_Store::job($item['job_id'])['policy'],true)['bulk']) ? WP_Seed_Pixel_Jobs::quarantine_action((int)$item['job_id'],'restore',array(),(int)$item['id'])
                : WP_Seed_Pixel_Jobs::restore_master((int)$item['job_id']));
        check($result,'official target engine restoration');
        $actual = WP_Seed_Pixel_Master_Adapter::snapshot((int)$item['attachment_id'],false,true); check($actual,'restored native graph');
        check($actual === $expected,'exact original bytes derivatives fields dimensions and references');
        dump_result($mode);
    }
    check(WP_Seed_Pixel_Migration_Binding::verify_store($state),'source provenance unchanged after target restore');
    $proof = WP_Seed_Pixel_Migration_Evidence::inspect();
    if (is_wp_error($proof) && $proof->get_error_code() === 'MIGRATION_SQL_CONFLICT') {
        check((bool)array_filter(items(),fn($item)=>(int)$item['lease_until'] >= time()),'active terminal lease correctly blocks immediate export');
    } else { check($proof,'all restored terminal histories exportable'); }
    dump_result($mode); exit;
}
if ($mode === 'restored') {
    check(WP_Seed_Pixel_Migration_Evidence::inspect(),'all restored histories exportable after lease expiration');
    foreach (items() as $item) {
        if ($item['kind'] !== 'operation') { continue; }
        $original = array_values(array_filter($s['proof']['sql']['items'],fn($row)=>$row['id'] === $item['id']))[0];
        if (in_array($original['stage'],array('needs_review','skipped','cancelled'),true) || WP_Seed_Pixel_Job_Store::job($item['job_id'])['kind'] === 'simulation') { check($original === $item,'inert source refusal/history preserved exactly'); }
        elseif ($original['stage'] === 'purged') { check($item['stage'] === 'purged' && !WP_Seed_Pixel_Migration_Binding::availability($item),'purged history never acquires a recovery'); }
        else { check($item['stage'] === 'rolled_back','all recoverable target operations restored without fake recovery'); }
    }
    dump_result($mode); exit;
}
if ($mode === 'crosswrite') {
    $db = new wpdb('root','','seed_portability_' . $s['site'],'localhost:' . $root . '/mysql.sock');
    foreach ($s['tables'] as $table=>$rows) { check($db->get_results("SELECT * FROM `$table` ORDER BY 1",ARRAY_A) === $rows,'source table unchanged'); }
    foreach ($s['proof']['files'] as $name=>$f) { $path = $s['proof']['storage']['path'] . '/' . $name; check(hash_file('sha256',$path) === $f['sha256'] && lstat($path)['ino'] === $f['ino'],'source recovery bytes and identities unchanged'); }
    foreach ($s['proof']['public'] as $snapshot) { foreach ($snapshot['files'] as $name=>$f) { check(hash_file('sha256',$s['proof']['context']['uploads'] . '/' . $name) === $f['sha256'],'source public bytes unchanged'); } }
    dump_result($mode); exit;
}
throw new RuntimeException('Explicit engine lab mode required');
