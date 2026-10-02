<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Admin {
    public static function boot() {
        WP_Seed_Pixel_Media::boot();
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
                wp_localize_script('wp-seed-pixel-media', 'wpSeedPixelMedia', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wp_seed_pixel'), 'preset' => WP_Seed_Pixel_Plugin::settings()['preset'], 'processing' => __('Processing; keep this page open.', 'wp-seed-pixel'), 'regenerate' => __('Regenerate web versions', 'wp-seed-pixel'), 'failed' => __('Request failed. Your original is preserved; check details before retrying.', 'wp-seed-pixel')));
            }
            return;
        }
        wp_enqueue_style('wp-seed-pixel-admin', plugins_url('assets/admin.css', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_VERSION);
        wp_enqueue_script('wp-seed-pixel-admin', plugins_url('assets/admin.js', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_VERSION, true);
        wp_enqueue_script('wp-seed-pixel-media', plugins_url('assets/media.js', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_VERSION, true);
        wp_localize_script('wp-seed-pixel-media', 'wpSeedPixelMedia', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wp_seed_pixel'), 'preset' => WP_Seed_Pixel_Plugin::settings()['preset'], 'processing' => __('Processing', 'wp-seed-pixel'), 'regenerate' => __('Regenerate web versions', 'wp-seed-pixel'), 'failed' => __('Processing could not finish. Your original is preserved.', 'wp-seed-pixel')));
        wp_localize_script('wp-seed-pixel-admin', 'wpSeedPixel', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wp_seed_pixel'), 'error' => __('Request failed. Resume later; no automatic retry was sent.', 'wp-seed-pixel'), 'labels' => array('running' => __('Processing', 'wp-seed-pixel'), 'paused' => __('Paused; resume when ready', 'wp-seed-pixel'), 'complete' => __('Finished', 'wp-seed-pixel'), 'partial' => __('Finished with errors; review before retrying', 'wp-seed-pixel'), 'empty' => __('No processing started. Select images in the Media Library or confirm the whole library below.', 'wp-seed-pixel'), 'optimized' => __('optimized', 'wp-seed-pixel'), 'current' => __('already up to date', 'wp-seed-pixel'), 'skipped' => __('kept without changes', 'wp-seed-pixel'), 'failed' => __('need attention', 'wp-seed-pixel'), 'confirm' => __('Confirm the whole-library selection first.', 'wp-seed-pixel'))));
    }

    public static function columns($columns) {
        $columns['wp_seed_pixel'] = 'WP Seed Pixel';
        return $columns;
    }

    public static function column($column, $id) {
        if ($column !== 'wp_seed_pixel') {
            return;
        }
        echo '<span class="pixel-state">' . esc_html(WP_Seed_Pixel_Media::label(WP_Seed_Pixel_Media::state($id))) . '</span>';
    }

    public static function row_actions($actions, $post) {
        if ($post->post_mime_type === 'image/jpeg' && current_user_can('upload_files') && current_user_can('edit_post', $post->ID)) {
            $actions['pixel'] = str_replace('class="button pixel-regenerate"', 'class="button-link pixel-regenerate"', WP_Seed_Pixel_Media::button($post->ID));
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
        if ($operation === 'optimize') {
            $result['message'] = WP_Seed_Pixel_Media::label(WP_Seed_Pixel_Media::state($id));
            $result['panel'] = WP_Seed_Pixel_Media::details($id);
        } elseif (!empty($result['failed_ids'])) {
            $ids = array_slice($result['failed_ids'], 0, 10);
            get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => $ids, 'posts_per_page' => 10, 'update_post_meta_cache' => false, 'update_post_term_cache' => false));
            $result['failure_items'] = array();
            foreach ($ids as $failed_id) {
                if (current_user_can('edit_post', $failed_id)) {
                    $result['failure_items'][] = array('title' => html_entity_decode(get_the_title($failed_id), ENT_QUOTES, 'UTF-8'), 'url' => get_edit_post_link($failed_id, 'raw'));
                }
            }
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
                <div class="notice notice-info"><p><?php echo esc_html(WP_Seed_Pixel_Media::label($_GET['pixel_status'])); ?></p></div>
            <?php } ?>
            <p><?php esc_html_e('Local image optimization. Your original is kept; no image is sent to an external service.', 'wp-seed-pixel'); ?></p>
            <?php if (isset($_GET['saved'])) { ?><div class="notice notice-success"><p><?php esc_html_e('Settings saved. Existing images have not been reprocessed.', 'wp-seed-pixel'); ?></p></div><?php } ?>
            <?php if (self::other_optimizer()) { ?><div class="notice notice-warning"><p><?php esc_html_e('Another image optimizer is active. Both may process the same images. Review their automatic settings; nothing has been disabled.', 'wp-seed-pixel'); ?></p></div><?php } ?>
            <p><a class="button" href="<?php echo esc_url(admin_url('upload.php?mode=list')); ?>"><?php esc_html_e('Choose images in the Media Library', 'wp-seed-pixel'); ?></a></p>
            <h2><?php esc_html_e('Settings', 'wp-seed-pixel'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wp_seed_pixel_settings">
                <?php wp_nonce_field('wp_seed_pixel_settings'); ?>
                <p><label><input type="checkbox" name="automatic" <?php checked($settings['automatic']); ?>> <?php esc_html_e('Optimize new JPEG uploads automatically', 'wp-seed-pixel'); ?></label></p>
                <p><label for="pixel-preset"><?php esc_html_e('Image profile', 'wp-seed-pixel'); ?></label> <select name="preset" id="pixel-preset">
                    <?php foreach (WP_Seed_Pixel_Presets::all() as $name => $preset) { ?>
                        <option value="<?php echo esc_attr($name); ?>" <?php selected($settings['preset'], $name); ?>><?php echo esc_html(self::profile_label($name)); ?></option>
                    <?php } ?>
                </select></p>
                <p class="description"><?php esc_html_e('Balanced is recommended. It adapts web versions to the image. Compatibility profiles keep the earlier fixed method.', 'wp-seed-pixel'); ?></p>
                <details><summary><?php esc_html_e('Advanced settings', 'wp-seed-pixel'); ?></summary>
                    <p><label><input type="checkbox" name="cleanup_on_uninstall" <?php checked($settings['cleanup_on_uninstall']); ?>> <?php esc_html_e('Remove owned web versions and settings when uninstalling. Originals are always kept.', 'wp-seed-pixel'); ?></label></p>
                    <p><?php esc_html_e('Enable only after reviewing links and cached versions that may still use these files.', 'wp-seed-pixel'); ?></p>
                </details>
                <?php submit_button(); ?>
            </form>
            <details class="pixel-diagnostic"><summary><?php esc_html_e('Diagnostics and developer tools', 'wp-seed-pixel'); ?></summary>
            <p><?php echo esc_html('WordPress ' . get_bloginfo('version') . ' | PHP ' . PHP_VERSION . ' | WP Seed Pixel ' . WP_SEED_PIXEL_VERSION . ' | GD: ' . (extension_loaded('gd') ? 'available' : 'unavailable') . ' | Imagick: ' . (extension_loaded('imagick') ? 'available (not certified)' : 'unavailable') . ' | bounded-rgb-3'); ?></p>
            <p><?php esc_html_e('For normal use, choose an image in the Media Library. This fallback is for diagnosis only.', 'wp-seed-pixel'); ?></p>
            <form id="pixel-single" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wp_seed_pixel_manual">
                <?php wp_nonce_field('wp_seed_pixel_manual'); ?>
                <label for="pixel-id"><?php esc_html_e('Attachment ID', 'wp-seed-pixel'); ?></label>
                <input id="pixel-id" name="attachment_id" type="number" min="1" required>
                <label><input type="checkbox" name="force" value="1"> <?php esc_html_e('Regenerate from master', 'wp-seed-pixel'); ?></label>
                <button class="button" type="submit"><?php esc_html_e('Optimize', 'wp-seed-pixel'); ?></button>
            </form>
            </details>
            <h2 id="pixel-bulk"><?php esc_html_e('Optimize existing images', 'wp-seed-pixel'); ?></h2>
            <p><?php esc_html_e('Select images with the Media Library bulk action, then resume here. One image is processed at a time. You can pause or return later to resume; processing stops when this page is closed.', 'wp-seed-pixel'); ?></p>
            <noscript><p><?php esc_html_e('Batch controls require JavaScript. Single-attachment processing and settings remain available.', 'wp-seed-pixel'); ?></p></noscript>
            <p><label><input type="checkbox" id="pixel-confirm"> <?php esc_html_e('Process the whole image library, including images already up to date', 'wp-seed-pixel'); ?></label></p>
            <div class="pixel-actions">
                <button class="button button-primary" id="pixel-start" type="button"><?php esc_html_e('Start whole library', 'wp-seed-pixel'); ?></button>
                <button class="button" id="pixel-pause" type="button"><?php esc_html_e('Pause', 'wp-seed-pixel'); ?></button>
                <button class="button" id="pixel-resume" type="button"><?php esc_html_e('Resume', 'wp-seed-pixel'); ?></button>
                <button class="button" id="pixel-retry" type="button"><?php esc_html_e('Retry one failure', 'wp-seed-pixel'); ?></button>
            </div>
            <label for="pixel-progress"><?php esc_html_e('Progress', 'wp-seed-pixel'); ?></label>
            <progress id="pixel-progress" max="1" value="0"></progress>
            <p id="pixel-result" role="status" aria-live="polite" aria-atomic="true"></p>
            <details id="pixel-failures" hidden><summary><?php esc_html_e('Images needing attention', 'wp-seed-pixel'); ?></summary>
                <p><?php esc_html_e('Open an image to review its details. Up to ten failed items are shown; others remain in the Media Library. Retrying is explicit, with at most two retries per image.', 'wp-seed-pixel'); ?></p>
                <ul id="pixel-failure-items"></ul>
            </details>
            <h2><?php esc_html_e('Recent results', 'wp-seed-pixel'); ?></h2>
            <?php $recent = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 10, 'meta_key' => WP_Seed_Pixel_Store::KEY)); ?>
            <?php foreach ($recent as $item) { $manifest = WP_Seed_Pixel_Store::manifest($item->ID); if (!$manifest || !isset($manifest['files'])) { continue; } ?>
                <details><summary><?php echo esc_html($item->post_title); ?></summary>
                    <?php echo WP_Seed_Pixel_Media::details($item->ID); ?>
                </details>
            <?php } ?>
        </div>
        <?php
    }

    public static function profile_label($name) {
        $labels = array('balanced' => __('Balanced (recommended)', 'wp-seed-pixel'), 'web' => __('Single web view (compatibility)', 'wp-seed-pixel'), 'participant_album' => __('Photo album (compatibility)', 'wp-seed-pixel'));
        return isset($labels[$name]) ? $labels[$name] : $name;
    }

    public static function other_optimizer() {
        $plugins = array_merge((array) get_option('active_plugins', array()), array_keys((array) get_site_option('active_sitewide_plugins', array())));
        foreach ($plugins as $plugin) {
            if (preg_match('/shortpixel|imagify|wp-smush|ewww-image-optimizer/i', $plugin)) {
                return true;
            }
        }
        return false;
    }
}
