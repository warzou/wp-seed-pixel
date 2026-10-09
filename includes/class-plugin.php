<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Plugin {
    public static function settings() {
        return array_merge(array('automatic' => false, 'preset' => 'balanced', 'cleanup_on_uninstall' => false), (array) get_option('wp_seed_pixel_settings', array()));
    }

    public static function boot() {
        add_action('init', static function () {
            load_plugin_textdomain('wp-seed-pixel', false, dirname(plugin_basename(WP_SEED_PIXEL_FILE)) . '/languages');
        });
        WP_Seed_Pixel_Admin::boot();
        WP_Seed_Pixel_Storage_Admin::boot();
        WP_Seed_Pixel_Jobs_Admin::boot();
        WP_Seed_Pixel_Future_Uploads::boot();
        add_action('added_post_meta', array(__CLASS__, 'uploaded'), 10, 4);
        add_action('wp_seed_pixel_auto', array(__CLASS__, 'automatic'));
        add_action('delete_attachment', array('WP_Seed_Pixel_Store', 'cleanup'));
    }

    public static function uploaded($meta_id, $id, $key, $value) {
        if (class_exists('WP_Seed_Pixel_Metadata_Uploads') && WP_Seed_Pixel_Metadata_Uploads::blocks_optimizer($id)) { return; }
        $future = WP_Seed_Pixel_Future_Uploads::settings();
        if ($future['mode'] !== 'off' && in_array('jpeg', $future['formats'], true)) { return; }
        if ($key !== '_wp_attachment_metadata' || !self::settings()['automatic'] || get_post_mime_type($id) !== 'image/jpeg' || wp_next_scheduled('wp_seed_pixel_auto', array((int) $id))) {
            return;
        }
        update_post_meta($id, '_seed_pixel_job', array('status' => 'pending', 'time' => time()));
        wp_schedule_single_event(time() + 10, 'wp_seed_pixel_auto', array((int) $id));
    }

    public static function automatic($id) {
        if (class_exists('WP_Seed_Pixel_Metadata_Uploads') && WP_Seed_Pixel_Metadata_Uploads::blocks_optimizer($id)) { return; }
        $future = WP_Seed_Pixel_Future_Uploads::settings();
        if ($future['mode'] !== 'off' && in_array('jpeg', $future['formats'], true)) { return; }
        $settings = self::settings();
        if ($settings['automatic']) {
            $result = wp_seed_pixel_optimize((int) $id, $settings['preset']);
            if (is_wp_error($result) && $result->get_error_code() === 'pixel_locked') {
                $attempts = (int) get_post_meta($id, '_seed_pixel_auto_attempts', true);
                if ($attempts < 2) {
                    update_post_meta($id, '_seed_pixel_auto_attempts', $attempts + 1);
                    wp_schedule_single_event(time() + 30, 'wp_seed_pixel_auto', array((int) $id));
                }
            } else {
                delete_post_meta($id, '_seed_pixel_auto_attempts');
            }
        }
    }

    public static function activate($network_wide = false) {
        if ($network_wide) {
            deactivate_plugins(plugin_basename(WP_SEED_PIXEL_FILE));
            wp_die(esc_html__('Network activation is not supported. Activate separately on each site.', 'wp-seed-pixel'));
        }
        add_option('wp_seed_pixel_settings', array('automatic' => false, 'preset' => 'balanced', 'cleanup_on_uninstall' => false), '', false);
        if (class_exists('WP_Seed_Pixel_Metadata_Uploads')) { WP_Seed_Pixel_Metadata_Uploads::initialize(); }
    }

    public static function deactivate() {
        wp_unschedule_hook('wp_seed_pixel_auto');
        wp_unschedule_hook('wp_seed_pixel_future_job');
        $batch = WP_Seed_Pixel_Batch::current();
        if ($batch && $batch['status'] === 'running') {
            WP_Seed_Pixel_Batch::pause(true);
        }
    }
}
