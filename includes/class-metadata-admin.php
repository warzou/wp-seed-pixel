<?php
defined('ABSPATH') || exit;

/** Explicit single-image privacy workflow in the existing attachment panel. */
final class WP_Seed_Pixel_Metadata_Admin {
    private static $analysis_cache = array();
    public static function boot() {
        add_action('wp_ajax_wp_seed_pixel_metadata',array(__CLASS__,'ajax'));
        add_action('admin_enqueue_scripts',array(__CLASS__,'assets'));
    }
    public static function assets($hook) {
        if (!in_array($hook,array('upload.php','post.php','post-new.php'),true) || !current_user_can('manage_options')) { return; }
        wp_enqueue_script('wp-seed-pixel-metadata',plugins_url('assets/metadata.js',WP_SEED_PIXEL_FILE),array(),WP_SEED_PIXEL_BUILD,true);
        wp_enqueue_style('wp-seed-pixel-metadata',plugins_url('assets/metadata.css',WP_SEED_PIXEL_FILE),array(),WP_SEED_PIXEL_BUILD);
        wp_localize_script('wp-seed-pixel-metadata','wpSeedPixelMetadata',array('url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('wp_seed_pixel_metadata'),
            'working'=>__('Processing; keep this page open.','wp-seed-pixel'),'failed'=>__('The operation stopped safely. Review the technical details before resuming.','wp-seed-pixel')));
    }
    public static function reason($code) {
        $messages=array(
            'METADATA_ORIENTATION'=>__('This image requires orientation information. Pixel cannot yet anonymize it without changing the image.','wp-seed-pixel'),
            'METADATA_PROVENANCE'=>__('This image contains provenance or authenticity information. It will not be removed automatically.','wp-seed-pixel'),
            'METADATA_PUBLIC_COPY'=>__('Another public copy still contains metadata. This image has not been changed.','wp-seed-pixel'),
            'METADATA_GRAPH_CHANGED'=>__('A public version changed after analysis. Pixel stopped without applying the planned changes. Analyze the image again.','wp-seed-pixel'),
            'METADATA_ALREADY_CLEAN'=>__('No supported private metadata was detected. The image is unchanged.','wp-seed-pixel'));
        if (in_array($code,array('CEILING_EXCEEDED','LOW_DISK','CAPACITY_EXCEEDED','STORAGE_BUDGET'),true)) {
            return __('Anonymization was not performed because there is not enough storage for the recovery originals. The uploaded image is unchanged.','wp-seed-pixel');
        }
        return $messages[$code] ?? __('Pixel cannot certify the current metadata state. Review the recovery information before resuming.','wp-seed-pixel');
    }
    public static function allowed($id) { return $id>0 && current_user_can('manage_options') && current_user_can('edit_post',$id) && get_post_type($id)==='attachment' && !is_multisite(); }
    public static function state($id, $analysis = null) {
        if (!self::allowed($id)) { return 'review'; }
        if ($analysis === null) { $analysis = self::analyze($id); }
        if (is_wp_error($analysis)) {
            $states = array('METADATA_ORIENTATION'=>'blocked_orientation','METADATA_PROVENANCE'=>'blocked_provenance','METADATA_PUBLIC_COPY'=>'blocked_public_copy','METADATA_REVIEW'=>'blocked_unknown_metadata');
            return $states[$analysis->get_error_code()] ?? 'review';
        }
        $item = WP_Seed_Pixel_Media::operation($id);
        if ($item) {
            $job = WP_Seed_Pixel_Job_Store::job($item['job_id']);
            $policy = $job ? json_decode($job['policy'],true) : null;
            if (($policy['intent']['metadata'] ?? '') === 'anonymize') {
                if ($item['stage'] === 'rolled_back') { return !empty($analysis['master']['categories']) ? 'restored' : 'clean'; }
                if ($item['stage'] === 'retained') {
                    $view = WP_Seed_Pixel_Quarantine::inspect($item);
                    return !is_wp_error($view) && !empty($view['rollback_available']) ? 'anonymized' : 'review';
                }
                if (in_array($item['stage'], array('queued','preparing','ready','switch_intent','switched','verified'), true)) { return 'anonymizing'; }
                return 'review';
            }
        }
        if (!empty($analysis['master']['categories']) && WP_Seed_Pixel_Metadata_Uploads::review_reason($id)) { return 'review'; }
        return !empty($analysis['master']['categories']) ? 'filterable' : 'clean';
    }
    public static function analyze($id) {
        if (!self::allowed($id)) { return new WP_Error('PERMISSION_DENIED'); }
        $conversion=WP_Seed_Pixel_Format_Conversion::record($id);
        if (is_wp_error($conversion)) { return $conversion; }
        if ($conversion && !in_array($conversion['record']['phase'],array('restored','discarded'),true)) { return new WP_Error('METADATA_REVIEW'); }
        $before=WP_Seed_Pixel_Master_Adapter::snapshot($id,true); if (is_wp_error($before)) { return $before; }
        // Transitional snapshots are useful for reviewing retained results, but not unknown ownership.
        $analysis=WP_Seed_Pixel_Analyzer::analyze($id);
        if (is_wp_error($analysis) || $analysis['health']!=='healthy') { return new WP_Error('INVENTORY_INCOMPLETE'); }
        $key=hash('sha256',wp_json_encode($before));
        if (!isset(self::$analysis_cache[$key])) { self::$analysis_cache[$key]=WP_Seed_Pixel_Metadata_Public_Graph::analyze($before); }
        return self::$analysis_cache[$key];
    }
    public static function ajax() {
        $id=is_scalar($_POST['attachment_id']??null)?absint($_POST['attachment_id']):0;
        if (!self::allowed($id) || !check_ajax_referer('wp_seed_pixel_metadata','nonce',false)) { wp_send_json_error(array('message'=>'PERMISSION_DENIED'),403); }
        $operation=is_string($_POST['operation']??null)?$_POST['operation']:'';
        if ($operation==='analyze') {
            $analysis=self::analyze($id); wp_send_json_success(array('panel'=>self::panel($id,$analysis)));
        }
        if (!WP_Seed_Pixel_Metadata_Graph_Transaction::enabled()) { wp_send_json_error(array('code'=>'METADATA_CERTIFICATION_REQUIRED','message'=>__('Local candidate: replacement and restoration certification is still required. No image has been changed.','wp-seed-pixel')),409); }
        if ($operation==='start' && ($_POST['confirmed']??'')==='1' && ($_POST['metadata']??'')==='anonymize') {
            $signature=is_string($_POST['signature']??null)?wp_unslash($_POST['signature']):'';
            $before=WP_Seed_Pixel_Master_Adapter::snapshot($id); $plan=is_wp_error($before)?$before:WP_Seed_Pixel_Metadata_Public_Graph::plan($before);
            $capacity=is_wp_error($plan)?$plan:WP_Seed_Pixel_Metadata_Graph_Transaction::capacity($plan);
            $result=is_wp_error($capacity)?$capacity:WP_Seed_Pixel_Jobs::replace_one($id,array('master'=>'replace_verified','metadata'=>'anonymize'),$capacity,'',$signature);
        } elseif ($operation==='step') {
            $item=WP_Seed_Pixel_Media::operation($id); $job=$item?WP_Seed_Pixel_Job_Store::job($item['job_id']):null;
            $policy=$job?json_decode($job['policy'],true):null;
            $result=$job && ($policy['intent']['metadata']??'')==='anonymize'?WP_Seed_Pixel_Jobs::step((int)$job['id']):new WP_Error('JOB_REQUIRED');
        } else { $result=new WP_Error('CONFIRMATION_REQUIRED'); }
        if (is_wp_error($result)) { wp_send_json_error(array('code'=>$result->get_error_code(),'message'=>__('The operation stopped safely. Review the technical details before resuming.','wp-seed-pixel')),409); }
        wp_send_json_success(array('state'=>$result,'panel'=>self::panel($id,self::analyze($id)),'media_panel'=>WP_Seed_Pixel_Media::details($id)));
    }
    public static function panel($id,$analysis=null) {
        if (!self::allowed($id) || !in_array(get_post_mime_type($id),array('image/jpeg','image/png'),true)) { return ''; }
        if ($analysis === null) { $analysis=self::analyze($id); }
        $labels=array('gps'=>__('GPS location','wp-seed-pixel'),'device'=>__('Camera or device','wp-seed-pixel'),'date'=>__('Capture date','wp-seed-pixel'),
            'author'=>__('Author or contact','wp-seed-pixel'),'description'=>__('Description or keywords','wp-seed-pixel'),'comments'=>__('Comments','wp-seed-pixel'),
            'software'=>__('Software history','wp-seed-pixel'),'iptc'=>__('IPTC descriptions or contact','wp-seed-pixel'),'xmp'=>__('XMP descriptions or contact','wp-seed-pixel'));
        $witness=get_post_meta($id,'_seed_pixel_master_state',true);
        $state=self::state($id,$analysis); $retained=$state==='anonymized';
        $actionable=in_array($state,array('filterable','restored'),true) && is_array($analysis) && !empty($analysis['master']['categories']);
        $state_labels=array('filterable'=>__('Personal metadata detected','wp-seed-pixel'),'clean'=>__('No personal metadata detected','wp-seed-pixel'),'conserved'=>__('Kept','wp-seed-pixel'),'anonymizing'=>__('Anonymization in progress','wp-seed-pixel'),'anonymized'=>__('Anonymized','wp-seed-pixel'),'restored'=>__('Restored exactly','wp-seed-pixel'),'review'=>__('Review required','wp-seed-pixel'),
            'blocked_orientation'=>__('Blocked: orientation','wp-seed-pixel'),'blocked_unknown_metadata'=>__('Blocked: unknown metadata','wp-seed-pixel'),'blocked_provenance'=>__('Blocked: provenance','wp-seed-pixel'),'blocked_public_copy'=>__('Blocked: public copy','wp-seed-pixel'));
        ob_start(); ?>
        <section class="pixel-metadata" data-id="<?php echo (int)$id; ?>" aria-label="<?php esc_attr_e('Metadata','wp-seed-pixel'); ?>">
            <h3><?php esc_html_e('Metadata','wp-seed-pixel'); ?></h3>
            <p class="pixel-metadata-result" data-state="<?php echo esc_attr($state); ?>"><strong><?php echo esc_html($state_labels[$state]); ?></strong></p>
            <?php if ($actionable) { ?>
            <fieldset><legend><?php esc_html_e('Metadata','wp-seed-pixel'); ?></legend>
                <label><input type="radio" name="pixel-metadata-<?php echo (int)$id; ?>" value="keep" checked> <?php esc_html_e('Keep','wp-seed-pixel'); ?></label>
                <label><input type="radio" name="pixel-metadata-<?php echo (int)$id; ?>" value="anonymize"> <?php esc_html_e('Anonymize','wp-seed-pixel'); ?></label>
            </fieldset>
            <?php } ?>
            <p><?php esc_html_e('Removes information embedded in the file that may reveal location, device, author or capture date.','wp-seed-pixel'); ?></p>
            <p><?php esc_html_e('Information necessary to display the image correctly is preserved.','wp-seed-pixel'); ?></p>
            <p><?php esc_html_e('Color profiles are kept and may contain descriptive information. This is not a guarantee of complete anonymity.','wp-seed-pixel'); ?></p>
            <?php if ($retained) { ?>
                <p><?php esc_html_e('The recovery original still contains its metadata. Restoring it restores those metadata.','wp-seed-pixel'); ?></p>
                <p><?php echo esc_html(sprintf(__('Metadata removed: %d bytes. This is not image compression.','wp-seed-pixel'),(int)($witness['metadata_removed_bytes']??0))); ?></p>
                <ul><?php foreach (($witness['metadata_categories']??array()) as $category) { if (isset($labels[$category])) { ?><li><?php echo esc_html($labels[$category]); ?></li><?php } } ?></ul>
            <?php } ?>
            <?php if (is_wp_error($analysis)) { ?><p role="status"><?php echo esc_html(self::reason($analysis->get_error_code())); ?></p>
            <?php } elseif (is_array($analysis)) { ?>
                <?php if ($state==='review') { ?><p role="status"><?php echo esc_html(self::reason(WP_Seed_Pixel_Metadata_Uploads::review_reason($id))); ?></p><?php } ?>
                <?php if ($actionable) { ?><p><strong><?php esc_html_e('Pixel will remove:','wp-seed-pixel'); ?></strong></p><ul>
                    <?php foreach ($analysis['master']['categories'] as $category) { ?><li><?php echo esc_html($labels[$category]); ?></li><?php } ?></ul>
                    <?php if (WP_Seed_Pixel_Metadata_Graph_Transaction::enabled()) { ?>
                        <p><label><input type="checkbox" class="pixel-metadata-approval"> <?php esc_html_e('I choose to anonymize this image and keep its original for restoration.','wp-seed-pixel'); ?></label></p>
                        <button type="button" class="button pixel-metadata-action" data-operation="start" data-signature="<?php echo esc_attr($analysis['signature']); ?>" disabled><?php esc_html_e('Anonymize this image','wp-seed-pixel'); ?></button>
                    <?php } else { ?><p role="status"><?php esc_html_e('Local candidate: replacement and restoration certification is still required. No image has been changed.','wp-seed-pixel'); ?></p><?php } ?>
                <?php } elseif ($state==='anonymized' || $state==='clean') { ?><p><?php echo esc_html($retained?__('No supported private metadata remains in the public files checked.','wp-seed-pixel'):self::reason('METADATA_ALREADY_CLEAN')); ?></p><?php } ?>
            <?php } ?>
            <p><button type="button" class="button pixel-metadata-action" data-operation="analyze"><?php esc_html_e('Analyze metadata','wp-seed-pixel'); ?></button></p>
            <p class="pixel-metadata-status" role="status" aria-live="polite"></p>
        </section>
        <?php return ob_get_clean();
    }
}
