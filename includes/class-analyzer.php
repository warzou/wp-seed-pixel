<?php
defined('ABSPATH') || exit;

/** Inspection only: no encoder, filesystem writes or canonical metadata setters. */
final class WP_Seed_Pixel_Analyzer {
    const VERSION = 1;
    const MAX_DIRECTORY_ENTRIES = 10000;
    const MAX_RELATED_ENTRIES = 128;
    const ROLES = array('operational', 'preserved_original', 'pixel_current', 'pixel_history', 'wp_sizes', 'edit_backups', 'unattributed');

    public static function analyze($id) {
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment' || !current_user_can('manage_options') || !current_user_can('edit_post', $id)) {
            return new WP_Error('pixel_scan_permission', __('Permission denied.', 'wp-seed-pixel'));
        }
        $uploads = wp_upload_dir(null, false);
        $root = realpath($uploads['basedir']);
        if (!$root || !empty($uploads['error']) || is_link($uploads['basedir'])) {
            return new WP_Error('pixel_scan_root', __('Local upload storage is unavailable.', 'wp-seed-pixel'));
        }
        $root = wp_normalize_path($root);
        $meta = get_post_meta($id, '_wp_attachment_metadata', true);
        $meta = is_array($meta) ? $meta : array();
        $attached = get_attached_file($id, true);
        $files = array(); $issues = array();
        $add = static function ($path, $role) use (&$files, &$issues, $root) {
            if (count($files) >= 256) { $issues[] = 'inventory_limit'; return; }
            $file = self::inspect_file($path, $root);
            if (is_wp_error($file)) { $issues[] = $file->get_error_code(); return; }
            $key = $file['identity'];
            if (!isset($files[$key])) { $file['roles'] = array(); $file['relative_paths'] = array(); $files[$key] = $file; }
            $files[$key]['relative_paths'] = array_values(array_unique(array_merge($files[$key]['relative_paths'], array($file['relative_path']))));
            $files[$key]['roles'][] = $role;
            $files[$key]['roles'] = array_values(array_unique($files[$key]['roles']));
            $files[$key]['primary_role'] = self::primary_role($files[$key]['roles']);
        };
        if ($attached) { $add($attached, 'operational'); } else { $issues[] = 'missing_mapping'; }
        $directory = $attached ? dirname($attached) : '';
        if ($directory && !empty($meta['original_image']) && is_string($meta['original_image'])) { $add($directory . '/' . $meta['original_image'], 'preserved_original'); }
        $sizes = (array) ($meta['sizes'] ?? array());
        if (count($sizes) > 128) { $issues[] = 'inventory_limit'; }
        foreach (array_slice($sizes, 0, 128) as $size) {
            if (is_array($size) && isset($size['file']) && is_string($size['file'])) { $add($directory . '/' . $size['file'], 'wp_sizes'); }
        }
        $backups = (array) get_post_meta($id, '_wp_attachment_backup_sizes', true);
        if (count($backups) > 128) { $issues[] = 'inventory_limit'; }
        foreach (array_slice($backups, 0, 128) as $size) {
            if (is_array($size) && isset($size['file']) && is_string($size['file'])) { $add($directory . '/' . $size['file'], 'edit_backups'); }
        }
        $current = get_post_meta($id, '_seed_pixel_manifest', true);
        $history = get_post_meta($id, '_seed_pixel_history', true);
        $states = array(array('state' => $current, 'role' => 'pixel_current'));
        if (count((array) $history) > 20) { $issues[] = 'inventory_limit'; }
        foreach (array_slice((array) $history, 0, 20) as $state) { $states[] = array('state' => $state, 'role' => 'pixel_history'); }
        foreach ($states as $record) {
            if (!empty($record['state']) && !is_array($record['state'])) { $issues[] = 'ambiguous_manifest'; continue; }
            $resources = (array) ($record['state']['files'] ?? array());
            if (count($resources) > 32) { $issues[] = 'inventory_limit'; }
            foreach (array_slice($resources, 0, 32) as $file) {
                if (!is_array($file)) { $issues[] = 'ambiguous_manifest'; continue; }
                if (isset($file['path']) && is_string($file['path'])) { $add($file['path'], $record['role']); }
                elseif (isset($file['relative']) && is_string($file['relative'])) { $add($root . '/' . $file['relative'], $record['role']); }
                else { $issues[] = 'ambiguous_manifest'; }
            }
        }
        // A bounded sibling check, never a recursive uploads crawler or ownership claim.
        $extra_complete = true;
        if ($attached && !is_wp_error(WP_Seed_Pixel_Files::path($attached))) {
            $handle = @opendir($directory); $visited = 0; $related = 0;
            $stem = pathinfo($attached, PATHINFO_FILENAME);
            $foreign = self::foreign_native_files($id, $directory, $root, $stem);
            if (is_wp_error($foreign)) { $extra_complete = false; $foreign = array(); }
            if ($handle) {
                try {
                    while (false !== ($name = readdir($handle))) {
                        if ($name === '.' || $name === '..') { continue; }
                        if (++$visited > self::MAX_DIRECTORY_ENTRIES) { $extra_complete = false; break; }
                        $path = $directory . '/' . $name;
                        if (strpos($name, $stem) === 0 && (is_file($path) || is_link($path))) {
                            if (isset($foreign[$path])) { continue; }
                            if (++$related > self::MAX_RELATED_ENTRIES) { $extra_complete = false; break; }
                            $file = self::inspect_file($path, $root);
                            if (is_wp_error($file)) { $issues[] = $file->get_error_code(); }
                            elseif (!isset($files[$file['identity']])) { $add($path, 'unattributed'); }
                        }
                    }
                } finally { closedir($handle); }
            } else { $extra_complete = false; }
        }
        $operational = null; $bytes = 0; $potential = 0; $uncertain = 0;
        $roles = array_fill_keys(self::ROLES, 0); $opportunities = array();
        foreach ($files as $file) {
            $bytes += $file['logical_bytes'];
            $roles[$file['primary_role']] += $file['logical_bytes'];
            if (in_array('operational', $file['roles'], true)) { $operational = $file; }
            if (!$file['exists']) { $issues[] = 'missing_file'; }
            if ($file['exists'] && !$file['readable']) { $issues[] = 'unreadable_file'; }
            $ownership_review = $file['primary_role'] === 'unattributed' || $file['link_count'] > 1;
            if ($ownership_review) { $issues[] = 'ownership_review'; }
            if ($ownership_review || ($file['exists'] && !$file['readable'])) { $uncertain += $file['logical_bytes']; }
            if ($file['primary_role'] === 'preserved_original' && $file['exists']) {
                $potential += $file['logical_bytes'];
                $opportunities[] = array('category' => 'preserved_original_review', 'bytes' => $file['logical_bytes'], 'confidence' => 'measured_conditional', 'eligibility' => 'review_required');
            }
            if ($file['primary_role'] === 'pixel_history' && $file['exists']) {
                $opportunities[] = array('category' => 'history_review', 'bytes' => $file['logical_bytes'], 'confidence' => 'measured_conditional', 'eligibility' => 'review_required');
            }
        }
        $image = $operational && $operational['exists'] && $operational['readable'] ? self::image_info($attached, $operational['logical_bytes']) : array('format' => 'unknown', 'width' => null, 'height' => null, 'icc' => 'unknown', 'alpha' => 'unknown', 'animation' => 'unknown', 'orientation' => null);
        $image['reported_mime'] = $post->post_mime_type;
        $known_mimes = array('jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'avif' => 'image/avif', 'bmp' => 'image/bmp', 'tiff' => 'image/tiff');
        if (isset($known_mimes[$image['format']]) && $post->post_mime_type !== $known_mimes[$image['format']]) { $issues[] = 'mime_mismatch'; }
        if (!in_array($image['format'], array('jpeg', 'png'), true)) { $issues[] = 'unsupported_format'; }
        if ($image['format'] === 'jpeg' && max($image['width'] ?? 0, $image['height'] ?? 0) > 2560) {
            $opportunities[] = array('category' => 'oversized_review', 'bytes' => null, 'confidence' => 'unmeasured', 'eligibility' => 'review_required');
        }
        if ($image['format'] === 'jpeg') { $opportunities[] = array('category' => 'recompression_unmeasured', 'bytes' => null, 'confidence' => 'unmeasured', 'eligibility' => 'not_tested'); }
        if ($image['format'] === 'png') { $opportunities[] = array('category' => 'lossless_unmeasured', 'bytes' => null, 'confidence' => 'unmeasured', 'eligibility' => 'not_tested'); }
        if (!empty($meta['original_image']) && (!$operational || empty($operational['exists']))) { $potential = 0; }
        if (get_attached_file($id) !== $attached) { $issues[] = 'filtered_storage'; }
        if (count(get_post_meta($id, '_wp_attachment_metadata', false)) > 1 || !empty(get_post_meta($id, '_wp_attachment_backup_sizes', true))) { $issues[] = 'metadata_review'; }
        if ($operational && isset($meta['width'], $meta['height']) && $image['width'] !== null && ((int) $meta['width'] !== $image['width'] || (int) $meta['height'] !== $image['height'])) { $issues[] = 'dimension_mismatch'; }
        $issues = array_values(array_unique($issues));
        $health = in_array('missing_file', $issues, true) || in_array('missing_mapping', $issues, true) ? 'missing' : ($issues ? 'needs_review' : 'healthy');
        if (count($issues) === 1 && $issues[0] === 'unsupported_format') { $health = 'unsupported'; }
        $thumb_path = isset($meta['sizes']['thumbnail']['file']) ? $directory . '/' . $meta['sizes']['thumbnail']['file'] : $attached;
        $thumb_safe = $thumb_path ? WP_Seed_Pixel_Files::path($thumb_path) : new WP_Error('missing_preview');
        $preview = !is_wp_error($thumb_safe) && in_array($image['format'], array('jpeg', 'png', 'gif', 'webp', 'avif'), true) ? wp_get_attachment_image_url($id, 'thumbnail') : '';
        $host = $preview ? wp_parse_url($preview, PHP_URL_HOST) : null;
        if (!$preview || $host !== wp_parse_url(home_url(), PHP_URL_HOST) || wp_parse_url($preview, PHP_URL_USER) || wp_parse_url($preview, PHP_URL_PASS)) { $preview = ''; }
        return array('analysis_version' => self::VERSION, 'attachment_id' => (int) $id, 'title' => get_the_title($id), 'preview_url' => $preview, 'scanned_at' => time(), 'metadata_revision' => self::revision($id), 'health' => $health, 'issues' => $issues, 'image' => $image, 'files' => array_values($files), 'storage' => array('unique_logical_bytes' => $bytes, 'by_role' => $roles, 'potential_original_bytes' => $potential, 'uncertain_bytes' => $uncertain, 'reclaimed_bytes' => 0, 'allocated_bytes' => null, 'quota_bytes' => null), 'opportunities' => $opportunities, 'extra_inventory_complete' => $extra_complete, 'deep_analysis' => false, 'stale' => false, 'capabilities' => array('gd' => extension_loaded('gd'), 'imagick' => extension_loaded('imagick'), 'managed_rgb' => WP_Seed_Pixel_Color::available(), 'exif' => function_exists('exif_read_data'), 'destructive' => false));
    }

