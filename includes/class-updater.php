<?php
defined('ABSPATH') || exit;

/** Verified updates use the core upgrader; this adapter never changes media or policy. */
final class WP_Seed_Pixel_Updater {
    const ID = 'wp-seed-pixel/wp-seed-pixel.php';
    const SCHEMA = 1;
    const OFFICIAL_MANIFEST = 'https://raw.githubusercontent.com/warzou/wp-seed-pixel/main/updates/stable.json';
    const MANIFEST_LIMIT = 16384;
    const PACKAGE_LIMIT = 16777216;

    public static function boot() {
        add_filter('pre_set_site_transient_update_plugins', array(__CLASS__, 'offer'));
        add_filter('plugins_api', array(__CLASS__, 'details'), 20, 3);
        add_filter('upgrader_pre_download', array(__CLASS__, 'download'), PHP_INT_MAX, 4);
        add_action('upgrader_process_complete', array(__CLASS__, 'complete'), 10, 2);
    }

    public static function endpoint() {
        $url = defined('WP_SEED_PIXEL_UPDATE_MANIFEST') ? WP_SEED_PIXEL_UPDATE_MANIFEST : self::OFFICIAL_MANIFEST;
        return self::https($url) ? $url : '';
    }

    private static function https($url) {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return false; }
        $p = wp_parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https' && !empty($p['host'])
            && !isset($p['user'], $p['pass']) && !isset($p['user']) && !isset($p['pass'])
            && !isset($p['fragment']) && !isset($p['query'])
            && (!isset($p['port']) || $p['port'] === 443);
    }

    public static function validate($v, $endpoint) {
        $keys = array('schema', 'slug', 'channel', 'version', 'requires', 'tested', 'requires_php', 'package', 'sha256', 'released', 'notes_url');
        if (!is_array($v) || array_diff(array_keys($v), $keys) || array_diff($keys, array_keys($v))
            || $v['schema'] !== self::SCHEMA || $v['slug'] !== 'wp-seed-pixel'
            || !in_array($v['channel'], array('stable', 'private'), true)) { return false; }
        foreach (array('version', 'requires', 'tested', 'requires_php') as $key) {
            if (!is_string($v[$key]) || !preg_match('/^[0-9]+\.[0-9]+(?:\.[0-9]+)?(?:-(?:alpha|beta|rc|private)\.[0-9]+)?$/D', $v[$key])) { return false; }
        }
        if (!self::https($endpoint) || !self::https($v['package']) || !self::https($v['notes_url'])
            || !preg_match('/\.zip$/D', wp_parse_url($v['package'], PHP_URL_PATH) ?? '')
            || !is_string($v['sha256']) || !preg_match('/^[a-f0-9]{64}$/D', $v['sha256'])
            || !is_string($v['released']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $v['released'])) { return false; }
        if ($v['channel'] === 'stable') {
            if ($endpoint !== self::OFFICIAL_MANIFEST || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $v['version'])
                || $v['package'] !== 'https://github.com/warzou/wp-seed-pixel/releases/download/v' . $v['version'] . '/wp-seed-pixel-' . $v['version'] . '.zip'
                || $v['notes_url'] !== 'https://github.com/warzou/wp-seed-pixel/releases/tag/v' . $v['version']) { return false; }
        } elseif ($endpoint === self::OFFICIAL_MANIFEST
            || strtolower(wp_parse_url($v['package'], PHP_URL_HOST)) !== strtolower(wp_parse_url($endpoint, PHP_URL_HOST))
            || strtolower(wp_parse_url($v['notes_url'], PHP_URL_HOST)) !== strtolower(wp_parse_url($endpoint, PHP_URL_HOST))) { return false; }
        return $v;
    }

    public static function manifest($fresh = false) {
        $url = self::endpoint();
        if (!$url) { return false; }
        $key = 'wp_seed_pixel_update_' . substr(hash('sha256', $url), 0, 24);
        if (!$fresh && ($cached = get_site_transient($key)) !== false) {
            return is_array($cached) ? self::validate($cached, $url) : false;
        }
        // No site URL, user, media, locale or credential is added to this GET.
        $r = wp_safe_remote_get($url, array('timeout' => 10, 'redirection' => 0, 'limit_response_size' => self::MANIFEST_LIMIT + 1,
            'user-agent' => 'WP-Seed-Pixel-Updater/1', 'cookies' => array(), 'headers' => array('Accept-Encoding' => 'identity')));
        $v = false;
        if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
            $body = wp_remote_retrieve_body($r);
            $length = wp_remote_retrieve_header($r, 'content-length');
            // A valid JSON prefix must not conceal a truncated, oversized response.
            if (is_string($body) && strlen($body) <= self::MANIFEST_LIMIT
                && ($length === '' || (is_scalar($length) && ctype_digit((string) $length) && (int) $length === strlen($body)))) {
                $v = self::validate(json_decode($body, true), $url);
            }
        }
        set_site_transient($key, $v ?: 'unavailable', $v ? 6 * HOUR_IN_SECONDS : 15 * MINUTE_IN_SECONDS);
        return $v;
    }

    public static function offer($t) {
        if (!is_object($t)) { return $t; }
        unset($t->response[self::ID], $t->no_update[self::ID]);
        $m = self::manifest();
        if (!$m || version_compare(PHP_VERSION, $m['requires_php'], '<')
            || version_compare(get_bloginfo('version'), $m['requires'], '<')) { return $t; }
        $current = $t->checked[self::ID] ?? WP_SEED_PIXEL_VERSION;
        $data = (object) array('slug' => 'wp-seed-pixel', 'plugin' => self::ID, 'new_version' => $m['version'],
            'url' => $m['notes_url'], 'package' => $m['package'], 'requires' => $m['requires'],
            'tested' => $m['tested'], 'requires_php' => $m['requires_php'], 'id' => 'wp-seed-pixel');
        if (version_compare($m['version'], $current, '>')) { $t->response[self::ID] = $data; }
        else { $data->package = ''; $t->no_update[self::ID] = $data; }
        return $t;
    }

    public static function details($result, $action, $args) {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'wp-seed-pixel') { return $result; }
        $m = self::manifest();
        if (!$m) { return new WP_Error('pixel_update_unavailable', __('Private update information is unavailable.', 'wp-seed-pixel')); }
        return (object) array('name' => 'WP Seed Pixel', 'slug' => 'wp-seed-pixel', 'version' => $m['version'],
            'requires' => $m['requires'], 'tested' => $m['tested'], 'requires_php' => $m['requires_php'],
            'last_updated' => $m['released'], 'download_link' => $m['package'],
            'sections' => array('description' => esc_html__('Local image optimization and verified recovery.', 'wp-seed-pixel'),
                'changelog' => '<a href="' . esc_url($m['notes_url']) . '">' . esc_html__('Read release notes', 'wp-seed-pixel') . '</a>'));
    }

    public static function archive($file, array $m) {
        if (!class_exists('ZipArchive')) { return new WP_Error('pixel_update_zip', __('ZIP validation is unavailable. Use the verified manual recovery procedure.', 'wp-seed-pixel')); }
        $z = new ZipArchive();
        if ($z->open($file, ZipArchive::CHECKCONS) !== true) { return new WP_Error('pixel_update_zip', __('Invalid update package.', 'wp-seed-pixel')); }
        try {
            if ($z->numFiles < 2 || $z->numFiles > 500) { return new WP_Error('pixel_update_zip'); }
            $seen = array(); $bytes = 0;
            for ($i = 0; $i < $z->numFiles; ++$i) {
                $s = $z->statIndex($i); $name = $s['name'];
                $opsys = 0; $attr = 0; $z->getExternalAttributesIndex($i, $opsys, $attr);
                if (!preg_match('~^wp-seed-pixel/(?:[A-Za-z0-9_-]+(?:\.[A-Za-z0-9_-]+)*/?)*$~D', $name)
                    || isset($seen[$name]) || (($attr >> 16) & 0170000) === 0120000 || !empty($s['encryption_method'])) { return new WP_Error('pixel_update_zip'); }
                $seen[$name] = true; $bytes += $s['size'];
                if ($bytes > 16 * 1024 * 1024) { return new WP_Error('pixel_update_zip'); }
            }
            $header = $z->getFromName(self::ID);
            if (!is_string($header) || strlen($header) > 65536
                || !preg_match('/^[ \t*]*Plugin Name:\s*WP Seed Pixel\s*$/m', $header)
                || !preg_match('/^[ \t*]*Version:\s*([^\r\n]+)$/m', $header, $match)
                || trim($match[1]) !== $m['version'] || !isset($seen['wp-seed-pixel/includes/class-updater.php'])) { return new WP_Error('pixel_update_identity', __('The package is not the expected Pixel version.', 'wp-seed-pixel')); }
            return true;
        } finally { $z->close(); }
    }

    public static function download($reply, $package, $upgrader, $extra) {
        if (($extra['plugin'] ?? '') !== self::ID) { return $reply; }
        $m = self::manifest(true);
        if (!$m || $package !== $m['package'] || !version_compare($m['version'], WP_SEED_PIXEL_VERSION, '>')
            || version_compare(PHP_VERSION, $m['requires_php'], '<')
            || version_compare(get_bloginfo('version'), $m['requires'], '<')) { return new WP_Error('pixel_update_untrusted', __('Update metadata or compatibility could not be verified.', 'wp-seed-pixel')); }
        // Verification happens before core unpacks or removes the old plugin directory.
        if (!function_exists('wp_tempnam')) { require_once ABSPATH . 'wp-admin/includes/file.php'; }
        $file = wp_tempnam('wp-seed-pixel-update.zip');
        if (!$file) { return new WP_Error('pixel_update_temp'); }
        $accepted = false;
        try {
            $r = self::package_request($package, $file, $m['channel'] === 'stable');
            if (is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200 || !is_file($file)
                || filesize($file) > self::PACKAGE_LIMIT
                || !hash_equals($m['sha256'], hash_file('sha256', $file))) { return new WP_Error('pixel_update_integrity', __('Update package integrity check failed. Nothing was installed.', 'wp-seed-pixel')); }
            $ok = self::archive($file, $m);
            if (is_wp_error($ok)) { return $ok; }
            $accepted = true;
            return $file;
        } finally { if (!$accepted && is_file($file)) { unlink($file); } }
    }

    private static function asset_location($url) {
        if (!is_string($url) || strlen($url) > 8192 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return false; }
        $p = wp_parse_url($url);
        return is_array($p) && ($p['scheme'] ?? '') === 'https'
            && ($p['host'] ?? '') === 'release-assets.githubusercontent.com'
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['fragment'])
            && (!isset($p['port']) || $p['port'] === 443)
            && preg_match('~^/github-production-release-asset/[0-9]+/[A-Za-z0-9-]+$~D', $p['path'] ?? '');
    }

    private static function package_request($url, $file, $official) {
        $args = array('timeout' => 60, 'redirection' => 0, 'stream' => true, 'filename' => $file,
            'limit_response_size' => self::PACKAGE_LIMIT + 1, 'user-agent' => 'WP-Seed-Pixel-Updater/1', 'cookies' => array());
        $r = wp_safe_remote_get($url, $args);
        if ($official && !is_wp_error($r) && wp_remote_retrieve_response_code($r) === 302) {
            $location = wp_remote_retrieve_header($r, 'location');
            if (!self::asset_location($location)) { return new WP_Error('pixel_update_redirect'); }
            // Signed queries exist only in memory; no automatic or third-hop redirects.
            $r = wp_safe_remote_get($location, $args);
        }
        return $r;
    }

    public static function complete($upgrader, $extra) {
        if (($extra['type'] ?? '') !== 'plugin' || ($extra['action'] ?? '') !== 'update'
            || !in_array(self::ID, $extra['plugins'] ?? array(), true)) { return; }
        if (self::endpoint()) { delete_site_transient('wp_seed_pixel_update_' . substr(hash('sha256', self::endpoint()), 0, 24)); }
    }
}
