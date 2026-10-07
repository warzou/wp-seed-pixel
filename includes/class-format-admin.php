<?php
defined('ABSPATH') || exit;

/** One attachment panel; authenticated previews never expose recovery URLs. */
final class WP_Seed_Pixel_Format_Admin {
    public static function boot() {
        add_action('wp_ajax_wp_seed_pixel_format', array(__CLASS__, 'ajax'));
        add_action('wp_ajax_wp_seed_pixel_format_preview', array(__CLASS__, 'preview'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
    }

    public static function assets($hook) {
        if (!in_array($hook, array('upload.php', 'post.php', 'post-new.php'), true) || !current_user_can('manage_options')) { return; }
        wp_enqueue_script('wp-seed-pixel-format', plugins_url('assets/format.js', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_BUILD, true);
        wp_enqueue_style('wp-seed-pixel-format', plugins_url('assets/format.css', WP_SEED_PIXEL_FILE), array(), WP_SEED_PIXEL_BUILD);
        wp_localize_script('wp-seed-pixel-format', 'wpSeedPixelFormat', array('url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_seed_pixel_format'), 'working' => __('Processing locally. No other image is changed.', 'wp-seed-pixel'),
            'failed' => __('The operation stopped safely. Review the technical details before resuming.', 'wp-seed-pixel')));
    }

    private static function allowed($id) { return $id > 0 && current_user_can('manage_options') && current_user_can('edit_post', $id) && get_post_type($id) === 'attachment'; }

    public static function ajax() {
        $id = absint($_POST['attachment_id'] ?? 0);
        if (!self::allowed($id) || !check_ajax_referer('wp_seed_pixel_format', 'nonce', false)) { wp_send_json_error(array('message' => 'PERMISSION_DENIED'), 403); }
        $action = sanitize_key($_POST['operation'] ?? '');
        $generation = sanitize_text_field(wp_unslash($_POST['generation'] ?? ''));
        $confirmed = ($_POST['confirmed'] ?? '') === '1';
        $profile = sanitize_key($_POST['profile'] ?? '');
        if ($action === 'analyze') { $result = WP_Seed_Pixel_Format_Conversion::analyze($id); }
        elseif ($action === 'select') { $result = WP_Seed_Pixel_Format_Conversion::select_profile($id, $generation, $profile); }
        elseif ($action === 'convert') {
            $bundle = WP_Seed_Pixel_Format_Conversion::record($id);
            $result = is_array($bundle) && isset($bundle['record']['profiles']) && $profile === '' ? new WP_Error('INVALID_PROFILE') :
                WP_Seed_Pixel_Format_Conversion::convert($id, $generation, $confirmed, ($_POST['provenance'] ?? '') === '1', $profile === '' ? null : $profile, ($_POST['quality_override'] ?? '') === '1');
        }
        elseif ($action === 'resume' && $confirmed) { $result = WP_Seed_Pixel_Format_Conversion::resume($id, $generation); }
        elseif ($action === 'restore' && $confirmed) { $result = WP_Seed_Pixel_Format_Conversion::restore($id, $generation); }
        elseif ($action === 'discard' && $confirmed) { $result = WP_Seed_Pixel_Format_Conversion::discard($id, $generation); }
        elseif ($action === 'purge') { $result = WP_Seed_Pixel_Format_Conversion::purge($id, $generation, $confirmed, ($_POST['urls'] ?? '') === '1'); }
        else { $result = new WP_Error('CONFIRMATION_REQUIRED'); }
        if (is_wp_error($result)) { wp_send_json_error(array('message' => self::error($result->get_error_code()), 'code' => $result->get_error_code()), 409); }
        wp_send_json_success(array('panel' => WP_Seed_Pixel_Media::details($id)));
    }

    private static function error($code) {
        $messages = array(
            'NO_CONVERSION_BENEFIT' => __('No useful JPEG saving. The PNG is unchanged.', 'wp-seed-pixel'),
            'QUALITY_REJECTED' => __('The JPEG quality is insufficient. The PNG is unchanged.', 'wp-seed-pixel'),
            'QUALITY_CONFIRMATION_REQUIRED' => __('Compare this version and explicitly confirm the compression level before converting.', 'wp-seed-pixel'),
            'OLD_URL_REFERENCED' => __('Existing content still uses the PNG. Its original cannot be deleted.', 'wp-seed-pixel'),
            'TARGET_METADATA_UNSUPPORTED' => __('This image contains unsupported color or metadata information. It is preserved.', 'wp-seed-pixel'),
            'TRANSPARENCY_OR_PALETTE' => __('Transparency or palette information must be preserved. JPEG conversion is unavailable.', 'wp-seed-pixel'));
        return $messages[$code] ?? __('The operation stopped safely. Review the technical details before resuming.', 'wp-seed-pixel');
    }

    public static function preview() {
        $id = absint($_GET['attachment_id'] ?? 0); $kind = sanitize_key($_GET['kind'] ?? '');
        if (!self::allowed($id) || !check_ajax_referer('wp_seed_pixel_format_preview_' . $id, 'nonce', false)) { wp_die('', '', array('response' => 403)); }
        $b = WP_Seed_Pixel_Format_Conversion::record($id);
        if (!is_array($b) || !in_array($b['record']['phase'], array('ready', 'retained'), true)) { wp_die('', '', array('response' => 409)); }
        $r = $b['record'];
        if (!hash_equals($r['generation'], sanitize_text_field(wp_unslash($_GET['generation'] ?? '')))) { wp_die('', '', array('response' => 409)); }
        if ($kind === 'original') { $p = $b['directory'] . '/' . $r['backup'][$r['before']['relative']]; $expected = $r['before']; $mime = 'image/png'; }
        elseif ($kind === 'candidate') {
            if (!$r['candidate']) { wp_die('', '', array('response' => 409)); }
            $p = $r['phase'] === 'ready' ? $b['directory'] . '/new-master.jpg' : get_attached_file($id, true);
            $expected = $r['candidate']; $mime = 'image/jpeg';
        } else { wp_die('', '', array('response' => 400)); }
        if (is_link($p) || !is_file($p) || filesize($p) !== $expected['bytes'] || hash_file('sha256', $p) !== $expected['sha256']) { wp_die('', '', array('response' => 409)); }
        nocache_headers(); header('Cache-Control: private, no-store, max-age=0'); header('X-Content-Type-Options: nosniff');
        header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($p)); readfile($p); exit;
    }

    private static function button($id, $operation, $label, $generation = '', $disabled = false) {
        return '<button type="button" class="button pixel-format-action" data-id="' . (int) $id . '" data-operation="' . esc_attr($operation) .
            '" data-generation="' . esc_attr($generation) . '"' . ($disabled ? ' disabled' : '') . '>' . esc_html($label) . '</button>';
    }

    public static function opportunity($id) {
        if (!self::allowed($id) || get_post_mime_type($id) !== 'image/png' || get_post_meta($id, '_seed_pixel_master_state', true) || !WP_Seed_Pixel_Master_Storage::enabled()) { return ''; }
        return '<div class="pixel-format-opportunity"><p>' . esc_html__('JPEG conversion is optional. Compare a local candidate before deciding.', 'wp-seed-pixel') . '</p>' .
            self::button($id, 'analyze', __('Analyze a JPEG version', 'wp-seed-pixel')) . '<p class="pixel-format-status" role="status" aria-live="polite"></p></div>';
    }

    public static function panel($id) {
        if (!self::allowed($id) || (int) get_option('wp_seed_pixel_job_schema') !== WP_Seed_Pixel_Job_Store::SCHEMA) { return ''; }
        $b = WP_Seed_Pixel_Format_Conversion::record($id);
        if (!$b || is_wp_error($b) || in_array($b['record']['phase'], array('restored', 'discarded'), true)) { return ''; }
        $r = $b['record']; $v = WP_Seed_Pixel_Format_Conversion::view($b['item'], $r, $b['directory']); $g = $r['generation'];
        $result_state = in_array($r['phase'], array('retained', 'purge_intent', 'purged'), true);
        $kept = $r['phase'] === 'retained' && $v['restore_available'];
        $accounting = sprintf(__('Active saving: %1$s. Recovery: %2$s. Old URLs: %3$s. Net logical space freed: %4$s.', 'wp-seed-pixel'), size_format($v['active_saving_bytes']), size_format($v['recovery_bytes']), size_format($v['compatibility_bytes']), size_format($v['freed_bytes']));
        ob_start(); ?>
        <div class="pixel-media-panel pixel-format-panel" tabindex="-1" data-attachment="<?php echo (int) $id; ?>">
            <p><strong><?php esc_html_e('Optional PNG to JPEG conversion', 'wp-seed-pixel'); ?></strong></p>
            <?php if ($result_state) {
                $before = $r['before']; $after = $r['candidate'];
                $saved = max(0, $before['bytes'] - $after['bytes']); ?>
                <dl class="pixel-media-comparison pixel-format-result">
                    <div><dt><?php esc_html_e('Original', 'wp-seed-pixel'); ?></dt><dd><?php echo esc_html('PNG · ' . (int) $before['width'] . ' × ' . (int) $before['height'] . ' · ' . size_format($before['bytes'], 2)); ?></dd>
                        <?php if ($kept) { ?><dd><?php esc_html_e('Original retained for restoration', 'wp-seed-pixel'); ?></dd><?php } ?></div>
                    <div><dt><?php esc_html_e('Optimized version', 'wp-seed-pixel'); ?></dt><dd><?php echo esc_html('JPEG · ' . (int) $after['width'] . ' × ' . (int) $after['height'] . ' · ' . size_format($after['bytes'], 2)); ?></dd></div>
                    <div><dt><?php esc_html_e('Saving on this image', 'wp-seed-pixel'); ?></dt><dd><?php echo esc_html(size_format($saved, 2) . ' · ' . number_format_i18n(100 * $saved / max(1, $before['bytes']), 1) . '%'); ?></dd></div>
                </dl>
                <?php if (!empty($after['provenance_lost'])) { ?><p class="pixel-format-provenance"><?php echo esc_html($kept ? __('The original contains provenance information not retained in the JPEG. It remains in the original kept for restoration.', 'wp-seed-pixel') : __('The JPEG does not contain the original provenance information.', 'wp-seed-pixel')); ?></p><?php }
                if ($r['phase'] === 'retained' && !$kept) { ?><p><?php esc_html_e('Restoration is unavailable. Review the original evidence.', 'wp-seed-pixel'); ?></p><?php }
            } ?>
            <?php if ($r['phase'] === 'ready') { ?>
                <?php if (isset($r['profiles'])) { ?>
                <fieldset class="pixel-format-profiles"><legend><?php esc_html_e('Choose the optimization level', 'wp-seed-pixel'); ?></legend>
                <?php foreach ($r['profiles'] as $key => $profile) {
                    $definition = WP_Seed_Pixel_Format_Processor::profiles()[$key];
                    $available = WP_Seed_Pixel_Format_Processor::selectable($profile, $r['before']['bytes']);
                    $recommended = $key === WP_Seed_Pixel_Format_Processor::lightest($r['profiles']); ?>
                    <label class="pixel-format-profile"><input type="radio" name="pixel-format-profile-<?php echo (int) $id; ?>" data-format-profile="<?php echo esc_attr($key); ?>" data-id="<?php echo (int) $id; ?>" data-generation="<?php echo esc_attr($g); ?>" <?php checked($key, $r['selected_profile']); disabled(!$available); ?>>
                        <span><strong><?php echo esc_html($definition['label']); ?></strong><?php if ($recommended) { echo ' - ' . esc_html__('recommended', 'wp-seed-pixel'); } ?>
                        <span><?php echo esc_html(size_format($profile['bytes'], 2) . ' · ' . number_format_i18n(100 * ($r['before']['bytes'] - $profile['bytes']) / $r['before']['bytes'], 1) . '%'); ?></span>
                        <?php if (!$available) { ?><span><?php esc_html_e('Unavailable for this image: insufficient saving.', 'wp-seed-pixel'); ?></span><?php } ?></span>
                    </label>
                <?php } ?></fieldset>
                <?php } ?>
                <?php if ($r['candidate']) {
                    $quality_override = !WP_Seed_Pixel_Format_Processor::quality_passed($r['candidate']); ?>
                <p><?php echo esc_html(sprintf(__('PNG: %1$s. Proposed JPEG: %2$s.', 'wp-seed-pixel'), size_format($v['original_bytes'], 2), size_format($r['candidate']['bytes'], 2))); ?></p>
                <p><?php esc_html_e('JPEG may introduce a small visual difference. This conversion is optional.', 'wp-seed-pixel'); ?></p>
                <?php if ($quality_override) { ?><p class="pixel-format-quality-warning"><?php esc_html_e('Quality not automatically validated by Pixel. This version is lighter but may show more visual differences. Compare it before confirming.', 'wp-seed-pixel'); ?></p>
                <?php } else { ?><p><?php esc_html_e('Quality automatically validated by Pixel.', 'wp-seed-pixel'); ?></p><?php } ?>
                <details class="pixel-format-comparison"<?php echo $quality_override ? ' open' : ''; ?>><summary><?php esc_html_e('Compare original and JPEG', 'wp-seed-pixel'); ?></summary>
                <div class="pixel-format-images">
                <?php foreach (array('original' => __('Original PNG', 'wp-seed-pixel'), 'candidate' => __('Proposed JPEG', 'wp-seed-pixel')) as $kind => $label) {
                    $url = add_query_arg(array('action' => 'wp_seed_pixel_format_preview', 'attachment_id' => $id, 'kind' => $kind, 'generation' => $g, 'nonce' => wp_create_nonce('wp_seed_pixel_format_preview_' . $id)), admin_url('admin-ajax.php')); ?>
                    <figure><figcaption><?php echo esc_html($label); ?></figcaption><img loading="lazy" src="<?php echo esc_url($url); ?>" alt="<?php echo esc_attr($label); ?>" width="<?php echo (int) $r['candidate']['width']; ?>" height="<?php echo (int) $r['candidate']['height']; ?>"></figure>
                <?php } ?></div></details>
                <div class="pixel-format-confirmation">
                    <p><label><input type="checkbox" data-format-approval="confirmed"> <?php esc_html_e('I compared the images and choose to convert this image to JPEG.', 'wp-seed-pixel'); ?></label></p>
                    <?php if ($quality_override) { ?><p><label><input type="checkbox" data-format-approval="quality_override"> <?php esc_html_e('I compared this version and want to use this compression level.', 'wp-seed-pixel'); ?></label></p><?php } ?>
                    <?php if ($r['candidate']['provenance_lost']) { ?><p><label><input type="checkbox" data-format-approval="provenance"> <?php esc_html_e('Content Credentials will not be preserved in the JPEG. I accept this; the exact PNG is retained.', 'wp-seed-pixel'); ?></label></p><?php } ?>
                    <?php echo self::button($id, 'convert', __('Convert this image to JPEG', 'wp-seed-pixel'), $g, true); ?>
                </div>
                <?php } else { ?><p><?php esc_html_e('No profile passed automatic quality validation. Choose a level explicitly to compare it; no conversion is selected by default.', 'wp-seed-pixel'); ?></p><?php } ?>
                <p><?php echo self::button($id, 'discard', __('Keep PNG and discard comparison', 'wp-seed-pixel'), $g); ?></p>
            <?php } elseif (in_array($r['phase'], array('retained', 'purge_intent'), true)) { ?>
                <p><?php echo esc_html($r['phase'] === 'purge_intent' ? __('Permanent deletion has started. Resume to finish removing the remaining PNG files.', 'wp-seed-pixel') : __('JPEG active. Original PNG and old URLs are retained; this storage has not been freed.', 'wp-seed-pixel')); ?></p>
                <?php if ($v['restore_available']) { echo self::button($id, 'restore', __('Restore original', 'wp-seed-pixel'), $g); } ?>
                <details class="pixel-format-confirmation"><summary><?php esc_html_e('Permanently delete original', 'wp-seed-pixel'); ?></summary>
                    <p><label><input type="checkbox" data-format-approval="confirmed"> <?php esc_html_e('I accept the JPEG and understand that deleting the original removes restoration.', 'wp-seed-pixel'); ?></label></p>
                    <p><label><input type="checkbox" data-format-approval="urls"> <?php esc_html_e('Old PNG URLs will stop working. Unknown external links cannot be checked.', 'wp-seed-pixel'); ?></label></p>
                    <?php echo self::button($id, 'purge', __('Permanently delete original', 'wp-seed-pixel'), $g, true); ?>
                </details>
            <?php } elseif ($r['phase'] === 'purged') { ?><p><?php esc_html_e('Original permanently deleted. Restoration is no longer available.', 'wp-seed-pixel'); ?></p>
            <?php } elseif (in_array($r['phase'], array('publish_intent', 'switched'), true) && !empty($r['approved'])) {
                echo self::button($id, 'resume', __('Resume the confirmed conversion', 'wp-seed-pixel'), $g);
            } elseif ($r['phase'] === 'restore_intent') { echo self::button($id, 'restore', __('Resume original restoration', 'wp-seed-pixel'), $g);
            } elseif ($r['phase'] === 'preparing') { echo self::button($id, 'analyze', __('Resume JPEG analysis', 'wp-seed-pixel'), $g);
            } else { ?><p><?php esc_html_e('The operation stopped safely. Review the technical details before resuming.', 'wp-seed-pixel'); ?></p><?php } ?>
            <?php if (!$result_state) { ?><p><?php echo esc_html($accounting); ?></p><?php } ?>
            <details><summary><?php esc_html_e('Technical details', 'wp-seed-pixel'); ?></summary><p><?php echo esc_html('Stage: ' . $r['phase'] . ' | Item: ' . (int) $b['item']['id']); ?></p>
                <?php if ($result_state) { ?><p><?php echo esc_html($accounting); ?></p><?php } ?>
                <p><?php echo esc_html(wp_json_encode(array('original_master_bytes' => $r['before']['bytes'], 'jpeg_master_bytes' => $r['candidate']['bytes'] ?? null, 'old_graph_bytes' => $v['old_graph_bytes'], 'new_graph_bytes' => $v['new_graph_bytes'], 'provenance_lost' => $r['candidate']['provenance_lost'] ?? false))); ?></p>
                <p><?php echo esc_html(wp_json_encode(array('profile' => $r['candidate']['profile'] ?? null, 'profile_label' => WP_Seed_Pixel_Format_Processor::profiles()[$r['candidate']['profile'] ?? '']['label'] ?? null, 'quality' => $r['candidate']['quality'] ?? null, 'quality_check' => $r['candidate'] ? (WP_Seed_Pixel_Format_Processor::quality_passed($r['candidate']) ? 'PASS' : 'FAIL') : null, 'user_override' => !empty($r['approved']['quality_override']) ? 'Confirmed' : 'Not used', 'metric' => $r['candidate']['metric'] ?? null, 'temporary_bytes' => $v['temporary_bytes'], 'audit_bytes' => $v['audit_bytes'], 'peak_reserved_bytes' => $v['peak_reserved_bytes']), JSON_UNESCAPED_UNICODE)); ?></p></details>
            <p class="pixel-format-status" role="status" aria-live="polite"></p>
        </div>
        <?php return ob_get_clean();
    }
}
