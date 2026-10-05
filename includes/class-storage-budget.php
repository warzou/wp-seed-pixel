<?php
defined('ABSPATH') || exit;

final class WP_Seed_Pixel_Storage_Budget {
    const OPTION = 'wp_seed_pixel_storage_limits';

    public static function validate(array $input) {
        $defaults = array('provider_quota_bytes' => 0, 'operational_ceiling_bytes' => 0,
            'uncertainty_reserve_bytes' => 0, 'safety_reserve_bytes' => 0, 'max_usage_age' => 60);
        if (array_diff_key($input, $defaults)) { return new WP_Error('POLICY_INVALID'); }
        $r = array_merge($defaults, $input);
        foreach ($r as $value) { if (!is_int($value) || $value < 0) { return new WP_Error('POLICY_INVALID'); } }
        if ($r['max_usage_age'] < 1 || $r['max_usage_age'] > 3600
            || ($r['provider_quota_bytes'] && $r['operational_ceiling_bytes'] > $r['provider_quota_bytes'])) { return new WP_Error('POLICY_INVALID'); }
        return $r;
    }

    public static function settings() {
        $input = get_option(self::OPTION, array());
        return is_array($input) ? self::validate($input) : new WP_Error('POLICY_INVALID');
    }

    public static function usage(array $settings) {
        // Trusted adapters return a complete upper-bound account scope, not just media bytes.
        $u = apply_filters('wp_seed_pixel_storage_usage', null, $settings);
        if (!is_array($u) || ($u['complete'] ?? false) !== true || ($u['live'] ?? false) !== true || ($u['includes_recovery'] ?? false) !== true
            || !is_int($u['bytes'] ?? null) || $u['bytes'] < 0 || !is_int($u['measured_at'] ?? null)
            || $u['measured_at'] > time() || time() - $u['measured_at'] > $settings['max_usage_age']
            || !is_int($u['uncertainty_bytes'] ?? null) || $u['uncertainty_bytes'] < 0) { return new WP_Error('QUOTA_UNKNOWN'); }
        return array('bytes' => $u['bytes'], 'measured_at' => $u['measured_at'],
            'uncertainty_bytes' => max($u['uncertainty_bytes'], $settings['uncertainty_reserve_bytes']));
    }

    private static function sum(array $values) {
        $n = 0;
        foreach ($values as $v) { if (!is_int($v) || $v < 0 || $n > PHP_INT_MAX - $v) { return new WP_Error('QUOTA_UNKNOWN'); } $n += $v; }
        return $n;
    }

    public static function status($extra_bytes = 0, $directory = null) {
        if (!is_int($extra_bytes) || $extra_bytes < 0) { return new WP_Error('POLICY_INVALID'); }
        $s = self::settings(); if (is_wp_error($s)) { return $s; }
        $r = array('state' => 'OK', 'reason' => '', 'settings' => $s, 'usage' => null, 'peak_bytes' => null, 'extra_bytes' => $extra_bytes);
        if ($s['operational_ceiling_bytes']) {
            $u = self::usage($s);
            if (is_wp_error($u)) { $r['state'] = 'UNKNOWN'; $r['reason'] = 'QUOTA_UNKNOWN'; return $r; }
            $r['usage'] = $u;
            $peak = self::sum(array($u['bytes'], $u['uncertainty_bytes'], $s['safety_reserve_bytes'], $extra_bytes));
            if (is_wp_error($peak)) { $r['state'] = 'UNKNOWN'; $r['reason'] = 'QUOTA_UNKNOWN'; return $r; }
            $r['peak_bytes'] = $peak;
            if ($peak >= $s['operational_ceiling_bytes']) { $r['state'] = 'BLOCKED'; $r['reason'] = 'CEILING_EXCEEDED'; return $r; }
            if ($s['operational_ceiling_bytes'] - $peak <= $s['safety_reserve_bytes']) { $r['state'] = 'WARNING'; }
        }
        if ($directory !== null) {
            $free = disk_free_space($directory);
            $need = self::sum(array($extra_bytes, $s['safety_reserve_bytes']));
            if ($free === false || is_wp_error($need) || $free < $need) { $r['state'] = 'BLOCKED'; $r['reason'] = 'LOW_DISK'; }
        }
        return $r;
    }

    public static function admit($extra_bytes, $directory) {
        if (!WP_Seed_Pixel_Authority::valid(0) || !WP_Seed_Pixel_Authority::valid_all()) { return new WP_Error('LOCKED'); }
        $r = self::status($extra_bytes, $directory);
        if (is_wp_error($r)) { return $r; }
        return in_array($r['state'], array('OK', 'WARNING'), true) ? $r : new WP_Error($r['reason']);
    }
}
