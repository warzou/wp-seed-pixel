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
        $job = get_post_meta($id, '_seed_pixel_job', true);
        $manifest = WP_Seed_Pixel_Store::manifest($id);
        if (isset($job['status']) && in_array($job['status'], array('failed', 'skipped', 'pending', 'processing'), true)) {
            return $job['status'];
        }
        if (!$manifest) {
            return get_post_mime_type($id) === 'image/jpeg' ? 'new' : 'unsupported';
        }
        if (empty($manifest['algorithm_version']) || !isset($manifest['preset']) || $manifest['preset'] !== WP_Seed_Pixel_Plugin::settings()['preset']) {
            return 'update';
        }
        return 'success';
    }

    public static function label($state) {
        $labels = array(
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

    public static function details($id) {
        $manifest = WP_Seed_Pixel_Store::manifest($id);
        $job = get_post_meta($id, '_seed_pixel_job', true);
        ob_start();
        ?>
        <div class="pixel-media-panel">
            <p><strong class="pixel-state"><?php echo esc_html(self::label(self::state($id))); ?></strong></p>
            <?php if (self::state($id) === 'update') { ?><p><?php esc_html_e('A new method or profile is available. Regeneration is optional.', 'wp-seed-pixel'); ?></p><?php } ?>
            <p><?php esc_html_e('Your original is kept. Processing is local; no image is sent to an external service.', 'wp-seed-pixel'); ?></p>
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
            <?php if (self::state($id) === 'failed') { ?><p><?php esc_html_e('Processing could not finish. Check the technical details before retrying.', 'wp-seed-pixel'); ?></p><?php } ?>
            <?php if (self::state($id) === 'skipped' || self::state($id) === 'unsupported') { ?><p><?php esc_html_e('This image was preserved because its format or image profile is not supported, or processing was excluded.', 'wp-seed-pixel'); ?></p><?php } ?>
            <p><?php echo self::button($id); ?></p>
            <?php if ($manifest) { ?><p><?php esc_html_e('Regeneration recreates web versions from the preserved original. Previous versions remain available.', 'wp-seed-pixel'); ?></p><?php } ?>
            <details><summary><?php esc_html_e('Technical details', 'wp-seed-pixel'); ?></summary>
                <?php if ($manifest) { ?>
                    <p><?php echo esc_html(sprintf(__('Algorithm: %s | Profile: %s | Original: %s', 'wp-seed-pixel'), isset($manifest['algorithm_version']) ? $manifest['algorithm_version'] : 'fixed', isset($manifest['preset']) ? $manifest['preset'] : '', size_format($manifest['master_bytes']))); ?></p>
                    <?php foreach ($manifest['files'] as $name => $file) { ?>
                        <p><?php echo esc_html($name . ': ' . $file['width'] . ' x ' . $file['height'] . ' | Quality: ' . ($file['quality'] === null ? 'source' : $file['quality']) . ' | Engine: ' . $file['engine']); ?></p>
                        <p><?php echo esc_html(isset($file['reason']) ? $file['reason'] : 'Fixed profile'); ?></p>
                        <?php if (!empty($file['metric'])) { ?><p><?php echo esc_html('SSIM: ' . $file['metric']['ssim'] . ' | PSNR: ' . $file['metric']['psnr']); ?></p><?php } ?>
                    <?php } ?>
                <?php } ?>
                <?php if (!empty($job['code'])) { ?><p><?php echo esc_html($job['code']); ?></p><?php } ?>
                <?php if (!empty($job['reason'])) { ?><p><?php echo esc_html($job['reason']); ?></p><?php } ?>
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
            wp_die(esc_html($result->get_error_message()), '', array('response' => 400));
        }
        WP_Seed_Pixel_Batch::pause(true);
        return admin_url('upload.php?page=wp-seed-pixel&selected=1#pixel-bulk');
    }
}
