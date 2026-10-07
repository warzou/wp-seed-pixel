<?php
defined('ABSPATH') || exit;

/** Explicit candidate generation only. Never invoked by upload or lossless PNG processing. */
final class WP_Seed_Pixel_Format_Processor {
    const VERSION = 'png-jpeg-explicit-1';
    const MIN_GAIN = 262144;
    const MIN_GAIN_RATIO = 0.25;

    public static function profiles() {
        return array('web' => array('quality' => 90, 'label' => __('Web', 'wp-seed-pixel')),
            'good' => array('quality' => 94, 'label' => __('Good quality', 'wp-seed-pixel')),
            'best' => array('quality' => 98, 'label' => __('Best quality', 'wp-seed-pixel')));
    }

    public static function lightest(array $profiles) {
        $selected = null;
        foreach ($profiles as $key => $candidate) {
            if (!empty($candidate['accepted']) && ($selected === null || $candidate['bytes'] < $profiles[$selected]['bytes'])) { $selected = $key; }
        }
        return $selected;
    }

    public static function selectable(array $candidate, $original_bytes = null) {
        if (array_key_exists('selectable', $candidate)) { return $candidate['selectable'] === true; }
        // private.4 journals already distinguish a graph-benefit refusal from quality alone.
        return !empty($candidate['accepted']) || (($candidate['rejection'] ?? '') === 'QUALITY_REJECTED'
            && self::benefit($original_bytes, $candidate['bytes']));
    }

    public static function quality_passed(array $candidate) {
        return isset($candidate['metric']['ssim'], $candidate['metric']['psnr'])
            && $candidate['metric']['ssim'] >= .995 && $candidate['metric']['psnr'] >= 40;
    }

    public static function inspect($source) {
        $png = WP_Seed_Pixel_PNG_Processor::inspect($source, true);
        if (is_wp_error($png)) { return $png; }
        if (!in_array($png['header']['color'], array(0, 2), true)) { return new WP_Error('TRANSPARENCY_OR_PALETTE'); }
        $provenance = false;
        foreach ($png['chunks'] as $chunk) {
            if ($chunk[0] === 'caBX') { $provenance = true; continue; }
            if (!in_array($chunk[0], array('IHDR', 'IDAT', 'IEND', 'sRGB'), true)) { return new WP_Error('TARGET_METADATA_UNSUPPORTED'); }
        }
        return array('width' => $png['header']['width'], 'height' => $png['header']['height'],
            'bytes' => $png['bytes'], 'provenance' => $provenance, 'color' => 'sRGB-web-assumption',
            'sha256' => hash_file('sha256', $source));
    }

    public static function benefit($before, $after) {
        return is_int($before) && is_int($after) && $after > 0
            && $before - $after >= max(self::MIN_GAIN, (int) ceil($before * self::MIN_GAIN_RATIO));
    }

