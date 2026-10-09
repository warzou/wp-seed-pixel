<?php
$argv[1] = 'library';
require __DIR__ . '/metadata-wp.php';
meta_check(WP_Seed_Pixel_Job_Store::install(), 'SQL schema');
$cases = array();
foreach (array('intent','escrow','candidate','pre_swap','post_swap','rollback_file') as $boundary) {
    $id = meta_fixture('jpeg-exif.jpg');
    $before = WP_Seed_Pixel_Master_Adapter::snapshot($id);
    $job = meta_job($id);
    if ($boundary === 'rollback_file') { meta_check(WP_Seed_Pixel_Jobs::step($job), 'prepare retained restore crash'); }
    $cmd = array('/bin/bash',getenv('PIXEL_METADATA_SOURCE').'/tests/metadata-lab.sh','php',__DIR__.'/m3-crash.php',(string)$job,$boundary,$boundary==='rollback_file'?'restore':'step');
    $process = proc_open($cmd,array(0=>array('pipe','r'),1=>array('pipe','w'),2=>array('pipe','w')),$pipes);
    meta_check(is_resource($process),'crash worker started');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]);fclose($pipes[2]);
    $exit=proc_close($process);
    meta_check(in_array($exit,array(9,137),true),'owned worker terminated at '.$boundary.': '.$exit.' '.$error);
    wp_cache_delete($id,'post_meta'); clearstatcache();
    if (in_array($boundary,array('intent','escrow','candidate','pre_swap'),true)) { meta_check(hash_file('sha256',get_attached_file($id))===$before['sha256'],'source authoritative before '.$boundary); }
    $cases[]=array('id'=>$id,'job'=>$job,'boundary'=>$boundary,'before'=>$before);
}
echo "Six owned workers terminated. Waiting for natural SQL lease expiry.\n";flush();
sleep(65);
foreach ($cases as $case) {
    $job=$case['job'];$id=$case['id'];wp_cache_delete($id,'post_meta');
    if ($case['boundary']==='rollback_file') { meta_check(WP_Seed_Pixel_Jobs::restore_master($job),'restore reconciliation after kill'); }
    else {
        $result=WP_Seed_Pixel_Jobs::step($job);
        meta_check($result,'reconcile '.$case['boundary']);
        $item=meta_item($job);
        meta_check($item['stage']==='retained','crash converges retained '.$case['boundary'].': '.($item['error_code']??''));
        meta_check(WP_Seed_Pixel_Jobs::restore_master($job),'restore after '.$case['boundary']);
    }
    meta_check(WP_Seed_Pixel_Master_Adapter::snapshot($id)===$case['before'],'exact coherent graph after '.$case['boundary']);
    meta_check(WP_Seed_Pixel_Jobs::restore_master($job),'idempotent restore after '.$case['boundary']);
}
file_put_contents($root.'/evidence/crashes.json',wp_json_encode(array('passed'=>count($checks),'checks'=>$checks,'boundaries'=>array_column($cases,'boundary'),'lease_expiry'=>'natural, no database overrides','permanent_purge'=>0),JSON_PRETTY_PRINT));
echo count($checks)." crash/recovery checks passed.\n";
