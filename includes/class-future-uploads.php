<?php
defined('ABSPATH') || exit;

/** Enrollment only: image effects always go through the accepted Jobs coordinator. */
final class WP_Seed_Pixel_Future_Uploads {
    const OPTION = 'wp_seed_pixel_future_uploads';
    const META = '_seed_pixel_future_upload';
    private static $uploads = array();

    public static function boot() {
        add_action('add_attachment', array(__CLASS__, 'created'));
        add_filter('wp_handle_upload', array(__CLASS__, 'uploaded'), 100, 2);
        add_filter('wp_generate_attachment_metadata', array(__CLASS__, 'metadata'), 100, 3);
        add_action('wp_seed_pixel_future_job', array(__CLASS__, 'run'), 10, 1);
    }

    public static function settings() {
        $v = get_option(self::OPTION, array());
        $default = array('mode' => 'off', 'generation' => '', 'cutoff_id' => 0, 'cutoff_utc' => 0, 'capacity_bytes' => 0, 'actor_id' => 0, 'formats' => array('jpeg'));
        if (!is_array($v) || array_diff(array_keys($v), array_keys($default))) { return $default; }
        $v = array_merge($default, $v);
        if (!is_array($v['formats']) || !$v['formats'] || array_diff($v['formats'], array('jpeg', 'png')) || count(array_unique($v['formats'])) !== count($v['formats'])) { return $default; }
        foreach (array('cutoff_id', 'cutoff_utc', 'capacity_bytes', 'actor_id') as $key) { if (!is_int($v[$key]) || $v[$key] < 0) { return $default; } }
        if (!in_array($v['mode'], array('off', 'analyze', 'process'), true) || !is_string($v['generation'])
            || ($v['mode'] !== 'off' && (!preg_match('/^[a-f0-9]{48}$/D', $v['generation']) || !$v['actor_id']))) { return $default; }
        return $v;
    }

    public static function configure($mode, $capacity_bytes = 0, $limits = null, array $formats = array('jpeg')) {
        global $wpdb;
        if (!current_user_can('manage_options') || !in_array($mode, array('off', 'analyze', 'process'), true)
            || !is_int($capacity_bytes) || $capacity_bytes < 0 || ($mode === 'process' && $capacity_bytes < 1 && !WP_Seed_Pixel_Quarantine::enabled())) { return new WP_Error('PERMISSION_DENIED'); }
        if (!$formats || array_diff($formats, array('jpeg', 'png')) || count(array_unique($formats)) !== count($formats)) { return new WP_Error('POLICY_INVALID'); }
        if ($limits !== null) { $limits = WP_Seed_Pixel_Storage_Budget::validate($limits); if (is_wp_error($limits)) { return $limits; } }
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return $lock; }
        try {
            $cutoff = $wpdb->get_var("SELECT COALESCE(MAX(ID),0) FROM {$wpdb->posts}");
            if ($wpdb->last_error || !WP_Seed_Pixel_Authority::valid(0)) { return new WP_Error('STORE_FAILED'); }
            $v = array('mode' => $mode, 'generation' => bin2hex(random_bytes(24)), 'cutoff_id' => (int) $cutoff,
                'cutoff_utc' => time(), 'capacity_bytes' => $capacity_bytes, 'actor_id' => get_current_user_id(), 'formats' => array_values($formats));
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s', $wpdb->options));
            if (strtolower((string) $engine) !== 'innodb' || $wpdb->query('START TRANSACTION') === false) { return new WP_Error('STORE_FAILED'); }
            update_option(self::OPTION, $v, false);
            if ($limits !== null) { update_option(WP_Seed_Pixel_Storage_Budget::OPTION, $limits, false); }
            if (!WP_Seed_Pixel_Authority::valid(0) || get_option(self::OPTION) !== $v
                || ($limits !== null && get_option(WP_Seed_Pixel_Storage_Budget::OPTION) !== $limits)
                || $wpdb->query('COMMIT') === false) {
                $wpdb->query('ROLLBACK'); wp_cache_delete(self::OPTION, 'options'); wp_cache_delete(WP_Seed_Pixel_Storage_Budget::OPTION, 'options'); wp_cache_delete('alloptions', 'options');
                return new WP_Error('STORE_FAILED');
            }
            return $v;
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
    }

