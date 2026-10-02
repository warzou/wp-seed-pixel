<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Presets {
    private static $custom = array();

    public static function register($name, array $preset) {
        if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $name)) {
            return new WP_Error('pixel_preset_name', 'Invalid preset name.');
        }
        $checked = self::validate($preset);
        if (is_wp_error($checked)) {
            return $checked;
        }
        self::$custom[$name] = $checked;
        return true;
    }

    public static function all() {
        $presets = array(
            'balanced' => array('sizes' => array('thumb' => array('width' => 640, 'height' => 640, 'quality' => 94), 'view' => array('width' => 1920, 'height' => 1920, 'quality' => 94)), 'metadata' => 'strip_sensitive', 'color' => 'preserve', 'upscale' => false, 'format' => 'image/jpeg'),
            'web' => array('sizes' => array('web' => array('width' => 1600, 'height' => 1600, 'quality' => 82)), 'metadata' => 'strip_sensitive', 'color' => 'preserve', 'upscale' => false, 'format' => 'image/jpeg'),
            'participant_album' => array('sizes' => array('thumb' => array('width' => 640, 'height' => 640, 'quality' => 80), 'view' => array('width' => 2048, 'height' => 2048, 'quality' => 90)), 'metadata' => 'strip_sensitive', 'color' => 'preserve', 'upscale' => false, 'format' => 'image/jpeg'),
        );
        return array_merge($presets, self::$custom);
    }

    public static function get($name) {
        $all = self::all();
        return isset($all[$name]) ? self::validate($all[$name]) : new WP_Error('pixel_unknown_preset', 'Unknown preset.');
    }

    public static function adaptive($name) {
        return $name === 'balanced' && !isset(self::$custom[$name]);
    }

    public static function validate(array $preset) {
        $keys = array('sizes', 'metadata', 'color', 'upscale', 'format');
        if (array_diff(array_keys($preset), $keys) || array_diff($keys, array_keys($preset))) {
            return new WP_Error('pixel_preset_shape', 'The preset must contain exactly the documented keys.');
        }
        if ($preset['format'] !== 'image/jpeg' || $preset['metadata'] !== 'strip_sensitive' || $preset['color'] !== 'preserve' || $preset['upscale'] !== false) {
            return new WP_Error('pixel_preset_policy', 'Supported policies: JPEG, sensitive metadata removal, color preservation and no upscaling.');
        }
        if (!is_array($preset['sizes']) || count($preset['sizes']) < 1 || count($preset['sizes']) > 4) {
            return new WP_Error('pixel_preset_sizes', 'A preset requires one to four derivatives.');
        }
        foreach ($preset['sizes'] as $name => $size) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,19}$/D', $name) || !is_array($size) || count($size) !== 3 || array_diff(array_keys($size), array('width', 'height', 'quality'))) {
                return new WP_Error('pixel_preset_size', 'Invalid derivative definition.');
            }
            foreach (array('width', 'height', 'quality') as $key) {
                if (!isset($size[$key]) || !is_int($size[$key]) || $size[$key] < 1 || $size[$key] > ($key === 'quality' ? 100 : 8192)) {
                    return new WP_Error('pixel_preset_range', 'Dimensions must be 1..8192 and JPEG quality 1..100.');
                }
            }
        }
        ksort($preset['sizes']);
        ksort($preset);
        return $preset;
    }
}