    public static function create($source, $target, $reservation = null, $profile = null) {
        if ($profile !== null && !isset(self::profiles()[$profile])) { return new WP_Error('INVALID_PROFILE'); }
        $info = self::inspect($source); if (is_wp_error($info)) { return $info; }
        if ($reservation) {
            $s = @lstat($target);
            if (!WP_Seed_Pixel_Authority::valid_all() || !$s || is_link($target) || $s['nlink'] !== 1 || $s['size'] !== 0
                || $s['dev'] !== $reservation['dev'] || $s['ino'] !== $reservation['ino'] || $s['uid'] !== fileowner(dirname($target))) { return new WP_Error('CLAIM_CONFLICT'); }
        } elseif (file_exists($target) || is_link($target)) { return new WP_Error('CLAIM_CONFLICT'); }
        $probe = wp_get_image_editor($source);
        if (is_wp_error($probe) || !($probe instanceof WP_Image_Editor_Imagick)) { return new WP_Error('BACKEND_UNAVAILABLE'); }
        unset($probe);
        foreach ($profile === null ? WP_Seed_Pixel_Master_Processor::quality_profile() : array(self::profiles()[$profile]['quality']) as $quality) {
            $editor = new WP_Image_Editor_Imagick($source);
            $loaded = $editor->load(); if (is_wp_error($loaded)) { return $loaded; }
            $saved = self::save_jpeg($editor, $target, $quality);
            if (is_wp_error($saved)) { return $saved; }
            $out = getimagesize($target);
            if (!$out || $out[2] !== IMAGETYPE_JPEG || $out[0] !== $info['width'] || $out[1] !== $info['height']) { return new WP_Error('CANDIDATE_INVALID'); }
            $metric = WP_Seed_Pixel_Adaptive::metric($source, $target);
            if (is_wp_error($metric)) { return $metric; }
            $markers = WP_Seed_Pixel_Files::markers($target);
            if (is_wp_error($markers) || $markers['private'] || $markers['icc'] || $markers['exif']) { return new WP_Error('TARGET_METADATA_UNSUPPORTED'); }
            $sampling = self::sampling($target);
            if (is_wp_error($sampling) || array_filter($sampling, static function ($factor) { return $factor !== 0x11; })) { return new WP_Error('CANDIDATE_INVALID'); }
            if ($profile !== null || ($metric['ssim'] >= 0.995 && $metric['psnr'] >= 40)) {
                $bytes = filesize($target);
                if ($profile === null && !self::benefit($info['bytes'], $bytes)) { if (!$reservation) { unlink($target); } return new WP_Error('NO_CONVERSION_BENEFIT'); }
                $result = array('bytes' => $bytes, 'sha256' => hash_file('sha256', $target), 'width' => $out[0], 'height' => $out[1],
                    'quality' => $quality, 'metric' => $metric, 'sampling' => $sampling, 'provenance_lost' => $info['provenance'],
                    'backend' => 'WP_Image_Editor_Imagick', 'processor' => self::VERSION);
                if ($profile !== null) {
                    $result['profile'] = $profile;
                    $result['quality_passed'] = self::quality_passed($result);
                    $result['selectable'] = self::benefit($info['bytes'], $bytes);
                    $result['accepted'] = $result['quality_passed'] && $result['selectable'];
                    $result['rejection'] = $metric['ssim'] < .995 || $metric['psnr'] < 40 ? 'QUALITY_REJECTED' : (self::benefit($info['bytes'], $bytes) ? '' : 'NO_CONVERSION_BENEFIT');
                }
                return $result;
            }
            if ($reservation) {
                $s = lstat($target);
                if (is_link($target) || $s['dev'] !== $reservation['dev'] || $s['ino'] !== $reservation['ino'] || $s['nlink'] !== 1 || !WP_Seed_Pixel_Authority::valid_all()) { return new WP_Error('EVIDENCE_INVALID'); }
                $f = fopen($target, 'r+b'); if (!$f) { return new WP_Error('CANDIDATE_INVALID'); }
                $ok = ftruncate($f, 0) && fflush($f) && fsync($f); fclose($f);
                if (!$ok) { return new WP_Error('CANDIDATE_INVALID'); }
            } else { unlink($target); }
        }
        return new WP_Error('QUALITY_REJECTED');
    }

    public static function save_jpeg($editor, $target, $quality) {
        // WP resets quality when changing MIME. Scope the override to this synchronous save.
        $filter = static function ($default, $mime) use ($quality) { return $mime === 'image/jpeg' ? $quality : $default; };
        add_filter('wp_editor_set_quality', $filter, PHP_INT_MAX, 2);
        try {
            $set = $editor->set_quality($quality);
            $saved = is_wp_error($set) ? $set : $editor->save($target, 'image/jpeg');
            $actual = $editor->get_quality();
        } finally { remove_filter('wp_editor_set_quality', $filter, PHP_INT_MAX); }
        return is_wp_error($saved) || ($saved['path'] ?? '') !== $target || $actual !== $quality ? new WP_Error('CANDIDATE_INVALID') : $saved;
    }

    private static function sampling($path) {
        $f = fopen($path, 'rb');
        try {
            if (fread($f, 2) !== "\xff\xd8") { return new WP_Error('CANDIDATE_INVALID'); }
            while (!feof($f) && ftell($f) < 4194304) {
                if (fread($f, 1) !== "\xff") { break; }
                do { $byte = fread($f, 1); } while ($byte === "\xff");
                if ($byte === '') { break; }
                $marker = ord($byte);
                $length = fread($f, 2); if (strlen($length) !== 2) { break; }
                $n = unpack('n', $length)[1] - 2; if ($n < 0) { break; }
                $data = $n ? fread($f, $n) : ''; if (strlen($data) !== $n) { break; }
                if (in_array($marker, array(0xc0, 0xc1, 0xc2), true) && $n >= 9) {
                    $count = ord($data[5]); if ($n !== 6 + 3 * $count || !in_array($count, array(1, 3), true)) { break; }
                    $factors = array(); for ($i = 0; $i < $count; $i++) { $factors[] = ord($data[7 + 3 * $i]); }
                    return $factors;
                }
                if ($marker === 0xda || $marker === 0xd9) { break; }
            }
            return new WP_Error('CANDIDATE_INVALID');
        } finally { fclose($f); }
    }
}
