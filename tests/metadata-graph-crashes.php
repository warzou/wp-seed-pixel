<?php
define('WP_SEED_PIXEL_GRAPH_FIXTURES_ONLY', true); require __DIR__ . '/metadata-graph-wp.php';
$cases = array();
$boundaries = array('after_plan','after_candidates','after_recovery','after_prepared','after_rename_1','after_file_1','after_file_2','after_file_3','before_verify','before_commit','after_metadata','after_commit','after_restore_1');
foreach ($boundaries as $boundary) {
    $id = graph_fixture(false, true); $before = WP_Seed_Pixel_Master_Adapter::snapshot($id); $job = meta_job($id);
    $restore = $boundary === 'after_restore_1';
    if ($restore) { meta_check(WP_Seed_Pixel_Jobs::step($job), 'prepare committed user restore'); }
    $cmd = array('/bin/bash', getenv('PIXEL_METADATA_SOURCE').'/tests/metadata-lab.sh','php',__DIR__.'/metadata-graph-crash-worker.php',(string)$job,$boundary,$restore?'restore':'step');
    $p = proc_open($cmd,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    meta_check(is_resource($p), 'owned real worker started'); fclose($pipes[0]);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    meta_check(in_array($exit,array(9,137),true),'real SIGKILL reached '.$boundary.' '.$exit.' '.$err);
    $cases[]=array('id'=>$id,'job'=>$job,'boundary'=>$boundary,'before'=>$before);
}
echo count($boundaries)." graph workers killed. Waiting for natural lease expiry.\n";flush();sleep(65);
foreach ($cases as $case) {
    $id=$case['id'];$job=$case['job'];wp_cache_delete($id,'post_meta');clearstatcache();
    $restore=$case['boundary']==='after_restore_1';
    meta_check($restore?WP_Seed_Pixel_Jobs::restore_master($job):WP_Seed_Pixel_Jobs::step($job),'first real reconciliation '.$case['boundary']);
    $item=meta_item($job);
    $committed=$case['boundary']==='after_commit';
    $expected=$committed?'retained':'rolled_back';
    meta_check($item['stage']===$expected,'reconciled stage '.$case['boundary'].': '.$item['stage'].' '.$item['error_code']);
    $snapshot=WP_Seed_Pixel_Master_Adapter::snapshot($id,true);$dir=WP_Seed_Pixel_Master_Storage::directory($item,false);$hash=hash_file('sha256',$dir.'/journal.json');
    meta_check($restore?WP_Seed_Pixel_Jobs::restore_master($job):WP_Seed_Pixel_Jobs::step($job),'second real reconciliation');
    meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id,true)===$snapshot,'second reconciliation files/SQL no-op');
    meta_check(hash_file('sha256',$dir.'/journal.json')===$hash,'second reconciliation journal no-op');
    if ($expected==='retained') { meta_check(WP_Seed_Pixel_Jobs::restore_master($job),'restore committed graph after crash'); }
    meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id)===$case['before'],'exact whole graph and SQL restored');
}
file_put_contents($root.'/evidence/graph-crashes.json',wp_json_encode(array('passed'=>count($checks),'checks'=>$checks,'boundaries'=>$boundaries,'death'=>'SIGKILL','lease_expiry'=>'natural','permanent_purge'=>0),JSON_PRETTY_PRINT));
echo count($checks)." native graph process-death checks PASS\n";
