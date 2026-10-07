<?php
// Disposable WordPress MU fixture only. It never makes a network request.
add_filter('pre_http_request',function($pre,$args,$url){
    $root=getenv('PIXEL_UPDATER_LAB');
    if($root!=='/home/warzy/.cache/wp-seed-pixel-051-updater-lab'){return new WP_Error('lab_required');}
    $m=json_decode(file_get_contents($root.'/manifest.json'),true);
    $endpoint=defined('WP_SEED_PIXEL_UPDATE_MANIFEST')?WP_SEED_PIXEL_UPDATE_MANIFEST:'https://raw.githubusercontent.com/warzou/wp-seed-pixel/main/updates/stable.json';
    $r=array('response'=>array('code'=>200),'headers'=>array(),'body'=>'','cookies'=>array());
    if($url===$endpoint){$r['body']=wp_json_encode($m);return $r;}
    if($url===$m['package']){
        if($m['channel']==='stable'){$r['response']['code']=302;$r['headers']['location']='https://release-assets.githubusercontent.com/github-production-release-asset/123/fixture?signature=synthetic';return $r;}
        if(!empty($args['stream'])){copy($root.'/fixture.zip',$args['filename']);}return $r;
    }
    if($url==='https://release-assets.githubusercontent.com/github-production-release-asset/123/fixture?signature=synthetic'){
        if(!empty($args['stream'])){copy($root.'/fixture.zip',$args['filename']);}return $r;
    }
    return new WP_Error('lab_external_blocked');
},10,3);
add_filter('pre_wp_mail','__return_false');
