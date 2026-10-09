<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Admin {
    public static function boot() {
        WP_Seed_Pixel_Media::boot();
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_menu', array(__CLASS__, 'consolidate_menu'), 99);
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

    public static function consolidate_menu() {
        foreach (array('wp-seed-pixel-storage', 'wp-seed-pixel-host', 'wp-seed-pixel-jobs', 'wp-seed-pixel-bulk', 'wp-seed-pixel-quarantine') as $slug) {
            remove_submenu_page('upload.php', $slug);
        }
    }

    public static function save_settings(array $input) {
        if (!current_user_can('manage_options')) { return new WP_Error('PERMISSION_DENIED'); }
        $preset = isset($input['preset']) && is_string($input['preset']) ? sanitize_key($input['preset']) : '';
        if (is_wp_error(WP_Seed_Pixel_Presets::get($preset))) { return new WP_Error('POLICY_INVALID'); }
        if (!empty($input['metadata_privacy_settings'])) {
            $r=WP_Seed_Pixel_Metadata_Uploads::configure(!empty($input['metadata_automatic']));
            if (is_wp_error($r)) { return $r; }
        }
        $future = WP_Seed_Pixel_Future_Uploads::settings();
        $png = !empty($input['png']);
        $jpeg = !empty($input['automatic']);
        $old_png = $future['mode'] === 'process' && in_array('png', $future['formats'], true);
        $future_jpeg = $future['mode'] === 'process' && in_array('jpeg', $future['formats'], true);
        if ($png !== $old_png || $jpeg !== $future_jpeg) {
            if ($png || $jpeg) { $r = WP_Seed_Pixel_Workflow::prepare(); if (is_wp_error($r)) { return $r; } }
            $formats = array();
            if ($jpeg) { $formats[] = 'jpeg'; }
            if ($png) { $formats[] = 'png'; }
            $r = WP_Seed_Pixel_Future_Uploads::configure($formats ? 'process' : 'off', $future['capacity_bytes'], null, $formats ?: array('jpeg'));
            if (is_wp_error($r)) { return $r; }
        }
        update_option('wp_seed_pixel_settings', array('automatic' => $jpeg, 'preset' => $preset,
            'cleanup_on_uninstall' => !empty($input['cleanup_on_uninstall'])), false);
        return true;
    }

    public static function assets($hook) {
        if ($hook !== 'media_page_wp-seed-pixel') {
            $screen = get_current_screen();
            if ($hook === 'upload.php' || $screen && $screen->post_type === 'attachment') {
                wp_enqueue_style('wp-seed-pixel-admin', plugins_url('assets/admin.css', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_BUILD);
                wp_enqueue_script('wp-seed-pixel-media', plugins_url('assets/media.js', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_BUILD, true);
                wp_localize_script('wp-seed-pixel-media', 'wpSeedPixelMedia', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wp_seed_pixel'), 'preset' => WP_Seed_Pixel_Plugin::settings()['preset'], 'processing' => __('Processing; keep this page open.', 'wp-seed-pixel'), 'regenerate' => __('Regenerate web versions', 'wp-seed-pixel'), 'failed' => __('Request failed. Your original is preserved; check details before retrying.', 'wp-seed-pixel')));
            }
            return;
        }
        wp_enqueue_style('wp-seed-pixel-admin', plugins_url('assets/admin.css', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_BUILD);
        wp_enqueue_media();
        wp_enqueue_script('wp-seed-pixel-admin', plugins_url('assets/admin.js', WP_SEED_PIXEL_FILE), array('media-views'), WP_SEED_PIXEL_BUILD, true);
        wp_enqueue_script('wp-seed-pixel-media', plugins_url('assets/media.js', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_BUILD, true);
        wp_localize_script('wp-seed-pixel-media', 'wpSeedPixelMedia', array('url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wp_seed_pixel'), 'preset' => WP_Seed_Pixel_Plugin::settings()['preset'], 'processing' => __('Processing', 'wp-seed-pixel'), 'regenerate' => __('Regenerate web versions', 'wp-seed-pixel'), 'failed' => __('Processing could not finish. Your original is preserved.', 'wp-seed-pixel')));
        wp_localize_script('wp-seed-pixel-admin', 'wpSeedPixel', array('counters' => WP_Seed_Pixel_I18n::counters(), 'url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('wp_seed_pixel'), 'error' => __('Request failed. Resume later; no automatic retry was sent.', 'wp-seed-pixel'), 'labels' => array('running' => __('Processing', 'wp-seed-pixel'), 'paused' => __('Paused; resume when ready', 'wp-seed-pixel'), 'complete' => __('Finished', 'wp-seed-pixel'), 'partial' => __('Finished with errors; review before retrying', 'wp-seed-pixel'), 'empty' => __('No processing started. Select images in the Media Library or confirm the whole library below.', 'wp-seed-pixel'), 'optimized' => __('optimized', 'wp-seed-pixel'), 'current' => __('already up to date', 'wp-seed-pixel'), 'skipped' => __('kept without changes', 'wp-seed-pixel'), 'failed' => __('need attention', 'wp-seed-pixel'), 'confirm' => __('Confirm the whole-library selection first.', 'wp-seed-pixel'))));
        wp_localize_script('wp-seed-pixel-admin', 'wpSeedPixelSelection', array('choose' => __('Select images', 'wp-seed-pixel'),
            'count' => __('%d images selected', 'wp-seed-pixel'), 'start' => __('Optimize the %d images', 'wp-seed-pixel'),
            'review' => __('JPEG web versions; other formats are kept without changes.', 'wp-seed-pixel')));
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
        if (in_array($post->post_mime_type, array('image/jpeg', 'image/png'), true) && current_user_can('manage_options') && current_user_can('edit_post', $post->ID)) {
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
        $result = self::save_settings(wp_unslash($_POST));
        if (is_wp_error($result)) { wp_die(esc_html(WP_Seed_Pixel_Workflow::message($result->get_error_code())), '', array('response' => 409)); }
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
                case 'image_start':
                    $id = is_scalar($_POST['attachment_id'] ?? null) ? absint($_POST['attachment_id']) : 0;
                    $result = WP_Seed_Pixel_Selected_Admin::start(array($id));
                    break;
                case 'image_restore': case 'image_purge':
                    $id = is_scalar($_POST['attachment_id'] ?? null) ? absint($_POST['attachment_id']) : 0;
                    $generation = is_string($_POST['generation'] ?? null) ? wp_unslash($_POST['generation']) : '';
                    $result = WP_Seed_Pixel_Workflow::original($id, substr($operation, 6), $generation, ($_POST['confirmed'] ?? '') === '1');
                    if (!is_wp_error($result)) { $result = array('panel' => WP_Seed_Pixel_Media::details($id)); }
                    break;
                case 'image_panel':
                    $id = is_scalar($_POST['attachment_id'] ?? null) ? absint($_POST['attachment_id']) : 0;
                    $result = $id && current_user_can('edit_post', $id) ? array('panel' => WP_Seed_Pixel_Media::details($id)) : new WP_Error('PERMISSION_DENIED');
                    break;
                case 'selected':
                    $selection = isset($_POST['selection']) && is_string($_POST['selection']) ? wp_unslash($_POST['selection']) : '';
                    $ids = preg_match('/^[1-9][0-9]*(?:,[1-9][0-9]*){0,499}$/D', $selection) ? explode(',', $selection) : array();
                    $preset = isset($_POST['preset']) && is_string($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';
                    $result = WP_Seed_Pixel_Selected_Admin::start($ids);
                    break;
                case 'native_step': case 'native_start': case 'native_pause': case 'native_resume': case 'native_cancel':
                    $result = WP_Seed_Pixel_Selected_Admin::command($operation, is_scalar($_POST['job_id'] ?? null) ? absint($_POST['job_id']) : 0);
                    break;
                case 'start':
                    $preset = isset($_POST['preset']) && is_string($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';
                    $native = WP_Seed_Pixel_Selected_Admin::available() ? WP_Seed_Pixel_Selected_Admin::current() : array();
                    if ($native && $native['status'] !== 'complete') { $result = new WP_Error('LOCKED'); break; }
                    $result = WP_Seed_Pixel_Batch::start($preset, isset($_POST['confirmed']) && $_POST['confirmed'] === '1');
                    break;
                case 'step': $result = WP_Seed_Pixel_Batch::step(); break;
                case 'status':
                    $result = WP_Seed_Pixel_Batch::current();
                    if ((!$result || $result['status'] === 'complete') && WP_Seed_Pixel_Selected_Admin::available()) {
                        $native = WP_Seed_Pixel_Selected_Admin::current(); if ($native) { $result = $native; }
                    }
                    break;
                case 'pause': $result = WP_Seed_Pixel_Batch::pause(true); break;
                case 'resume': $result = WP_Seed_Pixel_Batch::pause(false); break;
                case 'retry': $result = WP_Seed_Pixel_Batch::retry_one(); break;
                default: $result = new WP_Error('pixel_operation', __('Unknown operation.', 'wp-seed-pixel'));
            }
        }
        if (is_wp_error($result)) {
            wp_send_json_error(array('code' => $result->get_error_code(), 'message' => WP_Seed_Pixel_Workflow::message($result->get_error_code())), 400);
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
        $future = WP_Seed_Pixel_Future_Uploads::settings();
        $png = $future['mode'] === 'process' && in_array('png', $future['formats'], true);
        ?>
        <div class="wrap wp-seed-pixel">
            <h1>WP Seed Pixel</h1>
            <?php if (isset($_GET['pixel_status']) && is_string($_GET['pixel_status']) && in_array($_GET['pixel_status'], array('success', 'failed', 'skipped'), true)) { ?>
                <div class="notice notice-info"><p><?php echo esc_html(WP_Seed_Pixel_Media::label($_GET['pixel_status'])); ?></p></div>
            <?php } ?>
            <p><?php esc_html_e('Local image optimization. Your original is kept; no image is sent to an external service.', 'wp-seed-pixel'); ?></p>
            <?php if (isset($_GET['saved'])) { ?><div class="notice notice-success"><p><?php esc_html_e('Settings saved. Existing images have not been reprocessed.', 'wp-seed-pixel'); ?></p></div><?php } ?>
            <?php if (self::other_optimizer()) { ?><div class="notice notice-warning"><p><?php esc_html_e('Another image optimizer is active. Both may process the same images. Review their automatic settings; nothing has been disabled.', 'wp-seed-pixel'); ?></p></div><?php } ?>
            <h2><?php esc_html_e('Automatic optimization', 'wp-seed-pixel'); ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="wp_seed_pixel_settings">
                <?php wp_nonce_field('wp_seed_pixel_settings'); ?>
                <?php if (!is_multisite()) { ?>
                <input type="hidden" name="metadata_privacy_settings" value="1">
                <h3><?php esc_html_e('Metadata','wp-seed-pixel'); ?></h3>
                <p><label><input type="checkbox" name="metadata_automatic" <?php checked(WP_Seed_Pixel_Metadata_Uploads::settings()['enabled']); ?>> <?php esc_html_e('Automatically anonymize new images','wp-seed-pixel'); ?></label></p>
                <p class="description"><?php esc_html_e('Removes personal metadata from newly uploaded images when Pixel can preserve their appearance. Existing images are not processed.','wp-seed-pixel'); ?></p>
                <p class="description"><?php esc_html_e('Originals are retained for restoration. This uses private storage; no automatic purge is performed.','wp-seed-pixel'); ?></p>
                <p class="description"><?php esc_html_e('Privacy runs first. Other processing may require restoring the retained original before starting.','wp-seed-pixel'); ?></p>
                <?php } ?>
                <p><label><input type="checkbox" name="automatic" <?php checked($settings['automatic'] || ($future['mode'] === 'process' && in_array('jpeg', $future['formats'], true))); ?>> <?php esc_html_e('Optimize new JPEG uploads automatically', 'wp-seed-pixel'); ?></label></p>
                <p id="pixel-png"><label><input type="checkbox" name="png" <?php checked($png); ?>> <?php esc_html_e('Optimize new PNG uploads without loss', 'wp-seed-pixel'); ?></label></p>
                <p class="description"><?php esc_html_e('Lossless optimization; transparency is preserved and PNG remains PNG. Existing media are not processed automatically.', 'wp-seed-pixel'); ?></p>
                <details><summary><?php esc_html_e('Advanced settings', 'wp-seed-pixel'); ?></summary>
                <p><label for="pixel-preset"><?php esc_html_e('Image profile', 'wp-seed-pixel'); ?></label> <select name="preset" id="pixel-preset">
                    <?php foreach (WP_Seed_Pixel_Presets::all() as $name => $preset) { ?>
                        <option value="<?php echo esc_attr($name); ?>" <?php selected($settings['preset'], $name); ?>><?php echo esc_html(self::profile_label($name)); ?></option>
                    <?php } ?>
                </select></p>
                <p class="description"><?php esc_html_e('Balanced is recommended. It adapts web versions to the image. Compatibility profiles keep the earlier fixed method.', 'wp-seed-pixel'); ?></p>
                    <p><label><input type="checkbox" name="cleanup_on_uninstall" <?php checked($settings['cleanup_on_uninstall']); ?>> <?php esc_html_e('Remove owned web versions and settings when uninstalling. Originals are always kept.', 'wp-seed-pixel'); ?></label></p>
                    <p><?php esc_html_e('Enable only after reviewing links and cached versions that may still use these files.', 'wp-seed-pixel'); ?></p>
                </details>
                <?php submit_button(); ?>
            </form>
            <details class="pixel-diagnostic"><summary><?php esc_html_e('Diagnostics and advanced tools', 'wp-seed-pixel'); ?></summary>
            <p><a href="<?php echo esc_url(admin_url('upload.php?page=wp-seed-pixel-storage')); ?>"><?php esc_html_e('Analyze storage usage', 'wp-seed-pixel'); ?></a> | <a href="<?php echo esc_url(admin_url('upload.php?page=wp-seed-pixel-bulk')); ?>"><?php esc_html_e('Advanced batch tools', 'wp-seed-pixel'); ?></a> | <a href="<?php echo esc_url(admin_url('upload.php?page=wp-seed-pixel-quarantine')); ?>"><?php esc_html_e('Retained originals', 'wp-seed-pixel'); ?></a></p>
            <p><a href="<?php echo esc_url(admin_url('upload.php?page=wp-seed-pixel-host')); ?>"><?php esc_html_e('Storage configuration', 'wp-seed-pixel'); ?></a> | <a href="<?php echo esc_url(admin_url('upload.php?page=wp-seed-pixel-jobs')); ?>"><?php esc_html_e('Storage simulation', 'wp-seed-pixel'); ?></a></p>
            <p><?php echo esc_html(sprintf(__('Private updates: %s', 'wp-seed-pixel'), WP_Seed_Pixel_Updater::endpoint() ? __('Configured', 'wp-seed-pixel') : __('Endpoint not configured', 'wp-seed-pixel'))); ?></p>
            <p><?php echo esc_html('WordPress ' . get_bloginfo('version') . ' | PHP ' . PHP_VERSION . ' | WP Seed Pixel ' . WP_SEED_PIXEL_VERSION . ' | GD: ' . (extension_loaded('gd') ? __('available', 'wp-seed-pixel') : __('unavailable', 'wp-seed-pixel')) . ' | Imagick: ' . (extension_loaded('imagick') ? __('available (not certified)', 'wp-seed-pixel') : __('unavailable', 'wp-seed-pixel')) . ' | bounded-rgb-3'); ?></p>
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
            <p><?php esc_html_e('Select images. Only this selection is processed, one image at a time. Closing this page stops the batch; you can resume later.', 'wp-seed-pixel'); ?></p>
            <p><?php esc_html_e('JPEG stays JPEG. PNG stays PNG, without loss. Other formats are kept unchanged.', 'wp-seed-pixel'); ?></p>
            <p><button class="button button-primary" id="pixel-select" type="button"><?php esc_html_e('Select images', 'wp-seed-pixel'); ?></button></p>
            <p id="pixel-selection-count" role="status" aria-live="polite"></p>
            <ul id="pixel-selection-review"></ul>
            <p><button class="button button-primary" id="pixel-selected-start" type="button" disabled><?php esc_html_e('Optimize selected images', 'wp-seed-pixel'); ?></button></p>
            <noscript><p><?php esc_html_e('Batch controls require JavaScript. Single-attachment processing and settings remain available.', 'wp-seed-pixel'); ?></p></noscript>
            <details><summary><?php esc_html_e('Advanced: whole image library', 'wp-seed-pixel'); ?></summary>
            <p><?php esc_html_e('This can process many images. Images already up to date are reused without re-encoding.', 'wp-seed-pixel'); ?></p>
            <p><label><input type="checkbox" id="pixel-confirm"> <?php esc_html_e('I explicitly authorize processing the whole image library.', 'wp-seed-pixel'); ?></label></p>
            <p><button class="button" id="pixel-start" type="button"><?php esc_html_e('Start whole library', 'wp-seed-pixel'); ?></button></p>
            </details>
            <h2><?php esc_html_e('Recent activity', 'wp-seed-pixel'); ?></h2>
            <div class="pixel-actions">
                <button class="button" id="pixel-pause" type="button" hidden><?php esc_html_e('Pause', 'wp-seed-pixel'); ?></button>
                <button class="button" id="pixel-resume" type="button" hidden><?php esc_html_e('Resume', 'wp-seed-pixel'); ?></button>
                <button class="button" id="pixel-retry" type="button" hidden><?php esc_html_e('Retry one failure', 'wp-seed-pixel'); ?></button>
                <button class="button" id="pixel-cancel" type="button" hidden><?php esc_html_e('Cancel', 'wp-seed-pixel'); ?></button>
            </div>
            <label for="pixel-progress"><?php esc_html_e('Progress', 'wp-seed-pixel'); ?></label>
            <progress id="pixel-progress" max="1" value="0"></progress>
            <p id="pixel-result" role="status" aria-live="polite" aria-atomic="true"></p>
            <details id="pixel-failures" hidden><summary><?php esc_html_e('Images needing attention', 'wp-seed-pixel'); ?></summary>
                <p><?php esc_html_e('Open an image to review its details. Up to ten failed items are shown; others remain in the Media Library. Retrying is explicit, with at most two retries per image.', 'wp-seed-pixel'); ?></p>
                <ul id="pixel-failure-items"></ul>
            </details>
            <?php $recent = get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'posts_per_page' => 10,
                'meta_query' => array('relation' => 'OR', array('key' => WP_Seed_Pixel_Store::KEY, 'compare' => 'EXISTS'), array('key' => '_seed_pixel_master_state', 'compare' => 'EXISTS'), array('key' => WP_Seed_Pixel_Future_Uploads::META, 'compare' => 'EXISTS')))); ?>
            <?php foreach ($recent as $item) { ?>
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
