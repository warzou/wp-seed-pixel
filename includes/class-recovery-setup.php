<?php
defined('ABSPATH') || exit;

/** Prepares storage only. Image effects remain owned by the existing coordinator. */
final class WP_Seed_Pixel_Recovery_Setup {
    const OPTION = 'wp_seed_pixel_recovery_storage';

    private static function key() { return hash('sha256', realpath(ABSPATH) . ':' . $GLOBALS['wpdb']->prefix); }

    private static function plain_path($path) {
        if (!is_string($path) || $path === '' || $path[0] !== '/' || strpos($path, "\0") !== false
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) { return false; }
        $cursor = '';
        foreach (explode('/', trim($path, '/')) as $part) {
            $cursor .= '/' . $part;
            if (is_link($cursor)) { return false; }
        }
        return realpath($path) === rtrim($path, '/');
    }

    private static function inside($path, $root) { return $path === $root || str_starts_with($path, $root . '/'); }

    private static function safe_parent($parent, $web, array $uploads) {
        return $parent !== '/' && self::plain_path($parent) && !self::inside($parent, $web)
            && !self::inside($parent, $uploads['path']) && is_writable($parent)
            && (fileperms($parent) & 0022) === 0 && stat($parent)['uid'] === posix_geteuid();
    }

    private static function private_parent($web, array $uploads) {
        $parent = dirname($web); $mismatch = false;
        // A shared-host sites/ directory may be group-writable; never relax its permissions.
        for ($depth = 0; $depth < 2 && $parent !== '/'; $depth++, $parent = dirname($parent)) {
            if (!self::safe_parent($parent, $web, $uploads)) { continue; }
            if (stat($parent)['dev'] === $uploads['dev']) { return $parent; }
            $mismatch = true;
        }
        return new WP_Error($mismatch ? 'FILESYSTEM_MISMATCH' : 'PRIVATE_PARENT_UNAVAILABLE');
    }

