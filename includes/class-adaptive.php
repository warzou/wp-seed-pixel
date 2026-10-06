<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Adaptive {
    const VERSION = 'bounded-rgb-3';
    const SSIM_FLOOR = 0.985;
    const PSNR_FLOOR = 35.0;
    const THUMB_SSIM_FLOOR = 0.960;
    const THUMB_PSNR_FLOOR = 30.0;

    public static function select($master, array $size, $stage, $allow_reuse = true) {
        $info = getimagesize($master);
        $markers = $info[2] === IMAGETYPE_JPEG ? WP_Seed_Pixel_Files::markers($master) : array('private' => true, 'icc' => false);
        if (is_wp_error($markers)) {
            return $markers;
        }
        $clean = $allow_reuse && !$markers['private'] && !$markers['icc'];
        $bounded = $info[0] <= $size['width'] && $info[1] <= $size['height'];
        $target = $size['width'] <= 640 ? 50000 : 500000;
        if ($clean && $bounded && filesize($master) <= $target) {
            return self::reuse($master, $info, 'Source already bounded, light and metadata-safe.', 0);
        }
        $metric_available = function_exists('imagecreatefromjpeg') && ($info[2] !== IMAGETYPE_PNG || function_exists('imagecreatefrompng'));
        $qualities = $metric_available ? array(78, 86, 94) : array(94);
        $attempts = 0;
        foreach ($qualities as $quality) {
            ++$attempts;
            // Each candidate starts from MASTER or its lossless sRGB reference.
            $editor = wp_get_image_editor($master);
            if (is_wp_error($editor)) {
                return $editor;
            }
            $rotated = $editor->maybe_exif_rotate();
            if (is_wp_error($rotated)) {
                return $rotated;
            }
            $dim = $editor->get_size();
            $resized = ($dim['width'] > $size['width'] || $dim['height'] > $size['height']) ? $editor->resize($size['width'], $size['height'], false) : true;
            if (is_wp_error($resized)) {
                return $resized;
            }
            $accepted = $editor->set_quality($quality);
            if (is_wp_error($accepted) || $editor->get_quality() !== $quality) {
                return new WP_Error('pixel_quality', 'Image editor rejected the selected JPEG quality.');
            }
            $saved = $editor->save($stage, 'image/jpeg');
            $engine = get_class($editor);
            unset($editor);
            if (is_wp_error($saved)) {
                return $saved;
            }
            if (empty($saved['path']) || wp_normalize_path($saved['path']) !== wp_normalize_path($stage) || !is_file($stage)) {
                return new WP_Error('pixel_candidate_path', 'Unexpected candidate output path.');
            }
            clearstatcache(true, $stage);
            $score = $metric_available ? self::metric($master, $stage) : null;
            if (is_wp_error($score)) {
                return $score;
            }
            $qualified = $score === null || self::accepts($score, $size['width'] <= 640);
            if (!$qualified) {
                continue;
            }
            $gain = 1 - filesize($stage) / filesize($master);
            if ($clean && $gain < 0.05) {
                unlink($stage);
                return self::reuse($master, $info, 'No material byte saving at the quality floor; source retained.', $attempts);
            }
            return array('kind' => 'derived', 'quality' => $quality, 'engine' => $engine, 'candidates' => $attempts, 'metric' => $score, 'reason' => !$clean && $gain < 0.05 ? 'Metadata/orientation sanitization required; byte increase disclosed.' : ($score === null ? 'Metric unavailable: conservative fixed Q94 fallback.' : 'First bounded candidate satisfying local quality and byte gates.'), 'soft_target_exceeded' => filesize($stage) > $target);
        }
        if ($clean) {
            if (is_file($stage)) {
                unlink($stage);
            }
            return self::reuse($master, $info, 'No candidate meets the quality floor; source retained, dimension target may be exceeded.', $attempts);
        }
        return new WP_Error('pixel_quality_floor', 'No sanitized candidate meets the quality floor. MASTER and existing mappings were retained.');
    }

    public static function accepts(array $score, $thumbnail = false) {
        return isset($score['ssim'], $score['psnr']) && is_finite($score['ssim']) && is_finite($score['psnr']) && $score['ssim'] >= ($thumbnail ? self::THUMB_SSIM_FLOOR : self::SSIM_FLOOR) && $score['psnr'] >= ($thumbnail ? self::THUMB_PSNR_FLOOR : self::PSNR_FLOOR);
    }

    private static function reuse($master, array $info, $reason, $attempts) {
        return array('kind' => 'master', 'quality' => null, 'engine' => 'source', 'candidates' => $attempts, 'metric' => null, 'reason' => $reason, 'soft_target_exceeded' => filesize($master) > 500000, 'width' => $info[0], 'height' => $info[1]);
    }

    public static function metric($master, $candidate) {
        $info = @getimagesize($master);
        $source = $info && $info[2] === IMAGETYPE_PNG ? @imagecreatefrompng($master) : @imagecreatefromjpeg($master);
        $out = @imagecreatefromjpeg($candidate);
        if (!$source || !$out) {
            return new WP_Error('pixel_metric_decode', 'Local quality measurement failed.');
        }
        $reference = null;
        try {
            $exif = function_exists('exif_read_data') ? @exif_read_data($master) : array();
            $o = is_array($exif) && isset($exif['Orientation']) ? $exif['Orientation'] : 1;
            if (in_array($o, array(2, 4, 5, 7), true)) {
                imageflip($source, $o === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
            }
            $angles = array(3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90);
            if (isset($angles[$o])) {
                $rotated = imagerotate($source, $angles[$o], 0);
                if (!$rotated) {
                    return new WP_Error('pixel_metric_orientation', 'Reference rotation failed.');
                }
                unset($source);
                $source = $rotated;
            }
            $w = imagesx($out);
            $h = imagesy($out);
            $reference = imagecreatetruecolor($w, $h);
            imagecopyresampled($reference, $source, 0, 0, 0, 0, $w, $h, imagesx($source), imagesy($source));
            $scores = array();
            $mse = 0;
            $samples = 0;
            $tile = min(8, $w, $h);
            for ($gy = 0; $gy < 8; ++$gy) {
                for ($gx = 0; $gx < 8; ++$gx) {
                    $x = (int) round($gx * ($w - $tile) / 7);
                    $y = (int) round($gy * ($h - $tile) / 7);
                    $a = $b = $aa = $bb = $ab = 0;
                    for ($dy = 0; $dy < $tile; ++$dy) {
                        for ($dx = 0; $dx < $tile; ++$dx) {
                            $p = imagecolorat($reference, $x + $dx, $y + $dy);
                            $q = imagecolorat($out, $x + $dx, $y + $dy);
                            $pa = $pb = 0;
                            foreach (array(16 => 0.299, 8 => 0.587, 0 => 0.114) as $shift => $weight) {
                                $v = ($p >> $shift) & 255;
                                $z = ($q >> $shift) & 255;
                                $mse += ($v - $z) ** 2;
                                ++$samples;
                                $pa += $v * $weight;
                                $pb += $z * $weight;
                            }
                            $a += $pa; $b += $pb; $aa += $pa * $pa; $bb += $pb * $pb; $ab += $pa * $pb;
                        }
                    }
                    $n = $tile * $tile;
                    $ma = $a / $n; $mb = $b / $n;
                    $va = max(0, $aa / $n - $ma * $ma); $vb = max(0, $bb / $n - $mb * $mb); $cov = $ab / $n - $ma * $mb;
                    $scores[] = ((2 * $ma * $mb + 6.5025) * (2 * $cov + 58.5225)) / (($ma * $ma + $mb * $mb + 6.5025) * ($va + $vb + 58.5225));
                }
            }
            return array('method' => '64-stratified-8px-luma-blocks+RGB-PSNR', 'ssim' => round(array_sum($scores) / count($scores), 6), 'psnr' => $mse === 0 ? 99.0 : round(10 * log10(65025 / ($mse / $samples)), 3));
        } finally {
            unset($source, $out);
            if ($reference) {
                unset($reference);
            }
        }
    }
}
