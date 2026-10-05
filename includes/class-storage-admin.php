<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Storage_Admin {
    public static function boot() {
        add_action('admin_menu', static function () { add_media_page(__('Image storage', 'wp-seed-pixel'), __('Pixel - Image storage', 'wp-seed-pixel'), 'manage_options', 'wp-seed-pixel-storage', array(__CLASS__, 'page')); });
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('wp_ajax_wp_seed_pixel_scan', array(__CLASS__, 'ajax'));
    }

    public static function labels() {
        return array('running' => __('Analyzing', 'wp-seed-pixel'), 'paused' => __('Paused', 'wp-seed-pixel'), 'cancelled' => __('Cancelled', 'wp-seed-pixel'), 'complete' => __('Analysis complete', 'wp-seed-pixel'), 'healthy' => __('Consistent', 'wp-seed-pixel'), 'needs_review' => __('Needs review', 'wp-seed-pixel'), 'missing' => __('Missing file', 'wp-seed-pixel'), 'unsupported' => __('Unsupported operation', 'wp-seed-pixel'), 'operational' => __('Main images', 'wp-seed-pixel'), 'preserved_original' => __('WordPress preserved originals', 'wp-seed-pixel'), 'wp_sizes' => __('WordPress sizes', 'wp-seed-pixel'), 'pixel_current' => __('Current Pixel versions', 'wp-seed-pixel'), 'pixel_history' => __('Previous Pixel versions', 'wp-seed-pixel'), 'edit_backups' => __('WordPress edit backups', 'wp-seed-pixel'), 'unattributed' => __('Unattributed related files', 'wp-seed-pixel'), 'preserved_original_review' => __('Preserved original: review future retirement', 'wp-seed-pixel'), 'oversized_review' => __('Main image exceeds 2560 pixels: review dimensions', 'wp-seed-pixel'), 'recompression_unmeasured' => __('JPEG compression savings not measured', 'wp-seed-pixel'), 'lossless_unmeasured' => __('PNG lossless savings not measured', 'wp-seed-pixel'), 'history_review' => __('Previous Pixel versions: review references', 'wp-seed-pixel'), 'unknown' => __('Unknown', 'wp-seed-pixel'), 'stale' => __('Changed since analysis; analyze again', 'wp-seed-pixel'), 'related' => __('Related bytes (not additive)', 'wp-seed-pixel'), 'files' => __('Files and their roles', 'wp-seed-pixel'), 'progress' => __('attachments analyzed', 'wp-seed-pixel'), 'empty' => __('No analysis yet', 'wp-seed-pixel'), 'nothing' => __('No matching images', 'wp-seed-pixel'));
    }

    private static function issue_labels() {
        return array(
            'shared_file' => __('File shared by several attachments', 'wp-seed-pixel'),
            'ownership_review' => __('Related file ownership needs review', 'wp-seed-pixel'),
            'missing_mapping' => __('Main file mapping is missing', 'wp-seed-pixel'),
            'missing_file' => __('A referenced file is missing', 'wp-seed-pixel'),
            'unreadable_file' => __('A referenced file cannot be read', 'wp-seed-pixel'),
            'mime_mismatch' => __('Reported format differs from the file header', 'wp-seed-pixel'),
            'dimension_mismatch' => __('Reported dimensions differ from the file header', 'wp-seed-pixel'),
            'metadata_review' => __('WordPress edit backups or ambiguous metadata', 'wp-seed-pixel'),
            'ambiguous_manifest' => __('Pixel file relationships are ambiguous', 'wp-seed-pixel'),
            'inventory_limit' => __('Large file graph: inventory is partial', 'wp-seed-pixel'),
            'filtered_storage' => __('External storage mapping needs review', 'wp-seed-pixel'),
            'external_storage' => __('File is outside local uploads', 'wp-seed-pixel'),
            'symlink_storage' => __('Symbolic link excluded from local accounting', 'wp-seed-pixel'),
            'unsafe_path' => __('Unsafe file mapping excluded', 'wp-seed-pixel'),
            'unsupported_format' => __('Format is not eligible for processing', 'wp-seed-pixel'),
            'pixel_scan_permission' => __('Permission denied.', 'wp-seed-pixel'),
            'pixel_scan_root' => __('Local upload storage is unavailable.', 'wp-seed-pixel'),
        );
    }

    public static function assets($hook) {
        if ($hook !== 'media_page_wp-seed-pixel-storage') { return; }
        wp_enqueue_style('wp-seed-pixel-storage', plugins_url('assets/storage.css', WP_SEED_PIXEL_FILE), array(), WP_Seed_Pixel_Analyzer::VERSION);
        wp_enqueue_script('wp-seed-pixel-storage', plugins_url('assets/storage.js', WP_SEED_PIXEL_FILE), array(), WP_Seed_Pixel_Analyzer::VERSION, true);
        wp_localize_script('wp-seed-pixel-storage', 'wpSeedPixelScan', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wp_seed_pixel_scan'), 'labels' => array_merge(self::labels(), self::issue_labels()), 'error' => __('Request failed. Resume later; no automatic retry was sent.', 'wp-seed-pixel'), 'locale' => str_replace('_', '-', get_user_locale())));
    }

    public static function ajax() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { wp_send_json_error(array('message' => __('POST required.', 'wp-seed-pixel')), 405); }
        if (!current_user_can('manage_options')) { wp_send_json_error(array('message' => __('Permission denied.', 'wp-seed-pixel')), 403); }
        check_ajax_referer('wp_seed_pixel_scan', 'nonce');
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        $id = isset($_POST['job_id']) && is_scalar($_POST['job_id']) ? absint($_POST['job_id']) : 0;
        switch ($operation) {
            case 'start': $result = WP_Seed_Pixel_Scan::start(); break;
            case 'step': $result = WP_Seed_Pixel_Scan::step($id); break;
            case 'status': $result = WP_Seed_Pixel_Scan::current(); break;
            case 'pause': $result = WP_Seed_Pixel_Scan::control($id, 'paused'); break;
            case 'resume': $result = WP_Seed_Pixel_Scan::control($id, 'running'); break;
            case 'cancel': $result = WP_Seed_Pixel_Scan::control($id, 'cancelled'); break;
            case 'results': $result = WP_Seed_Pixel_Scan::results($id, isset($_POST['page']) && is_scalar($_POST['page']) ? absint($_POST['page']) : 1, isset($_POST['filter']) && is_string($_POST['filter']) ? sanitize_key(wp_unslash($_POST['filter'])) : ''); break;
            default: $result = new WP_Error('pixel_scan_operation', __('Unknown operation.', 'wp-seed-pixel'));
        }
        if (is_wp_error($result)) { wp_send_json_error(array('code' => $result->get_error_code(), 'message' => $result->get_error_message()), 400); }
        wp_send_json_success($result);
    }

    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        ?>
        <div class="wrap pixel-storage">
            <h1><?php esc_html_e('Image storage', 'wp-seed-pixel'); ?></h1>
            <p><?php esc_html_e('Analysis only. Images and their WordPress data are not changed.', 'wp-seed-pixel'); ?></p>
            <div class="pixel-scan-actions">
                <button type="button" class="button button-primary" id="pixel-scan-start"><?php esc_html_e('Analyze library', 'wp-seed-pixel'); ?></button>
                <button type="button" class="button" id="pixel-scan-pause" disabled><?php esc_html_e('Pause', 'wp-seed-pixel'); ?></button>
                <button type="button" class="button" id="pixel-scan-resume" disabled><?php esc_html_e('Resume', 'wp-seed-pixel'); ?></button>
                <button type="button" class="button" id="pixel-scan-cancel" disabled><?php esc_html_e('Cancel analysis', 'wp-seed-pixel'); ?></button>
            </div>
            <p id="pixel-scan-status" role="status" aria-live="polite"><?php esc_html_e('No analysis yet', 'wp-seed-pixel'); ?></p>
            <label for="pixel-scan-progress"><?php esc_html_e('Progress', 'wp-seed-pixel'); ?></label>
            <progress id="pixel-scan-progress" value="0" max="1"></progress>
            <dl class="pixel-scan-totals">
                <div><dt><?php esc_html_e('Unique file bytes observed', 'wp-seed-pixel'); ?></dt><dd id="pixel-scan-bytes">—</dd></div>
                <div><dt><?php esc_html_e('Original bytes to review', 'wp-seed-pixel'); ?></dt><dd id="pixel-scan-potential">—</dd></div>
                <div><dt><?php esc_html_e('Uncertain or shared bytes', 'wp-seed-pixel'); ?></dt><dd id="pixel-scan-uncertain">—</dd></div>
                <div><dt><?php esc_html_e('Space reclaimed', 'wp-seed-pixel'); ?></dt><dd id="pixel-scan-reclaimed">0</dd></div>
            </dl>
            <p><?php esc_html_e('Potential is conditional, not freed space. Quarantine would still occupy storage. Filesystem allocation and hosting quota are unknown.', 'wp-seed-pixel'); ?></p>
            <h2><?php esc_html_e('Where the bytes are', 'wp-seed-pixel'); ?></h2>
            <ul id="pixel-scan-roles"></ul>
            <?php if (WP_Seed_Pixel_Quarantine::enabled()) { ?>
            <?php $q = WP_Seed_Pixel_Quarantine::summary(); ?>
            <h2><?php esc_html_e('Retained versions: latest 20 operations only', 'wp-seed-pixel'); ?></h2>
            <dl>
                <dt><?php esc_html_e('Retained bytes', 'wp-seed-pixel'); ?></dt><dd><?php echo esc_html(number_format_i18n($q['quarantine_bytes'])); ?> B</dd>
                <dt><?php esc_html_e('Recoverable space after permanent deletion', 'wp-seed-pixel'); ?></dt><dd><?php echo esc_html(number_format_i18n($q['potential_purge_bytes'])); ?> B</dd>
                <dt><?php esc_html_e('Verified quarantine bytes removed', 'wp-seed-pixel'); ?></dt><dd><?php echo esc_html(number_format_i18n($q['removed_bytes'])); ?> B</dd>
            </dl>
            <p><a href="<?php echo esc_url(admin_url('upload.php?page=wp-seed-pixel-quarantine')); ?>"><?php esc_html_e('Retained image versions', 'wp-seed-pixel'); ?></a></p>
            <?php } ?>
            <p><?php esc_html_e('Quick inventory, dated per image. No compression test. Related-looking extra files are checked in a bounded folder sample; this is not a complete disk audit.', 'wp-seed-pixel'); ?></p>
            <h2><?php esc_html_e('Images', 'wp-seed-pixel'); ?></h2>
            <label for="pixel-scan-filter"><?php esc_html_e('State', 'wp-seed-pixel'); ?></label>
            <select id="pixel-scan-filter"><option value=""><?php esc_html_e('All states', 'wp-seed-pixel'); ?></option>
                <?php foreach (array('healthy', 'needs_review', 'missing', 'unsupported') as $key) { ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html(self::labels()[$key]); ?></option><?php } ?>
            </select>
            <div id="pixel-scan-results"></div>
            <div class="pixel-scan-actions">
                <button class="button" type="button" id="pixel-scan-previous" disabled><?php esc_html_e('Previous', 'wp-seed-pixel'); ?></button>
                <span id="pixel-scan-page"></span>
                <button class="button" type="button" id="pixel-scan-next" disabled><?php esc_html_e('Next', 'wp-seed-pixel'); ?></button>
            </div>
            <noscript><p><?php esc_html_e('Analysis controls require JavaScript. No image processing starts on this page.', 'wp-seed-pixel'); ?></p></noscript>
        </div>
        <?php
    }
}
