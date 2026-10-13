<?php
declare(strict_types=1);
require_once __DIR__.'/standalone-runtime.php';
$result=['verdict'=>'BLOCKED','source_mutation'=>false,'wordpress_bootstrap'=>false];
try {
    $request=json_decode(stream_get_contents(STDIN),true,32,JSON_THROW_ON_ERROR);
    if (!is_array($request) || ($request['confirm']??'') !== 'READ_ONLY_SOURCE_EXPORT') { throw new RuntimeException('EXPORT_CONFIRM_REQUIRED'); }
    $context=seed_export_runtime($request);
    if(($request['mode']??'')==='diagnose'){
        require_once __DIR__.'/standalone-diagnostics.php';
        $before=seed_export_diagnostics();
        $wpdb->query('ROLLBACK');$wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');wp_cache_flush();
        if(seed_export_diagnostics()!==$before){throw new RuntimeException('EXPORT_DIAGNOSTIC_SOURCE_CHANGED');}
        $result=$context+['verdict'=>'PASS','inspection_only'=>true,'export_attempted'=>false,'diagnostics'=>$before,
            'retained_items'=>array_column($before,'item'),'source_state_exact'=>true,'source_mutation'=>false,'wordpress_bootstrap'=>false];
    }else{
    if (($request['mode']??'inspect')==='export') {
        $bundle=WP_Seed_Pixel_Migration_Evidence::export($request['destination'],$request['all_webroots']);
        if (is_wp_error($bundle)) { throw new RuntimeException($bundle->get_error_code()); }
        $proof=json_decode(file_get_contents($bundle['directory'].'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
        if (hash('sha256',wp_json_encode($proof))!==$bundle['manifest_sha256']) { throw new RuntimeException('EXPORT_EMITTED_PROOF_MISMATCH'); }
        $verify=WP_Seed_Pixel_Migration_Evidence::verify_bundle($bundle['directory'],$bundle['manifest_sha256'],$request['all_webroots']);
        if (is_wp_error($verify)) { throw new RuntimeException($verify->get_error_code()); }
        $result['bundle']=$bundle; $result['transport_state']=$verify['state'];
    } else {
        $proof=WP_Seed_Pixel_Migration_Evidence::inspect();
        if (is_wp_error($proof)) { throw new RuntimeException($proof->get_error_code()); }
    }
    $wpdb->query('ROLLBACK'); $wpdb->query('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY'); wp_cache_flush();
    if (WP_Seed_Pixel_Migration_Evidence::inspect() !== $proof) { throw new RuntimeException('EXPORT_FRESH_SOURCE_CHANGED'); }
    if (($request['mode']??'inspect')==='barrier-test') {
        $refused=0;
        foreach (['UPDATE x SET a=1','INSERT INTO x VALUES(1)','DELETE FROM x','CREATE TABLE x(a INT)','DROP TABLE x',
            "SELECT 1 INTO OUTFILE '/tmp/export-must-not-write'",'SELECT SLEEP(1)','SELECT 1; SELECT 2','SELECT /* untrusted */ 1'] as $query) {
            try { $wpdb->query($query); throw new RuntimeException('EXPORT_BARRIER_FAILED'); }
            catch (RuntimeException $e) { if ($e->getMessage()!=='EXPORT_SQL_WRITE_REFUSED') { throw $e; } $refused++; }
        }
        $result['barrier_refusals']=$refused;
        $result['wp_mail_blocked']=wp_mail('qa@example.invalid','Synthetic','Synthetic')===false;
        $result['http_blocked']=is_wp_error(wp_remote_get('https://example.invalid/'));
        if (!$result['wp_mail_blocked'] || !$result['http_blocked']) { throw new RuntimeException('EXPORT_SIDE_EFFECT_BARRIER_FAILED'); }
    }
    $result += $context+['proof_sha256'=>hash('sha256',wp_json_encode($proof)), 'jobs'=>count($proof['sql']['jobs']),
        'items'=>count($proof['sql']['items']), 'public_graphs'=>count($proof['public']), 'private_files'=>count($proof['files']),
        'retained_items'=>array_values(array_map(fn($x)=>(int)$x['id'],array_filter($proof['sql']['items'],fn($x)=>$x['kind']==='operation'&&$x['stage']==='retained'))),
        'sql_queries'=>count($wpdb->read_audit), 'init_executed'=>did_action('init'), 'plugins_loaded_executed'=>did_action('plugins_loaded')];
    $result['fresh_source_state_exact']=true;
    $result['verdict']='PASS';
    }
} catch (Throwable $e) {
    $result['error']=$e instanceof mysqli_sql_exception?'EXPORT_SQL_CONNECTION_FAILURE':$e->getMessage();
} finally { if (isset($wpdb) && $wpdb instanceof Seed_Export_Read_Wpdb) { $wpdb->close_read(); } }
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
exit($result['verdict']==='PASS'?0:3);
