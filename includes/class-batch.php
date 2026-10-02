<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Batch {
    const OPTION = 'wp_seed_pixel_batch';

    public static function current() {
        return get_option(self::OPTION, array());
    }

    public static function start($preset, $confirmed, $ids = null) {
        global $wpdb;
        if ($confirmed !== true) {
            return new WP_Error('pixel_confirmation', 'Confirm the whole-library operation first.');
        }
        if ($ids !== null) {
            if (!is_array($ids) || !$ids || count($ids) > 1000) {
                return new WP_Error('pixel_selection', 'Select between 1 and 1000 media items.');
            }
            foreach ($ids as $id) {
                if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1) {
                    return new WP_Error('pixel_selection', 'The selection contains an unavailable media item.');
                }
            }
            $ids = array_values(array_unique(array_map('intval', $ids)));
            sort($ids, SORT_NUMERIC);
            // Prime the post cache once; capability checks must not query per selected item.
            get_posts(array('post_type' => 'attachment', 'post_status' => 'inherit', 'post__in' => $ids, 'posts_per_page' => count($ids), 'update_post_meta_cache' => false, 'update_post_term_cache' => false));
            foreach ($ids as $id) {
                if (get_post_type($id) !== 'attachment' || !current_user_can('edit_post', $id)) {
                    return new WP_Error('pixel_selection', 'The selection contains an unavailable media item.');
                }
            }
        }
        $valid = WP_Seed_Pixel_Presets::get($preset);
        if (is_wp_error($valid)) {
            return $valid;
        }
        $lock = WP_Seed_Pixel_Files::lock(0);
        if (is_wp_error($lock)) {
            return $lock;
        }
        try {
            $existing = self::current();
            if ($existing && $existing['status'] !== 'complete') {
                return new WP_Error('pixel_batch_exists', 'Resume or finish the existing batch before starting another.');
            }
            $ceiling = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%'");
            $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%%' AND ID <= %d", $ceiling));
            $batch = array('id' => bin2hex(random_bytes(8)), 'status' => 'running', 'preset' => $preset, 'ceiling' => $ceiling, 'total' => $total, 'cursor' => 0, 'active' => 0, 'processed' => 0, 'success' => 0, 'failed' => 0, 'skipped' => 0, 'failed_ids' => array(), 'retry_counts' => array(), 'started' => time(), 'added_disk_bytes' => 0, 'last' => null);
            $batch['unchanged'] = 0;
            if ($ids !== null) {
                $batch['selection'] = $ids;
                $batch['total'] = count($ids);
            }
            update_option(self::OPTION, $batch, false);
            return $batch;
        } finally {
            WP_Seed_Pixel_Files::unlock($lock);
        }
    }

    public static function step() {
        global $wpdb;
        $lock = WP_Seed_Pixel_Files::lock(0);
        if (is_wp_error($lock)) {
            return $lock;
        }
        try {
            $batch = self::current();
            if (!$batch || $batch['status'] !== 'running') {
                return $batch;
            }
            if (isset($batch['selection'])) {
                $remaining = array_values(array_filter($batch['selection'], function ($item) use ($batch) { return $item > $batch['cursor']; }));
                $id = $batch['active'] ?: (isset($remaining[0]) ? $remaining[0] : 0);
            } else {
                $id = $batch['active'] ?: (int) $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_mime_type LIKE 'image/%%' AND ID > %d AND ID <= %d ORDER BY ID LIMIT 1", $batch['cursor'], $batch['ceiling']));
            }
            if (!$id) {
                $batch['status'] = 'complete';
                $batch['finished'] = time();
                update_option(self::OPTION, $batch, false);
                return $batch;
            }
            if (isset($batch['selection']) && !current_user_can('edit_post', $id)) {
                $batch['status'] = 'paused';
                update_option(self::OPTION, $batch, false);
                return new WP_Error('pixel_batch_permission', 'The next media item is no longer editable. Review the selection before resuming.');
            }
            $batch['active'] = $id;
            update_option(self::OPTION, $batch, false);
            $result = wp_seed_pixel_optimize($id, $batch['preset']);
            $status = is_wp_error($result) ? 'failed' : $result['status'];
            if (is_wp_error($result) && $result->get_error_code() === 'pixel_locked') {
                return new WP_Error('pixel_batch_busy', 'The selected attachment is busy; resume later.');
            }
            ++$batch[$status];
            if (!is_wp_error($result) && !empty($result['unchanged'])) {
                $batch['unchanged'] = isset($batch['unchanged']) ? $batch['unchanged'] + 1 : 1;
            }
            ++$batch['processed'];
            $batch['cursor'] = $id;
            $batch['active'] = 0;
            $batch['last'] = array('id' => $id, 'status' => $status, 'detail' => is_wp_error($result) ? $result->get_error_message() : (isset($result['reason']) ? $result['reason'] : ''));
            if ($status === 'failed' && count($batch['failed_ids']) < 1000) {
                $batch['failed_ids'][] = $id;
            }
            if ($status === 'success' && empty($result['unchanged'])) {
                $batch['added_disk_bytes'] += $result['added_disk_bytes'];
            }
            update_option(self::OPTION, $batch, false);
            return $batch;
        } finally {
            WP_Seed_Pixel_Files::unlock($lock);
        }
    }

    public static function pause($paused) {
        $lock = WP_Seed_Pixel_Files::lock(0);
        if (is_wp_error($lock)) {
            return $lock;
        }
        try {
            $batch = self::current();
            if (!$batch || $batch['status'] === 'complete') {
                return $batch;
            }
            $batch['status'] = $paused ? 'paused' : 'running';
            update_option(self::OPTION, $batch, false);
            return $batch;
        } finally {
            WP_Seed_Pixel_Files::unlock($lock);
        }
    }

    public static function retry_one() {
        $lock = WP_Seed_Pixel_Files::lock(0);
        if (is_wp_error($lock)) {
            return $lock;
        }
        try {
            $batch = self::current();
            if (!$batch || $batch['status'] !== 'complete') {
                return new WP_Error('pixel_retry_state', 'Finish the initial batch before retrying failures.');
            }
            foreach ($batch['failed_ids'] as $offset => $id) {
                $attempts = isset($batch['retry_counts'][$id]) ? $batch['retry_counts'][$id] : 0;
                if ($attempts >= 2) {
                    continue;
                }
                // Persist before processing, so crashes cannot produce unlimited retries.
                $batch['retry_counts'][$id] = $attempts + 1;
                update_option(self::OPTION, $batch, false);
                $result = wp_seed_pixel_optimize((int) $id, $batch['preset']);
                $status = is_wp_error($result) ? 'failed' : $result['status'];
                if ($status !== 'failed') {
                    unset($batch['failed_ids'][$offset]);
                    $batch['failed_ids'] = array_values($batch['failed_ids']);
                    --$batch['failed'];
                    ++$batch[$status];
                }
                $batch['last'] = array('id' => $id, 'status' => $status, 'detail' => is_wp_error($result) ? $result->get_error_message() : '');
                update_option(self::OPTION, $batch, false);
                return $batch;
            }
            return new WP_Error('pixel_retry_limit', 'No retryable failures remain (maximum two retries per attachment).');
        } finally {
            WP_Seed_Pixel_Files::unlock($lock);
        }
    }
}
