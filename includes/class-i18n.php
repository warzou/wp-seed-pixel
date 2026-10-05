<?php
defined('ABSPATH') || exit;

/** Presentation boundary: stored engine evidence remains untranslated. */
final class WP_Seed_Pixel_I18n {
    public static function message($message) {
        $catalogue = require __DIR__ . '/i18n-messages.php';
        return isset($catalogue[$message]) ? $catalogue[$message] : $message;
    }

    public static function counters() {
        $result = array();
        foreach (array(0, 1, 2) as $number) {
            $result[$number] = array(
                'optimized' => _n('%d image optimized', '%d images optimized', $number, 'wp-seed-pixel'),
                'current' => _n('%d image already up to date', '%d images already up to date', $number, 'wp-seed-pixel'),
                'skipped' => _n('%d image kept without changes', '%d images kept without changes', $number, 'wp-seed-pixel'),
                'failed' => _n('%d image needs attention', '%d images need attention', $number, 'wp-seed-pixel'),
            );
        }
        return $result;
    }
}
