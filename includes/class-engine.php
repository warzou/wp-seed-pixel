<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Engine {
    public static function process($id, $preset_name = 'web', $force = false) {
        if (!is_int($id) || $id < 1 || !is_string($preset_name) || !is_bool($force)) {
            return new WP_Error('pixel_arguments', 'Expected a positive attachment ID, preset name and boolean force flag.');
        }
        $preset = WP_Seed_Pixel_Presets::get($preset_name);
        if (is_wp_error($preset)) {
            return $preset;
        }
        $post = get_post($id);
        if (!$post || $post->post_type !== 'attachment') {
            return new WP_Error('pixel_attachment', 'Attachment not found.');
        }
        if ($post->post_mime_type !== 'image/jpeg' || apply_filters('wp_seed_pixel_exclude', false, $id, $preset_name)) {
            $skipped = array('status' => 'skipped', 'attachment_id' => $id, 'reason' => 'Unsupported format or explicit exclusion.');
            update_post_meta($id, '_seed_pixel_job', $skipped);
            return $skipped;
        }
        $lock = WP_Seed_Pixel_Files::lock($id);
        if (is_wp_error($lock)) {
            return $lock;
        }
        $dir = null;
        $published = array();
        $committed = false;
        $preserve_journal = false;
        $master = null;
        $start = microtime(true);
        update_post_meta($id, '_seed_pixel_job', array('status' => 'processing', 'time' => time()));
        try {
            $master = WP_Seed_Pixel_Files::path(wp_get_original_image_path($id));
            if (is_wp_error($master)) {
                return self::error($id, $master);
            }
            $recovery = WP_Seed_Pixel_Store::recover($id, $master);
            if (is_wp_error($recovery)) {
                return self::error($id, $recovery);
            }
            $info = WP_Seed_Pixel_Files::jpeg($master);
            if (is_wp_error($info)) {
                if ($info->get_error_code() === 'pixel_color_unsupported') {
                    $result = array('status' => 'skipped', 'attachment_id' => $id, 'reason' => $info->get_error_message());
                    update_post_meta($id, '_seed_pixel_job', $result);
                    return $result;
                }
                return self::error($id, $info);
            }
            $metadata = wp_get_attachment_metadata($id, true);
            if (!is_array($metadata) || empty($metadata['file'])) {
                return self::error($id, new WP_Error('pixel_metadata_missing', 'Generate native WordPress metadata before optimization.'));
            }
            $attached = WP_Seed_Pixel_Files::path(get_attached_file($id));
            if (is_wp_error($attached) || dirname($attached) !== dirname($master)) {
                return self::error($id, new WP_Error('pixel_master_layout', 'The original and attached files must share a local upload directory.'));
            }
            $master_hash = hash_file('sha256', $master);
            $adaptive = WP_Seed_Pixel_Presets::adaptive($preset_name);
            $backend = wp_json_encode(array('gd' => function_exists('gd_info') ? gd_info() : null, 'imagick' => class_exists('Imagick') ? Imagick::getVersion() : null, 'editors' => apply_filters('wp_image_editors', array('WP_Image_Editor_Imagick', 'WP_Image_Editor_GD')), 'wordpress' => $GLOBALS['wp_version']));
            $config_hash = hash('sha256', WP_SEED_PIXEL_VERSION . $preset_name . wp_json_encode($preset) . ($adaptive ? WP_Seed_Pixel_Adaptive::VERSION . WP_Seed_Pixel_Adaptive::SSIM_FLOOR . WP_Seed_Pixel_Adaptive::PSNR_FLOOR . WP_Seed_Pixel_Adaptive::THUMB_SSIM_FLOOR . WP_Seed_Pixel_Adaptive::THUMB_PSNR_FLOOR . $backend : 'fixed'));
            $old = WP_Seed_Pixel_Store::manifest($id);
            if (!$force && is_array($old) && isset($old['master_sha256'], $old['config_sha256']) && $old['master_sha256'] === $master_hash && $old['config_sha256'] === $config_hash && self::valid_files($old['files']) && WP_Seed_Pixel_Store::matches($old['files'], $metadata)) {
                $old['status'] = 'success';
                $old['unchanged'] = true;
                update_post_meta($id, '_seed_pixel_job', array('status' => 'success', 'time' => time(), 'unchanged' => true));
                return $old;
            }
            foreach ($preset['sizes'] as $name => $size) {
                $key = 'seed-pixel-' . $name;
                if (isset($metadata['sizes'][$key]) && (!is_array($old) || !isset($old['files'][$name]) || $metadata['sizes'][$key]['file'] !== basename($old['files'][$name]['path']))) {
                    return self::error($id, new WP_Error('pixel_size_collision', 'An existing image size uses this plugin key without proven ownership.'));
                }
            }
            if (is_array($old)) {
                $retired = WP_Seed_Pixel_Store::retire($id, $old);
                if (is_wp_error($retired)) {
                    return self::error($id, $retired);
                }
            }
            $free = disk_free_space(dirname($master));
            if ($free === false || $free < max(16777216, filesize($master) * 6)) {
                return self::error($id, new WP_Error('pixel_disk_budget', 'Insufficient staging disk space.'));
            }
            $dir = WP_Seed_Pixel_Store::workspace($id);
            if (is_wp_error($dir)) {
                $error = $dir;
                $dir = null;
                return self::error($id, $error);
            }
            $generation = bin2hex(random_bytes(8));
            $files = array();
            $sizes = array();
            do_action('wp_seed_pixel_before', $id, $preset_name);
            // Scope the JPEG output contract; do not alter other WordPress jobs.
            add_filter('image_editor_output_format', array('WP_Seed_Pixel_Files', 'output_map'), PHP_INT_MAX);
            try {
                foreach ($preset['sizes'] as $name => $size) {
                    $stage = $dir . '/' . $name . '.jpg';
                    $decision = $adaptive ? WP_Seed_Pixel_Adaptive::select($master, $size, $stage) : null;
                    if (is_wp_error($decision)) {
                        return self::error($id, $decision);
                    }
                    if ($decision && $decision['kind'] === 'master') {
                        $files[$name] = array_merge($decision, array('path' => $master, 'sha256' => $master_hash, 'bytes' => filesize($master), 'transfer_saving_bytes_vs_master' => 0, 'transfer_saving_percent_vs_master' => 0));
                        $sizes['seed-pixel-' . $name] = array('file' => basename($master), 'width' => $decision['width'], 'height' => $decision['height'], 'mime-type' => 'image/jpeg', 'filesize' => filesize($master));
                        continue;
                    }
                    if (!$adaptive) {
                        $editor = wp_get_image_editor($master);
                        if (is_wp_error($editor)) {
                            return self::error($id, $editor);
                        }
                        $rotated = $editor->maybe_exif_rotate();
                        if (is_wp_error($rotated)) {
                            return self::error($id, $rotated);
                        }
                        $dimensions = $editor->get_size();
                        $resized = ($dimensions['width'] > $size['width'] || $dimensions['height'] > $size['height']) ? $editor->resize($size['width'], $size['height'], false) : true;
                        if (is_wp_error($resized)) {
                            return self::error($id, $resized);
                        }
                        // WordPress resizing can reset quality; enforce it after resizing.
                        $quality = $editor->set_quality($size['quality']);
                        if (is_wp_error($quality) || $editor->get_quality() !== $size['quality']) {
                            return self::error($id, new WP_Error('pixel_quality', 'The requested JPEG quality was not accepted by the image editor.'));
                        }
                        $saved = $editor->save($stage, 'image/jpeg');
                        if (is_wp_error($saved)) {
                            return self::error($id, $saved);
                        }
                        $engine = get_class($editor);
                        unset($editor);
                    } else {
                        $saved = array('path' => $stage);
                        $size['quality'] = $decision['quality'];
                        $engine = $decision['engine'];
                    }
                    $output = @getimagesize($stage);
                    if (!isset($saved['path']) || wp_normalize_path($saved['path']) !== wp_normalize_path($stage) || !$output || $output[2] !== IMAGETYPE_JPEG || filesize($stage) < 1 || $output[0] > $size['width'] || $output[1] > $size['height'] || $output[0] * $output[1] > $info[0] * $info[1]) {
                        return self::error($id, new WP_Error('pixel_invalid_output', 'The image editor did not produce a valid, bounded JPEG.'));
                    }
                    $exif = function_exists('exif_read_data') ? @exif_read_data($stage) : array();
                    $markers = WP_Seed_Pixel_Files::markers($stage);
                    if (is_wp_error($markers) || $markers['private'] || $markers['icc'] || is_array($exif) && (isset($exif['GPSLatitude']) || isset($exif['GPSLongitude']) || isset($exif['Orientation']) && $exif['Orientation'] !== 1)) {
                        return self::error($id, new WP_Error('pixel_metadata_output', 'Sensitive metadata or unapplied orientation remained in the derivative.'));
                    }
                    $dest = dirname($master) . '/seed-pixel-' . $id . '-' . $generation . '-' . $name . '.jpg';
                    $safe = WP_Seed_Pixel_Files::path($dest, false);
                    if (is_wp_error($safe) || file_exists($dest)) {
                        return self::error($id, new WP_Error('pixel_destination', 'Unsafe or occupied output destination.'));
                    }
                    $files[$name] = array_merge($decision ?: array('kind' => 'derived', 'candidates' => 1, 'reason' => 'Explicit legacy/custom fixed preset.'), array('path' => $safe, 'sha256' => hash_file('sha256', $stage), 'bytes' => filesize($stage), 'width' => $output[0], 'height' => $output[1], 'quality' => $size['quality'], 'engine' => $engine, 'transfer_saving_bytes_vs_master' => filesize($master) - filesize($stage), 'transfer_saving_percent_vs_master' => round(100 * (1 - filesize($stage) / filesize($master)), 2)));
                    $sizes['seed-pixel-' . $name] = array('file' => basename($safe), 'width' => $output[0], 'height' => $output[1], 'mime-type' => 'image/jpeg', 'filesize' => filesize($stage));
                    unset($editor);
                }
            } finally {
                remove_filter('image_editor_output_format', array('WP_Seed_Pixel_Files', 'output_map'), PHP_INT_MAX);
            }
            $result = array('status' => 'success', 'attachment_id' => $id, 'preset' => $preset_name, 'strategy' => $adaptive ? 'bounded-adaptive' : 'fixed', 'algorithm_version' => $adaptive ? WP_Seed_Pixel_Adaptive::VERSION : 'legacy-fixed-1', 'generation' => $generation, 'master_sha256' => $master_hash, 'master_bytes' => filesize($master), 'config_sha256' => $config_hash, 'files' => $files, 'added_disk_bytes' => array_sum(array_column(array_filter($files, function ($f) { return $f['kind'] !== 'master'; }), 'bytes')), 'candidates' => array_sum(array_column($files, 'candidates')), 'seconds' => round(microtime(true) - $start, 4), 'created' => time());
            $journal = WP_Seed_Pixel_Store::journal($dir, array('generation' => $generation, 'files' => $files, 'manifest' => $result));
            if (is_wp_error($journal)) {
                return self::error($id, $journal);
            }
            do_action('wp_seed_pixel_checkpoint', 'before_publish', $id);
            foreach ($files as $name => $file) {
                if (isset($file['kind']) && $file['kind'] === 'master') {
                    continue;
                }
                if (!rename($dir . '/' . $name . '.jpg', $file['path'])) {
                    return self::error($id, new WP_Error('pixel_publish', 'Atomic derivative publication failed.'));
                }
                $published[] = $file;
                @chmod($file['path'], 0644);
            }
            do_action('wp_seed_pixel_checkpoint', 'before_commit', $id);
            clearstatcache(true, $master);
            if (!hash_equals($master_hash, hash_file('sha256', $master)) || get_post_mime_type($id) !== 'image/jpeg' || wp_normalize_path(wp_get_original_image_path($id)) !== $master || wp_normalize_path(get_attached_file($id)) !== $attached || !self::valid_files($files)) {
                return self::error($id, new WP_Error('pixel_source_changed', 'The source or published derivatives changed during processing.'));
            }
            $next = $metadata;
            if (is_array($old)) {
                foreach ($old['files'] as $name => $file) {
                    if (isset($next['sizes']['seed-pixel-' . $name]) && $next['sizes']['seed-pixel-' . $name]['file'] === basename($file['path'])) {
                        unset($next['sizes']['seed-pixel-' . $name]);
                    }
                }
            }
            $next['sizes'] = array_merge(isset($next['sizes']) ? $next['sizes'] : array(), $sizes);
            $saved = WP_Seed_Pixel_Store::commit($id, $metadata, $next);
            if (is_wp_error($saved)) {
                return self::error($id, $saved);
            }
            $committed = true;
            $preserve_journal = true;
            do_action('wp_seed_pixel_checkpoint', 'after_native_commit', $id);
            $saved_manifest = WP_Seed_Pixel_Store::save_manifest($id, $result);
            if (is_wp_error($saved_manifest)) {
                return self::error($id, $saved_manifest);
            }
            $preserve_journal = false;
            update_post_meta($id, '_seed_pixel_job', array('status' => 'success', 'time' => time()));
            $history = WP_Seed_Pixel_Store::history($id);
            $result['retained_previous_generations'] = is_wp_error($history) ? null : count($history);
            do_action('wp_seed_pixel_after', $id, $result);
            return $result;
        } catch (Throwable $exception) {
            if ($committed && !$preserve_journal) {
                $result['warning'] = 'A completion callback failed after the valid metadata commit.';
                return $result;
            }
            return self::error($id, new WP_Error('pixel_exception', 'Processing stopped safely; retry after checking the local environment.'));
        } finally {
            if (!$committed && is_string($master)) {
                foreach ($published as $file) {
                    WP_Seed_Pixel_Files::owned_delete($file, $id, $master);
                    if (is_file($file['path'])) {
                        $preserve_journal = true;
                    }
                }
            }
            if (is_string($dir) && !$preserve_journal) {
                WP_Seed_Pixel_Store::clean_workspace($dir);
            }
            WP_Seed_Pixel_Files::unlock($lock);
        }
    }

    private static function valid_files($files) {
        if (!is_array($files) || !$files) {
            return false;
        }
        foreach ($files as $file) {
            $safe = isset($file['path']) ? WP_Seed_Pixel_Files::path($file['path']) : new WP_Error('pixel_path');
            if (is_wp_error($safe) || !isset($file['sha256']) || !hash_equals($file['sha256'], hash_file('sha256', $safe))) {
                return false;
            }
        }
        return true;
    }

    private static function error($id, WP_Error $error) {
        update_post_meta($id, '_seed_pixel_job', array('status' => 'failed', 'code' => $error->get_error_code(), 'message' => $error->get_error_message(), 'time' => time()));
        do_action('wp_seed_pixel_error', $id, $error);
        return $error;
    }
}
