<?php
defined('ABSPATH') || exit;

/** Main-screen adapter for the existing M5 coordinator, not a parallel queue. */
final class WP_Seed_Pixel_Selected_Admin {
    public static function available() {
        return class_exists('WP_Seed_Pixel_Workflow');
    }
    public static function start($ids) {
        if (!current_user_can('manage_options') || !self::available() || !$ids || count($ids) > 500) { return new WP_Error('PERMISSION_DENIED'); }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $legacy = WP_Seed_Pixel_Batch::current();
        if ($legacy && $legacy['status'] !== 'complete') { return new WP_Error('LOCKED'); }
        foreach ($ids as $id) { if ($id < 1 || get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) { return new WP_Error('POLICY_INVALID'); } }
        $prepared = WP_Seed_Pixel_Workflow::prepare(); if (is_wp_error($prepared)) { return $prepared; }
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return $lock; }
        try {
            $current = self::current();
            if (is_wp_error($current)) { return $current; }
            if (!empty($current['id']) && $current['status'] !== 'complete') { return new WP_Error('LOCKED'); }
            $scan = WP_Seed_Pixel_Scan::start($ids);
            return is_wp_error($scan) ? $scan : self::scan_view($scan);
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
    }
    public static function current() {
        $scan = WP_Seed_Pixel_Scan::current();
        if (!is_wp_error($scan) && !empty($scan['id'])) {
            if (WP_Seed_Pixel_Scan::selection((int) $scan['id']) !== null) {
                global $wpdb;
                $child = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . " WHERE source_id=%d AND kind='plan' ORDER BY id DESC LIMIT 1", $scan['id']));
                if (!$child && $scan['status'] !== 'cancelled') { return self::scan_view($scan); }
            }
        }
        global $wpdb;
        if ((int) get_option('wp_seed_pixel_job_schema') !== WP_Seed_Pixel_Job_Store::SCHEMA) { return array(); }
        $id = (int) $wpdb->get_var('SELECT MAX(id) FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . " WHERE kind IN ('plan','replace') AND JSON_EXTRACT(CASE WHEN JSON_VALID(policy) THEN policy ELSE '{}' END,'$.bulk.selected_ids') IS NOT NULL");
        if (!$id) { return array(); }
        $r = WP_Seed_Pixel_Jobs::status($id);
        if (is_wp_error($r) || empty($r['id']) || !isset($r['bulk']['selected_ids']) || ($r['bulk']['operation'] ?? '') !== 'replace') { return array(); }
        return self::view($r);
    }
    public static function command($op, $id) {
        if (!current_user_can('manage_options') || !self::available()) { return new WP_Error('PERMISSION_DENIED'); }
        $selection = WP_Seed_Pixel_Scan::selection($id);
        if ($selection !== null) {
            if ($op === 'native_pause' || $op === 'native_resume' || $op === 'native_cancel') {
                $r = WP_Seed_Pixel_Scan::control($id, array('native_pause' => 'paused', 'native_resume' => 'running', 'native_cancel' => 'cancelled')[$op]);
            } elseif ($op === 'native_step') {
                $r = WP_Seed_Pixel_Scan::step($id);
                if (!is_wp_error($r) && $r['status'] === 'complete') {
                    $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return $lock; }
                    try {
                        global $wpdb;
                        // A lost HTTP response must not create another execution plan.
                        $child = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . WP_Seed_Pixel_Job_Store::table('jobs') . " WHERE source_id=%d AND kind='plan' ORDER BY id LIMIT 1", $id));
                        if ($child) { $r = WP_Seed_Pixel_Jobs::status((int) $child); }
                        else {
                            $capacity = WP_Seed_Pixel_Workflow::capacity($selection);
                            if (is_wp_error($capacity)) { return $capacity; }
                            $r = WP_Seed_Pixel_Jobs::bulk_plan($id, array('master' => 'replace_verified'), $capacity, 'replace', 1, false, $selection);
                        }
                        return is_wp_error($r) ? $r : self::view($r);
                    } finally { WP_Seed_Pixel_Files::unlock($lock); }
                }
            } else { return new WP_Error('INVALID_STATE'); }
            return is_wp_error($r) ? $r : self::scan_view($r);
        }
        $r = WP_Seed_Pixel_Jobs::status($id);
        if (is_wp_error($r) || empty($r['id']) || !isset($r['bulk']['selected_ids']) || ($r['bulk']['operation'] ?? '') !== 'replace') { return new WP_Error('JOB_REQUIRED'); }
        if ($op === 'native_start') { $r = WP_Seed_Pixel_Jobs::start($id); }
        elseif ($op === 'native_step') { $r = WP_Seed_Pixel_Jobs::bulk_step($id); }
        elseif (in_array($op, array('native_pause', 'native_resume', 'native_cancel'), true)) { $r = WP_Seed_Pixel_Jobs::control($id, substr($op, 7)); }
        else { return new WP_Error('INVALID_STATE'); }
        return is_wp_error($r) ? $r : self::view($r);
    }
    private static function scan_view(array $r) {
        return array('native' => true, 'id' => (int) $r['id'], 'phase' => 'analysis',
            'status' => $r['status'] === 'cancelled' ? 'complete' : ($r['status'] === 'paused' ? 'paused' : 'running'),
            'total' => (int) $r['total'], 'processed' => 0, 'success' => 0, 'skipped' => 0, 'failed' => 0, 'failed_ids' => array());
    }
    private static function view(array $r) {
        $planning = $r['kind'] === 'plan';
        $terminal = in_array($r['status'], array('completed', 'completed_errors', 'cancelled'), true);
        $failed_plan = $planning && in_array($r['status'], array('completed_errors', 'cancelled', 'failed_systemic'), true);
        return array('native' => true, 'id' => (int) $r['id'], 'phase' => $planning ? ($r['status'] === 'completed' ? 'ready' : 'plan') : 'process',
            'status' => $planning ? ($failed_plan ? 'complete' : ($r['status'] === 'completed' ? 'paused' : 'running')) : ($terminal ? 'complete' : ($r['status'] === 'running' ? 'running' : 'paused')),
            'total' => (int) $r['total'], 'processed' => $planning ? 0 : (int) $r['done'], 'success' => (int) ($r['states']['retained'] ?? 0),
            'skipped' => (int) ($r['states']['skipped'] ?? 0),
            'failed' => (int) ($r['states']['failed'] ?? 0) + (int) ($r['states']['needs_review'] ?? 0), 'failed_ids' => array());
    }
}
