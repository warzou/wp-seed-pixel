<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Color {
    const VERSION = 'rgb-icc-lcms-1';
    // LittleCMS built-in sRGB profile: its copyright tag permits free use.
    const SRGB = 'AAACTGxjbXMEQAAAbW50clJHQiBYWVogB+oACgAEAAYALAAbYWNzcE1TRlQAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAPbWAAEAAAAA0y1sY21zAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAALZGVzYwAAAQgAAAA2Y3BydAAAAUAAAABMd3RwdAAAAYwAAAAUY2hhZAAAAaAAAAAsclhZWgAAAcwAAAAUYlhZWgAAAeAAAAAUZ1hZWgAAAfQAAAAUclRSQwAAAggAAAAgZ1RSQwAAAggAAAAgYlRSQwAAAggAAAAgY2hybQAAAigAAAAkbWx1YwAAAAAAAAABAAAADGVuVVMAAAAaAAAAHABzAFIARwBCACAAYgB1AGkAbAB0AC0AaQBuAABtbHVjAAAAAAAAAAEAAAAMZW5VUwAAADAAAAAcAE4AbwAgAGMAbwBwAHkAcgBpAGcAaAB0ACwAIAB1AHMAZQAgAGYAcgBlAGUAbAB5WFlaIAAAAAAAAPbWAAEAAAAA0y1zZjMyAAAAAAABDEIAAAXe///zJQAAB5MAAP2Q///7of///aIAAAPcAADAblhZWiAAAAAAAABvoAAAOPUAAAOQWFlaIAAAAAAAACSfAAAPhAAAtsNYWVogAAAAAAAAYpcAALeHAAAY2XBhcmEAAAAAAAMAAAACZmYAAPKnAAANWQAAE9AAAApbY2hybQAAAAAAAwAAAACj1wAAVHsAAEzNAACZmgAAJmYAAA9c';

    public static function available() {
        if (!class_exists('Imagick') || !method_exists('Imagick', 'profileImage') || !method_exists('Imagick', 'autoOrient')) { return false; }
        $delegates = Imagick::getConfigureOptions('DELEGATES');
        return strpos(strtolower(implode(' ', $delegates)), 'lcms') !== false && (bool)Imagick::queryFormats('PNG');
    }

    public static function inspect($path) {
        $markers = WP_Seed_Pixel_Files::markers($path, true);
        if (is_wp_error($markers)) { return $markers; }
        if (!$markers['icc']) { return null; }
        $p = $markers['profile']; $length = strlen($p);
        if ($length < 132 || unpack('N', substr($p, 0, 4))[1] !== $length || substr($p, 36, 4) !== 'acsp' || substr($p, 16, 4) !== 'RGB ' || !in_array(substr($p, 20, 4), array('XYZ ', 'Lab '), true)) {
            return new WP_Error('pixel_color_unsupported', 'Malformed or non-RGB ICC profile; no conversion was attempted.');
        }
        $tags = unpack('N', substr($p, 128, 4))[1];
        if ($tags > 4096 || 132 + $tags * 12 > $length) { return new WP_Error('pixel_color_unsupported', 'Invalid ICC tag table.'); }
        for ($i = 0; $i < $tags; ++$i) {
            $entry = unpack('Noffset/Nsize', substr($p, 136 + $i * 12, 8));
            if ($entry['offset'] < 132 + $tags * 12 || $entry['size'] < 8 || $entry['offset'] + $entry['size'] > $length) {
                return new WP_Error('pixel_color_unsupported', 'Invalid ICC tag boundary.');
            }
        }
        return $p;
    }

    public static function prepare($master, $directory) {
        $safe_master = WP_Seed_Pixel_Files::path($master);
        if (is_wp_error($safe_master)) { return $safe_master; }
        $master = $safe_master;
        $profile = self::inspect($master);
        if (is_wp_error($profile)) { return $profile; }
        if ($profile === null) { return array('path' => $master, 'converted' => false); }
        if (!self::available()) { return new WP_Error('pixel_color_unsupported', 'Imagick with LittleCMS is required; no uncalibrated fallback is allowed.'); }
        $info = getimagesize($master);
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        if ($limit > 0 && memory_get_usage(true) + $info[0] * $info[1] * 32 + 16777216 > $limit) {
            return new WP_Error('pixel_memory_budget', 'Insufficient memory budget for ICC conversion.');
        }
        $path = $directory . '/color-reference.png';
        $safe = WP_Seed_Pixel_Files::path($path, false);
        if (is_wp_error($safe) || file_exists($path) || disk_free_space($directory) < $info[0] * $info[1] * 8 + 16777216) {
            return new WP_Error('pixel_color_workspace', 'A safe, sufficiently sized ICC workspace is required.');
        }
        $image = null;
        try {
            $image = new Imagick(); $image->readImage($master);
            if ($image->getNumberImages() !== 1 || !hash_equals($profile, $image->getImageProfile('icc'))) {
                return new WP_Error('pixel_color_profile', 'The decoded source profile does not match the validated JPEG profile.');
            }
            $target = base64_decode(self::SRGB, true);
            $image->setImageRenderingIntent(Imagick::RENDERINGINTENT_RELATIVE);
            if (!$image->profileImage('icc', $target) || !hash_equals($target, $image->getImageProfile('icc'))) {
                return new WP_Error('pixel_color_transform', 'The color-managed sRGB transform failed.');
            }
            $image->autoOrient(); $image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
            $image->stripImage(); $image->setImageFormat('png'); $image->setImageDepth(8);
            $image->setOption('png:exclude-chunks', 'all');
            if (!$image->writeImage($path) || !is_file($path)) { return new WP_Error('pixel_color_write', 'The lossless working reference could not be written.'); }
            $output = getimagesize($path);
            if (!$output || $output[2] !== IMAGETYPE_PNG || $output[0] * $output[1] !== $info[0] * $info[1]) {
                return new WP_Error('pixel_color_output', 'The color-managed reference failed validation.');
            }
            return array('path' => $path, 'converted' => true, 'source_profile_sha256' => hash('sha256', $profile), 'target_profile_sha256' => hash('sha256', $target), 'method' => 'Imagick/LittleCMS RGB ICC to sRGB, relative colorimetric, lossless 8-bit PNG reference', 'version' => self::VERSION);
        } catch (Throwable $exception) {
            return new WP_Error('pixel_color_transform', 'ICC conversion stopped safely. No profile stripping fallback was used.');
        } finally {
            if ($image) { $image->clear(); $image->destroy(); }
        }
    }
}
