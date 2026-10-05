<?php
defined('ABSPATH') || exit;

/** Lossless IDAT recompression only; all other PNG chunks remain byte-identical. */
final class WP_Seed_Pixel_PNG_Processor {
    const VERSION = 'png-idat-lossless-1';
    const MAX_BYTES = 16777216;
    const MAX_PIXELS = 4194304;

    public static function inspect($path, $decode = false) {
        if (!function_exists('inflate_init') || !function_exists('gzcompress')) { return new WP_Error('BACKEND_UNAVAILABLE'); }
        $size = @filesize($path);
        if (!$size || $size > self::MAX_BYTES) { return new WP_Error('NEEDS_REVIEW'); }
        $memory = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($memory > 0 && $memory - memory_get_usage(true) < 4 * $size + 16777216) { return new WP_Error('NEEDS_REVIEW'); }
        $raw = file_get_contents($path);
        if (!is_string($raw) || substr($raw, 0, 8) !== "\x89PNG\r\n\x1a\n") { return new WP_Error('CANDIDATE_INVALID'); }
        $offset = 8; $chunks = array(); $idat = ''; $phase = 0; $ihdr = null; $palette = 0; $seen = array(); $end = false;
        while ($offset + 12 <= strlen($raw) && count($chunks) < 512) {
            $length = unpack('N', substr($raw, $offset, 4))[1]; $type = substr($raw, $offset + 4, 4);
            if ($length > self::MAX_BYTES || $offset + 12 + $length > strlen($raw) || !preg_match('/^[A-Za-z]{4}$/D', $type) || !ctype_upper($type[2])) { return new WP_Error('CANDIDATE_INVALID'); }
            $data = substr($raw, $offset + 8, $length); $chunk = substr($raw, $offset, $length + 12);
            if (hash('crc32b', $type . $data, true) !== substr($chunk, -4)) { return new WP_Error('CANDIDATE_INVALID'); }
            if (in_array($type, array('acTL', 'fcTL', 'fdAT'), true)) { return new WP_Error('NEEDS_REVIEW'); }
            // Compressed ancillary text has no bounded metadata decoder in V1.
            if (in_array($type, array('zTXt', 'iTXt'), true)) { return new WP_Error('NEEDS_REVIEW'); }
            if ($type === 'iCCP') {
                if (!$ihdr || $phase || isset($seen['sRGB'])) { return new WP_Error('ICC_UNSAFE'); }
                $zero = strpos($data, "\0");
                if (!$zero || $zero > 79 || ($data[$zero + 1] ?? '') !== "\0") { return new WP_Error('ICC_UNSAFE'); }
                $profile = @gzuncompress(substr($data, $zero + 2), 4194304);
                if (!$profile || strlen($profile) < 132 || unpack('N', substr($profile, 0, 4))[1] !== strlen($profile)
                    || substr($profile, 36, 4) !== 'acsp' || !in_array(substr($profile, 16, 4), array('RGB ', 'GRAY'), true)) { return new WP_Error('ICC_UNSAFE'); }
                if (substr($profile, 16, 4) !== (in_array($ihdr['color'], array(0, 4), true) ? 'GRAY' : 'RGB ')) { return new WP_Error('ICC_UNSAFE'); }
                $tags = unpack('N', substr($profile, 128, 4))[1];
                if ($tags > 128 || 132 + 12 * $tags > strlen($profile)) { return new WP_Error('ICC_UNSAFE'); }
                for ($t = 0; $t < $tags; $t++) {
                    $tag = unpack('Noffset/Nsize', substr($profile, 136 + 12 * $t, 8));
                    if ($tag['offset'] < 128 || $tag['offset'] > strlen($profile) || $tag['size'] > strlen($profile) - $tag['offset']) { return new WP_Error('ICC_UNSAFE'); }
                }
                unset($profile);
            }
            if ($type === 'sRGB' && (isset($seen['iCCP']) || $phase || $length !== 1 || ord($data[0]) > 3)) { return new WP_Error('ICC_UNSAFE'); }
            if (($type === 'gAMA' && ($phase || $length !== 4)) || ($type === 'cHRM' && ($phase || $length !== 32))) { return new WP_Error('ICC_UNSAFE'); }
            if (ctype_upper($type[0]) && !in_array($type, array('IHDR', 'PLTE', 'IDAT', 'IEND'), true)) { return new WP_Error('NEEDS_REVIEW'); }
            if (!$chunks && $type !== 'IHDR') { return new WP_Error('CANDIDATE_INVALID'); }
            if (in_array($type, array('IHDR', 'PLTE', 'IEND', 'tRNS', 'iCCP', 'sRGB', 'gAMA', 'cHRM'), true) && isset($seen[$type])) { return new WP_Error('CANDIDATE_INVALID'); }
            $seen[$type] = true;
            if ($type === 'IHDR') {
                if ($length !== 13) { return new WP_Error('CANDIDATE_INVALID'); }
                $ihdr = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $data);
                $channels = array(0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4);
                if (!$ihdr['width'] || !$ihdr['height'] || $ihdr['width'] > self::MAX_PIXELS / $ihdr['height']
                    || !isset($channels[$ihdr['color']]) || $ihdr['depth'] !== 8 || $ihdr['interlace'] !== 0) { return new WP_Error('NEEDS_REVIEW'); }
                if ($ihdr['compression'] || $ihdr['filter']) { return new WP_Error('CANDIDATE_INVALID'); }
                $ihdr['row'] = $ihdr['width'] * $channels[$ihdr['color']] + 1;
            } elseif ($type === 'PLTE') {
                if ($phase || !$length || $length % 3 || $length > 768 || in_array($ihdr['color'], array(0, 4), true)) { return new WP_Error('CANDIDATE_INVALID'); }
                $palette = (int) ($length / 3);
            } elseif ($type === 'tRNS') {
                if ($phase || ($ihdr['color'] === 3 && (!$palette || !$length || $length > $palette))
                    || ($ihdr['color'] === 0 && $length !== 2) || ($ihdr['color'] === 2 && $length !== 6)
                    || in_array($ihdr['color'], array(4, 6), true)) { return new WP_Error('CANDIDATE_INVALID'); }
            } elseif ($type === 'IDAT') {
                if ($phase === 2 || ($ihdr['color'] === 3 && !$palette)) { return new WP_Error('CANDIDATE_INVALID'); }
                $phase = 1; $idat .= $data;
            } elseif ($type === 'IEND') {
                if ($length || !$phase || !$idat) { return new WP_Error('CANDIDATE_INVALID'); }
                $end = true;
            } elseif ($phase === 1) { $phase = 2; }
            $chunks[] = array($type, $chunk); $offset += $length + 12;
            if ($end) { break; }
        }
        if (!$end || $offset !== strlen($raw)) { return new WP_Error('CANDIDATE_INVALID'); }
        unset($raw);
        $out = array('header' => $ihdr, 'chunks' => $chunks, 'idat' => $idat, 'bytes' => $size);
        if (!$decode) { return $out; }
        $expected = $ihdr['row'] * $ihdr['height'];
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($limit > 0 && $limit - memory_get_usage(true) < 5 * $size + 3 * $expected + 16777216) { return new WP_Error('NEEDS_REVIEW'); }
        $context = inflate_init(ZLIB_ENCODING_DEFLATE); $pixels = '';
        if (!$context) { return new WP_Error('BACKEND_UNAVAILABLE'); }
        // Small compressed slices bound transient expansion before the exact size gate.
        for ($i = 0; $i < strlen($idat); $i += 256) {
            $part = @inflate_add($context, substr($idat, $i, 256), $i + 256 >= strlen($idat) ? ZLIB_FINISH : ZLIB_SYNC_FLUSH);
            if ($part === false || strlen($pixels) + strlen($part) > $expected) { return new WP_Error('CANDIDATE_INVALID'); }
            $pixels .= $part;
        }
        if (strlen($pixels) !== $expected || inflate_get_status($context) !== ZLIB_STREAM_END || inflate_get_read_len($context) !== strlen($idat)) { return new WP_Error('CANDIDATE_INVALID'); }
        for ($y = 0; $y < $ihdr['height']; $y++) { if (ord($pixels[$y * $ihdr['row']]) > 4) { return new WP_Error('CANDIDATE_INVALID'); } }
        $out['pixels'] = $pixels;
        return $out;
    }

    public static function create($source, $candidate, array $policy) {
        if (!function_exists('imagecreatefrompng')) { return new WP_Error('BACKEND_UNAVAILABLE'); }
        if (($policy['intent']['dimensions'] ?? '') !== 'keep') { return new WP_Error('DIMENSION_CONFLICT'); }
        $a = self::inspect($source, true); if (is_wp_error($a)) { return $a; }
        $packed = gzcompress($a['pixels'], 9); if ($packed === false) { return new WP_Error('BACKEND_UNAVAILABLE'); }
        $output = "\x89PNG\r\n\x1a\n"; $written = false; $semantics = '';
        foreach ($a['chunks'] as $chunk) {
            if ($chunk[0] !== 'IDAT') { $output .= $chunk[1]; $semantics .= $chunk[1]; }
            elseif (!$written) { $output .= pack('N', strlen($packed)) . 'IDAT' . $packed . hash('crc32b', 'IDAT' . $packed, true); $written = true; }
        }
        // Reserve 64 KiB for mandatory durable state, in addition to a 5% saving floor.
        if ($a['bytes'] - strlen($output) < max(65536, (int) ceil($a['bytes'] * 0.05))) { return new WP_Error('NO_BENEFIT'); }
        if (file_put_contents($candidate, $output, LOCK_EX) !== strlen($output)) { return new WP_Error('CANDIDATE_INVALID'); }
        unset($output, $packed);
        $b = self::inspect($candidate, true); if (is_wp_error($b)) { return $b; }
        $other = ''; foreach ($b['chunks'] as $chunk) { if ($chunk[0] !== 'IDAT') { $other .= $chunk[1]; } }
        if ($a['header'] !== $b['header'] || $a['pixels'] !== $b['pixels'] || $semantics !== $other) { return new WP_Error('CANDIDATE_INVALID'); }
        $decoded = @imagecreatefrompng($candidate);
        if (!$decoded || imagesx($decoded) !== $b['header']['width'] || imagesy($decoded) !== $b['header']['height']) { return new WP_Error('CANDIDATE_INVALID'); }
        unset($decoded);
        return array('sha256' => hash_file('sha256', $candidate), 'bytes' => $b['bytes'], 'width' => $b['header']['width'], 'height' => $b['header']['height'],
            'backend' => 'PHP zlib', 'quality' => null, 'metric' => array('lossless' => true, 'scanline_sha256' => hash('sha256', $b['pixels']), 'semantic_sha256' => hash('sha256', $other)), 'processor' => self::VERSION);
    }
}
