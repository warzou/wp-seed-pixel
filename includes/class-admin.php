<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Admin {
    public static function boot() {
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_wp_seed_pixel_settings', array(__CLASS__, 'save'));
        add_action('admin_post_wp_seed_pixel_manual', array(__CLASS__, 'manual'));
        add_action('wp_ajax_wp_seed_pixel', array(__CLASS__, 'ajax'));
        add_filter('manage_media_columns', array(__CLASS__, 'columns'));
        add_action('manage_media_custom_column', array(__CLASS__, 'column'), 10, 2);
        add_filter('media_row_actions', array(__CLASS__, 'row_actions'), 10, 2);
    }

    public static function menu() {
        add_media_page('WP Seed Pixel', 'WP Seed Pixel', 'manage_options', 'wp-seed-pixel', array(__CLASS__, 'page'));
    }

    public static function assets($hook) {
        if ($hook !== 'media_page_wp-seed-pixel') {
            $screen = get_current_screen();
            if ($hook === 'upload.php' || $screen && $screen->post_type === 'attachment') {
                wp_enqueue_style('wp-seed-pixel-admin', plugins_url('assets/admin.css', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_VERSION);
                wp_enqueue_script('wp-seed-pixel-media', plugins_url('assets/media.js', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_VERSION, true);
                wp_localize_script('wp-seed-pixel-media', 'wpSeedPixelMedia', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wp_seed_pixel'), 'preset' => WP_Seed_Pixel_Plugin::settings()['preset'], 'processing' => __('Processing', 'wp-seed-pixel'), 'failed' => __('Request failed; no automatic retry.', 'wp-seed-pixel')));
            }
            return;
        }
        wp_enqueue_style('wp-seed-pixel-admin', plugins_url('assets/admin.css', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_VERSION);
        wp_enqueue_script('wp-seed-pixel-admin', plugins_url('assets/admin.js', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_VERSION, true);
        wp_localize_script('wp-seed-pixel-admin', 'wpSeedPixel', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wp_seed_pixel'), 'error' => __('Request failed. Resume later; no automatic retry was sent.', 'wp-seed-pixel')));
    }

    public static function columns($columns) {
        $columns['wp_seed_pixel'] = 'WP Seed Pixel';
        return $columns;
    }

    public static function column($column, $id) {
        if ($column !== 'wp_seed_pixel') {
            return;
        }
        $state = get_post_meta($id, '_seed_pixel_job', true);
        echo esc_html(isset($state['status']) ? $state['status'] : __('Not processed', 'wp-seed-pixel'));
        $manifest = WP_Seed_Pixel_Store::manifest($id);
        if ($manifest && (!isset($manifest['strategy']) || $manifest['strategy'] === 'fixed')) {
            echo '<br>' . esc_html__('Fixed result - optional adaptive regeneration', 'wp-seed-pixel');
        }
    }

    public static function row_actions($actions, $post) {
        if ($post->post_mime_type === 'image/jpeg' && current_user_can('upload_files') && current_user_can('edit_post', $post->ID)) {
            $actions['pixel'] = '<button type="button" class="button-link pixel-regenerate" data-id="' . (int) $post->ID . '"><span class="dashicons dashicons-update" aria-hidden="true"></span> ' . esc_html__('Regenerate with WP Seed Pixel', 'wp-seed-pixel') . '</button><span class="pixel-media-status" role="status" aria-live="polite"></span>';
        }
        return $actions;
    }

    public static function save() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(esc_html__('POST required.', 'wp-seed-pixel'), '', array('response' => 405));
        }
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-seed-pixel'), '', array('response' => 403));
        }
        check_admin_referer('wp_seed_pixel_settings');
        $preset = isset($_POST['preset']) && is_string($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';
        if (is_wp_error(WP_Seed_Pixel_Presets::get($preset))) {
            wp_die(esc_html__('Unknown preset.', 'wp-seed-pixel'), '', array('response' => 400));
        }
        update_option('wp_seed_pixel_settings', array('automatic' => isset($_POST['automatic']), 'preset' => $preset, 'cleanup_on_uninstall' => isset($_POST['cleanup_on_uninstall'])), false);
        wp_safe_redirect(admin_url('upload.php?page=wp-seed-pixel&saved=1'));
        exit;
    }

    public static function manual() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_die(esc_html__('POST required.', 'wp-seed-pixel'), '', array('response' => 405));
        }
        $id = isset($_POST['attachment_id']) && is_scalar($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
        if (!$id || !current_user_can('upload_files') || !current_user_can('edit_post', $id)) {
            wp_die(esc_html__('Permission denied.', 'wp-seed-pixel'), '', array('response' => 403));
        }
        check_admin_referer('wp_seed_pixel_manual');
        $result = wp_seed_pixel_optimize($id, WP_Seed_Pixel_Plugin::settings()['preset'], isset($_POST['force']));
        $status = is_wp_error($result) ? 'failed' : $result['status'];
        wp_safe_redirect(add_query_arg('pixel_status', $status, admin_url('upload.php?page=wp-seed-pixel')));
        exit;
    }

    public static function ajax() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            wp_send_json_error(array('message' => __('POST required.', 'wp-seed-pixel')), 405);
        }
        check_ajax_referer('wp_seed_pixel', 'nonce');
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        if ($operation === 'optimize') {
            $id = isset($_POST['attachment_id']) && is_scalar($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
            if (!$id || !current_user_can('upload_files') || !current_user_can('edit_post', $id)) {
                wp_send_json_error(array('message' => __('Permission denied.', 'wp-seed-pixel')), 403);
            }
            $preset = isset($_POST['preset']) && is_string($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';
            $result = wp_seed_pixel_optimize($id, $preset, isset($_POST['force']) && $_POST['force'] === '1');
        } else {
            if (!current_user_can('manage_options')) {
                wp_send_json_error(array('message' => __('Permission denied.', 'wp-seed-pixel')), 403);
            }
            switch ($operation) {
                case 'start':
                    $preset = isset($_POST['preset']) && is_string($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';
                    $result = WP_Seed_Pixel_Batch::start($preset, isset($_POST['confirmed']) && $_POST['confirmed'] === '1');
                    break;
                case 'step': $result = WP_Seed_Pixel_Batch::step(); break;
                case 'status': $result = WP_Seed_Pixel_Batch::current(); break;
                case 'pause': $result = WP_Seed_Pixel_Batch::pause(true); break;
                case 'resume': $result = WP_Seed_Pixel_Batch::pause(false); break;
                case 'retry': $result = WP_Seed_Pixel_Batch::retry_one(); break;
                default: $result = new WP_Error('pixel_operation', 'Unknown operation.');
            }
        }
        if (is_wp_error($result)) {
            wp_send_json_error(array('code' => $result->get_error_code(), 'message' => $result->get_error_message()), 400);
        }
        // File system paths are reserved for trusted PHP API consumers, not AJAX output.
        if (isset($result['files'])) {
            foreach ($result['files'] as &$file) {
                unset($file['path']);
            }
            unset($file);
        }
        wp_send_json_success($result);
    }

    public static function page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $settings = WP_Seed_Pixel_Plugin::settings();
        ?>
        <div class="wrap wp-seed-pixel">
            <h1>WP Seed Pixel</h1>
            <?php if (isset($_GET['pixel_status']) && is_string($_GET['pixel_status']) && in_array($_GET['pixel_status'], array('success', 'failed', 'skipped'), true)) { ?>
                <div class="notice notice-info"><p><?php echo esc_html($_GET['pixel_status']); ?></p></div>
            <?php } ?>
            <p class="description"><?php esc_html_e('Masters are retained. Derivatives increase stored bytes; smaller transfers are a separate metric.', 'wp-seed-pixel'); ?></p>
            <p role="note"><?php esc_html_e('Other image optimizers may compete for attachment metadata. No optimizer is disabled automatically.', 'wp-seed-pixel'); ?></p>
            <h2><?php esc_html_e('Settings', 'wp-seed-pixel'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wp_seed_pixel_settings">
                <?php wp_nonce_field('wp_seed_pixel_settings'); ?>
                <p><label><input type="checkbox" name="automatic" <?php checked($settings['automatic']); ?>> <?php esc_html_e('Optimize new JPEG uploads automatically', 'wp-seed-pixel'); ?></label></p>
                <p><label for="pixel-preset"><?php esc_html_e('Optimization intent', 'wp-seed-pixel'); ?></label> <select name="preset" id="pixel-preset">
                    <?php foreach (WP_Seed_Pixel_Presets::all() as $name => $preset) { ?>
                        <option value="<?php echo esc_attr($name); ?>" <?php selected($settings['preset'], $name); ?>><?php echo esc_html($name === 'balanced' ? __('Balanced - adaptive', 'wp-seed-pixel') : $name . ' (fixed / compatibility)'); ?></option>
                    <?php } ?>
                </select></p>
                <p><label><input type="checkbox" name="cleanup_on_uninstall" <?php checked($settings['cleanup_on_uninstall']); ?>> <?php esc_html_e('Remove plugin settings and owned derivatives on uninstall (masters remain)', 'wp-seed-pixel'); ?></label></p>
                <?php submit_button(); ?>
            </form>
            <h2><?php esc_html_e('Single attachment', 'wp-seed-pixel'); ?></h2>
            <form id="pixel-single" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wp_seed_pixel_manual">
                <?php wp_nonce_field('wp_seed_pixel_manual'); ?>
                <label for="pixel-id"><?php esc_html_e('Attachment ID', 'wp-seed-pixel'); ?></label>
                <input id="pixel-id" name="attachment_id" type="number" min="1" required>
                <label><input type="checkbox" name="force" value="1"> <?php esc_html_e('Regenerate from master', 'wp-seed-pixel'); ?></label>
                <button class="button" type="submit"><?php esc_html_e('Optimize', 'wp-seed-pixel'); ?></button>
            </form>
            <h2><?php esc_html_e('Library batch', 'wp-seed-pixel'); ?></h2>
            <noscript><p><?php esc_html_e('Batch controls require JavaScript. Single-attachment processing and settings remain available.', 'wp-seed-pixel'); ?></p></noscript>
            <p><label><input type="checkbox" id="pixel-confirm"> <?php esc_html_e('I confirm processing the current image library', 'wp-seed-pixel'); ?></label></p>
            <div class="pixel-actions">
                <button class="button button-primary" id="pixel-start" type="button"><?php esc_html_e('Start batch', 'wp-seed-pixel'); ?></button>
                <button class="button" id="pixel-pause" type="button"><?php esc_html_e('Pause', 'wp-seed-pixel'); ?></button>
                <button class="button" id="pixel-resume" type="button"><?php esc_html_e('Resume', 'wp-seed-pixel'); ?></button>
                <button class="button" id="pixel-retry" type="button"><?php esc_html_e('Retry one failure', 'wp-seed-pixel'); ?></button>
            </div>
            <label for="pixel-progress"><?php esc_html_e('Progress', 'wp-seed-pixel'); ?></label>
            <progress id="pixel-progress" max="1" value="0"></progress>
            <pre id="pixel-result" role="status" aria-live="polite" aria-atomic="true"></pre>
            <h2><?php esc_html_e('Recent results', 'wp-seed-pixel'); ?></h2>
            <?php $recent = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 10, 'meta_key' => WP_Seed_Pixel_Store::KEY)); ?>
            <?php foreach ($recent as $item) { $manifest = WP_Seed_Pixel_Store::manifest($item->ID); if (!$manifest || !isset($manifest['files'])) { continue; } ?>
                <details><summary><?php echo esc_html($item->post_title); ?></summary>
                    <p><?php echo esc_html(sprintf(__('Source: %s bytes | Added disk: %s bytes | %s', 'wp-seed-pixel'), number_format_i18n($manifest['master_bytes']), number_format_i18n($manifest['added_disk_bytes']), isset($manifest['algorithm_version']) ? $manifest['algorithm_version'] : 'legacy-fixed')); ?></p>
                    <ul><?php foreach ($manifest['files'] as $name => $file) { ?><li><?php echo esc_html(sprintf('%s: %d x %d | %s bytes | %s | %s', $name, $file['width'], $file['height'], number_format_i18n($file['bytes']), isset($file['kind']) ? $file['kind'] : 'derived', isset($file['reason']) ? $file['reason'] : 'Legacy fixed preset')); ?><br><?php echo esc_html(sprintf('Quality: %s | Engine: %s | Transfer saving: %s%%', $file['quality'] === null ? 'source' : $file['quality'], $file['engine'], $file['transfer_saving_percent_vs_master'])); ?></li><?php } ?></ul>
                </details>
            <?php } ?>
        </div>
        <?php
    }
}
