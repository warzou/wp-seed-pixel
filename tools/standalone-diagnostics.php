<?php
defined('ABSPATH') || exit;
function seed_export_metadata_diff($expected,$actual,string $path=''): array {
    if($expected===$actual){return [];}
    if(is_array($expected)&&is_array($actual)){
        $out=[];foreach(array_unique(array_merge(array_keys($expected),array_keys($actual))) as $key){
            $out=array_merge($out,seed_export_metadata_diff($expected[$key]??null,$actual[$key]??null,$path===''?(string)$key:$path.'.'.$key));
        }return $out;
    }
    $safe=static fn($v)=>is_int($v)||is_float($v)||is_bool($v)||$v===null?$v:['type'=>gettype($v),'sha256'=>hash('sha256',serialize($v))];
    return [['field'=>$path,'expected'=>$safe($expected),'actual'=>$safe($actual)]];
}
function seed_export_diagnostics(): array {
    global $wpdb;
    $lock=WP_Seed_Pixel_Files::lock(0); if(is_wp_error($lock)){throw new RuntimeException($lock->get_error_code());}
    $result=[];
    try {
        $items=$wpdb->get_results('SELECT * FROM '.WP_Seed_Pixel_Job_Store::table('items')." WHERE kind='operation' AND stage='retained' ORDER BY id",ARRAY_A);
        foreach($items as $item){
            $id=(int)$item['attachment_id'];$owner=WP_Seed_Pixel_Files::lock($id);
            if(is_wp_error($owner)){throw new RuntimeException($owner->get_error_code());}
            try{
                $row=['item'=>(int)$item['id'],'job'=>(int)$item['job_id'],'attachment'=>$id,'action'=>$item['action'],'stage'=>$item['stage']];
                $dir=WP_Seed_Pixel_Master_Storage::directory($item,false);
                $r=is_wp_error($dir)?$dir:WP_Seed_Pixel_Master_Storage::load($dir,$item);
                $row['journal']=is_wp_error($r)?$r->get_error_code():($r['phase']??'missing');
                if(is_wp_error($r)||!$r){$result[]=$row;continue;}
                $available=WP_Seed_Pixel_Migration_Binding::availability($item);
                $row['native_available']=is_wp_error($available)?$available->get_error_code():$available;
                $snapshot=WP_Seed_Pixel_Master_Adapter::snapshot($id,false,true);
                $row['snapshot']=is_wp_error($snapshot)?$snapshot->get_error_code():'PASS';
                $row['current_sha256']=is_wp_error($snapshot)?null:$snapshot['sha256'];
                $private=[];
                if($item['action']==='convert'){
                    foreach(['observe','old_files','new_files','desired'] as $method){
                        $f=new ReflectionMethod(WP_Seed_Pixel_Format_Conversion::class,$method);
                        if($method==='observe'){$v=$f->invoke(null,$r);$row['conversion_observed']=is_wp_error($v)?$v->get_error_code():$v;}
                        elseif($method==='desired'){
                            $desired=$f->invoke(null,$r,false);$diff=[];
                            foreach($desired as $key=>$expected){
                                $actual=$wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=%s ORDER BY meta_id",$id,$key));
                                if($actual!==$expected){$diff[]=['key'=>$key,'expected_sha256'=>hash('sha256',serialize($expected)),'actual_sha256'=>hash('sha256',serialize($actual)),'expected_rows'=>count($expected),'actual_rows'=>count($actual)];}
                            }$row['metadata_differences']=$diff;
                        }else{
                            $v=$method==='old_files'?$f->invoke(null,$r,$dir):$f->invoke(null,$r,$dir,false);
                            $row[$method]=is_wp_error($v)?$v->get_error_code():$v;
                        }
                    }
                    foreach($r['backup'] as $relative=>$name){$private[$name]=$r['before']['files'][$relative];}
                }else{
                    $v=WP_Seed_Pixel_Quarantine::inspect($item);
                    $row['quarantine']=is_wp_error($v)?$v->get_error_code():array_intersect_key($v,array_flip(['state','rollback_available','active_delta']));
                    if(isset($r['graph_version'])){
                        $verify=WP_Seed_Pixel_Metadata_Graph_Transaction::verify($item,$r);
                        $row['graph_verify']=is_wp_error($verify)?$verify->get_error_code():true;
                        $observed=WP_Seed_Pixel_Master_Adapter::observe($r);
                        $row['graph_observe']=is_wp_error($observed)?$observed->get_error_code():$observed;
                        if(!is_wp_error($snapshot)){
                            $row['file_set_exact']=array_keys($snapshot['files'])===array_keys($r['before']['files']);
                            $row['url_exact']=$snapshot['url']===$r['before']['url'];$row['guid_exact']=$snapshot['guid']===$r['before']['guid'];
                            $row['attributes_exact']=array_intersect_key($snapshot,array_flip(['mode','uid','gid']))===array_intersect_key($r['before'],array_flip(['mode','uid','gid']));
                            $row['wp_metadata_after_differences']=seed_export_metadata_diff($r['after']??null,maybe_unserialize($snapshot['rows']['_wp_attachment_metadata'][0]??''));
                            $row['graph_file_checks']=[];
                            foreach($r['graph_plan']['files'] as $relative=>$e){
                                $path=WP_Seed_Pixel_Files::path(wp_upload_dir(null,false)['basedir'].'/'.$relative);
                                $read=is_wp_error($path)?$path:WP_Seed_Pixel_Metadata::read($path);
                                $beforeBytes=$e['classification']==='A';
                                $row['graph_file_checks'][]=['relative_sha256'=>hash('sha256',$relative),
                                    'roles_exact'=>($snapshot['files'][$relative]['roles']??null)===$e['roles'],
                                    'sha_exact'=>($snapshot['files'][$relative]['sha256']??null)===($beforeBytes?$e['before_sha256']:$e['after_sha256']),
                                    'bytes_exact'=>($snapshot['files'][$relative]['bytes']??null)===($beforeBytes?$e['before_bytes']:$e['after_bytes']),
                                    'metadata_read'=>is_wp_error($read)?$read->get_error_code():'PASS',
                                    'private_metadata_absent'=>is_wp_error($read)?null:!$read['categories'],
                                    'image_sha_exact'=>is_wp_error($read)?null:$read['image_sha256']===$e['image_sha256'],
                                    'color_sha_exact'=>is_wp_error($read)?null:$read['color_sha256']===$e['color_sha256']];
                            }
                        }
                    }
                    foreach($r['graph_files']??[] as $relative=>$file){$private[$file['original']]=$r['before']['files'][$relative];}
                    if(!$private&&isset($r['before']['sha256'])){$private['recovery.jpg']=['sha256'=>$r['before']['sha256'],'bytes'=>$r['before']['bytes']];}
                }
                $verified=0;$bad=[];
                foreach($private as $name=>$expected){
                    $path=$dir.'/'.$name;
                    if(is_file($path)&&!is_link($path)&&lstat($path)['nlink']===1&&filesize($path)===$expected['bytes']&&hash_file('sha256',$path)===$expected['sha256']){$verified++;}
                    else{$bad[]=hash('sha256',$name);}
                }
                $row['originals_verified']=$verified;$row['originals_expected']=count($private);$row['original_mismatch_name_hashes']=$bad;
                $result[]=$row;
            }finally{WP_Seed_Pixel_Files::unlock($owner);}
        }
    }finally{WP_Seed_Pixel_Files::unlock($lock);}
    return $result;
}
