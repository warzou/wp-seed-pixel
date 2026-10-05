<?php
defined('ABSPATH') || exit;

/** Admin presentation only; optimization remains in the shared engine. */
final class WP_Seed_Pixel_Media {
    public static function boot() {
        add_filter('attachment_fields_to_edit', array(__CLASS__, 'fields'), 10, 2);
        add_filter('bulk_actions-upload', array(__CLASS__, 'bulk_actions'));
        add_filter('handle_bulk_actions-upload', array(__CLASS__, 'bulk'), 10, 3);
    }

    public static function state($id) {
        if (get_post_meta($id, '_seed_pixel_master_state', true)) { return 'master_optimized'; }
        $item = self::operation($id);
        if ($item) {
            if (($item['error_code'] ?? '') === 'NO_BENEFIT') { return 'no_benefit'; }
            if (($item['error_code'] ?? '') === 'EXCLUDED_BY_POLICY') { return 'excluded'; }
            if (($item['error_code'] ?? '') === 'ICC_UNSAFE') { return 'unsupported_profile'; }
            if (in_array($item['stage'], array('failed', 'needs_review', 'recovery_required'), true)) { return 'failed'; }
            if ($item['stage'] === 'rolled_back') { return 'restored'; }
            if (!in_array($item['stage'], WP_Seed_Pixel_Job_Store::TERMINAL, true)) { return 'processing'; }
        }
        $future = get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true);
        if (is_array($future)) {
            if (!empty($future['job_id'])) {
                $operation = WP_Seed_Pixel_Job_Store::job((int) $future['job_id']);
                if ($operation && $operation['status'] === 'completed_errors') { return 'failed'; }
            }
            if (($future['reason'] ?? '') === 'NO_BENEFIT') { return 'no_benefit'; }
            if (($future['reason'] ?? '') === 'EXCLUDED_BY_POLICY') { return 'excluded'; }
            if (($future['state'] ?? '') === 'review') { return 'failed'; }
            if (($future['state'] ?? '') === 'awaiting_metadata') { return 'pending'; }
        }
        $job = get_post_meta($id, '_seed_pixel_job', true);
        $manifest = WP_Seed_Pixel_Store::manifest($id);
        if (isset($job['status']) && in_array($job['status'], array('failed', 'skipped', 'pending', 'processing'), true)) {
            return $job['status'];
        }
        if (!$manifest) {
            $mime = get_post_mime_type($id);
            if ($mime === 'image/png') { return 'png_lossless'; }
            $settings = WP_Seed_Pixel_Future_Uploads::settings();
            if ($mime === 'image/jpeg' && $settings['mode'] !== 'off' && $id <= $settings['cutoff_id']) { return 'protected'; }
            return $mime === 'image/jpeg' ? 'new' : 'unsupported';
        }
        if (empty($manifest['algorithm_version']) || !isset($manifest['preset']) || $manifest['preset'] !== WP_Seed_Pixel_Plugin::settings()['preset']) {
            return 'update';
        }
        return 'success';
    }

    public static function label($state) {
        $labels = array(
            'unsupported_profile' => __('Unsupported image profile or orientation.', 'wp-seed-pixel'),
            'restored' => __('Restored exactly', 'wp-seed-pixel'),
            'master_optimized' => __('Optimized native image', 'wp-seed-pixel'),
            'png_lossless' => __('PNG: explicit lossless processing available', 'wp-seed-pixel'),
            'protected' => __('Preexisting media: protected from automatic processing', 'wp-seed-pixel'),
            'excluded' => __('Excluded by the selected policy.', 'wp-seed-pixel'),
            'no_benefit' => __('No useful saving; source preserved.', 'wp-seed-pixel'),
            'new' => __('Not optimized', 'wp-seed-pixel'),
            'success' => __('Optimized', 'wp-seed-pixel'),
            'update' => __('New optimization available', 'wp-seed-pixel'),
            'skipped' => __('Kept without changes', 'wp-seed-pixel'),
            'unsupported' => __('Unsupported format', 'wp-seed-pixel'),
            'failed' => __('Needs attention', 'wp-seed-pixel'),
            'pending' => __('Waiting for automatic processing', 'wp-seed-pixel'),
            'processing' => __('Processing', 'wp-seed-pixel'),
        );
        return isset($labels[$state]) ? $labels[$state] : __('Not optimized', 'wp-seed-pixel');
    }

    public static function button($id) {
        if (get_post_mime_type($id) !== 'image/jpeg' || !current_user_can('upload_files') || !current_user_can('edit_post', $id)) {
            return '';
        }
        $regenerate = (bool) WP_Seed_Pixel_Store::manifest($id);
        return '<button type="button" class="button pixel-regenerate" data-id="' . (int) $id . '" data-force="' . ($regenerate ? '1' : '0') . '">' . esc_html($regenerate ? __('Regenerate web versions', 'wp-seed-pixel') : __('Optimize with WP Seed Pixel', 'wp-seed-pixel')) . '</button><span class="pixel-media-status" role="status" aria-live="polite"></span>';
    }

    private static function operation($id) {
        if (!current_user_can('edit_post', $id) || (int) get_option('wp_seed_pixel_job_schema') !== WP_Seed_Pixel_Job_Store::SCHEMA) { return null; }
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE attachment_id=%d AND kind='operation' AND action='replace' ORDER BY id DESC LIMIT 1", $id), ARRAY_A);
    }

    public static function details($id) {
        $manifest = WP_Seed_Pixel_Store::manifest($id);
        $job = get_post_meta($id, '_seed_pixel_job', true);
        $state = self::state($id);
        ob_start();
        ?>
        <div class="pixel-media-panel">
            <p><strong class="pixel-state"><?php echo esc_html(self::label($state)); ?></strong></p>
            <?php if ($state === 'update') { ?><p><?php esc_html_e('A new method or profile is available. Regeneration is optional.', 'wp-seed-pixel'); ?></p><?php } ?>
            <p><?php esc_html_e('Processing is local; no image is sent to an external service.', 'wp-seed-pixel'); ?></p>
            <?php
            $witness = get_post_meta($id, '_seed_pixel_master_state', true);
            if (!$witness) { ?><p><?php esc_html_e('Your original is kept.', 'wp-seed-pixel'); ?></p><?php }
            elseif (current_user_can('manage_options')) {
                $item = (int) get_option('wp_seed_pixel_job_schema') === WP_Seed_Pixel_Job_Store::SCHEMA ? WP_Seed_Pixel_Job_Store::item((int) ($witness['item_id'] ?? 0)) : null;
                if ($item && ((int) $item['attachment_id'] !== (int) $id || (int) $item['job_id'] !== (int) ($witness['job_id'] ?? 0))) { $item = null; }
                $view = $item ? WP_Seed_Pixel_Quarantine::inspect($item) : new WP_Error('NEEDS_REVIEW');
                ?><p><?php echo esc_html(!is_wp_error($view) && $view['rollback_available'] ? __('Restoration available', 'wp-seed-pixel') : __('Restoration unavailable', 'wp-seed-pixel')); ?></p><?php
                if (!is_wp_error($view)) { ?><p><?php echo esc_html(sprintf(__('Retained recovery: %s', 'wp-seed-pixel'), size_format($view['quarantine_bytes']))); ?></p><?php }
                if (is_wp_error($view) || ($view['active_delta'] ?? null) === null) { ?><p><?php esc_html_e('The media changed; review required.', 'wp-seed-pixel'); ?></p><?php }
            }
            if (is_array($future = get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true)) && !empty($future['reason'])) { ?><p><?php echo esc_html(WP_Seed_Pixel_Host_Admin::reason($future['reason'])); ?></p><?php }
            $item = self::operation($id);
            if ($item && !empty($item['error_code'])) { ?><p><?php echo esc_html(WP_Seed_Pixel_Host_Admin::reason($item['error_code'])); ?></p><?php }
            if (get_post_mime_type($id) === 'image/png' && current_user_can('manage_options')) { ?><p><a href="<?php echo esc_url(admin_url('upload.php?page=wp-seed-pixel-bulk')); ?>"><?php esc_html_e('Selected media', 'wp-seed-pixel'); ?></a></p><?php }
            ?>
            <?php if ($manifest && !empty($manifest['files'])) { ?>
                <ul class="pixel-benefits">
                <?php foreach ($manifest['files'] as $name => $file) { ?>
                    <li><?php echo esc_html($name === 'thumb' ? __('Thumbnail', 'wp-seed-pixel') : __('Large web view', 'wp-seed-pixel')); ?>:
                        <?php echo esc_html(size_format($file['bytes'])); ?>
                        <?php if (isset($file['kind']) && $file['kind'] === 'master') { ?>
                            &mdash; <?php esc_html_e('Original already suitable; reused without a new copy.', 'wp-seed-pixel'); ?>
                        <?php } else { ?>
                            &mdash; <?php echo esc_html(sprintf(__('%s%% less transfer than the original', 'wp-seed-pixel'), number_format_i18n($file['transfer_saving_percent_vs_master'], 1))); ?>
                        <?php } ?>
                    </li>
                <?php } ?>
                </ul>
                <p><?php echo esc_html(sprintf(__('Additional disk usage: %s. This is not a disk saving.', 'wp-seed-pixel'), size_format($manifest['added_disk_bytes']))); ?></p>
            <?php } ?>
            <?php if ($state === 'failed') { ?><p><?php esc_html_e('Processing could not finish. Check the technical details before retrying.', 'wp-seed-pixel'); ?></p><?php } ?>
            <?php if ($state === 'skipped' || $state === 'unsupported') { ?><p><?php esc_html_e('This image was preserved because its format or image profile is not supported, or processing was excluded.', 'wp-seed-pixel'); ?></p><?php } ?>
            <p><?php echo self::button($id); ?></p>
            <?php if ($manifest) { ?><p><?php esc_html_e('Regeneration recreates web versions from the preserved original. Previous versions remain available.', 'wp-seed-pixel'); ?></p><?php } ?>
            <details><summary><?php esc_html_e('Technical details', 'wp-seed-pixel'); ?></summary>
                <?php if ($manifest) { ?>
                    <p><?php echo esc_html(sprintf(__('Algorithm: %s | Profile: %s | Original: %s', 'wp-seed-pixel'), isset($manifest['algorithm_version']) ? $manifest['algorithm_version'] : 'fixed', isset($manifest['preset']) ? $manifest['preset'] : '', size_format($manifest['master_bytes']))); ?></p>
                    <?php foreach ($manifest['files'] as $name => $file) { ?>
                        <p><?php echo esc_html(sprintf(__('%1$s: %2$s x %3$s | Quality: %4$s | Engine: %5$s', 'wp-seed-pixel'), $name, $file['width'], $file['height'], $file['quality'] === null ? __('source', 'wp-seed-pixel') : $file['quality'], $file['engine'])); ?></p>
                        <p><?php echo esc_html(WP_Seed_Pixel_I18n::message(isset($file['reason']) ? $file['reason'] : 'Fixed profile')); ?></p>
                        <?php if (!empty($file['metric'])) { ?><p><?php echo esc_html('SSIM: ' . $file['metric']['ssim'] . ' | PSNR: ' . $file['metric']['psnr']); ?></p><?php } ?>
                    <?php } ?>
                <?php } ?>
                <?php if (!empty($job['code'])) { ?><p><?php echo esc_html($job['code']); ?></p><?php } ?>
                <?php if (!empty($job['reason'])) { ?><p><?php echo esc_html(WP_Seed_Pixel_I18n::message($job['reason'])); ?></p><?php } ?>
            </details>
            <noscript><p><?php esc_html_e('Image actions require JavaScript. Settings remain available without it.', 'wp-seed-pixel'); ?></p></noscript>
        </div>
        <?php
        return ob_get_clean();
    }

    public static function fields($fields, $post) {
        if (current_user_can('upload_files') && current_user_can('edit_post', $post->ID)) {
            $fields['wp_seed_pixel'] = array('label' => 'WP Seed Pixel', 'input' => 'html', 'html' => self::details($post->ID));
        }
        return $fields;
    }

    public static function bulk_actions($actions) {
        if (current_user_can('manage_options')) {
            $actions['wp_seed_pixel'] = __('Optimize with WP Seed Pixel', 'wp-seed-pixel');
        }
        return $actions;
    }

    public static function bulk($redirect, $action, $ids) {
        if ($action !== 'wp_seed_pixel') {
            return $redirect;
        }
        check_admin_referer('bulk-media');
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Permission denied.', 'wp-seed-pixel'), '', array('response' => 403));
        }
        $result = WP_Seed_Pixel_Batch::start(WP_Seed_Pixel_Plugin::settings()['preset'], true, $ids);
        if (is_wp_error($result)) {
            wp_die(esc_html(WP_Seed_Pixel_I18n::message($result->get_error_message())), '', array('response' => 400));
        }
        WP_Seed_Pixel_Batch::pause(true);
        return admin_url('upload.php?page=wp-seed-pixel&selected=1#pixel-bulk');
    }
}
