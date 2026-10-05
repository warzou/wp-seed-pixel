<?php
defined('ABSPATH') || exit;

/** UI adapter only: all mutations remain fenced by M2 and the M4 item lifecycle. */
final class WP_Seed_Pixel_Bulk_Admin {
    public static function boot() {
        if (!WP_Seed_Pixel_Quarantine::enabled()) { return; }
        add_action('admin_menu', static function () { add_media_page(__('Bulk storage saver', 'wp-seed-pixel'), __('Pixel - Storage saver', 'wp-seed-pixel'), 'manage_options', 'wp-seed-pixel-bulk', array(__CLASS__, 'page')); });
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_wp_seed_pixel_bulk', array(__CLASS__, 'ajax'));
    }
    public static function labels() {
        return array_merge(WP_Seed_Pixel_Jobs_Admin::labels(), array(
            'running' => __('Running', 'wp-seed-pixel'), 'retained' => __('Verified; previous version quarantined', 'wp-seed-pixel'),
            'skipped' => __('Skipped', 'wp-seed-pixel'), 'purged' => __('Permanently deleted previous version', 'wp-seed-pixel'),
            'rolled_back' => __('Restored exactly', 'wp-seed-pixel'), 'replace' => __('Replace eligible JPEG and PNG images', 'wp-seed-pixel'),
            'EXCLUDED_BY_POLICY' => __('Excluded by the selected policy.', 'wp-seed-pixel'),
            'preparing' => __('Preparing candidate', 'wp-seed-pixel'), 'switch_intent' => __('Replacement intent recorded', 'wp-seed-pixel'),
            'switched' => __('Master replaced; verification pending', 'wp-seed-pixel'), 'verified' => __('Master verified', 'wp-seed-pixel'),
            'retire' => __('Retire preserved originals', 'wp-seed-pixel'),
            'empty' => __('No bulk job yet', 'wp-seed-pixel'), 'audit' => __('Current physical audit', 'wp-seed-pixel'),
            'current_active_bytes' => __('Current active source bytes', 'wp-seed-pixel'),
            'active_saved_bytes' => __('Active source bytes saved', 'wp-seed-pixel'),
            'quarantine_bytes' => __('Quarantine bytes still on disk', 'wp-seed-pixel'),
            'potential_purge_bytes' => __('Potential bytes removable by explicit purge', 'wp-seed-pixel'),
            'removed_source_bytes' => __('Verified file bytes physically removed', 'wp-seed-pixel'),
            'allocated_reclaimed_bytes' => __('Allocated blocks removed (not hosting quota)', 'wp-seed-pixel'),
            'net_reclaimed_bytes' => __('Net file bytes saved including recovery and audit files', 'wp-seed-pixel'),
            'unknown_items' => __('Items requiring a fresh physical review', 'wp-seed-pixel'),
            'unknown' => __('Unknown', 'wp-seed-pixel'),
            'removeNotice' => __('Permanent deletion is separate. Open retained versions to select one image and acknowledge the loss of restoration.', 'wp-seed-pixel'),
            'CANDIDATE_INVALID' => __('Candidate rejected', 'wp-seed-pixel'), 'BACKUP_FAILED' => __('Recovery integrity needs review', 'wp-seed-pixel'),
            'SWAP_FAILED' => __('Replacement failed', 'wp-seed-pixel'), 'VERIFY_FAILED' => __('Verification failed.', 'wp-seed-pixel'),
            'PURGE_FAILED' => __('Permanent deletion stopped.', 'wp-seed-pixel')));
    }
    public static function assets($hook) {
        if ($hook !== 'media_page_wp-seed-pixel-bulk') { return; }
        wp_enqueue_style('wp-seed-pixel-bulk', plugins_url('assets/bulk.css', WP_SEED_PIXEL_FILE), array(), '1');
        wp_enqueue_script('wp-seed-pixel-bulk', plugins_url('assets/bulk.js', WP_SEED_PIXEL_FILE), array(), '1', true);
        wp_enqueue_media();
        wp_localize_script('wp-seed-pixel-bulk', 'wpSeedPixelBulk', array('url'=>admin_url('admin-ajax.php'), 'nonce'=>wp_create_nonce('wp_seed_pixel_bulk'), 'labels'=>self::labels()));
    }
    public static function ajax() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_options') || !WP_Seed_Pixel_Quarantine::enabled()) { wp_send_json_error(array('code'=>'PERMISSION_DENIED'),403); }
        check_ajax_referer('wp_seed_pixel_bulk', 'nonce');
        $op = is_string($_POST['operation'] ?? null) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $id = is_scalar($_POST['job_id'] ?? null) ? absint($_POST['job_id']) : 0;
        $existing = $id ? WP_Seed_Pixel_Job_Store::job($id) : null;
        $p = $existing ? json_decode($existing['policy'],true) : null;
        if ($op !== 'plan' && (!$existing || !isset($p['bulk']))) { wp_send_json_error(array('code'=>'JOB_REQUIRED'),400); }
        if ($op === 'plan') {
            $scan = WP_Seed_Pixel_Scan::current();
            $mode = ($_POST['mode'] ?? '') === 'retire' ? 'retire' : 'replace';
            $capacity = filter_var($_POST['capacity_mb'] ?? null, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1,'max_range'=>1048576)));
            $lot = filter_var($_POST['lot'] ?? null, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1,'max_range'=>5)));
            $ack = ($_POST['original_ack'] ?? '') === '1';
            $intent = $mode === 'retire' ? array('original'=>'retire_verified') : array('master'=>'replace_verified');
            $selection = is_string($_POST['selection'] ?? null) ? wp_unslash($_POST['selection']) : '';
            $ids = preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,499}$/D', $selection) ? array_map('intval', explode(',', $selection)) : array();
            $r = !$capacity || !$lot || !$ids ? new WP_Error('POLICY_INVALID') : (is_wp_error($scan) || empty($scan['id']) ? new WP_Error('SCAN_REQUIRED') : WP_Seed_Pixel_Jobs::bulk_plan((int)$scan['id'],$intent,$capacity*1048576,$mode,$lot,$ack,$ids));
        } elseif ($op === 'start') { $r = WP_Seed_Pixel_Jobs::start($id); }
        elseif ($op === 'step') { $r = WP_Seed_Pixel_Jobs::bulk_step($id); }
        elseif ($op === 'resume' && $existing['kind'] === 'plan') { $r = WP_Seed_Pixel_Jobs::bulk_step($id); }
        elseif (in_array($op,array('pause','resume','cancel','retry'),true)) { $r = WP_Seed_Pixel_Jobs::control($id,$op); }
        elseif ($op === 'status') { $r = WP_Seed_Pixel_Jobs::status($id); }
        elseif ($op === 'audit') { $r = WP_Seed_Pixel_Jobs::storage_audit($id); }
        elseif ($op === 'results') { $r = WP_Seed_Pixel_Jobs::results($id,is_scalar($_POST['page'] ?? null) ? absint($_POST['page']) : 1); }
        else { $r = new WP_Error('INVALID_STATE'); }
        if (is_wp_error($r)) { $code=$r->get_error_code(); wp_send_json_error(array('code'=>$code,'message'=>self::labels()[$code] ?? __('Request failed. Resume later; no automatic retry was sent.', 'wp-seed-pixel')),400); }
        wp_send_json_success($r);
    }
    public static function page() {
        global $wpdb;
        if (!current_user_can('manage_options')) { return; }
        $jobs = (int) get_option('wp_seed_pixel_job_schema') === WP_Seed_Pixel_Job_Store::SCHEMA
            ? $wpdb->get_results('SELECT id,kind,status,policy FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . " WHERE kind IN ('plan','replace','retire') ORDER BY id DESC LIMIT 50",ARRAY_A) : array();
        ?>
        <div class="wrap pixel-bulk"><h1><?php esc_html_e('Bulk storage saver','wp-seed-pixel'); ?></h1>
        <p><?php esc_html_e('Replacement reduces active image bytes. Previous versions remain on disk until a separate, explicit permanent deletion. No work starts automatically.','wp-seed-pixel'); ?></p>
        <p><?php esc_html_e('Measurements cover planned operational sources, not unchanged thumbnails or excluded files. Hosting quota remains unknown.','wp-seed-pixel'); ?></p>
        <p><a href="<?php echo esc_url(admin_url('upload.php?page=wp-seed-pixel-storage')); ?>"><?php esc_html_e('Analyze image storage','wp-seed-pixel'); ?></a> | <a href="<?php echo esc_url(admin_url('upload.php?page=wp-seed-pixel-quarantine')); ?>"><?php esc_html_e('Retained image versions','wp-seed-pixel'); ?></a></p>
        <form id="pixel-bulk-policy">
        <label><?php esc_html_e('Operation','wp-seed-pixel'); ?><select name="mode"><option value="replace"><?php esc_html_e('Replace eligible JPEG and PNG images','wp-seed-pixel'); ?></option><option value="retire"><?php esc_html_e('Retire preserved originals','wp-seed-pixel'); ?></option></select></label>
        <p><button type="button" class="button" id="pixel-bulk-select"><?php esc_html_e('Choose existing media','wp-seed-pixel'); ?></button> <span id="pixel-bulk-selection-status" role="status" aria-live="polite"></span><input type="hidden" name="selection" id="pixel-bulk-selection" value=""></p>
        <label><?php esc_html_e('Verified hosting budget (MiB)','wp-seed-pixel'); ?><input type="number" name="capacity_mb" min="1" max="1048576" required></label>
        <label><?php esc_html_e('Images per request','wp-seed-pixel'); ?><input type="number" name="lot" min="1" max="5" value="1" required></label>
        <label><input type="checkbox" name="original_ack" value="1"> <?php esc_html_e('For original retirement, I accept that unknown external original-image URLs may stop working.','wp-seed-pixel'); ?></label>
        <button class="button" type="submit"><?php esc_html_e('Build plan from latest analysis','wp-seed-pixel'); ?></button></form>
        <label for="pixel-bulk-job"><?php esc_html_e('Plan or job','wp-seed-pixel'); ?></label><select id="pixel-bulk-job"><option value="0">-</option><?php $labels=self::labels(); foreach($jobs as $j) { if(!isset(json_decode($j['policy'],true)['bulk'])) {continue;} ?><option value="<?php echo (int)$j['id']; ?>"><?php echo esc_html('#'.$j['id'].' '.($labels[$j['kind']]??$j['kind']).' / '.($labels[$j['status']]??$j['status'])); ?></option><?php } ?></select>
        <div class="pixel-bulk-actions"><?php foreach(array('start'=>__('Start verified operation','wp-seed-pixel'),'pause'=>__('Pause','wp-seed-pixel'),'resume'=>__('Resume','wp-seed-pixel'),'cancel'=>__('Cancel','wp-seed-pixel'),'retry'=>__('Retry failed items','wp-seed-pixel'),'audit'=>__('Measure current file space','wp-seed-pixel')) as $k=>$v) { ?><button type="button" class="button" data-command="<?php echo esc_attr($k); ?>" disabled><?php echo esc_html($v); ?></button><?php } ?></div>
        <p id="pixel-bulk-status" role="status" aria-live="polite"></p><p id="pixel-bulk-error" role="alert"></p>
        <label for="pixel-bulk-progress"><?php esc_html_e('Progress','wp-seed-pixel'); ?></label><progress id="pixel-bulk-progress" max="1" value="0"></progress>
        <dl id="pixel-bulk-metrics"></dl><p id="pixel-bulk-fingerprint"></p>
        <div id="pixel-bulk-results"></div>
        <div class="pixel-bulk-actions"><button class="button" id="pixel-bulk-prev" disabled><?php esc_html_e('Previous','wp-seed-pixel'); ?></button><span id="pixel-bulk-page"></span><button class="button" id="pixel-bulk-next" disabled><?php esc_html_e('Next','wp-seed-pixel'); ?></button></div>
        <noscript><?php esc_html_e('Controls require JavaScript. No work starts automatically.','wp-seed-pixel'); ?></noscript></div>
        <?php
    }
}
