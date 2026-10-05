<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Store {
    const KEY = '_seed_pixel_manifest';

    public static function manifest($id) {
        $rows = get_post_meta($id, self::KEY, false);
        return count($rows) === 1 && is_array($rows[0]) ? $rows[0] : null;
    }

    public static function save_manifest($id, array $manifest) {
        if (count(get_post_meta($id, self::KEY, false)) > 1) {
            return new WP_Error('pixel_manifest_conflict', 'Ambiguous protected manifest.');
        }
        update_post_meta($id, self::KEY, $manifest);
        return get_post_meta($id, self::KEY, true) === $manifest ? true : new WP_Error('pixel_manifest_write', 'Protected manifest write requires recovery.');
    }

    public static function history($id) {
        $rows = get_post_meta($id, '_seed_pixel_history', false);
        if (!$rows) {
            return array();
        }
        if (count($rows) !== 1 || !is_array($rows[0])) {
            return new WP_Error('pixel_history_invalid', 'Protected history is ambiguous or malformed; no history files were deleted.');
        }
        foreach ($rows[0] as $generation => $state) {
            if ((!is_string($generation) && !is_int($generation)) || !preg_match('/^[a-f0-9]{16}$/D', (string) $generation) || !is_array($state) || !isset($state['files']) || !is_array($state['files'])) {
                return new WP_Error('pixel_history_invalid', 'Protected history is malformed; no history files were deleted.');
            }
        }
        return $rows[0];
    }

    public static function retire($id, array $manifest) {
        $history = self::history($id);
        if (is_wp_error($history)) {
            return $history;
        }
        if (isset($history[$manifest['generation']])) {
            return true;
        }
        if (count($history) >= 20) {
            return new WP_Error('pixel_history_limit', 'Twenty previous generations are retained. Back up and review cached URLs before explicitly pruning history with the PHP API.');
        }
        $history[$manifest['generation']] = $manifest;
        update_post_meta($id, '_seed_pixel_history', $history);
        return self::history($id) === $history ? true : new WP_Error('pixel_history_write', 'Could not retain the previous generation safely.');
    }

    public static function prune($id, $confirmed) {
        if (!is_int($id) || $id < 1 || $confirmed !== true) {
            return new WP_Error('pixel_confirmation', 'Explicitly confirm that old external/cached URLs are no longer required before pruning history.');
        }
        $lock = WP_Seed_Pixel_Files::lock($id);
        if (is_wp_error($lock)) {
            return $lock;
        }
        try {
            $master = WP_Seed_Pixel_Files::path(wp_get_original_image_path($id));
            if (is_wp_error($master)) {
                return $master;
            }
            $history = self::history($id);
            if (is_wp_error($history)) {
                return $history;
            }
            $remaining = array();
            foreach ($history as $generation => $state) {
                $retained = false;
                foreach ($state['files'] as $file) {
                    if (isset($file['kind']) && $file['kind'] === 'master') {
                        continue;
                    }
                    WP_Seed_Pixel_Files::owned_delete($file, $id, $master);
                    $retained = $retained || is_file($file['path']);
                }
                if ($retained) {
                    $remaining[$generation] = $state;
                }
            }
            update_post_meta($id, '_seed_pixel_history', $remaining);
            if (self::history($id) !== $remaining) {
                return new WP_Error('pixel_history_write', 'History cleanup bookkeeping requires retry.');
            }
            return array('status' => 'success', 'retained_generations' => count($remaining));
        } finally {
            WP_Seed_Pixel_Files::unlock($lock);
        }
    }

    public static function matches(array $files, array $meta) {
        foreach ($files as $name => $file) {
            $size = isset($meta['sizes']['seed-pixel-' . $name]) ? $meta['sizes']['seed-pixel-' . $name] : null;
            if (!$size || $size['file'] !== basename($file['path']) || $size['width'] !== $file['width'] || $size['height'] !== $file['height'] || $size['mime-type'] !== 'image/jpeg') {
                return false;
            }
        }
        return (bool) $files;
    }

    public static function commit($id, array $before, array $after) {
        if (!WP_Seed_Pixel_Authority::valid($id)) { return new WP_Error('pixel_locked'); }
        global $wpdb;
        $filtered = apply_filters('wp_update_attachment_metadata', $after, $id);
        if ($filtered !== $after) {
            return new WP_Error('pixel_metadata_filter', 'A metadata filter changed the proposed result; no metadata was committed.');
        }
        $rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_wp_attachment_metadata'", $id));
        if (count($rows) !== 1 || maybe_unserialize($rows[0]->meta_value) !== $before) {
            return new WP_Error('pixel_metadata_conflict', 'Attachment metadata changed concurrently or is ambiguous.');
        }
        if ($before === $after) {
            return true;
        }
        // Compare-and-swap protects changes made by other optimizers during decoding.
        $updated = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=%s WHERE meta_id=%d AND meta_value=%s", maybe_serialize($after), $rows[0]->meta_id, $rows[0]->meta_value));
        wp_cache_delete($id, 'post_meta');
        if ($updated !== 1 || wp_get_attachment_metadata($id, true) !== $after) {
            return new WP_Error('pixel_metadata_conflict', 'The atomic metadata update could not be verified.');
        }
        return true;
    }

    public static function workspace($id) {
        $uploads = wp_upload_dir(null, false);
        $parent = $uploads['basedir'] . '/wp-seed-pixel';
        if (is_link($parent) || (!is_dir($parent) && !mkdir($parent, 0700))
            || wp_normalize_path(realpath($parent)) !== wp_normalize_path(realpath($uploads['basedir'])) . '/wp-seed-pixel') {
            return new WP_Error('pixel_stage_dir', 'Cannot establish the owned staging root.');
        }
        $dir = $parent . '/job-' . (int) $id . '-' . bin2hex(random_bytes(8));
        if (!mkdir($dir, 0700)) {
            return new WP_Error('pixel_stage_dir', 'Cannot create a private staging directory.');
        }
        return $dir;
    }

    public static function journal($dir, array $journal) {
        $uploads = wp_upload_dir(null, false);
        foreach ($journal['files'] as &$file) {
            $file['path'] = substr(wp_normalize_path($file['path']), strlen(wp_normalize_path($uploads['basedir'])) + 1);
        }
        unset($file);
        $journal['manifest']['files'] = $journal['files'];
        $journal['relative_paths'] = true;
        $payload = wp_json_encode($journal);
        if ($payload === false || file_put_contents($dir . '/journal.json', $payload, LOCK_EX) !== strlen($payload)) {
            return new WP_Error('pixel_journal', 'Could not persist the recovery journal.');
        }
        return true;
    }

    public static function recover($id, $master) {
        $uploads = wp_upload_dir(null, false);
        $dirs = glob($uploads['basedir'] . '/wp-seed-pixel/job-' . (int) $id . '-*', GLOB_ONLYDIR);
        foreach ($dirs ?: array() as $dir) {
            if (is_link($dir) || !preg_match('/^job-' . (int) $id . '-[a-f0-9]{16}$/D', basename($dir))) {
                continue;
            }
            $journal_file = WP_Seed_Pixel_Files::path($dir . '/journal.json');
            if (!is_wp_error($journal_file)) {
                $journal = json_decode(file_get_contents($journal_file), true);
                $meta = wp_get_attachment_metadata($id, true);
                if (!is_array($journal) || empty($journal['relative_paths']) || !isset($journal['generation'], $journal['files'], $journal['manifest']) || !is_array($journal['files'])) {
                    return new WP_Error('pixel_recovery_journal', 'Recovery journal is invalid; no files were deleted.');
                }
                foreach ($journal['files'] as &$file) {
                    $path = WP_Seed_Pixel_Files::path($uploads['basedir'] . '/' . $file['path'], false);
                    if (is_wp_error($path)) {
                        return $path;
                    }
                    $file['path'] = $path;
                }
                unset($file);
                $journal['manifest']['files'] = $journal['files'];
                if (is_array($meta) && self::matches($journal['files'], $meta)) {
                    foreach ($journal['files'] as $file) {
                        if (!is_file($file['path']) || !hash_equals($file['sha256'], hash_file('sha256', $file['path']))) {
                            return new WP_Error('pixel_recovery_integrity', 'Committed generation requires manual integrity review.');
                        }
                    }
                    if (!hash_equals($journal['manifest']['master_sha256'], hash_file('sha256', $master))) {
                        return new WP_Error('pixel_recovery_source', 'The master changed before manifest recovery.');
                    }
                    $old = self::manifest($id);
                    $saved = self::save_manifest($id, $journal['manifest']);
                    if (is_wp_error($saved)) {
                        return $saved;
                    }
                    // Previous URLs are retained; already reserved before native commit.
                } else {
                    foreach ($journal['files'] as $file) {
                        if (isset($file['kind']) && $file['kind'] === 'master') {
                            continue;
                        }
                        WP_Seed_Pixel_Files::owned_delete($file, $id, $master);
                        if (is_file($file['path'])) {
                            return new WP_Error('pixel_recovery_ownership', 'A journal file changed or remains referenced; it was retained.');
                        }
                    }
                }
            }
            self::clean_workspace($dir);
        }
        return true;
    }

    public static function clean_workspace($dir) {
        $checked = WP_Seed_Pixel_Files::path($dir . '/journal.json', false);
        if (is_wp_error($checked) || is_link($dir) || !preg_match('/^job-([0-9]+)-[a-f0-9]{16}$/D', basename($dir), $identity)
            || !WP_Seed_Pixel_Authority::valid((int) $identity[1])) {
            return;
        }
        foreach (glob($dir . '/*') ?: array() as $path) {
            if (!is_link($path) && is_file($path) && (in_array(basename($path), array('journal.json', 'color-reference.png'), true) || preg_match('/^[a-z][a-z0-9_]{0,19}\.jpg$/D', basename($path)))) {
                if (!WP_Seed_Pixel_Authority::valid((int) $identity[1])) { return; }
                unlink($path);
            }
        }
        @rmdir($dir);
    }

    public static function cleanup($id) {
        $lock = WP_Seed_Pixel_Files::lock($id);
        if (is_wp_error($lock)) {
            return $lock;
        }
        try {
            $master = WP_Seed_Pixel_Files::path(wp_get_original_image_path($id));
            if (is_wp_error($master)) {
                return $master;
            }
            $recovered = self::recover($id, $master);
            if (is_wp_error($recovered)) {
                return $recovered;
            }
            $meta = wp_get_attachment_metadata($id, true);
            $history = self::history($id);
            if (is_wp_error($history)) {
                return $history;
            }
            $state = self::manifest($id);
            if (!is_array($meta) || !$state) {
                return true;
            }
            $next = $meta;
            foreach ($state['files'] as $name => $file) {
                $key = 'seed-pixel-' . $name;
                if (isset($next['sizes'][$key]) && $next['sizes'][$key]['file'] === basename($file['path'])) {
                    unset($next['sizes'][$key]);
                }
            }
            $saved = self::commit($id, $meta, $next);
            if (is_wp_error($saved)) {
                return $saved;
            }
            delete_post_meta($id, self::KEY);
            if (get_post_meta($id, self::KEY, true)) {
                return new WP_Error('pixel_manifest_cleanup', 'Protected manifest cleanup did not complete.');
            }
            foreach ($state['files'] as $file) {
                WP_Seed_Pixel_Files::owned_delete($file, $id, $master);
            }
            delete_post_meta($id, '_seed_pixel_history');
            foreach ($history as $old) {
                foreach ($old['files'] as $file) {
                    WP_Seed_Pixel_Files::owned_delete($file, $id, $master);
                }
            }
            return true;
        } finally {
            WP_Seed_Pixel_Files::unlock($lock);
        }
    }
}