    /** Ignore only paths explicitly mapped to another native attachment, never filename guesses. */
    private static function foreign_native_files($id, $directory, $root, $stem) {
        global $wpdb;
        $relative = ltrim(substr($directory, strlen($root)), '/');
        $needle = $wpdb->esc_like(($relative === '' ? '' : $relative . '/') . $stem) . '%';
        $owners = $wpdb->get_col($wpdb->prepare("SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.post_id<>%d AND p.post_type='attachment' AND m.meta_key='_wp_attached_file' AND m.meta_value LIKE %s LIMIT 129", $id, $needle));
        if ($wpdb->last_error || count($owners) > 128) { return new WP_Error('INVENTORY_INCOMPLETE'); }
        $paths = array();
        foreach (array_unique($owners) as $owner) {
            $attached = get_attached_file((int) $owner, true);
            if (!is_string($attached) || dirname($attached) !== $directory || is_wp_error(WP_Seed_Pixel_Files::path($attached))) { continue; }
            $paths[$attached] = true;
            $meta = wp_get_attachment_metadata((int) $owner);
            $sizes = array_merge((array) ($meta['sizes'] ?? array()), (array) get_post_meta((int) $owner, '_wp_attachment_backup_sizes', true));
            if (count($sizes) > 256) { return new WP_Error('INVENTORY_INCOMPLETE'); }
            $names = array_column($sizes, 'file');
            if (!empty($meta['original_image'])) { $names[] = $meta['original_image']; }
            foreach ($names as $name) {
                if (is_string($name) && basename($name) === $name && $name !== '.' && $name !== '..') { $paths[$directory . '/' . $name] = true; }
            }
        }
        return $paths;
    }

