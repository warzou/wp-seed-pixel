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
            $reason = self::operation_reason($item);
            if ($reason === 'NO_BENEFIT') { return 'no_benefit'; }
            if ($reason === 'EXCLUDED_BY_POLICY') { return 'excluded'; }
            if ($reason === 'ICC_UNSAFE') { return 'unsupported_profile'; }
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
            if ($mime === 'image/png') { return WP_Seed_Pixel_Quarantine::enabled() ? 'png_lossless' : 'png_inactive'; }
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
            'png_inactive' => __('PNG: not processed', 'wp-seed-pixel'),
            'protected' => __('Preexisting media: protected from automatic processing', 'wp-seed-pixel'),
            'excluded' => __('Excluded by the selected policy.', 'wp-seed-pixel'),
            'no_benefit' => __('No useful saving', 'wp-seed-pixel'),
            'new' => __('Not optimized', 'wp-seed-pixel'),
            'success' => __('Optimized', 'wp-seed-pixel'),
            'update' => __('New optimization available', 'wp-seed-pixel'),
            'skipped' => __('Kept without changes', 'wp-seed-pixel'),
            'unsupported' => __('Unsupported format', 'wp-seed-pixel'),
            'failed' => __('Optimization unavailable', 'wp-seed-pixel'),
            'pending' => __('Waiting for automatic processing', 'wp-seed-pixel'),
            'processing' => __('Processing', 'wp-seed-pixel'),
        );
        return isset($labels[$state]) ? $labels[$state] : __('Not optimized', 'wp-seed-pixel');
    }

    public static function button($id) {
        if (!in_array(get_post_mime_type($id), array('image/jpeg', 'image/png'), true) || !current_user_can('manage_options') || !current_user_can('edit_post', $id)
            || in_array(self::state($id), array('master_optimized', 'processing', 'pending', 'unsupported_profile'), true)) {
            return '';
        }
        $label = self::state($id) === 'failed' ? __('Retry', 'wp-seed-pixel') : __('Optimize this image', 'wp-seed-pixel');
        return '<button type="button" class="button pixel-regenerate" data-id="' . (int) $id . '" data-operation="image_start">' . esc_html($label) . '</button><span class="pixel-media-status" role="status" aria-live="polite"></span>';
    }

    private static function operation_reason($item) {
        if (!$item) { return ''; }
        $data = json_decode((string) ($item['data'] ?? ''), true);
        return !empty($item['error_code']) ? $item['error_code'] : ($data['reason'] ?? '');
    }

    public static function operation($id) {
        if (!current_user_can('edit_post', $id) || (int) get_option('wp_seed_pixel_job_schema') !== WP_Seed_Pixel_Job_Store::SCHEMA) { return null; }
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE attachment_id=%d AND kind='operation' AND action='replace' ORDER BY id DESC LIMIT 1", $id), ARRAY_A);
    }

    public static function details($id) {
        $conversion = WP_Seed_Pixel_Format_Admin::panel($id);
        if ($conversion) { return $conversion; }
        $native = wp_get_attachment_metadata($id);
        $path = get_attached_file($id, true);
        $bytes = is_string($path) && !is_wp_error(WP_Seed_Pixel_Files::path($path)) && is_file($path) ? filesize($path) : null;
        $state = self::state($id);
        $item = self::operation($id);
        $record = $view = null;
        $witness = get_post_meta($id, '_seed_pixel_master_state', true);
        $privacy = is_array($witness) && ($witness['metadata'] ?? '') === 'anonymized';
        if (is_array($witness) && !empty($witness['item_id']) && !empty($witness['job_id']) && $item && (int) $witness['item_id'] === (int) $item['id'] && (int) $witness['job_id'] === (int) $item['job_id']) {
            $record = WP_Seed_Pixel_Quarantine::record($item);
            $view = WP_Seed_Pixel_Quarantine::inspect($item);
        }
        $valid = is_array($record) && is_array($view) && ($view['active_delta'] ?? null) !== null;
        $resume_graph_restore = false;
        if ($item && $item['stage'] === 'recovery_required' && WP_Seed_Pixel_Metadata_Graph_Transaction::is_item($item)) {
            $pending_graph = WP_Seed_Pixel_Quarantine::record($item);
            $resume_graph_restore = is_array($pending_graph) && in_array($pending_graph['phase'], array('graph_rolling_back', 'graph_restored'), true);
        }
        $format = strtoupper(str_replace('image/', '', (string) get_post_mime_type($id)));
        ob_start(); ?>
        <div class="pixel-media-panel" tabindex="-1" data-attachment="<?php echo (int) $id; ?>">
            <?php echo WP_Seed_Pixel_Metadata_Admin::panel($id); ?>
            <?php if ($resume_graph_restore) { ?>
                <p><button type="button" class="button pixel-regenerate" data-id="<?php echo (int) $id; ?>" data-operation="image_restore"><?php esc_html_e('Restore original', 'wp-seed-pixel'); ?></button><span class="pixel-media-status" role="status" aria-live="polite"></span></p>
            <?php } ?>
            <p class="pixel-media-current"><?php echo esc_html($format . ' · ' . (int) ($native['width'] ?? 0) . ' × ' . (int) ($native['height'] ?? 0) . ' · ' . ($bytes === null ? __('Unknown', 'wp-seed-pixel') : size_format($bytes, 2))); ?></p>
            <?php if ($valid) {
                $before = $record['before']; $after = $record['candidate'];
                $saved = max(0, $before['bytes'] - $after['bytes']); ?>
                <dl class="pixel-media-comparison">
                    <div><dt><?php esc_html_e('Original', 'wp-seed-pixel'); ?></dt><dd><?php echo esc_html($before['width'] . ' × ' . $before['height'] . ' · ' . size_format($before['bytes'], 2)); ?></dd></div>
                    <div><dt><?php echo esc_html($privacy ? __('Anonymized version','wp-seed-pixel') : __('Optimized version','wp-seed-pixel')); ?></dt><dd><?php echo esc_html($after['width'] . ' × ' . $after['height'] . ' · ' . size_format($after['bytes'], 2)); ?></dd></div>
                </dl>
                <?php if (!$privacy) { ?><p><?php echo esc_html(sprintf(__('Active image saving: %1$s (%2$s%%).', 'wp-seed-pixel'), size_format($saved, 2), number_format_i18n(100 * $saved / max(1, $before['bytes']), 1))); ?></p><?php } ?>
                <?php if ($view['rollback_available']) { ?>
                    <p><?php esc_html_e('Original retained for restoration. Its space has not yet been freed.', 'wp-seed-pixel'); ?></p>
                    <p><button type="button" class="button pixel-regenerate" data-id="<?php echo (int) $id; ?>" data-operation="image_restore"><?php esc_html_e('Restore original', 'wp-seed-pixel'); ?></button><span class="pixel-media-status" role="status" aria-live="polite"></span></p>
                <?php } elseif ($item['stage'] === 'purged') { ?>
                    <p><?php esc_html_e('Original permanently deleted. Restoration is no longer available.', 'wp-seed-pixel'); ?></p>
                    <?php if ($view['net_reclaimed_bytes'] !== null && $view['net_reclaimed_bytes'] > 0) { ?><p><?php echo esc_html(sprintf(__('Space freed: %s.', 'wp-seed-pixel'), size_format($view['net_reclaimed_bytes'], 2))); ?></p><?php } ?>
                <?php }
                if (!empty($view['purge_available'])) { ?>
                    <details class="pixel-delete-confirmation"><summary><?php esc_html_e('Permanently delete original', 'wp-seed-pixel'); ?></summary>
                        <p><?php esc_html_e('The original will be permanently deleted. Restoration will no longer be possible; retained storage will then be released.', 'wp-seed-pixel'); ?></p>
                        <p><label><input type="checkbox" class="pixel-delete-approval"> <?php esc_html_e('I understand that this deletion cannot be undone.', 'wp-seed-pixel'); ?></label></p>
                        <p><button type="button" class="button pixel-regenerate" data-id="<?php echo (int) $id; ?>" data-operation="image_purge" data-generation="<?php echo esc_attr($view['generation']); ?>" disabled><?php esc_html_e('Permanently delete original', 'wp-seed-pixel'); ?></button><span class="pixel-media-status" role="status" aria-live="polite"></span></p>
                    </details>
                <?php }
            } else {
                $unprocessed = in_array($state, array('new', 'protected', 'png_lossless', 'png_inactive', 'success', 'update'), true); ?>
                <p><strong class="pixel-state"><?php echo esc_html($unprocessed ? __('Not yet optimized.', 'wp-seed-pixel') : self::label($state)); ?></strong></p>
                <?php if ($state === 'failed') {
                    $reason = self::operation_reason($item);
                    if (!$reason) { $future = get_post_meta($id, WP_Seed_Pixel_Future_Uploads::META, true); $reason = $future['reason'] ?? ''; }
                    ?><p><?php echo esc_html(WP_Seed_Pixel_Workflow::message($reason)); ?></p><?php
                } elseif ($state === 'no_benefit') { ?><p><?php esc_html_e('The current image is already sufficiently optimized without loss.', 'wp-seed-pixel'); ?></p><?php } ?>
                <?php if ($unprocessed && $format === 'PNG') { ?><p><?php esc_html_e('Lossless PNG optimization available.', 'wp-seed-pixel'); ?></p><?php } ?>
                <?php if ($witness) { ?><p><?php esc_html_e('The media changed; review required.', 'wp-seed-pixel'); ?></p><?php } elseif ($state !== 'no_benefit') { ?>
                    <p><?php esc_html_e('The original will be retained for restoration.', 'wp-seed-pixel'); ?></p>
                    <p><?php echo self::button($id); ?></p>
                <?php }
            } ?>
            <?php echo WP_Seed_Pixel_Format_Admin::opportunity($id); ?>
            <details><summary><?php esc_html_e('Technical details', 'wp-seed-pixel'); ?></summary>
                <p><?php echo esc_html(sprintf(__('Current file: %d bytes.', 'wp-seed-pixel'), (int) $bytes)); ?></p>
                <?php foreach (($native['sizes'] ?? array()) as $name => $size) { ?><p><?php echo esc_html($name . ': ' . $size['width'] . ' × ' . $size['height']); ?></p><?php } ?>
                <?php echo self::technical_details($id); ?>
            </details>
        </div>
        <?php return ob_get_clean();
    }

    private static function technical_details($id) {
        $manifest = WP_Seed_Pixel_Store::manifest($id);
        $job = get_post_meta($id, '_seed_pixel_job', true);
        $state = self::state($id);
        ob_start();
        ?>
            <?php if ($state === 'update') { ?><p><?php esc_html_e('A new method or profile is available. Regeneration is optional.', 'wp-seed-pixel'); ?></p><?php } ?>
            <p><?php esc_html_e('Processing is local; no image is sent to an external service.', 'wp-seed-pixel'); ?></p>
            <?php if ($state === 'png_inactive') { ?><p><?php esc_html_e('Lossless PNG processing is not enabled on this site. No image has been changed.', 'wp-seed-pixel'); ?></p><?php } ?>
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
            if ($item) { ?><p><?php echo esc_html('Job: ' . (int) $item['job_id'] . ' | Item: ' . (int) $item['id'] . ' | Stage: ' . $item['stage'] . ' | Code: ' . self::operation_reason($item)); ?></p><?php }
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
            <?php if ($state === 'skipped' || $state === 'unsupported') { ?><p><?php esc_html_e('This image was preserved because its format or image profile is not supported, or processing was excluded.', 'wp-seed-pixel'); ?></p><?php } ?>
            <?php if ($manifest) { ?><p><?php esc_html_e('Regeneration recreates web versions from the preserved original. Previous versions remain available.', 'wp-seed-pixel'); ?></p><?php } ?>
                <?php $native = wp_get_attachment_metadata($id); ?>
                <p><?php echo esc_html(sprintf(__('Format: %s', 'wp-seed-pixel'), get_post_mime_type($id) ?: __('Unknown', 'wp-seed-pixel'))); ?></p>
                <p><?php echo esc_html(sprintf(__('Dimensions: %1$d x %2$d pixels', 'wp-seed-pixel'), (int) ($native['width'] ?? 0), (int) ($native['height'] ?? 0))); ?></p>
                <p><?php esc_html_e('Color profile is checked before processing, not inferred from the file extension.', 'wp-seed-pixel'); ?></p>
                <p><?php echo esc_html(sprintf(__('Current state: %s', 'wp-seed-pixel'), self::label($state))); ?></p>
                <?php if ($state === 'png_inactive' && current_user_can('manage_options')) { ?>
                    <p><?php esc_html_e('Lossless PNG processing is not enabled on this site. No image has been changed.', 'wp-seed-pixel'); ?>
                    <a href="<?php echo esc_url(admin_url('upload.php?page=wp-seed-pixel#pixel-png')); ?>"><?php esc_html_e('PNG settings', 'wp-seed-pixel'); ?></a></p>
                <?php } ?>
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
            <noscript><p><?php esc_html_e('Image actions require JavaScript. Settings remain available without it.', 'wp-seed-pixel'); ?></p></noscript>
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
        $result = WP_Seed_Pixel_Selected_Admin::start($ids);
        if (is_wp_error($result)) {
            wp_die(esc_html(WP_Seed_Pixel_I18n::message($result->get_error_message())), '', array('response' => 400));
        }
        WP_Seed_Pixel_Selected_Admin::command('native_pause', $result['id']);
        return admin_url('upload.php?page=wp-seed-pixel&selected=1#pixel-bulk');
    }
}
