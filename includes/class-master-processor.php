<?php
defined('ABSPATH') || exit;

/** Encodes candidates only. Publishing and recovery belong to the storage adapter. */
final class WP_Seed_Pixel_Master_Processor {
    const VERSION = 'm3-jpeg-q98-q100-1';

    public static function headers($path) {
        $f = @fopen($path, 'rb'); $out = '';
        if (!$f) { return new WP_Error('CANDIDATE_INVALID'); }
        try {
            if (fread($f, 2) !== "\xff\xd8") { return new WP_Error('CANDIDATE_INVALID'); }
            while (!feof($f) && ftell($f) < 4194304) {
                if (fread($f, 1) !== "\xff") { return new WP_Error('CANDIDATE_INVALID'); }
                do { $byte = fread($f, 1); } while ($byte === "\xff");
                if (strlen($byte) !== 1) { break; }
                $marker = ord($byte);
                if ($marker === 0xda || $marker === 0xd9) { return $out; }
                $length = fread($f, 2);
                if (strlen($length) !== 2) { break; }
                $n = unpack('n', $length)[1] - 2;
                if ($n < 0) { break; }
                $payload = $n ? fread($f, $n) : '';
                if (strlen($payload) !== $n) { break; }
                if ($marker >= 0xe1 && $marker <= 0xef || $marker === 0xfe) {
                    // All source rights/EXIF/XMP/ICC markers survive byte-for-byte.
                    $out .= "\xff" . $byte . $length . $payload;
                }
            }
            return new WP_Error('CANDIDATE_INVALID');
        } finally { fclose($f); }
    }

    public static function create($source, $candidate, array $policy, $quality = 98) {
        $type = @getimagesize($source);
        if ($type && $type[2] === IMAGETYPE_PNG) { return WP_Seed_Pixel_PNG_Processor::create($source, $candidate, $policy); }
        $info = WP_Seed_Pixel_Files::jpeg($source);
        if (is_wp_error($info)) { return new WP_Error('ICC_UNSAFE'); }
        if (!function_exists('imagecreatefromjpeg')) { return new WP_Error('BACKEND_UNAVAILABLE'); }
        $markers = WP_Seed_Pixel_Files::markers($source, true);
        $exif = $markers['exif'] ? @exif_read_data($source) : array();
        if (($exif['Orientation'] ?? 1) !== 1) { return new WP_Error('ICC_UNSAFE'); }
        $headers = self::headers($source);
        if (is_wp_error($headers)) { return $headers; }
        $edge = $policy['intent']['dimensions'] === 'max_edge' ? $policy['intent']['max_edge'] : max($info[0], $info[1]);
        $ratio = min(1, $edge / max($info[0], $info[1]));
        // Dimension-bearing EXIF/XMP has no certified rewrite adapter in M3.
        if ($ratio < 1 && ($markers['exif'] || strpos($headers, 'http://ns.adobe.com') !== false)) { return new WP_Error('NEEDS_REVIEW'); }
        $editor = wp_get_image_editor($source);
        if (is_wp_error($editor)) { return new WP_Error('BACKEND_UNAVAILABLE'); }
        if ($markers['icc'] && !($editor instanceof WP_Image_Editor_Imagick)) { return new WP_Error('ICC_UNSAFE'); }
        if ($ratio < 1 && is_wp_error($editor->resize((int) round($info[0] * $ratio), (int) round($info[1] * $ratio), false))) { return new WP_Error('CANDIDATE_INVALID'); }
        if (!in_array($quality, array(98, 100), true) || is_wp_error($editor->set_quality($quality)) || $editor->get_quality() !== $quality) { return new WP_Error('CANDIDATE_INVALID'); }
        $saved = $editor->save($candidate, 'image/jpeg'); $backend = get_class($editor); unset($editor);
        if (is_wp_error($saved) || ($saved['path'] ?? '') !== $candidate) { return new WP_Error('CANDIDATE_INVALID'); }
        // Remove encoder metadata, then insert the exact bounded source markers.
        $raw = file_get_contents($candidate); $offset = 2; $image = "\xff\xd8" . $headers;
        while ($offset < strlen($raw)) {
            $start = $offset;
            if ($raw[$offset++] !== "\xff") { return new WP_Error('CANDIDATE_INVALID'); }
            while ($offset < strlen($raw) && $raw[$offset] === "\xff") { $offset++; }
            $m = ord($raw[$offset++]);
            if ($m === 0xda) { $image .= substr($raw, $start); break; }
            $n = unpack('n', substr($raw, $offset, 2))[1];
            if ($n < 2 || $offset + $n > strlen($raw)) { return new WP_Error('CANDIDATE_INVALID'); }
            $offset += $n;
            if (!($m >= 0xe1 && $m <= 0xef || $m === 0xfe)) { $image .= substr($raw, $start, $offset - $start); }
        }
        if (file_put_contents($candidate, $image, LOCK_EX) !== strlen($image)) { return new WP_Error('CANDIDATE_INVALID'); }
        unset($raw, $image);
        $out = @getimagesize($candidate); $decoded = @imagecreatefromjpeg($candidate);
        if (!$out || !$decoded || $out[2] !== IMAGETYPE_JPEG || $out[0] > $info[0] || $out[1] > $info[1]
            || $out[0] !== $saved['width'] || $out[1] !== $saved['height']
            || abs($out[0] / $out[1] - $info[0] / $info[1]) > 0.005
            || self::headers($candidate) !== $headers) { return new WP_Error('CANDIDATE_INVALID'); }
        unset($decoded);
        $metric = WP_Seed_Pixel_Adaptive::metric($source, $candidate);
        if (is_wp_error($metric) || $metric['ssim'] < 0.995 || $metric['psnr'] < 40) {
            if ($quality === 98 && !is_wp_error($metric)) {
                unlink($candidate);
                return self::create($source, $candidate, $policy, 100);
            }
            return new WP_Error('CANDIDATE_INVALID');
        }
        clearstatcache(true, $candidate);
        $bytes = filesize($candidate); $before = filesize($source);
        if ($before - $bytes < max(4096, (int) ceil($before * 0.05))) { return new WP_Error('NO_BENEFIT'); }
        return array('sha256' => hash_file('sha256', $candidate), 'bytes' => $bytes, 'width' => $out[0], 'height' => $out[1],
            'backend' => $backend, 'quality' => $quality, 'metric' => $metric, 'processor' => self::VERSION);
    }
}
