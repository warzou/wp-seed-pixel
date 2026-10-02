<?php
defined('ABSPATH') || exit;

/** Trusted server-side API. HTTP callers must enforce capabilities and a nonce. */
function wp_seed_pixel_optimize($attachment_id, $preset = 'web', $force = false) {
    return WP_Seed_Pixel_Engine::process($attachment_id, $preset, $force);
}

function wp_seed_pixel_register_preset($name, array $preset) {
    return WP_Seed_Pixel_Presets::register($name, $preset);
}

/** After an operator has reviewed cached and external URLs; never an automatic purge. */
function wp_seed_pixel_prune_history($attachment_id, $confirmed = false) {
    return WP_Seed_Pixel_Store::prune($attachment_id, $confirmed);
}

function wp_seed_pixel_get_derivative($attachment_id, $name) {
    if (!is_int($attachment_id) || $attachment_id < 1 || !is_string($name) || !preg_match('/^[a-z][a-z0-9_]{0,19}$/D', $name)) {
        return new WP_Error('pixel_arguments', 'Expected a positive attachment ID and a derivative name.');
    }
    $meta = wp_get_attachment_metadata($attachment_id);
    $state = WP_Seed_Pixel_Store::manifest($attachment_id);
    if (!$state || !isset($state['files'][$name])) {
        return new WP_Error('pixel_derivative_missing', 'Derivative not available.');
    }
    $file = $state['files'][$name];
    if (!isset($meta['sizes']['seed-pixel-' . $name]['file']) || $meta['sizes']['seed-pixel-' . $name]['file'] !== basename($file['path'])) {
        return new WP_Error('pixel_derivative_metadata', 'The native image-size mapping was changed externally.');
    }
    $safe = WP_Seed_Pixel_Files::path($file['path']);
    if (is_wp_error($safe) || !hash_equals($file['sha256'], hash_file('sha256', $safe))) {
        return new WP_Error('pixel_derivative_invalid', 'Derivative integrity failed.');
    }
    $image = wp_get_attachment_image_src($attachment_id, 'seed-pixel-' . $name);
    if (!$image) {
        return new WP_Error('pixel_derivative_missing', 'WordPress image metadata is unavailable.');
    }
    $file['url'] = $image[0];
    return $file;
}