    public static function revision($id) {
        return hash('sha256', serialize(array(get_post_meta($id, '_wp_attached_file', false), get_post_meta($id, '_wp_attachment_metadata', false), get_post_meta($id, '_wp_attachment_backup_sizes', false), get_post_meta($id, '_seed_pixel_manifest', false), get_post_meta($id, '_seed_pixel_history', false), get_post_mime_type($id))));
    }

    public static function primary_role(array $roles) {
        foreach (self::ROLES as $role) { if (in_array($role, $roles, true)) { return $role; } }
        return 'unattributed';
    }

    private static function inspect_file($path, $root) {
        if (!is_string($path) || strpos($path, "\0") !== false || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path)) { return new WP_Error('unsafe_path'); }
        $path = wp_normalize_path($path);
        if (strpos($path, $root . '/') !== 0) { return new WP_Error('external_storage'); }
        $relative = substr($path, strlen($root) + 1); $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.') { return new WP_Error('unsafe_path'); }
            $cursor .= '/' . $part;
            if (is_link($cursor)) { return new WP_Error('symlink_storage'); }
        }
        clearstatcache(true, $path);
        $exists = is_file($path); $stat = $exists ? @stat($path) : false;
        if ($exists && !$stat) { return new WP_Error('unreadable_file'); }
        $resolved = $exists ? wp_normalize_path(realpath($path)) : $path;
        if (strpos($resolved, $root . '/') !== 0) { return new WP_Error('external_storage'); }
        $physical = $stat && !empty($stat['ino']) ? $stat['dev'] . ':' . $stat['ino'] : (DIRECTORY_SEPARATOR === '\\' ? strtolower($resolved) : $resolved);
        return array('identity' => hash('sha256', $physical), 'relative_path' => $relative, 'exists' => $exists, 'readable' => $exists && is_readable($path), 'logical_bytes' => $stat ? (int) $stat['size'] : 0, 'allocated_bytes' => null, 'mtime' => $stat ? (int) $stat['mtime'] : null, 'link_count' => $stat ? (int) $stat['nlink'] : 0, 'ownership' => 'relationship_only');
    }

    private static function image_info($path, $bytes) {
        $result = array('format' => 'unknown', 'width' => null, 'height' => null, 'icc' => 'unknown', 'alpha' => 'unknown', 'animation' => 'unknown', 'orientation' => null);
        if ($bytes > 64000000) { return $result; }
        $info = @getimagesize($path);
        if (!$info) { return $result; }
        $formats = array(IMAGETYPE_JPEG => 'jpeg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp', IMAGETYPE_AVIF => 'avif', IMAGETYPE_BMP => 'bmp', IMAGETYPE_TIFF_II => 'tiff', IMAGETYPE_TIFF_MM => 'tiff');
        $result['format'] = $formats[$info[2]] ?? 'unsupported';
        $result['width'] = (int) $info[0]; $result['height'] = (int) $info[1];
        if ($result['format'] === 'jpeg') {
            $markers = WP_Seed_Pixel_Files::markers($path);
            $result['icc'] = is_wp_error($markers) ? 'unknown' : ($markers['icc'] ? 'present_unclassified' : 'none');
            $result['alpha'] = false; $result['animation'] = false;
            if (!is_wp_error($markers) && !$markers['exif']) { $result['orientation'] = 1; }
            if (!is_wp_error($markers) && $markers['exif'] && function_exists('exif_read_data')) { $exif = @exif_read_data($path); $result['orientation'] = $exif['Orientation'] ?? null; }
        } elseif ($result['format'] === 'png') {
            $handle = @fopen($path, 'rb');
            if ($handle) {
                try {
                    $header = fread($handle, 33);
                    $alpha = strlen($header) === 33 && in_array(ord($header[25]), array(4, 6), true);
                    $animation = false; $complete = false;
                    for ($i = 0; $i < 512 && ftell($handle) < 4194304; $i++) {
                        $chunk = fread($handle, 8); if (strlen($chunk) !== 8) { break; }
                        $length = unpack('N', substr($chunk, 0, 4))[1]; $type = substr($chunk, 4);
                        if ($type === 'tRNS') { $alpha = true; }
                        if ($type === 'acTL') { $animation = true; }
                        if ($type === 'IDAT' || $type === 'IEND') { $complete = true; break; }
                        if ($length > 4194304 || fseek($handle, $length + 4, SEEK_CUR) !== 0) { break; }
                    }
                    $result['alpha'] = $complete ? $alpha : 'unknown'; $result['animation'] = $complete ? $animation : 'unknown';
                } finally { fclose($handle); }
            }
        }
        return $result;
    }
}