    public static function created($id) {
        $s = self::settings();
        if (($s['mode'] ?? 'off') === 'off' || (int) $id <= $s['cutoff_id'] || strlen($s['generation']) !== 48) { return; }
        $format = get_post_mime_type($id) === 'image/png' ? 'png' : (get_post_mime_type($id) === 'image/jpeg' ? 'jpeg' : '');
        if (!in_array($format, $s['formats'], true)) { return; }
        // Import/restore tools must explicitly opt in; IDs and timestamps alone are not provenance.
        if (defined('WP_IMPORTING') && WP_IMPORTING || defined('WP_CLI') && WP_CLI) { return; }
        $path = get_attached_file($id);
        if (!is_string($path) || !isset(self::$uploads[wp_normalize_path($path)])) { return; }
        if (!current_user_can('upload_files') || !current_user_can('edit_post', $id)) { return; }
        unset(self::$uploads[wp_normalize_path($path)]);
        add_post_meta($id, self::META, array('generation' => $s['generation'], 'state' => 'awaiting_metadata', 'job_id' => 0), true);
    }

    public static function uploaded($upload, $context = 'upload') {
        if ($context === 'upload' && is_array($upload) && !empty($upload['file']) && empty($upload['error'])) {
            self::$uploads[wp_normalize_path($upload['file'])] = true;
        }
        return $upload;
    }

    public static function metadata($metadata, $id, $context = 'update') {
        if ($context !== 'create') { return $metadata; }
        $s = self::settings(); $v = get_post_meta($id, self::META, true);
        if (($s['mode'] ?? 'off') === 'off' || !is_array($v) || $v['generation'] !== $s['generation']
            || (int) $id <= $s['cutoff_id'] || $v['state'] !== 'awaiting_metadata') { return $metadata; }
        // Core still owns the metadata write; a later worker revalidates the completed native graph.
        if (is_array($metadata) && !empty($metadata['file']) && !empty($metadata['width']) && !empty($metadata['height'])) {
            $next = $v; $next['metadata_hash'] = hash('sha256', wp_json_encode($metadata));
            update_post_meta($id, self::META, $next, $v);
            if (!wp_next_scheduled('wp_seed_pixel_future_job', array((int) $id))) { wp_schedule_single_event(time() + 15, 'wp_seed_pixel_future_job', array((int) $id)); }
        }
        return $metadata;
    }

