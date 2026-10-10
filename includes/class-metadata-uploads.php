<?php
defined('ABSPATH') || exit;

/** New-upload provenance only; all file effects use the existing graph Jobs. */
final class WP_Seed_Pixel_Metadata_Uploads {
    const OPTION='wp_seed_pixel_metadata_uploads';
    const META='_seed_pixel_metadata_upload';
    private static $uploads=array();
    private static $busy=array();

    public static function boot() {
        add_action('init',array(__CLASS__,'initialize'),5);
        add_filter('wp_handle_upload',array(__CLASS__,'uploaded'),90,2);
        add_action('add_attachment',array(__CLASS__,'created'),5);
        add_filter('wp_generate_attachment_metadata',array(__CLASS__,'generated'),PHP_INT_MAX,3);
        add_action('added_post_meta',array(__CLASS__,'saved'),5,4);
        add_action('updated_post_meta',array(__CLASS__,'saved'),5,4);
    }
    public static function settings() {
        if (is_multisite()) { return array('enabled'=>false,'actor_id'=>0,'cutoff_id'=>0,'generation'=>''); }
        $v=get_option(self::OPTION,null);
        if ($v===null) { return array('enabled'=>true,'actor_id'=>0,'cutoff_id'=>0,'generation'=>''); }
        if (!is_array($v) || array_keys($v)!==array('enabled','actor_id','cutoff_id','generation')
            || !is_bool($v['enabled']) || !is_int($v['actor_id']) || !is_int($v['cutoff_id'])
            || $v['actor_id']<1 || $v['cutoff_id']<0 || !is_string($v['generation'])
            || !preg_match('/^[a-f0-9]{48}$/D',$v['generation'])) {
            return array('enabled'=>false,'actor_id'=>0,'cutoff_id'=>0,'generation'=>'');
        }
        return $v;
    }
    public static function initialize() {
        if (!is_multisite() && current_user_can('manage_options') && get_option(self::OPTION,null)===null) { self::configure(true); }
    }
    public static function configure($enabled) {
        global $wpdb;
        if (!is_bool($enabled) || is_multisite() || !current_user_can('manage_options')) { return new WP_Error('PERMISSION_DENIED'); }
        $lock=WP_Seed_Pixel_Files::lock(0);if(is_wp_error($lock)){return $lock;}
        try {
            $cutoff=$wpdb->get_var("SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts}");
            if ($wpdb->last_error || !WP_Seed_Pixel_Authority::valid(0)) { return new WP_Error('STORE_FAILED'); }
            $v=array('enabled'=>$enabled,'actor_id'=>get_current_user_id(),'cutoff_id'=>(int)$cutoff,'generation'=>bin2hex(random_bytes(24)));
            update_option(self::OPTION,$v,false);
            return get_option(self::OPTION) === $v ? $v : new WP_Error('STORE_FAILED');
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
    }
    public static function uploaded($upload,$context='upload') {
        if ($context==='upload' && is_array($upload) && empty($upload['error']) && !empty($upload['file'])) {
            self::$uploads[wp_normalize_path($upload['file'])]=self::settings();
        }
        return $upload;
    }
    public static function created($id) {
        $path=get_attached_file($id);$key=is_string($path)?wp_normalize_path($path):'';
        $s=self::$uploads[$key]??null;unset(self::$uploads[$key]);
        if (!$s || !$s['enabled'] || !$s['actor_id'] || (int)$id<=$s['cutoff_id'] || self::settings()!==$s
            || (defined('WP_IMPORTING')&&WP_IMPORTING) || (defined('WP_CLI')&&WP_CLI)
            || !in_array(get_post_mime_type($id),array('image/jpeg','image/png'),true)
            || !current_user_can('upload_files') || !current_user_can('edit_post',$id)) { return; }
        add_post_meta($id,self::META,array('generation'=>$s['generation'],'actor_id'=>$s['actor_id'],'state'=>'awaiting_metadata','job_id'=>0),true);
    }
    public static function generated($metadata,$id,$context='update') {
        $v=get_post_meta($id,self::META,true);
        if ($context==='create' && is_array($v) && ($v['state']??'')==='awaiting_metadata' && is_array($metadata)
            && !empty($metadata['file']) && !empty($metadata['width']) && !empty($metadata['height'])) {
            $next=$v;$next['metadata_hash']=hash('sha256',wp_json_encode($metadata));
            update_post_meta($id,self::META,$next,$v);
            // Core may already have persisted this exact final graph while generating sizes.
            // A later identical update emits no post-meta hook; certify that no-op here.
            if (hash_equals($next['metadata_hash'],hash('sha256',wp_json_encode(wp_get_attachment_metadata($id))))) {
                self::saved(0,$id,'_wp_attachment_metadata',$metadata);
            }
        }
        return $metadata;
    }
    public static function blocks_optimizer($id) {
        $v=get_post_meta($id,self::META,true);
        if (!is_array($v)) { return false; }
        if (in_array($v['state']??'',array('awaiting_metadata','processing','review'),true)) { return true; }
        if (($v['state']??'')!=='anonymized') { return false; }
        $item=WP_Seed_Pixel_Media::operation($id);
        return !$item || $item['stage']!=='rolled_back';
    }
    private static function status($id,array $old,$state,$reason='',$job=0) {
        $next=$old;$next['state']=$state;$next['reason']=$reason;$next['job_id']=(int)$job;
        if ($state==='review') {
            $before=WP_Seed_Pixel_Master_Adapter::snapshot($id,true,true);
            if (!is_wp_error($before)) { $next['review_identity']=hash('sha256',wp_json_encode($before)); }
        }
        update_post_meta($id,self::META,$next,$old);
    }
    public static function review_reason($id) {
        $v=get_post_meta($id,self::META,true);
        if (!is_array($v) || ($v['state']??'')!=='review' || empty($v['review_identity'])) { return ''; }
        $before=WP_Seed_Pixel_Master_Adapter::snapshot($id,true,true);
        if (is_wp_error($before) || !hash_equals($v['review_identity'],hash('sha256',wp_json_encode($before)))) { return ''; }
        return is_string($v['reason']??null)?$v['reason']:'METADATA_REVIEW';
    }
    public static function saved($meta_id,$id,$key,$value) {
        if ($key!=='_wp_attachment_metadata' || isset(self::$busy[$id])) { return; }
        $v=get_post_meta($id,self::META,true);$s=self::settings();
        if (!is_array($v) || ($v['state']??'')!=='awaiting_metadata' || empty($v['metadata_hash'])) { return; }
        if (!$s['enabled'] || $s['generation']!==($v['generation']??'') || (int)$id<=$s['cutoff_id']) {
            self::status($id,$v,'skipped','SETTING_CHANGED');return;
        }
        if (!hash_equals($v['metadata_hash'],hash('sha256',wp_json_encode(wp_get_attachment_metadata($id))))) {
            self::status($id,$v,'review','METADATA_GRAPH_CHANGED');return;
        }
        $previous=get_current_user_id();$job=0;self::$busy[$id]=true;
        try {
            wp_set_current_user((int)$v['actor_id']);
            if (!current_user_can('manage_options') || !current_user_can('edit_post',$id)) { self::status($id,$v,'review','PERMISSION_DENIED');return; }
            $a=WP_Seed_Pixel_Metadata_Admin::analyze($id);
            if (is_wp_error($a)) { self::status($id,$v,'review',$a->get_error_code());return; }
            if (!$a['master']['categories']) {
                self::status($id,$v,'clean');
                // Preserve an existing legacy JPEG opt-in after the temporary privacy gate.
                WP_Seed_Pixel_Plugin::uploaded(0,$id,'_wp_attachment_metadata',wp_get_attachment_metadata($id));
                return;
            }
            self::status($id,$v,'processing');$v=get_post_meta($id,self::META,true);
            $capacity=WP_Seed_Pixel_Metadata_Graph_Transaction::capacity($a['plan']);
            $result=WP_Seed_Pixel_Jobs::replace_one((int)$id,array('master'=>'replace_verified','metadata'=>'anonymize'),$capacity,'',$a['signature']);
            if (is_wp_error($result)) { self::status($id,$v,'review',$result->get_error_code());return; }
            $job=(int)$result['id'];self::status($id,$v,'processing','',$job);$v=get_post_meta($id,self::META,true);
            $result=WP_Seed_Pixel_Jobs::step($job);
            $a=WP_Seed_Pixel_Metadata_Admin::analyze($id);
            if (!is_wp_error($result) && !is_wp_error($a) && WP_Seed_Pixel_Metadata_Admin::state($id,$a)==='anonymized') {
                self::status($id,$v,'anonymized','',$job);
            } else {
                $item=WP_Seed_Pixel_Media::operation($id);
                $reason=is_wp_error($result)?$result->get_error_code():(is_wp_error($a)?$a->get_error_code():($item['error_code']??''));
                self::status($id,$v,'review',$reason?:'METADATA_REVIEW',$job);
            }
        } catch (Throwable $e) {
            // Do not expose raw engine or parser values to upload responses/logs.
            $current=get_post_meta($id,self::META,true);
            if (is_array($current)) { self::status($id,$current,'review','METADATA_REVIEW',$job); }
        } finally { wp_set_current_user($previous);unset(self::$busy[$id]); }
    }
}