    private static function uploads() {
        $path = wp_upload_dir(null, false)['basedir'];
        if (!is_string($path) || $path === '' || $path[0] !== '/' || strpos($path, "\0") !== false
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)) { return new WP_Error('UNSAFE_LOCATION'); }
        $anchor = $path;
        while (!file_exists($anchor) && !is_link($anchor) && $anchor !== '/') { $anchor = dirname($anchor); }
        if (!self::plain_path($anchor) || !is_dir($anchor) || (file_exists($path) && !self::plain_path($path))) { return new WP_Error('UNSAFE_LOCATION'); }
        return array('path' => $path, 'dev' => stat($anchor)['dev']);
    }

    public static function candidate() {
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || !function_exists('fsync') || is_multisite()) { return new WP_Error('HOST_UNSUPPORTED'); }
        $web = isset($_SERVER['DOCUMENT_ROOT']) ? rtrim($_SERVER['DOCUMENT_ROOT'], '/') : '';
        $wp = rtrim(ABSPATH, '/'); $u = self::uploads();
        if (is_wp_error($u)) { return $u; } $upload = $u['path'];
        if (!self::plain_path($web) || !self::plain_path($wp) || !self::inside($wp, $web)) { return new WP_Error('WEBROOT_UNKNOWN'); }
        $parent = self::private_parent($web, $u);
        if (is_wp_error($parent)) { return $parent; }
        return array('path' => $parent . '/.wp-seed-pixel-' . substr(self::key(), 0, 24), 'webroot' => $web, 'site' => self::key());
    }

    public static function verify($state = null) {
        $s = $state === null ? get_option(self::OPTION, null) : $state;
        if (!is_array($s)) { return new WP_Error('NOT_PREPARED'); }
        if (PHP_OS_FAMILY !== 'Linux' || !function_exists('posix_geteuid') || !function_exists('fsync') || is_multisite()) { return new WP_Error('HOST_UNSUPPORTED'); }
        foreach (array('path', 'webroot', 'site', 'token', 'dev', 'ino', 'uid') as $key) { if (!isset($s[$key])) { return new WP_Error('SETUP_INTERRUPTED'); } }
        if (!is_string($s['token']) || !preg_match('/^[a-f0-9]{48}$/D', $s['token']) || $s['site'] !== self::key()) { return new WP_Error('IDENTITY_CHANGED'); }
        $path = $s['path']; $wp = realpath(ABSPATH); $u = self::uploads();
        if (is_wp_error($u)) { return $u; } $uploads = $u['path'];
        $live_web = empty($_SERVER['DOCUMENT_ROOT']) ? $s['webroot'] : rtrim($_SERVER['DOCUMENT_ROOT'], '/');
        if (!self::plain_path($path) || !self::plain_path($s['webroot']) || !self::plain_path($live_web) || !$wp || !$uploads
            || !self::inside($wp, $s['webroot']) || !self::inside($wp, $live_web)
            || self::inside($path, $s['webroot']) || self::inside($path, $live_web) || self::inside($path, $wp) || self::inside($path, $uploads)
            || !in_array(dirname($path), array(dirname($s['webroot']), dirname(dirname($s['webroot']))), true)
            || basename($path) !== '.wp-seed-pixel-' . substr(self::key(), 0, 24)
            || !self::safe_parent(dirname($path), $s['webroot'], $u)) { return new WP_Error('UNSAFE_LOCATION'); }
        clearstatcache(true, $path); $st = @lstat($path);
        if (!$st || !is_dir($path) || ($st['mode'] & 0777) !== 0700 || $st['uid'] !== posix_geteuid() || !is_writable($path)
            || $st['dev'] !== $s['dev'] || $st['ino'] !== $s['ino'] || $st['uid'] !== $s['uid']) { return new WP_Error('IDENTITY_CHANGED'); }
        if ($st['dev'] !== $u['dev']) { return new WP_Error('FILESYSTEM_MISMATCH'); }
        $marker = $path . '/.pixel-owner.json'; $ms = @lstat($marker);
        if (!$ms || is_link($marker) || !is_file($marker) || $ms['nlink'] !== 1 || $ms['uid'] !== $st['uid'] || ($ms['mode'] & 0777) !== 0600 || $ms['size'] > 4096) { return new WP_Error('IDENTITY_CHANGED'); }
        $m = json_decode(file_get_contents($marker), true);
        if ($m !== array('version' => 1, 'site' => $s['site'], 'token' => $s['token'], 'dev' => $s['dev'], 'ino' => $s['ino'], 'uid' => $s['uid'])) { return new WP_Error('IDENTITY_CHANGED'); }
        return $s;
    }

    private static function remember(array $s) {
        update_option(self::OPTION, $s, false);
        return get_option(self::OPTION) === $s && WP_Seed_Pixel_Authority::valid(0);
    }

    public static function prepare() {
        if (!current_user_can('manage_options')) { return new WP_Error('PERMISSION_DENIED'); }
        if (defined('WP_SEED_PIXEL_RECOVERY_ROOT') && get_option(self::OPTION, null) === null) { return new WP_Error('HOST_MANAGED'); }
        if (defined('WP_SEED_PIXEL_STORAGE_ENABLED') && WP_SEED_PIXEL_STORAGE_ENABLED !== true) { return new WP_Error('HOST_DISABLED'); }
        $lock = WP_Seed_Pixel_Files::lock(0); if (is_wp_error($lock)) { return $lock; }
        try {
            $existing = get_option(self::OPTION, null);
            if ($existing !== null) {
                $s = self::verify($existing);
                if (is_wp_error($s)) { return $s; }
            } else {
                $s = self::candidate(); if (is_wp_error($s)) { return $s; }
                if (file_exists($s['path']) || is_link($s['path'])) { return new WP_Error('FOREIGN_DIRECTORY'); }
                $s['token'] = bin2hex(random_bytes(24)); $s['state'] = 'preparing';
                if (!self::remember($s)) { return new WP_Error('STORE_FAILED'); }
                if (!mkdir($s['path'], 0700)) { delete_option(self::OPTION); return new WP_Error('CREATE_FAILED'); }
                $st = lstat($s['path']); $s['dev'] = $st['dev']; $s['ino'] = $st['ino']; $s['uid'] = $st['uid'];
                // Persist identity before writing the marker; an ambiguous interruption fails closed.
                if (!self::remember($s)) { return new WP_Error('STORE_FAILED'); }
                $marker = array('version' => 1, 'site' => $s['site'], 'token' => $s['token'], 'dev' => $s['dev'], 'ino' => $s['ino'], 'uid' => $s['uid']);
                $old = umask(0077);
                try { $f = @fopen($s['path'] . '/.pixel-owner.json', 'xb'); } finally { umask($old); }
                if (!$f) { return new WP_Error('CREATE_FAILED'); }
                $json = wp_json_encode($marker);
                try { $ok = fwrite($f, $json) === strlen($json) && fflush($f) && fsync($f); } finally { fclose($f); }
                if (!$ok || !WP_Seed_Pixel_Master_Storage::sync_directory($s['path']) || is_wp_error(self::verify($s))) { return new WP_Error('VERIFY_FAILED'); }
            }
            $probe = $s['path'] . '/.probe-' . bin2hex(random_bytes(12));
            $old = umask(0077);
            try { $f = @fopen($probe, 'xb'); } finally { umask($old); }
            if (!$f) { return new WP_Error('WRITE_FAILED'); }
            try {
                $bytes = random_bytes(32);
                $ok = fwrite($f, $bytes) === 32 && fflush($f) && fsync($f);
                $ok = $ok && file_get_contents($probe) === $bytes;
            } finally { fclose($f); $removed = unlink($probe); }
            if (!$ok || !$removed || is_wp_error(self::verify($s))) { return new WP_Error('WRITE_FAILED'); }
            $s['state'] = 'ready';
            return self::remember($s) ? $s : new WP_Error('STORE_FAILED');
        } finally { WP_Seed_Pixel_Files::unlock($lock); }
    }

    public static function apply() {
        // Explicit host settings always win, including an explicit host refusal.
        if (defined('WP_SEED_PIXEL_STORAGE_ENABLED') || defined('WP_SEED_PIXEL_RECOVERY_ROOT')) { return; }
        $s = self::verify();
        if (is_wp_error($s) || ($s['state'] ?? '') !== 'ready') { return; }
        define('WP_SEED_PIXEL_RECOVERY_ROOT', $s['path']);
        define('WP_SEED_PIXEL_STORAGE_ENABLED', true);
    }

    public static function gate() {
        $s = get_option(self::OPTION, null);
        if ($s === null) { return true; } // Existing, explicitly configured hosts keep their accepted contract.
        return !is_wp_error(self::verify($s)) && ($s['state'] ?? '') === 'ready'
            && defined('WP_SEED_PIXEL_RECOVERY_ROOT') && WP_SEED_PIXEL_RECOVERY_ROOT === $s['path'];
    }

    public static function png_requirements() {
        $missing = array();
        $s = get_option(self::OPTION, null);
        if ($s !== null && is_wp_error($v = self::verify($s))) { $missing['recovery'] = self::message($v->get_error_code()); }
        elseif (!WP_Seed_Pixel_Quarantine::enabled()) { $missing['recovery'] = __('Prepare the protected storage for originals first.', 'wp-seed-pixel'); }
        $f = WP_Seed_Pixel_Future_Uploads::settings();
        if ($f['capacity_bytes'] < 1) { $missing['capacity'] = __('Choose the maximum temporary space per operation.', 'wp-seed-pixel'); }
        $limits = WP_Seed_Pixel_Storage_Budget::settings();
        if (is_wp_error($limits)) { $missing['ceiling'] = __('Review the invalid site storage limit.', 'wp-seed-pixel'); }
        elseif ($limits['operational_ceiling_bytes'] > 0) {
            $status = WP_Seed_Pixel_Storage_Budget::status();
            if (is_wp_error($status) || !in_array($status['state'], array('OK', 'WARNING'), true)) {
                $missing['ceiling'] = __('The site limit needs a current complete hosting measurement or more available margin.', 'wp-seed-pixel');
            }
        }
        if ($s !== null && get_option('wp_seed_pixel_storage_policy_confirmed', '') !== 'chosen') { $missing['policy'] = __('Choose whether to set an additional site-wide storage ceiling.', 'wp-seed-pixel'); }
        return $missing;
    }

    public static function message($code) {
        $messages = array(
            'NOT_PREPARED' => __('Storage is not prepared yet.', 'wp-seed-pixel'),
            'HOST_UNSUPPORTED' => __('This host cannot prove safe private recovery storage. Optimization remains unavailable.', 'wp-seed-pixel'),
            'WEBROOT_UNKNOWN' => __('The public web root could not be verified. No original will be stored.', 'wp-seed-pixel'),
            'PRIVATE_PARENT_UNAVAILABLE' => __('No writable private location outside the public web root is available.', 'wp-seed-pixel'),
            'FILESYSTEM_MISMATCH' => __('Recovery storage and images are on different filesystems. Optimization is blocked.', 'wp-seed-pixel'),
            'FOREIGN_DIRECTORY' => __('The proposed directory already belongs to something else. Pixel will not use or change it.', 'wp-seed-pixel'),
            'IDENTITY_CHANGED' => __('Recovery storage changed or disappeared. Optimization is blocked; existing images are unchanged.', 'wp-seed-pixel'),
            'UNSAFE_LOCATION' => __('The recovery location is no longer provably private. Optimization is blocked.', 'wp-seed-pixel'),
            'SETUP_INTERRUPTED' => __('Storage preparation was interrupted before ownership could be verified. No image will be processed.', 'wp-seed-pixel'),
            'HOST_MANAGED' => __('Recovery storage is controlled by this host. Its existing configuration is preserved.', 'wp-seed-pixel'),
            'HOST_DISABLED' => __('This host explicitly disabled recovery processing. Pixel will not override that choice.', 'wp-seed-pixel'),
        );
        return $messages[$code] ?? __('Storage preparation could not be verified. No image was changed.', 'wp-seed-pixel');
    }
}
