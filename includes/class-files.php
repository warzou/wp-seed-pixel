<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Files {
    public static function path($path, $must_exist = true) {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error'])) {
            return new WP_Error('pixel_uploads', 'The upload directory is unavailable.');
        }
        $root = realpath($uploads['basedir']);
        if (!$root || !is_string($path) || strpos($path, "\0") !== false || preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path)) {
            return new WP_Error('pixel_path', 'Invalid local upload path.');
        }
        $root = wp_normalize_path($root);
        $normalized = wp_normalize_path($path);
        if (strpos($normalized, $root . '/') !== 0) {
            return new WP_Error('pixel_path_boundary', 'The path is outside the current site upload directory.');
        }
        $relative = substr($normalized, strlen($root) + 1);
        $cursor = $root;
        foreach (explode('/', $relative) as $part) {
            if ($part === '' || $part === '.') {
                return new WP_Error('pixel_path', 'Invalid path component.');
            }
            $cursor .= '/' . $part;
            if (is_link($cursor)) {
                return new WP_Error('pixel_symlink', 'Symbolic links are not supported.');
            }
        }
        $resolved = $must_exist ? realpath($path) : realpath(dirname($path));
        if (!$resolved || ($must_exist && !is_file($resolved))) {
            return new WP_Error('pixel_missing_file', 'The local file or parent directory is missing.');
        }
        $resolved = wp_normalize_path($resolved);
        if ($resolved !== $root && strpos($resolved, $root . '/') !== 0) {
            return new WP_Error('pixel_path_boundary', 'Resolved path escaped the upload directory.');
        }
        return $must_exist ? $resolved : $resolved . '/' . basename($path);
    }

    public static function jpeg($path) {
        $info = @getimagesize($path);
        if (!$info || $info[2] !== IMAGETYPE_JPEG || !isset($info['mime']) || $info['mime'] !== 'image/jpeg') {
            return new WP_Error('pixel_not_jpeg', 'The source is not a valid JPEG.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000 || filesize($path) > 64000000) {
            return new WP_Error('pixel_image_limit', 'The image exceeds the 40 megapixel / 64 MB safety limit.');
        }
        $markers = self::markers($path);
        if (is_wp_error($markers)) {
            return $markers;
        }
        if (isset($info['channels']) && $info['channels'] !== 3) {
            return new WP_Error('pixel_color_unsupported', 'CMYK and non-RGB JPEGs are not supported.');
        }
        // Fail closed; profile-aware conversion is deliberately outside this release.
        if ($markers['icc']) {
            return new WP_Error('pixel_color_unsupported', 'ICC-profiled JPEGs are skipped to avoid unverified color conversion.');
        }
        if ($markers['exif'] && !function_exists('exif_read_data')) {
            return new WP_Error('pixel_exif_required', 'The EXIF extension is required for this source orientation.');
        }
        if ($markers['exif']) {
            $exif = @exif_read_data($path);
            if ($exif === false || isset($exif['Orientation']) && (!is_int($exif['Orientation']) || $exif['Orientation'] < 1 || $exif['Orientation'] > 8)) {
                return new WP_Error('pixel_exif_invalid', 'Malformed EXIF or an unsupported orientation value.');
            }
            if (isset($exif['ColorSpace']) && (int) $exif['ColorSpace'] !== 1) {
                return new WP_Error('pixel_color_unsupported', 'EXIF declares an uncalibrated or non-sRGB color space.');
            }
        }
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $info[0] * $info[1] * 12 + 16777216 > $limit) {
            return new WP_Error('pixel_memory_budget', 'Insufficient memory budget for safe image decoding.');
        }
        return $info;
    }

    public static function markers($path) {
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return new WP_Error('pixel_read', 'The source cannot be read.');
        }
        $flags = array('icc' => false, 'exif' => false, 'private' => false);
        try {
            if (fread($handle, 2) !== "\xff\xd8") {
                return new WP_Error('pixel_jpeg_header', 'Invalid JPEG header.');
            }
            while (!feof($handle) && ftell($handle) < 4194304) {
                if (fread($handle, 1) !== "\xff") {
                    return new WP_Error('pixel_jpeg_header', 'Malformed JPEG marker.');
                }
                do { $byte = fread($handle, 1); } while ($byte === "\xff" && !feof($handle));
                if ($byte === '') {
                    break;
                }
                $marker = ord($byte);
                if ($marker === 0xda || $marker === 0xd9) {
                    return $flags;
                }
                if ($marker === 0x01 || $marker >= 0xd0 && $marker <= 0xd7) {
                    continue;
                }
                $length_bytes = fread($handle, 2);
                if (strlen($length_bytes) !== 2) {
                    break;
                }
                $length = unpack('n', $length_bytes)[1] - 2;
                if ($length < 0) {
                    break;
                }
                $payload = $length ? fread($handle, $length) : '';
                if (strlen($payload) !== $length) {
                    break;
                }
                if ($marker === 0xe2 && strncmp($payload, 'ICC_PROFILE', 11) === 0) {
                    $flags['icc'] = true;
                }
                if ($marker === 0xe1 && strncmp($payload, 'Exif', 4) === 0) {
                    $flags['exif'] = true;
                }
                $technical_comment = $marker === 0xfe && preg_match('/^CREATOR: gd-jpeg v[0-9.]{1,8} \(using IJG JPEG v[0-9a-z]{1,8}\), quality = (?:[1-9]|[1-9][0-9]|100)\n$/D', $payload);
                $canonical_jfif = strlen($payload) === 14 && strncmp($payload, "JFIF\0", 5) === 0 && ord($payload[5]) === 1 && ord($payload[7]) <= 2 && $payload[12] === "\0" && $payload[13] === "\0";
                if ($marker >= 0xe1 && $marker <= 0xef || $marker === 0xfe && !$technical_comment || $marker === 0xe0 && !$canonical_jfif) {
                    $flags['private'] = true;
                }
            }
            return new WP_Error('pixel_jpeg_header_limit', 'Malformed or oversized JPEG metadata header.');
        } finally {
            fclose($handle);
        }
    }

    public static function lock($id) {
        $uploads = wp_upload_dir(null, false);
        $dir = $uploads['basedir'] . '/wp-seed-pixel';
        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            return new WP_Error('pixel_lock_dir', 'Cannot create the plugin workspace.');
        }
        $path = self::path($dir . '/lock-' . (int) $id, false);
        if (is_wp_error($path)) {
            return $path;
        }
        $handle = @fopen($path, 'c');
        if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
            if ($handle) {
                fclose($handle);
            }
            return new WP_Error('pixel_locked', 'This attachment or batch is already processing.');
        }
        return $handle;
    }

    public static function unlock($handle) {
        if (is_resource($handle)) {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public static function referenced_elsewhere($file, $id) {
        global $wpdb;
        $needle = '%' . $wpdb->esc_like(basename($file)) . '%';
        $meta_ref = $wpdb->get_var($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE NOT (post_id=%d AND meta_key='_seed_pixel_history') AND meta_value LIKE %s LIMIT 1", $id, $needle));
        $content_ref = $wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s LIMIT 1", $needle));
        $option_ref = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_value LIKE %s LIMIT 1", $needle));
        return (bool) ($meta_ref || $content_ref || $option_ref);
    }

    public static function owned_delete(array $file, $id, $master) {
        if (isset($file['kind']) && $file['kind'] === 'master') {
            return false;
        }
        if (!isset($file['path'], $file['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $file['sha256'])) {
            return false;
        }
        $path = self::path($file['path']);
        if (is_wp_error($path) || $path === $master || !preg_match('/^seed-pixel-' . (int) $id . '-[a-f0-9]{16}-[a-z][a-z0-9_]{0,19}\.jpg$/D', basename($path))) {
            return false;
        }
        if (!hash_equals($file['sha256'], hash_file('sha256', $path)) || self::referenced_elsewhere($path, $id)) {
            return false;
        }
        return unlink($path);
    }

    public static function output_map($formats) {
        $formats['image/jpeg'] = 'image/jpeg';
        return $formats;
    }
}
