<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Host_Admin {
    public static function boot() {
        add_action('admin_menu', static function () { add_media_page(__('Pixel storage and new uploads', 'wp-seed-pixel'), __('Pixel - New uploads', 'wp-seed-pixel'), 'manage_options', 'wp-seed-pixel-host', array(__CLASS__, 'page')); });
        add_action('admin_post_wp_seed_pixel_host', array(__CLASS__, 'save'));
    }

    public static function mb($value) {
        if (!is_string($value) || !preg_match('/^([0-9]{1,12})(?:\.([0-9]{1,6}))?$/D', $value, $m)) { return new WP_Error('POLICY_INVALID'); }
        return (int) $m[1] * 1000000 + (int) str_pad($m[2] ?? '', 6, '0');
    }

    public static function save() {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_options')) { wp_die(esc_html__('Permission denied.', 'wp-seed-pixel'), '', array('response' => 403)); }
        check_admin_referer('wp_seed_pixel_host');
        $input = array();
        foreach (array('provider_quota_bytes', 'operational_ceiling_bytes', 'uncertainty_reserve_bytes', 'safety_reserve_bytes') as $key) {
            $input[$key] = self::mb(isset($_POST[$key]) && is_string($_POST[$key]) ? wp_unslash($_POST[$key]) : '');
            if (is_wp_error($input[$key])) { wp_die(esc_html__('Invalid storage settings.', 'wp-seed-pixel'), '', array('response' => 400)); }
        }
        $s = WP_Seed_Pixel_Storage_Budget::validate($input);
        $capacity = self::mb(isset($_POST['capacity']) && is_string($_POST['capacity']) ? wp_unslash($_POST['capacity']) : '');
        $mode = isset($_POST['mode']) && is_string($_POST['mode']) ? wp_unslash($_POST['mode']) : '';
        $formats = isset($_POST['formats']) && is_array($_POST['formats']) ? array_map('sanitize_key', wp_unslash($_POST['formats'])) : array();
        if (is_wp_error($s) || is_wp_error($capacity) || !in_array($mode, array('off', 'analyze', 'process'), true)) { wp_die(esc_html__('Invalid storage settings.', 'wp-seed-pixel'), '', array('response' => 400)); }
        $r = WP_Seed_Pixel_Future_Uploads::configure($mode, $capacity, $s, $formats);
        if (is_wp_error($r)) { wp_die(esc_html(self::reason($r->get_error_code())), '', array('response' => 409)); }
        wp_safe_redirect(admin_url('upload.php?page=wp-seed-pixel-host')); exit;
    }

    public static function page() {
        if (!current_user_can('manage_options')) { return; }
        $s = WP_Seed_Pixel_Storage_Budget::settings();
        if (is_wp_error($s)) { echo '<div class="notice notice-error"><p>' . esc_html__('Invalid storage settings. Processing is blocked.', 'wp-seed-pixel') . '</p></div>'; return; }
        $f = WP_Seed_Pixel_Future_Uploads::settings(); $status = WP_Seed_Pixel_Storage_Budget::status();
        $labels = array('OK' => __('Available', 'wp-seed-pixel'), 'WARNING' => __('Low margin', 'wp-seed-pixel'), 'BLOCKED' => __('Blocked', 'wp-seed-pixel'), 'UNKNOWN' => __('Unknown usage', 'wp-seed-pixel'));
        $fields = array('provider_quota_bytes' => __('Provider quota (information only)', 'wp-seed-pixel'), 'operational_ceiling_bytes' => __('Operational ceiling (0: disabled)', 'wp-seed-pixel'),
            'uncertainty_reserve_bytes' => __('Uncertainty reserve', 'wp-seed-pixel'), 'safety_reserve_bytes' => __('Safety reserve', 'wp-seed-pixel'));
        echo '<div class="wrap"><h1>' . esc_html__('Pixel storage and new uploads', 'wp-seed-pixel') . '</h1><nav aria-label="' . esc_attr__('Pixel sections', 'wp-seed-pixel') . '"><p>';
        foreach (array('wp-seed-pixel' => __('Web versions', 'wp-seed-pixel'), 'wp-seed-pixel-storage' => __('Storage analysis', 'wp-seed-pixel'), 'wp-seed-pixel-jobs' => __('Jobs', 'wp-seed-pixel'), 'wp-seed-pixel-bulk' => __('Selected media', 'wp-seed-pixel'), 'wp-seed-pixel-quarantine' => __('Retained image versions', 'wp-seed-pixel')) as $slug => $label) { echo '<a class="button" href="' . esc_url(admin_url('upload.php?page=' . $slug)) . '">' . esc_html($label) . '</a> '; }
        echo '</p></nav><p role="status"><strong>' . esc_html(is_wp_error($status) ? __('Blocked', 'wp-seed-pixel') : ($labels[$status['state']] ?? __('Unknown', 'wp-seed-pixel'))) . '</strong></p>';
        if (WP_Seed_Pixel_Admin::other_optimizer()) { echo '<div class="notice notice-warning"><p>' . esc_html__('Another image optimizer is active. Both may process the same images. Review their automatic settings; nothing has been disabled.', 'wp-seed-pixel') . '</p></div>'; }
        echo '<p>' . esc_html__('Known usage', 'wp-seed-pixel') . ': ' . esc_html(!is_wp_error($status) && $status['usage'] ? size_format($status['usage']['bytes']) : __('Unknown', 'wp-seed-pixel')) . '</p>';
        echo '<p>' . esc_html__('Uncertainty reserve', 'wp-seed-pixel') . ': ' . esc_html(!is_wp_error($status) && $status['usage'] ? size_format($status['usage']['uncertainty_bytes']) : __('Unknown', 'wp-seed-pixel')) . '</p>';
        if (!is_wp_error($status) && $status['reason']) { echo '<p>' . esc_html(self::reason($status['reason'])) . '</p>'; }
        echo '<p>' . esc_html__('Usage must include quarantine and all hosting storage. An uploaded file already occupies space before Pixel runs.', 'wp-seed-pixel') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="wp_seed_pixel_host">';
        wp_nonce_field('wp_seed_pixel_host');
        echo '<table class="form-table"><tbody>';
        foreach ($fields as $key => $label) { echo '<tr><th><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" type="number" min="0" step="0.000001" value="' . esc_attr(number_format($s[$key] / 1000000, 6, '.', '')) . '"> ' . esc_html__('Decimal MB (1 MB = 1,000,000 bytes)', 'wp-seed-pixel') . '</td></tr>'; }
        echo '<tr><th><label for="pixel-mode">' . esc_html__('New uploads', 'wp-seed-pixel') . '</label></th><td><select id="pixel-mode" name="mode">';
        foreach (array('off' => __('Off', 'wp-seed-pixel'), 'analyze' => __('Analyze only', 'wp-seed-pixel'), 'process' => __('Process eligible new images', 'wp-seed-pixel')) as $mode => $label) { echo '<option value="' . esc_attr($mode) . '" ' . selected($f['mode'], $mode, false) . '>' . esc_html($label) . '</option>'; }
        echo '</select></td></tr><tr><th>' . esc_html__('Eligible formats', 'wp-seed-pixel') . '</th><td><fieldset>';
        foreach (array('jpeg' => 'JPEG', 'png' => 'PNG') as $format => $label) { echo '<label><input type="checkbox" name="formats[]" value="' . esc_attr($format) . '" ' . checked(in_array($format, $f['formats'], true), true, false) . '> ' . esc_html($label) . '</label> '; }
        echo '<p>' . esc_html__('PNG: lossless only, 8-bit non-interlaced, up to 4 megapixels and 16 MiB. Unsupported cases are preserved.', 'wp-seed-pixel') . '</p></fieldset></td></tr><tr><th><label for="pixel-capacity">' . esc_html__('Maximum operation budget', 'wp-seed-pixel') . '</label></th><td><input id="pixel-capacity" name="capacity" type="number" min="0" step="0.000001" value="' . esc_attr(number_format(($f['capacity_bytes'] ?? 0) / 1000000, 6, '.', '')) . '"> ' . esc_html__('Decimal MB', 'wp-seed-pixel') . '</td></tr></tbody></table>';
        echo '<p>' . esc_html__('Preexisting media are never processed automatically. Saving establishes a new baseline for subsequent uploads.', 'wp-seed-pixel') . '</p>';
        echo '<p>' . esc_html__('Baseline ID / activation UTC', 'wp-seed-pixel') . ': ' . esc_html((string) ($f['cutoff_id'] ?? 0)) . ' / ' . esc_html(!empty($f['cutoff_utc']) ? gmdate('Y-m-d H:i:s', $f['cutoff_utc']) : __('Off', 'wp-seed-pixel')) . '</p>';
        echo '<p>' . esc_html__('No automatic purge. Processing requires a private recovery root and explicit engine activation.', 'wp-seed-pixel') . '</p>';
        echo '<p>' . esc_html__('Recovery history is retained. New storage jobs stop at 10,000 records; restoration and permanent deletion remain available. No automatic expiry.', 'wp-seed-pixel') . '</p>';
        submit_button(); echo '</form>';
        self::recent();
        echo '</div>';
    }

    public static function recent() {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare("SELECT post_id,meta_value FROM {$wpdb->postmeta} WHERE meta_key=%s ORDER BY post_id DESC LIMIT 20", WP_Seed_Pixel_Future_Uploads::META), ARRAY_A);
        echo '<h2>' . esc_html__('Recent new uploads', 'wp-seed-pixel') . '</h2><table class="wp-list-table widefat fixed striped"><thead><tr>';
        foreach (array(__('Media', 'wp-seed-pixel'), __('State', 'wp-seed-pixel'), __('Operation', 'wp-seed-pixel'), __('Estimated peak', 'wp-seed-pixel'), __('Reason', 'wp-seed-pixel')) as $i => $label) { echo '<th scope="col"' . ($i === 0 ? ' class="column-primary"' : '') . '>' . esc_html($label) . '</th>'; }
        echo '</tr></thead><tbody>';
        foreach ((array) $rows as $row) { $v = maybe_unserialize($row['meta_value']); if (!is_array($v) || !current_user_can('edit_post', (int) $row['post_id'])) { continue; }
            $job = !empty($v['job_id']) ? WP_Seed_Pixel_Job_Store::job((int) $v['job_id']) : null;
            $peak = null; $peak_scope = '';
            if ($job) {
                $item = $wpdb->get_row($wpdb->prepare('SELECT data FROM ' . WP_Seed_Pixel_Job_Store::table('items') . " WHERE job_id=%d AND kind='operation' LIMIT 1", $job['id']), ARRAY_A);
                $data = json_decode($item['data'] ?? '', true); $before = $data['before'] ?? array();
                if (isset($before['bytes'], $before['width'], $before['height'])) {
                    $extra = WP_Seed_Pixel_Master_Storage::peak($before);
                    $budget = is_wp_error($extra) ? $extra : WP_Seed_Pixel_Storage_Budget::status($extra);
                    if (!is_wp_error($budget)) { $peak = $budget['peak_bytes'] ?? $extra; $peak_scope = $budget['peak_bytes'] === null ? __('Additional bytes', 'wp-seed-pixel') : __('Total bytes', 'wp-seed-pixel'); }
                }
            }
            echo '<tr class="is-expanded"><td class="column-primary"><a href="' . esc_url(get_edit_post_link((int) $row['post_id'])) . '">' . esc_html(get_the_title((int) $row['post_id'])) . '</a></td><td data-colname="' . esc_attr__('State', 'wp-seed-pixel') . '">' . esc_html(self::state($job['status'] ?? ($v['state'] ?? 'review'))) . '</td><td data-colname="' . esc_attr__('Operation', 'wp-seed-pixel') . '">' . esc_html((string) ($v['job_id'] ?? 0)) . '</td><td data-colname="' . esc_attr__('Estimated peak', 'wp-seed-pixel') . '">' . esc_html($peak === null ? __('Unknown', 'wp-seed-pixel') : number_format_i18n($peak) . ' ' . $peak_scope) . '</td><td data-colname="' . esc_attr__('Reason', 'wp-seed-pixel') . '">' . esc_html(self::reason($job['error_code'] ?? ($v['reason'] ?? ''))) . '</td></tr>'; }
        if (!$rows) { echo '<tr><td colspan="5">' . esc_html__('No new uploads tracked.', 'wp-seed-pixel') . '</td></tr>'; }
        echo '</tbody></table>';
    }

    public static function state($code) {
        $labels = array('awaiting_metadata' => __('Waiting for WordPress metadata', 'wp-seed-pixel'), 'queued' => __('Waiting', 'wp-seed-pixel'), 'running' => __('Processing', 'wp-seed-pixel'), 'analyzed' => __('Analyzed', 'wp-seed-pixel'), 'skipped' => __('Kept without changes', 'wp-seed-pixel'), 'review' => __('Needs review', 'wp-seed-pixel'), 'completed' => __('Completed', 'wp-seed-pixel'), 'completed_errors' => __('Completed with reservations', 'wp-seed-pixel'), 'cancelled' => __('Cancelled', 'wp-seed-pixel'), 'paused' => __('Paused', 'wp-seed-pixel'), 'failed_systemic' => __('Blocked', 'wp-seed-pixel'));
        return $labels[$code] ?? __('Needs review', 'wp-seed-pixel');
    }

    public static function reason($code) {
        if (!$code) { return ''; }
        if ($code === 'HISTORY_FULL') { return __('Recovery history is full. New work is blocked; existing recovery remains available.', 'wp-seed-pixel'); }
        $labels = array('QUOTA_UNKNOWN' => __('Complete usage is not demonstrated.', 'wp-seed-pixel'), 'CEILING_EXCEEDED' => __('Estimated peak meets or exceeds the ceiling.', 'wp-seed-pixel'), 'LOW_DISK' => __('Insufficient physical space.', 'wp-seed-pixel'), 'UNSUPPORTED_FORMAT' => __('Unsupported format', 'wp-seed-pixel'), 'UNSUPPORTED_STORAGE' => __('Private engine disabled or incompatible storage.', 'wp-seed-pixel'), 'LOCKED' => __('Another operation owns the lock.', 'wp-seed-pixel'), 'NO_BENEFIT' => __('No useful saving; source preserved.', 'wp-seed-pixel'), 'EXCLUDED_BY_POLICY' => __('Excluded by the selected policy.', 'wp-seed-pixel'), 'SOURCE_CHANGED' => __('The media changed; review required.', 'wp-seed-pixel'), 'STORE_FAILED' => __('Control write could not be verified.', 'wp-seed-pixel'), 'POLICY_INVALID' => __('Invalid storage settings.', 'wp-seed-pixel'), 'PERMISSION_DENIED' => __('Permission denied.', 'wp-seed-pixel'), 'ICC_UNSAFE' => __('Unsupported image profile or orientation.', 'wp-seed-pixel'), 'NEEDS_REVIEW' => __('Image semantics or ownership require review.', 'wp-seed-pixel'), 'DIMENSION_CONFLICT' => __('The requested dimensions cannot be preserved safely.', 'wp-seed-pixel'));
        return $labels[$code] ?? __('A safety check failed. Review technical details.', 'wp-seed-pixel');
    }
}
