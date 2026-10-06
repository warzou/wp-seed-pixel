<?php
defined('ABSPATH') || exit;

/** Normal-user facade; all writes still belong to Jobs and its fenced lifecycle. */
final class WP_Seed_Pixel_Workflow {
    public static function message($code) {
        if ($code === 'INVENTORY_INCOMPLETE') { return __('Pixel could not verify every related file. The image is unchanged; check its technical inventory before retrying.', 'wp-seed-pixel'); }
        if ($code === 'CANDIDATE_INVALID' || $code === 'VERIFY_FAILED') { return __('The optimized file did not pass the safety checks. The original has been preserved.', 'wp-seed-pixel'); }
        if ($code === 'NEEDS_REVIEW' || $code === '') { return __('Pixel could not confirm that this image can be optimized safely. The original is unchanged; review the technical details before retrying.', 'wp-seed-pixel'); }
        if (in_array($code, array('LOW_DISK', 'QUOTA_UNKNOWN', 'CEILING_EXCEEDED'), true)) {
            return __('Insufficient verified temporary space to optimize safely. No image was changed.', 'wp-seed-pixel');
        }
        if (in_array($code, array('UNSUPPORTED_STORAGE', 'NOT_PREPARED', 'HOST_UNSUPPORTED', 'WEBROOT_UNKNOWN', 'PRIVATE_PARENT_UNAVAILABLE', 'FILESYSTEM_MISMATCH', 'FOREIGN_DIRECTORY', 'IDENTITY_CHANGED', 'UNSAFE_LOCATION', 'SETUP_INTERRUPTED', 'HOST_MANAGED', 'HOST_DISABLED', 'WRITE_FAILED'), true)) {
            return __('The original cannot be kept safely for restoration. Optimization is blocked; see advanced diagnostics.', 'wp-seed-pixel');
        }
        return WP_Seed_Pixel_Host_Admin::reason($code);
    }
    public static function prepare() {
        if (!current_user_can('manage_options')) { return new WP_Error('PERMISSION_DENIED'); }
        if (!WP_Seed_Pixel_Quarantine::enabled()) {
            $r = WP_Seed_Pixel_Recovery_Setup::prepare();
            if (is_wp_error($r)) { return $r; }
            WP_Seed_Pixel_Recovery_Setup::apply();
        }
        return WP_Seed_Pixel_Quarantine::enabled() ? true : new WP_Error('UNSUPPORTED_STORAGE');
    }

    public static function capacity(array $ids) {
        $capacity = 1;
        foreach ($ids as $id) {
            if (!in_array(get_post_mime_type($id), array('image/jpeg', 'image/png'), true)) { continue; }
            $before = WP_Seed_Pixel_Master_Adapter::snapshot((int) $id);
            // Invalid sources are classified by the coordinator, never admitted here.
            if (is_wp_error($before)) { continue; }
            $peak = WP_Seed_Pixel_Master_Storage::peak($before);
            if (is_wp_error($peak)) { return $peak; }
            $capacity = max($capacity, $peak);
        }
        $configured = WP_Seed_Pixel_Future_Uploads::settings()['capacity_bytes'];
        // A previously chosen per-operation ceiling remains a real restriction.
        return $configured > 0 ? $configured : $capacity;
    }

    public static function original($id, $action, $generation = '', $confirmed = false) {
        if (!current_user_can('manage_options') || !current_user_can('edit_post', $id)
            || !in_array($action, array('restore', 'purge'), true)) { return new WP_Error('PERMISSION_DENIED'); }
        $witness = get_post_meta($id, '_seed_pixel_master_state', true);
        $item = is_array($witness) ? WP_Seed_Pixel_Job_Store::item((int) ($witness['item_id'] ?? 0)) : null;
        if (!$item || (int) $item['attachment_id'] !== (int) $id
            || (int) $item['job_id'] !== (int) ($witness['job_id'] ?? 0)) { return new WP_Error('EVIDENCE_INVALID'); }
        $approval = $action === 'purge' ? array('version' => WP_Seed_Pixel_Quarantine::AUTHORIZATION,
            'generation' => $generation, 'irreversible' => $confirmed === true) : array();
        return WP_Seed_Pixel_Jobs::quarantine_action((int) $item['job_id'], $action, $approval, (int) $item['id']);
    }
}