    public static function run($id) {
        $s = self::settings(); $v = get_post_meta($id, self::META, true);
        if (($s['mode'] ?? 'off') === 'off' || !is_array($v) || ($v['generation'] ?? '') !== ($s['generation'] ?? '') || (int) $id <= ($s['cutoff_id'] ?? PHP_INT_MAX)) { return; }
        $lock = WP_Seed_Pixel_Files::lock(0);
        if (is_wp_error($lock)) {
            if ($lock->get_error_code() === 'pixel_locked') { self::defer($id, $s); }
            return;
        }
        try {
            if (self::settings() !== $s) { return; }
            $v = get_post_meta($id, self::META, true);
            if (!$v || $v['generation'] !== $s['generation'] || !WP_Seed_Pixel_Authority::valid(0)) { return; }
            if (!empty($v['job_id'])) { $job = (int) $v['job_id']; }
            else {
                if (in_array($v['state'] ?? '', array('analyzed', 'skipped', 'review'), true)) { return; }
                if (!is_string($v['metadata_hash'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $v['metadata_hash'])) { return; }
                if (!hash_equals($v['metadata_hash'], hash('sha256', wp_json_encode(wp_get_attachment_metadata($id))))) {
                    $next = $v; $next['metadata_waits'] = (int) ($v['metadata_waits'] ?? 0) + 1;
                    if ($next['metadata_waits'] > 3) { $next['state'] = 'review'; $next['reason'] = 'NEEDS_REVIEW'; }
                    update_post_meta($id, self::META, $next, $v);
                    if ($next['metadata_waits'] <= 3 && !wp_next_scheduled('wp_seed_pixel_future_job', array((int) $id))) { wp_schedule_single_event(time() + 15, 'wp_seed_pixel_future_job', array((int) $id)); }
                    return;
                }
                $a = WP_Seed_Pixel_Analyzer::analyze((int) $id);
                if (is_wp_error($a)) { $v['state'] = 'review'; $v['reason'] = $a->get_error_code(); }
                elseif ($s['mode'] === 'analyze') { $v['state'] = 'analyzed'; }
                elseif (!in_array(get_post_mime_type($id), array('image/jpeg', 'image/png'), true)) { $v['state'] = 'skipped'; $v['reason'] = 'UNSUPPORTED_FORMAT'; }
                elseif (!in_array(get_post_mime_type($id) === 'image/png' ? 'png' : 'jpeg', $s['formats'], true)) { $v['state'] = 'skipped'; $v['reason'] = 'EXCLUDED_BY_POLICY'; }
                elseif (!WP_Seed_Pixel_Master_Storage::enabled()) { $v['state'] = 'review'; $v['reason'] = 'UNSUPPORTED_STORAGE'; }
                else { $v['state'] = 'ready'; }
                if (($v['state'] ?? '') !== 'ready') { update_post_meta($id, self::META, $v); }
                $job = 0;
            }
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
        if (!$job && ($v['state'] ?? '') === 'ready') {
            // replace_one owns its own site lock; provenance is revalidated by enrollment below.
            $job = self::enroll((int) $id, $s);
            if (is_wp_error($job)) {
                if ($job->get_error_code() === 'LOCKED') { self::defer($id, $s); }
                else { self::review($id, $job->get_error_code(), $s['generation']); }
                return;
            }
        }
        if (!$job) { return; }
        $record = WP_Seed_Pixel_Job_Store::job($job);
        if (!$record || in_array($record['status'], array('completed', 'completed_errors', 'cancelled', 'paused', 'failed_systemic'), true) || self::settings() !== $s) { return; }
        // Use the explicit opt-in actor, with current capabilities rechecked by the coordinator.
        $previous = get_current_user_id(); wp_set_current_user((int) $s['actor_id']);
        try { $r = WP_Seed_Pixel_Jobs::step($job); }
        finally { wp_set_current_user($previous); }
        if (is_wp_error($r)) {
            if ($r->get_error_code() === 'LOCKED') { self::defer($id, $s); }
            else { self::review($id, $r->get_error_code(), $s['generation']); }
            return;
        }
        if (in_array($r['status'] ?? '', array('running', 'queued'), true) && !wp_next_scheduled('wp_seed_pixel_future_job', array((int) $id))) {
            wp_schedule_single_event(time() + 15, 'wp_seed_pixel_future_job', array((int) $id));
        }
    }

    private static function defer($id, array $settings) {
        if (self::settings() === $settings && $settings['mode'] !== 'off'
            && !wp_next_scheduled('wp_seed_pixel_future_job', array((int) $id))) {
            wp_schedule_single_event(time() + 15, 'wp_seed_pixel_future_job', array((int) $id));
        }
    }

    private static function enroll($id, array $settings) {
        // The existing coordinator requires administrator opt-in and per-image authority.
        $previous = get_current_user_id(); wp_set_current_user((int) $settings['actor_id']);
        try {
            $v = get_post_meta($id, self::META, true);
            if (!$v || $v['generation'] !== $settings['generation']) { return new WP_Error('SOURCE_CHANGED'); }
            $capacity = $settings['capacity_bytes'] > 0 ? $settings['capacity_bytes'] : WP_Seed_Pixel_Workflow::capacity(array($id));
            if (is_wp_error($capacity)) { return $capacity; }
            $r = WP_Seed_Pixel_Jobs::replace_one($id, array('master' => 'replace_verified'), $capacity, $settings['generation']);
            if (is_wp_error($r)) { return $r; }
            return (int) $r['id'];
        } finally { wp_set_current_user($previous); }
    }

    private static function review($id, $reason, $generation) {
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return; }
        try {
            $v = get_post_meta($id, self::META, true);
            if (is_array($v) && ($v['generation'] ?? '') === $generation && WP_Seed_Pixel_Authority::valid(0)) {
                $next = $v; $next['state'] = 'review'; $next['reason'] = $reason; update_post_meta($id, self::META, $next, $v);
            }
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
    }
}
