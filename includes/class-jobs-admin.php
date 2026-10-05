<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Jobs_Admin {
    public static function boot() {
        add_action('admin_menu', static function () { add_media_page(__('Storage simulation', 'wp-seed-pixel'), __('Pixel - Simulation', 'wp-seed-pixel'), 'manage_options', 'wp-seed-pixel-jobs', array(__CLASS__, 'page')); });
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_wp_seed_pixel_jobs', array(__CLASS__, 'ajax'));
    }
    public static function labels() {
        return array('real_replace' => __('Single-image replacement', 'wp-seed-pixel'), 'real_running' => __('Running', 'wp-seed-pixel'),
            'real_preparing' => __('Preparing candidate', 'wp-seed-pixel'), 'real_switch_intent' => __('Replacement intent recorded', 'wp-seed-pixel'),
            'real_switched' => __('Master replaced; verification pending', 'wp-seed-pixel'), 'real_verified' => __('Master verified', 'wp-seed-pixel'),
            'real_retained' => __('Master verified; recovery retained', 'wp-seed-pixel'), 'real_rolled_back' => __('Restored exactly', 'wp-seed-pixel'),
            'real_skipped' => __('Not replaced', 'wp-seed-pixel'), 'real_NO_BENEFIT' => __('No meaningful saving; source retained', 'wp-seed-pixel'),
            'real_CANDIDATE_INVALID' => __('Candidate rejected', 'wp-seed-pixel'), 'real_BACKUP_FAILED' => __('Recovery integrity needs review', 'wp-seed-pixel'),
            'real_SWAP_FAILED' => __('Replacement failed', 'wp-seed-pixel'), 'real_VERIFY_FAILED' => __('Verification failed', 'wp-seed-pixel'),
            'queued' => __('Queued', 'wp-seed-pixel'), 'running' => __('Running simulation', 'wp-seed-pixel'),
            'paused' => __('Paused', 'wp-seed-pixel'), 'completed' => __('Complete', 'wp-seed-pixel'),
            'completed_errors' => __('Complete with errors', 'wp-seed-pixel'), 'failed_systemic' => __('Stopped: system error', 'wp-seed-pixel'),
            'cancelled' => __('Cancelled', 'wp-seed-pixel'), 'preparing' => __('Preparing simulation', 'wp-seed-pixel'),
            'ready' => __('Ready', 'wp-seed-pixel'), 'switch_intent' => __('Simulated switch intent', 'wp-seed-pixel'),
            'switched' => __('Simulated switch', 'wp-seed-pixel'), 'verified' => __('Simulation verified', 'wp-seed-pixel'),
            'retained' => __('Simulation complete', 'wp-seed-pixel'), 'skipped' => __('Excluded from simulation', 'wp-seed-pixel'),
            'failed' => __('Failed', 'wp-seed-pixel'), 'needs_review' => __('Needs review', 'wp-seed-pixel'),
            'recovery_required' => __('Reconciliation required', 'wp-seed-pixel'), 'plan' => __('Plan', 'wp-seed-pixel'),
            'simulation' => __('Simulation', 'wp-seed-pixel'), 'empty' => __('No simulation yet', 'wp-seed-pixel'),
            'attachment' => __('Image ID', 'wp-seed-pixel'), 'reason' => __('Reason', 'wp-seed-pixel'),
            'state' => __('State', 'wp-seed-pixel'), 'error' => __('Request failed. Resume later; no automatic retry was sent.', 'wp-seed-pixel'),
            'SCAN_REQUIRED' => __('Complete an image storage analysis first.', 'wp-seed-pixel'),
            'LOCKED' => __('Another worker owns this operation. Try later.', 'wp-seed-pixel'),
            'CLAIM_CONFLICT' => __('Another job reserves this image.', 'wp-seed-pixel'),
            'SOURCE_CHANGED' => __('Changed since analysis; analyze again', 'wp-seed-pixel'),
            'SOURCE_MISSING' => __('Missing file', 'wp-seed-pixel'), 'NEEDS_REVIEW' => __('Needs review', 'wp-seed-pixel'),
            'UNSUPPORTED_FORMAT' => __('Unsupported operation', 'wp-seed-pixel'), 'ICC_UNSAFE' => __('Color or orientation needs review', 'wp-seed-pixel'),
            'DIMENSION_CONFLICT' => __('Dimensions conflict with retained sizes', 'wp-seed-pixel'),
            'EVIDENCE_INVALID' => __('Saved evidence is inconsistent; review required.', 'wp-seed-pixel'),
            'ENGINE_INCOMPATIBLE' => __('Job version is incompatible; review required.', 'wp-seed-pixel'),
            'STORE_FAILED' => __('Job state could not be saved.', 'wp-seed-pixel'),
            'RECOVERY_REQUIRED' => __('Reconcile the interrupted item before cancelling.', 'wp-seed-pixel'),
            'RETRY_UNAVAILABLE' => __('No eligible failures remain to retry.', 'wp-seed-pixel'),
            'INVALID_STATE' => __('This action is not available in the current state.', 'wp-seed-pixel'),
            'PERMISSION_DENIED' => __('Permission denied.', 'wp-seed-pixel'),
            'POLICY_INVALID' => __('The policy is invalid.', 'wp-seed-pixel'), 'PLAN_CHANGED' => __('The frozen plan is inconsistent.', 'wp-seed-pixel'),
            'PLAN_REQUIRED' => __('A completed plan is required.', 'wp-seed-pixel'), 'JOB_REQUIRED' => __('Select an existing simulation.', 'wp-seed-pixel'),
            'SHARED_PATH' => __('File shared by several attachments', 'wp-seed-pixel'),
            'METADATA_CONFLICT' => __('Image data changed during the operation.', 'wp-seed-pixel'),
            'UNSUPPORTED_STORAGE' => __('External storage mapping needs review', 'wp-seed-pixel'),
            'LOW_DISK' => __('Insufficient disk space.', 'wp-seed-pixel'), 'QUOTA_UNKNOWN' => __('Hosting quota is unknown.', 'wp-seed-pixel'),
            'BACKEND_UNAVAILABLE' => __('The processing backend is unavailable.', 'wp-seed-pixel'),
            'CANDIDATE_INVALID' => __('The simulated candidate was rejected.', 'wp-seed-pixel'),
            'BACKUP_FAILED' => __('Simulated recovery preparation failed.', 'wp-seed-pixel'),
            'SWAP_FAILED' => __('The simulated switch failed.', 'wp-seed-pixel'), 'VERIFY_FAILED' => __('Simulation verification failed.', 'wp-seed-pixel'),
            'PURGE_FAILED' => __('The simulated purge failed.', 'wp-seed-pixel'),
            'CONFLICTING_OPTIMIZER' => __('Another optimizer conflicts with this operation.', 'wp-seed-pixel'),
            'REQUEST_INTERRUPTED' => __('The request was interrupted.', 'wp-seed-pixel'),
            'RETRY_EXHAUSTED' => __('Retry limit reached; review required.', 'wp-seed-pixel'),
            'SCHEMA_FAILED' => __('Job storage could not be prepared.', 'wp-seed-pixel'));
    }
    public static function assets($hook) {
        if ($hook !== 'media_page_wp-seed-pixel-jobs') { return; }
        wp_enqueue_style('wp-seed-pixel-jobs', plugins_url('assets/jobs.css', WP_SEED_PIXEL_FILE), array(), '2');
        wp_enqueue_script('wp-seed-pixel-jobs', plugins_url('assets/jobs.js', WP_SEED_PIXEL_FILE), array(), '2', true);
        wp_localize_script('wp-seed-pixel-jobs', 'wpSeedPixelJobs', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wp_seed_pixel_jobs'), 'labels' => self::labels()));
    }
    public static function ajax() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { wp_send_json_error(array('code' => 'POST_REQUIRED'), 405); }
        if (!current_user_can('manage_options')) { wp_send_json_error(array('code' => 'PERMISSION_DENIED'), 403); }
        check_ajax_referer('wp_seed_pixel_jobs', 'nonce');
        // Client input is IDs and enumerated operations only, never policy JSON or paths.
        $op = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $id = isset($_POST['job_id']) && is_scalar($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        $existing = $id ? WP_Seed_Pixel_Job_Store::job($id) : null;
        // The legacy simulation UI must never authorize a real bulk plan.
        $policy = $existing ? json_decode($existing['policy'], true) : null;
        if (isset($policy['bulk']) && !in_array($op, array('status', 'results'), true)) {
            wp_send_json_error(array('code' => 'PERMISSION_DENIED'), 403);
        }
        if ($existing && in_array($existing['kind'], array('replace', 'retire'), true) && !in_array($op, array('status', 'results'), true)) {
            wp_send_json_error(array('code' => 'PERMISSION_DENIED'), 403);
        }
        switch ($op) {
            case 'plan':
                $scan = WP_Seed_Pixel_Scan::current();
                $result = is_wp_error($scan) || empty($scan['id']) ? new WP_Error('SCAN_REQUIRED') : WP_Seed_Pixel_Jobs::plan($scan['id']); break;
            case 'start': $result = WP_Seed_Pixel_Jobs::start($id); break;
            case 'step': $result = WP_Seed_Pixel_Jobs::step($id); break;
            case 'status': $result = WP_Seed_Pixel_Jobs::status($id); break;
            case 'pause': case 'resume': case 'cancel': case 'retry': $result = WP_Seed_Pixel_Jobs::control($id, $op); break;
            case 'results': $result = WP_Seed_Pixel_Jobs::results($id, isset($_POST['page']) && is_scalar($_POST['page']) ? absint($_POST['page']) : 1); break;
            default: $result = new WP_Error('INVALID_STATE');
        }
        if (is_wp_error($result)) { $code = $result->get_error_code(); wp_send_json_error(array('code' => $code, 'message' => self::labels()[$code] ?? __('Job state could not be saved.', 'wp-seed-pixel')), 400); }
        wp_send_json_success($result);
    }
    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $current = WP_Seed_Pixel_Jobs::status(); $real = ($current['kind'] ?? '') === 'replace';
        ?>
        <div class="wrap pixel-jobs">
            <h1><?php echo esc_html($real ? __('Single-image replacement', 'wp-seed-pixel') : __('Storage simulation', 'wp-seed-pixel')); ?></h1>
            <?php if (!$real) { ?><p><?php esc_html_e('Simulation only. No images or attachment data are changed. Replacement and purge are unavailable.', 'wp-seed-pixel'); ?></p><?php } ?>
            <div class="pixel-job-actions">
                <?php foreach (array('plan' => __('Build plan from latest analysis', 'wp-seed-pixel'), 'start' => __('Run simulation', 'wp-seed-pixel'), 'pause' => __('Pause', 'wp-seed-pixel'), 'resume' => __('Resume', 'wp-seed-pixel'), 'cancel' => __('Cancel', 'wp-seed-pixel'), 'retry' => __('Retry failed items', 'wp-seed-pixel')) as $key => $label) { ?>
                    <button type="button" class="button" id="pixel-job-<?php echo esc_attr($key === 'plan' ? 'build' : $key); ?>" data-command="<?php echo esc_attr($key); ?>" <?php disabled($key !== 'plan'); ?>><?php echo esc_html($label); ?></button>
                <?php } ?>
            </div>
            <p id="pixel-job-status" role="status" aria-live="polite"><?php esc_html_e('No simulation yet', 'wp-seed-pixel'); ?></p>
            <p id="pixel-job-error" role="alert"></p>
            <label for="pixel-job-progress"><?php esc_html_e('Progress', 'wp-seed-pixel'); ?></label>
            <progress id="pixel-job-progress" value="0" max="1"></progress>
            <dl><dt><?php esc_html_e('Policy fingerprint', 'wp-seed-pixel'); ?></dt><dd id="pixel-job-policy">-</dd>
                <dt><?php esc_html_e('Plan fingerprint', 'wp-seed-pixel'); ?></dt><dd id="pixel-job-plan">-</dd>
                <dt><?php esc_html_e('Space reclaimed', 'wp-seed-pixel'); ?></dt><dd>0</dd></dl>
            <h2><?php esc_html_e('Images', 'wp-seed-pixel'); ?></h2>
            <div id="pixel-job-results"></div>
            <div class="pixel-job-actions"><button class="button" id="pixel-job-previous" disabled><?php esc_html_e('Previous', 'wp-seed-pixel'); ?></button><span id="pixel-job-page"></span><button class="button" id="pixel-job-next" disabled><?php esc_html_e('Next', 'wp-seed-pixel'); ?></button></div>
            <noscript><p><?php esc_html_e('Simulation controls require JavaScript. No work starts automatically.', 'wp-seed-pixel'); ?></p></noscript>
        </div>
        <?php
    }
}
